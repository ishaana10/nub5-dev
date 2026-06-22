/**
 * nu-select2-init.js
 *
 * Initialises Select2 on any <select> that carries:
 *   data-select-type="select2"   ← emitted by form.php
 *   .nu-select2                  ← legacy class (backwards compat)
 *
 * data-select-mode="single|multiple"  controls single vs multi-value.
 * data-placeholder="…"                placeholder text.
 * data-allow-clear="true|false"       show/hide the × clear button.
 *
 * ─── Why r.GetData(...).destroy is not a function ───────────────────
 * Select2 v4 constructor does:
 *   var id = el.getAttribute('data-select2-id'); // e.g. "select2-data-8-u9m9"
 *   $.data(el, id).destroy();
 *
 * If data-select2-id is still present on the element when the constructor
 * runs, Select2 looks up that key via $.data(el, id).  In jQuery 3 this
 * reads from an internal cache that is NOT the same as the raw expando
 * bucket.  Previous fixes wiped the expando but left the $.data cache
 * intact, so the stale (destroy-less) stub survived and crashed.
 *
 * Fix:
 *   1. Strip data-select2-id from ALL targets at the very start of
 *      nuInitSelect2, before any per-element work, so stale IDs left
 *      over from a previous render never reach the Select2 constructor.
 *   2. Remove data-select2-id FIRST per element, before any Select2 call.
 *   3. Call $.removeData(el) with NO key to atomically flush the entire
 *      jQuery data store for the element (both cache layers).
 *   4. Stamp data-nu-s2="1" after success to guard against double-init.
 *
 * Public API
 *   nuDestroySelect2(el)   — hard-destroy a single element (safe if none)
 *   nuInitSelect2(scope)   — init/re-init all targets inside scope
 *   nuReinitSelect2(el)    — atomic destroy + re-init one element
 */
