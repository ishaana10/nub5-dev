/**
 * nu-select2-init.js
 *
 * Initialises Select2 on any <select> that carries:
 *   data-select-type="select2"   ← emitted by FormRenderer.php
 *   .nu-select2                  ← legacy class (backwards compat)
 *
 * data-select-mode="single|multiple"  controls single vs multi-value.
 * data-placeholder="…"                placeholder text.
 * data-allow-clear="true|false"       show/hide the × clear button.
 *
 * ─── Why the crash happened ──────────────────────────────────────────
 * Error: "r.GetData(...).destroy is not a function"
 *
 * Select2's constructor calls GetData(el, 'select2').destroy() internally
 * before we get a chance to intercept it.  The stale object came from a
 * previous (failed or partial) Select2 init cycle; it was not a real
 * Select2 instance so it had no .destroy() method.
 *
 * Root cause: $.cache was REMOVED in jQuery 3.x.  In jQuery 3.x all
 * element data is stored directly on el[jQuery.expando] as a plain
 * object — NOT as a numeric index into a global $.cache bucket.
 *
 * The old hard-nuke code did:
 *   var cacheKey = el[expando];          // a NUMBER in jQuery 1/2
 *   var cache    = $.cache[cacheKey];    // always undefined in jQuery 3 ❌
 *   delete cache.data['select2'];        // never ran → stale data stayed
 *
 * Fix: treat el[expando] as the data bucket itself (jQuery 3.x) while
 * keeping a fallback for jQuery 1/2 environments.
 *
 * Public API
 *   nuDestroySelect2(el)        — hard-destroy a single element (safe if none)
 *   nuInitSelect2(scope)        — init/re-init all targets inside scope
 *   nuReinitSelect2(el)         — atomic destroy + re-init one element
 *                                 (called by field-type swap in form builder)
 */
(function () {
  'use strict';

  /**
   * Hard-destroy any Select2 instance on `el`, including corrupted ones.
   * Safe to call when no instance exists.
   *
   * @param {Element} el
   */
  function nuDestroySelect2(el) {
    if (typeof jQuery === 'undefined') return;
    var $ = jQuery;

    // ── Step 1: polite destroy via the public API ──────────────────────
    try {
      if (typeof $.fn.select2 !== 'undefined') {
        var existing = $.data(el, 'select2');
        if (existing && typeof existing.destroy === 'function') {
          $(el).select2('destroy');
        }
      }
    } catch (e) { /* ignore — the crash we are trying to prevent */ }

    // Always flush the public $.data entry
    $.removeData(el, 'select2');

    // ── Step 2: hard-nuke the jQuery internal expando bucket ───────────
    //
    // jQuery 3.x stores data directly on el[jQuery.expando] as a plain
    // object, e.g.:
    //   el["jQuery3600049091"] = { data: { select2: <instance>, … } }
    //
    // jQuery 1.x/2.x stored a numeric cache key on el[jQuery.expando]
    // and the actual data in jQuery.cache[key].
    //
    // We handle both:
    var expando = $.expando;
    if (expando && el[expando] !== undefined) {
      var bucket = el[expando];

      if (typeof bucket === 'object' && bucket !== null) {
        // jQuery 3.x: bucket IS the data container
        if (bucket.data) {
          delete bucket.data['select2'];
          delete bucket.data['select2-id'];
        }
        // Also wipe keys stored at the top level of the bucket
        // (some Select2 versions write here directly)
        delete bucket['select2'];
        delete bucket['select2-id'];
      } else if (typeof bucket === 'number') {
        // jQuery 1.x/2.x: bucket is a numeric cache key
        var cache = $.cache && $.cache[bucket];
        if (cache && cache.data) {
          delete cache.data['select2'];
          delete cache.data['select2-id'];
        }
      }
    }

    // ── Step 3: remove the HTML attribute stamp ────────────────────────
    el.removeAttribute('data-select2-id');

    // ── Step 4: remove the generated Select2 container from the DOM ───
    // Select2 injects a <span class="select2 …"> sibling after the
    // <select>.  If destroy() above didn't clean it up (because the
    // instance was corrupted), remove it manually so the next init
    // does not end up with a duplicate widget.
    var next = el.nextElementSibling;
    if (next && next.classList && next.classList.contains('select2')) {
      next.parentNode.removeChild(next);
    }
  }

  // Expose globally — nb-form-builder.js and other modules call this
  window.nuDestroySelect2 = nuDestroySelect2;

  /**
   * Initialise (or re-initialise) all Select2 targets within `scope`.
   *
   * @param {Element|null} scope  Container to search in (default: document)
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

    if (!$targets.length) return;

    $targets.each(function () {
      var el = this;

      // Hard-nuke any stale/corrupted instance BEFORE Select2's constructor
      // gets a chance to call GetData(el,'select2').destroy() itself.
      nuDestroySelect2(el);

      var placeholder = el.dataset.placeholder || 'Select\u2026';
      var allowClear  = el.dataset.allowClear !== 'false'; // default true
      var isMultiple  = el.dataset.selectMode === 'multiple' || el.hasAttribute('multiple');

      try {
        $(el).select2({
          width:          '100%',
          theme:          (window.nuUXOptions && window.nuUXOptions.nuSelect2Theme) || 'default',
          placeholder:    placeholder,
          allowClear:     allowClear,
          multiple:       isMultiple,
          dropdownParent: $(document.body),
        });
      } catch (initErr) {
        console.warn('[nuInitSelect2] select2() init failed on element:', el, initErr);
        // Final safety net — clean up so next attempt starts fresh
        nuDestroySelect2(el);
      }
    });
  }

  // Expose globally so other modules can call nuInitSelect2(containerEl)
  window.nuInitSelect2 = nuInitSelect2;

  /**
   * Atomically destroy + re-initialise Select2 on a single element.
   * Use this when swapping field type between select ↔ select2 in the
   * form builder, or when dynamically changing data-select-mode.
   *
   * @param {Element} el   The <select> element to reinitialise
   */
  function nuReinitSelect2(el) {
    if (!el) return;
    nuDestroySelect2(el);
    // Re-init just this one element
    nuInitSelect2(el.parentElement || document);
  }

  window.nuReinitSelect2 = nuReinitSelect2;

  // ── Auto-init hooks ─────────────────────────────────────────────────

  // Re-init whenever a form modal/panel is opened
  document.addEventListener('nu:form:opened', function (e) {
    var scope = e.detail && e.detail.scope;
    // Small delay to ensure DOM is fully painted before Select2 measures widths
    setTimeout(function () { nuInitSelect2(scope); }, 50);
  });

  // Init on page load for any select2 fields already in the DOM
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { nuInitSelect2(); });
  } else {
    nuInitSelect2();
  }

}());
