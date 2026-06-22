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
 * ─── Double-init crash (root cause) ──────────────────────────────────
 * The rendered form HTML contains inline <script> tags.
 * _execModuleScripts() re-executes those scripts the moment the form is
 * inserted into the live DOM — one of them calls nuInitSelect2().
 * 50 ms later, the nu:form:opened event fires and nuInitSelect2() runs
 * AGAIN on the same <select> elements.
 *
 * Select2's constructor internally calls GetData(el, '<generated-key>').destroy()
 * on whatever it finds in jQuery's data store.  If the first init already
 * wrote a live (but not fully settled) instance there, the second call
 * finds an object without a .destroy() method → crash.
 *
 * Fix: stamp data-nu-s2="1" on every element right after a successful
 * init.  nuInitSelect2 skips already-stamped elements.  nuDestroySelect2
 * removes the stamp so explicit re-inits (field-type swap, etc.) still work.
 *
 * Public API
 *   nuDestroySelect2(el)   — hard-destroy a single element (safe if none)
 *   nuInitSelect2(scope)   — init/re-init all targets inside scope
 *   nuReinitSelect2(el)    — atomic destroy + re-init one element
 */
(function () {
  'use strict';

  var READY_ATTR = 'data-nu-s2';   // stamp written after successful init

  var DEBUG = (window.NU_SELECT2_DEBUG !== false);
  function dbg() {
    if (!DEBUG) return;
    var args = Array.prototype.slice.call(arguments);
    args.unshift('[nu-select2]');
    console.log.apply(console, args);
  }

  /* ── Wipe ALL select2-* keyed data from the jQuery expando ────────────
   * Select2 v4 stores its instance under a GENERATED key such as
   * "select2-data-9-0u0n" inside bucket.data.  Deleting only the literal
   * key "select2" leaves the stale instance alive.
   */
  function nuNukeExpandoSelect2(el) {
    if (typeof jQuery === 'undefined') return;
    var expando = jQuery.expando;
    if (!expando || el[expando] === undefined) return;
    var bucket = el[expando];

    function nukeKeys(obj) {
      if (!obj || typeof obj !== 'object') return;
      Object.keys(obj).forEach(function (k) {
        if (k.indexOf('select2') === 0) delete obj[k];
      });
    }

    if (typeof bucket === 'object' && bucket !== null) {
      nukeKeys(bucket);
      nukeKeys(bucket.data);
    } else if (typeof bucket === 'number') {
      var cache = jQuery.cache && jQuery.cache[bucket];
      if (cache) { nukeKeys(cache); nukeKeys(cache.data); }
    }
  }

  /**
   * Hard-destroy any Select2 instance on `el`, including corrupted ones.
   * Clears the ready stamp so the element can be re-initialised.
   */
  function nuDestroySelect2(el) {
    if (typeof jQuery === 'undefined') return;
    var $ = jQuery;

    // Clear ready stamp first — must happen before any re-init attempt
    el.removeAttribute(READY_ATTR);

    dbg('destroy START', el.tagName,
        'name=' + (el.name || el.getAttribute('data-field') || '?'));

    // Step 1: nuclear expando wipe
    nuNukeExpandoSelect2(el);

    // Step 2: polite destroy (now safe — stale stub is gone)
    try {
      if (typeof $.fn.select2 !== 'undefined') {
        var existing = $.data(el, 'select2');
        dbg('existing Select2 instance —', existing ? 'destroying' : 'none');
        if (existing && typeof existing.destroy === 'function') {
          $(el).select2('destroy');
        }
      }
    } catch (e) { dbg('destroy threw (ignored):', e.message); }

    // Step 3: flush all select2-* public $.data entries
    try {
      var jqData = $.data(el);
      if (jqData) {
        Object.keys(jqData).forEach(function (k) {
          if (k.indexOf('select2') === 0) $.removeData(el, k);
        });
      }
    } catch (e) { /* ignore */ }

    // Step 4: remove the HTML attribute stamp Select2 writes
    el.removeAttribute('data-select2-id');

    // Step 5: remove orphaned Select2 DOM siblings
    var next = el.nextElementSibling;
    while (next && next.classList && (
      next.classList.contains('select2') ||
      next.classList.contains('select2-container')
    )) {
      var toRemove = next;
      next = next.nextElementSibling;
      toRemove.parentNode.removeChild(toRemove);
    }

    dbg('destroy END', 'name=' + (el.name || el.getAttribute('data-field') || '?'));
  }

  window.nuDestroySelect2 = nuDestroySelect2;

  /* ── Internal: run select2() on one already-cleaned element ───────── */
  function _initOne(el) {
    var $ = jQuery;
    var placeholder = el.dataset.placeholder || 'Select\u2026';
    var allowClear  = el.dataset.allowClear !== 'false';
    var isMultiple  = el.dataset.selectMode === 'multiple' || el.hasAttribute('multiple');

    dbg('  placeholder:', placeholder, '| allowClear:', allowClear, '| multiple:', isMultiple);

    try {
      $(el).select2({
        width:          '100%',
        theme:          (window.nuUXOptions && window.nuUXOptions.nuSelect2Theme) || 'default',
        placeholder:    placeholder,
        allowClear:     allowClear,
        multiple:       isMultiple,
        dropdownParent: $(document.body),
      });
      // ── Stamp success — prevents the double-init crash ──────────────
      el.setAttribute(READY_ATTR, '1');
      dbg('  init OK ✅', el.name || el.getAttribute('data-field') || '?');
      return true;
    } catch (initErr) {
      console.error('[nu-select2] select2() init FAILED ❌', initErr.message, el);
      // Nuclear retry: wipe expando completely then try once more
      try {
        nuNukeExpandoSelect2(el);
        el.removeAttribute('data-select2-id');
        $(el).select2({
          width:          '100%',
          theme:          (window.nuUXOptions && window.nuUXOptions.nuSelect2Theme) || 'default',
          placeholder:    placeholder,
          allowClear:     allowClear,
          multiple:       isMultiple,
          dropdownParent: $(document.body),
        });
        el.setAttribute(READY_ATTR, '1');
        dbg('  retry OK ✅', el.name || el.getAttribute('data-field') || '?');
        return true;
      } catch (retryErr) {
        console.error('[nu-select2] retry also FAILED ❌', retryErr.message, el);
        return false;
      }
    }
  }

  /**
   * Initialise (or re-initialise) all Select2 targets within `scope`.
   * Elements already stamped with data-nu-s2="1" are skipped — this is
   * the key guard that prevents the double-init crash.
   */
  function nuInitSelect2(scope) {
    var hasJQ      = typeof jQuery !== 'undefined';
    var hasSelect2 = hasJQ && typeof jQuery.fn.select2 !== 'undefined';

    if (!hasJQ || !hasSelect2) {
      console.warn('[nuInitSelect2] jQuery or Select2 not available — aborting');
      return;
    }

    var $ = jQuery;
    var root = scope instanceof Element ? scope : document;

    var $targets = $(root).find(
      'select[data-select-type="select2"], ' +
      'select.nu-select2'
    );

    dbg('nuInitSelect2 — scope:', root === document ? 'document' : root.tagName,
        '| targets:', $targets.length);

    $targets.each(function () {
      var el = this;

      // ── GUARD: skip if already successfully initialised ──────────────
      // This is what stops the double-init crash when both the inline
      // <script> and the nu:form:opened handler call nuInitSelect2.
      if (el.getAttribute(READY_ATTR) === '1') {
        dbg('  skip (already ready):', el.name || el.getAttribute('data-field') || '?');
        return;
      }

      dbg('init [' + (el.name || el.getAttribute('data-field') || '?') + ']',
          '| options:', el.options.length, '| in DOM:', document.contains(el));

      // Full destroy before every fresh init
      nuDestroySelect2(el);
      _initOne(el);
    });
  }

  window.nuInitSelect2 = nuInitSelect2;

  /**
   * Atomically destroy + re-initialise a single element.
   * Forces re-init even if already stamped.
   */
  function nuReinitSelect2(el) {
    if (!el) return;
    dbg('nuReinitSelect2:', el.name || el.getAttribute('data-field') || '?');
    el.removeAttribute(READY_ATTR);   // force re-init regardless of stamp
    nuDestroySelect2(el);
    _initOne(el);
  }

  window.nuReinitSelect2 = nuReinitSelect2;

  // ── Auto-init hooks ──────────────────────────────────────────────────

  document.addEventListener('nu:form:opened', function (e) {
    var scope = e.detail && e.detail.scope;
    // Delay to let inline scripts run first (they stamp elements they init).
    // Any element already stamped will be skipped — no double-init.
    setTimeout(function () { nuInitSelect2(scope); }, 50);
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { nuInitSelect2(); });
  } else {
    nuInitSelect2();
  }

}());
