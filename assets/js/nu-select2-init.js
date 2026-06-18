/**
 * nu-select2-init.js
 *
 * Initialises Select2 on every <select data-select2="1"> element found
 * within the given scope (defaults to document).
 *
 * Why the old clone approach broke:
 *   Select2 stores its instance via jQuery.data() — a JS-side cache keyed by
 *   an internal integer, not the DOM attribute. cloneNode(true) copies DOM
 *   attributes but NOT jQuery data, so $(clone).select2() still hit a stale
 *   jQuery-data entry and crashed with:
 *       "r.GetData(...).destroy is not a function"
 *
 * New approach:
 *   1. Attempt a graceful $(el).select2('destroy') — catch & ignore any error.
 *   2. Strip data-select2-id from the element AND its options (belt+suspenders).
 *   3. Call $(el).select2(opts) fresh.
 */
(function () {
  'use strict';

  function nuInitSelect2(scope) {
    var hasJQ     = typeof jQuery !== 'undefined';
    var hasSelect2 = hasJQ && typeof jQuery.fn.select2 !== 'undefined';

    console.group('[nuInitSelect2] called');
    console.log('  scope     :', scope || document);
    console.log('  jQuery    :', hasJQ     ? 'YES v' + (jQuery.fn.jquery || '?') : 'MISSING');
    console.log('  Select2   :', hasSelect2 ? 'YES' : 'MISSING — aborting');

    if (!hasJQ || !hasSelect2) {
      console.groupEnd();
      return;
    }

    var $ = jQuery;
    var root = scope || document;
    var $selects = $(root).find('select[data-select2="1"]');

    console.log('  found selects with data-select2="1":', $selects.length);

    if (!$selects.length) {
      // Also log ALL selects found so we can spot wrong attribute names
      var allSelects = $(root).find('select');
      console.log('  (all <select> elements in scope:', allSelects.length, ')');
      allSelects.each(function (i) {
        console.log('    [' + i + '] name=' + this.name + ' data-select2="' + (this.getAttribute('data-select2') || '') + '" data-select2-id="' + (this.getAttribute('data-select2-id') || '') + '"');
      });
    }

    $selects.each(function (i) {
      var el = this;
      console.group('  [' + i + '] <select name="' + el.name + '">');
      console.log('    data-select2-id before clean:', el.getAttribute('data-select2-id') || '(none)');
      console.log('    options count:', el.options.length);
      console.log('    in DOM:', document.body.contains(el));

      // Step 1: graceful destroy
      try {
        if ($(el).data('select2')) {
          console.log('    existing Select2 instance found — destroying');
          $(el).select2('destroy');
          console.log('    destroy OK');
        } else {
          console.log('    no existing Select2 instance — skipping destroy');
        }
      } catch (destroyErr) {
        console.warn('    destroy threw (ignored):', destroyErr.message);
      }

      // Step 2: strip stale cache keys from element and options
      el.removeAttribute('data-select2-id');
      var dirtyOpts = el.querySelectorAll('[data-select2-id]');
      if (dirtyOpts.length) {
        console.log('    stripped data-select2-id from', dirtyOpts.length, 'option(s)');
        for (var j = 0; j < dirtyOpts.length; j++) {
          dirtyOpts[j].removeAttribute('data-select2-id');
        }
      }

      // Step 3: build Select2 options
      var s2opts = {
        width:          '100%',
        theme:          'default',
        dropdownParent: $(document.body),
      };
      var blank = el.options[0];
      if (blank && blank.value === '') {
        s2opts.placeholder = blank.textContent.trim() || blank.innerText || 'Select…';
        s2opts.allowClear  = true;
        console.log('    placeholder:', s2opts.placeholder);
      }

      // Step 4: initialise
      try {
        $(el).select2(s2opts);
        console.log('    select2() init OK');
      } catch (initErr) {
        console.error('    select2() init FAILED:', initErr.message, initErr);
      }

      console.groupEnd();
    });

    console.groupEnd();
  }

  window.nuInitSelect2 = nuInitSelect2;

  document.addEventListener('nu:form:opened', function (e) {
    var scope = e.detail && e.detail.scope;
    console.log('[nuInitSelect2] nu:form:opened fired, scope:', scope || document);
    // Small delay so the DOM is fully painted before Select2 measures widths
    setTimeout(function () { nuInitSelect2(scope); }, 50);
  });

})();
