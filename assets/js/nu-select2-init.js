/**
 * nu-select2-init.js
 * Initialises Select2 on any <select data-select2="1"> inside a given scope.
 * Called after every form open (preview, edit, add).
 *
 * FIX 1: dropdownParent is always document.body — escapes overflow-y:auto
 *         clipping on the modal box div.
 * FIX 2: Safely destroy any existing Select2 instance before re-initialising
 *         to prevent "destroy is not a function" crash on double-init.
 */
(function () {

  function nuInitSelect2(scope) {
    if (typeof jQuery === 'undefined' || typeof jQuery.fn.select2 === 'undefined') return;
    var $ = jQuery;
    var root = scope || document;
    $(root).find('select[data-select2="1"]').each(function () {
      var $el = $(this);
      // Safely destroy any previous instance before re-init
      if ($el.hasClass('select2-hidden-accessible')) {
        try { $el.select2('destroy'); } catch (e) {}
      }
      var opts = {
        width: '100%',
        theme: 'default',
        dropdownParent: $(document.body)
      };
      var blank = this.options[0];
      if (blank && blank.value === '') {
        opts.placeholder = blank.textContent || blank.innerText || 'Select…';
        opts.allowClear  = true;
      }
      $el.select2(opts);
    });
  }

  window.nuInitSelect2 = nuInitSelect2;

  document.addEventListener('nu:form:opened', function (e) {
    var scope = e.detail && e.detail.scope;
    setTimeout(function () { nuInitSelect2(scope); }, 0);
  });

})();
