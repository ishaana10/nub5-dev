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
 * Public API
 *   nuDestroySelect2(el)   — hard-destroy a single element (safe if none)
 *   nuInitSelect2(scope)   — init/re-init all targets inside scope
 *   nuReinitSelect2(el)    — atomic destroy + re-init one element
 */
(function () {
  'use strict';

  var DEBUG = (window.NU_SELECT2_DEBUG !== false);

  function dbg() {
    if (!DEBUG) return;
    var args = Array.prototype.slice.call(arguments);
    args.unshift('[nu-select2]');
    console.log.apply(console, args);
  }

  /**
   * Wipe every key that starts with "select2" from both levels of the
   * jQuery expando bucket for `el`.
   *
   * Select2 v4 stores its instance under a GENERATED key such as
   * "select2-data-9-0u0n" inside bucket.data — NOT under the literal
   * key "select2".  Deleting only bucket.data['select2'] therefore
   * leaves the stale instance alive, which causes the next Select2
   * constructor to call GetData(el,'select2-data-…').destroy() on the
   * ghost object → "destroy is not a function".
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
      // jQuery 3.x — bucket IS the data container
      nukeKeys(bucket);          // top-level keys (e.g. bucket['select2'])
      nukeKeys(bucket.data);     // nested keys   (e.g. bucket.data['select2-data-9-0u0n'])
    } else if (typeof bucket === 'number') {
      // jQuery 1.x / 2.x — bucket is a numeric cache index
      var cache = jQuery.cache && jQuery.cache[bucket];
      if (cache) {
        nukeKeys(cache);
        nukeKeys(cache.data);
      }
    }
  }

  /**
   * Hard-destroy any Select2 instance on `el`, including corrupted ones.
   * Safe to call when no instance exists.
   */
  function nuDestroySelect2(el) {
    if (typeof jQuery === 'undefined') return;
    var $ = jQuery;

    dbg('destroy START', el.tagName,
        'name=' + (el.name || el.getAttribute('data-field') || '?'),
        'data-select2-id before clean:', el.getAttribute('data-select2-id'));

    // Step 1: nuclear expando wipe — removes ALL select2-* keyed data
    nuNukeExpandoSelect2(el);

    // Step 2: polite destroy via the public API (now safe — no stale stub)
    try {
      if (typeof $.fn.select2 !== 'undefined') {
        var existing = $.data(el, 'select2');
        dbg('existing Select2 instance found —', existing ? 'destroying' : 'none');
        if (existing && typeof existing.destroy === 'function') {
          $(el).select2('destroy');
        }
      }
    } catch (e) { dbg('destroy threw (ignored):', e.message); }

    // Step 3: flush any remaining public $.data entries that start with select2
    try {
      var jqData = $.data(el);
      if (jqData) {
        Object.keys(jqData).forEach(function (k) {
          if (k.indexOf('select2') === 0) $.removeData(el, k);
        });
      }
    } catch (e) { /* ignore */ }

    // Step 4: remove the HTML attribute stamp
    el.removeAttribute('data-select2-id');

    // Step 5: remove orphaned Select2 container siblings from the DOM
    var next = el.nextElementSibling;
    while (next && next.classList && (
      next.classList.contains('select2') ||
      next.classList.contains('select2-container')
    )) {
      var toRemove = next;
      next = next.nextElementSibling;
      toRemove.parentNode.removeChild(toRemove);
    }

    dbg('destroy END', el.tagName,
        'name=' + (el.name || el.getAttribute('data-field') || '?'));
  }

  window.nuDestroySelect2 = nuDestroySelect2;

  /**
   * Initialise (or re-initialise) all Select2 targets within `scope`.
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

    dbg('nuInitSelect2 called — scope:', root === document ? 'document' : (root.tagName + '#' + (root.id || '?')),
        '| targets matched:', $targets.length);

    if (!$targets.length) return;

    $targets.each(function () {
      var el = this;

      dbg('init [' + (el.name || el.getAttribute('data-field') || '?') + ']',
          '| options:', el.options.length,
          '| in DOM:', document.contains(el));

      // Full nuclear destroy before every init — wipes ALL select2-* keys
      nuDestroySelect2(el);

      var placeholder = el.dataset.placeholder || 'Select\u2026';
      var allowClear  = el.dataset.allowClear !== 'false';
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
        dbg('  select2() init OK ✅', el.name || el.getAttribute('data-field') || '?');
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
          dbg('  select2() retry OK ✅', el.name || el.getAttribute('data-field') || '?');
        } catch (retryErr) {
          console.error('[nu-select2] select2() retry also FAILED ❌', retryErr.message, el);
        }
      }
    });
  }

  window.nuInitSelect2 = nuInitSelect2;

  /**
   * Atomically destroy + re-initialise Select2 on a single element.
   */
  function nuReinitSelect2(el) {
    if (!el) return;
    dbg('nuReinitSelect2 called on', el.tagName,
        el.name || el.getAttribute('data-field') || '?');
    nuDestroySelect2(el);
    nuInitSelect2(el.parentElement || document);
  }

  window.nuReinitSelect2 = nuReinitSelect2;

  // Re-init whenever a form modal/panel is opened
  document.addEventListener('nu:form:opened', function (e) {
    var scope = e.detail && e.detail.scope;
    setTimeout(function () { nuInitSelect2(scope); }, 50);
  });

  // Init on page load
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { nuInitSelect2(); });
  } else {
    nuInitSelect2();
  }

}());
