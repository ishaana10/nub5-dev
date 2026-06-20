/**
 * nu-select2-init.js
 *
 * Initialises Select2 on any <select> that has data-select-type="select2".
 * The renderer emits data-select-mode="single|multiple" which controls
 * whether the element is a single or multi-value Select2.
 *
 * Also handles the legacy .nu-select2 class for backwards compatibility.
 *
 * Fix for "r.GetData(...).destroy is not a function":
 *   The crash happens inside Select2's own constructor — it calls
 *   GetData(el,'select2').destroy() before we can intercept it.
 *   The root cause is a stale entry in jQuery's INTERNAL __data__ cache
 *   (keyed by jQuery's expando property, e.g. jQuery123456) that $.data()
 *   and $.removeData() do NOT fully clear when the object is corrupted.
 *
 *   Solution: use nuDestroySelect2(el) which:
 *     1. Tries the normal $(el).select2('destroy') path
 *     2. Falls back to directly deleting the jQuery expando key from
 *        el[jQuery.expando] so Select2's constructor finds nothing
 *     3. Strips the data-select2-id attribute stamp
 *   This must be called BEFORE any $.select2() init — including the
 *   internal call inside Select2's constructor.
 */
(function () {
  'use strict';

  /**
   * Hard-destroy any Select2 instance on `el`, including corrupted ones.
   * Safe to call even when no instance exists.
   * @param {Element} el
   */
  function nuDestroySelect2(el) {
    if (typeof jQuery === 'undefined') return;
    var $ = jQuery;

    // ── Step 1: polite destroy via the public API ──────────────────────────
    var existing = $.data(el, 'select2');
    if (existing) {
      if (typeof existing.destroy === 'function') {
        try { $(el).select2('destroy'); } catch (e) { /* ignore */ }
      }
      // Flush the $.data entry whether destroy succeeded or not
      $.removeData(el, 'select2');
    }

    // ── Step 2: hard-nuke the jQuery internal expando entry ───────────────
    // $.removeData removes the key from jQuery's cache object, but the
    // expando property itself may still exist on the element with a
    // reference to the stale cache bucket.  Select2's GetData() reads
    // directly from that bucket, not through $.data(), so we must wipe
    // the 'select2' key out of the raw cache bucket too.
    var expando = $.expando; // e.g. "jQuery3600049091"
    if (expando && el[expando] !== undefined) {
      var cacheKey = el[expando];
      var cache    = $.cache && $.cache[cacheKey];
      if (cache && cache.data) {
        delete cache.data['select2'];
        // Also remove the stamped id so Select2 treats this as a fresh element
        delete cache.data['select2-id'];
      }
    }

    // ── Step 3: remove the HTML attribute stamp ────────────────────────────
    el.removeAttribute('data-select2-id');
  }

  // Expose globally — nb-form-builder.js calls this before any re-render
  window.nuDestroySelect2 = nuDestroySelect2;

  /**
   * Initialise (or re-initialise) all Select2 fields within `scope`.
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
        // Final safety net
        nuDestroySelect2(el);
      }
    });
  }

  // Expose globally so other modules can call nuInitSelect2(containerEl)
  window.nuInitSelect2 = nuInitSelect2;

  // Auto-init on form open events
  document.addEventListener('nu:form:opened', function (e) {
    var scope = e.detail && e.detail.scope;
    // Small delay to ensure DOM is fully painted before Select2 measures widths
    setTimeout(function () { nuInitSelect2(scope); }, 50);
  });

  // Auto-init on DOMContentLoaded for any select2 fields present at page load
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { nuInitSelect2(); });
  } else {
    nuInitSelect2();
  }

})();