(function () {
  'use strict';

  var READY_ATTR = 'data-nu-s2';

  var DEBUG = (window.NU_SELECT2_DEBUG !== false);
  function dbg() {
    if (!DEBUG) return;
    var args = Array.prototype.slice.call(arguments);
    args.unshift('[nu-select2]');
    console.log.apply(console, args);
  }

  /**
   * Hard-destroy any Select2 instance on `el`, including corrupted ones.
   * Clears the ready stamp so the element can be re-initialised.
   */
  function nuDestroySelect2(el) {
    if (typeof jQuery === 'undefined') return;
    var $ = jQuery;

    // Clear ready stamp
    el.removeAttribute(READY_ATTR);

    dbg('destroy', el.name || el.getAttribute('data-field') || '?');

    // Step 1: Remove data-select2-id FIRST.
    // Select2's constructor reads this attribute to find its stored instance.
    // Removing it before any $.data call means the constructor finds nothing
    // to look up, regardless of what is in the jQuery data cache.
    el.removeAttribute('data-select2-id');

    // Step 2: Polite destroy via the public API while instance still reachable
    try {
      if (typeof $.fn.select2 !== 'undefined') {
        var existing = $.data(el, 'select2');
        if (existing && typeof existing.destroy === 'function') {
          $(el).select2('destroy');
          dbg('  polite destroy OK');
        }
      }
    } catch (e) { dbg('  destroy threw (ignored):', e.message); }

    // Step 3: Flush the ENTIRE jQuery data store for this element.
    // $.removeData(el) with no key argument is the only reliable way to
    // clear both the expando bucket AND jQuery 3's internal data cache.
    try { $.removeData(el); } catch (e) { /* ignore */ }

    // Step 4: Also wipe raw expando as belt-and-braces
    try {
      var expando = $.expando;
      if (expando && el[expando]) {
        var bucket = el[expando];
        if (typeof bucket === 'object' && bucket !== null) {
          if (bucket.data) bucket.data = {};
          Object.keys(bucket).forEach(function (k) {
            if (k.indexOf('select2') === 0) delete bucket[k];
          });
        }
      }
    } catch (e) { /* ignore */ }

    // Step 5: Remove orphaned Select2 DOM siblings
    var next = el.nextElementSibling;
    while (next && next.classList && (
      next.classList.contains('select2') ||
      next.classList.contains('select2-container')
    )) {
      var toRemove = next;
      next = next.nextElementSibling;
      toRemove.parentNode.removeChild(toRemove);
    }
  }

  window.nuDestroySelect2 = nuDestroySelect2;

  /* ── Run select2() on one already-cleaned element ────────────────── */
  function _initOne(el) {
    var $ = jQuery;
    var placeholder = el.dataset.placeholder || 'Select…';
    var allowClear  = el.dataset.allowClear !== 'false';
    var isMultiple  = el.dataset.selectMode === 'multiple' || el.hasAttribute('multiple');

    // Guarantee no stale ID attribute exists right before we call select2()
    el.removeAttribute('data-select2-id');

    var opts = {
      width:          '100%',
      theme:          (window.nuUXOptions && window.nuUXOptions.nuSelect2Theme) || 'default',
      placeholder:    placeholder,
      allowClear:     allowClear,
      multiple:       isMultiple,
      dropdownParent: $(document.body),
    };

    try {
      $(el).select2(opts);
      el.setAttribute(READY_ATTR, '1');
      dbg('init OK ✅', el.name || el.getAttribute('data-field') || '?');
      return true;
    } catch (err) {
      console.error('[nu-select2] init FAILED ❌', err.message, el);
      // Full wipe then one retry
      try {
        el.removeAttribute('data-select2-id');
        try { $.removeData(el); } catch (e2) { /* ignore */ }
        $(el).select2(opts);
        el.setAttribute(READY_ATTR, '1');
        dbg('retry OK ✅', el.name || el.getAttribute('data-field') || '?');
        return true;
      } catch (retryErr) {
        console.error('[nu-select2] retry FAILED ❌', retryErr.message, el);
        return false;
      }
    }
  }

  /**
   * Initialise all Select2 targets within `scope`.
   * Elements stamped data-nu-s2="1" are skipped (double-init guard).
   */
  function nuInitSelect2(scope) {
    var hasJQ      = typeof jQuery !== 'undefined';
    var hasSelect2 = hasJQ && typeof jQuery.fn.select2 !== 'undefined';
    if (!hasJQ || !hasSelect2) {
      console.warn('[nuInitSelect2] jQuery or Select2 not available');
      return;
    }

    var $ = jQuery;
    var root = scope instanceof Element ? scope : document;

    var $targets = $(root).find(
      'select[data-select-type="select2"], select.nu-select2'
    );

    dbg('nuInitSelect2 — targets:', $targets.length);

    // ── KEY FIX: strip stale data-select2-id from every target up-front ──
    // When a form is re-rendered into an existing container, Select2 may have
    // previously stamped data-select2-id onto the <select> elements. If those
    // IDs survive into the next init cycle, Select2's constructor tries to call
    // .destroy() on whatever $.data(el, id) returns — which is no longer a
    // real Select2 instance — causing "r.GetData(...).destroy is not a function".
    // Removing the attribute before any per-element work ensures the constructor
    // never finds a stale ID to look up.
    $targets.each(function () {
      this.removeAttribute('data-select2-id');
    });

    $targets.each(function () {
      var el = this;

      // Double-init guard
      if (el.getAttribute(READY_ATTR) === '1') {
        dbg('skip (already ready):', el.name || el.getAttribute('data-field') || '?');
        return;
      }

      dbg('init [' + (el.name || el.getAttribute('data-field') || '?') + ']',
          '| options:', el.options.length);

      nuDestroySelect2(el);
      _initOne(el);
    });
  }

  window.nuInitSelect2 = nuInitSelect2;

  /**
   * Force destroy + re-init a single element regardless of stamp.
   */
  function nuReinitSelect2(el) {
    if (!el) return;
    el.removeAttribute(READY_ATTR);
    el.removeAttribute('data-select2-id');
    nuDestroySelect2(el);
    _initOne(el);
  }

  window.nuReinitSelect2 = nuReinitSelect2;

  // Auto-init: nu:form:opened fires after modal is in DOM
  document.addEventListener('nu:form:opened', function (e) {
    var scope = e.detail && e.detail.scope;
    // Small delay lets any inline scripts run and stamp their elements first
    setTimeout(function () { nuInitSelect2(scope); }, 50);
  });

  // Page-load init
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { nuInitSelect2(); });
  } else {
    nuInitSelect2();
  }

}());
