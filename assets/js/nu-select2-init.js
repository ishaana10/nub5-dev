/**
 * nu-select2-init.js
 *
 * ROOT CAUSE (confirmed by reading select2.min.js source):
 *   Select2 stores its instance in a static cache (s.__cache) keyed by the
 *   element's `data-select2-id` attribute. At the top of its constructor it
 *   does:
 *       if (GetData(el, "select2")) GetData(el, "select2").destroy()
 *   When we cloneNode(true), the clone inherits the old `data-select2-id`
 *   attribute, so the cache lookup finds the DEAD previous instance object
 *   (which has no .destroy method) and crashes:
 *       "r.GetData(...).destroy is not a function"
 *
 * FIX: remove `data-select2-id` from the clone BEFORE calling .select2().
 *   This makes Select2's cache lookup return undefined, so it skips the
 *   destroy call entirely and constructs a clean new instance.
 */
(function () {

  function nuInitSelect2(scope) {
    if (typeof jQuery === 'undefined' || typeof jQuery.fn.select2 === 'undefined') return;
    var $ = jQuery;
    var root = scope || document;

    $(root).find('select[data-select2="1"]').each(function () {
      var original = this;

      // Clone the element (copies <option> children) then strip the
      // stale Select2 cache key so its constructor finds nothing to destroy.
      var clone = original.cloneNode(true);
      clone.removeAttribute('data-select2-id');

      // Also wipe any select2-id attrs on child <option> elements
      var opts = clone.querySelectorAll('[data-select2-id]');
      for (var i = 0; i < opts.length; i++) {
        opts[i].removeAttribute('data-select2-id');
      }

      original.parentNode.replaceChild(clone, original);

      var $el = $(clone);
      var s2opts = {
        width: '100%',
        theme: 'default',
        dropdownParent: $(document.body)
      };
      var blank = clone.options[0];
      if (blank && blank.value === '') {
        s2opts.placeholder = blank.textContent || blank.innerText || 'Select…';
        s2opts.allowClear  = true;
      }
      $el.select2(s2opts);
    });
  }

  window.nuInitSelect2 = nuInitSelect2;

  document.addEventListener('nu:form:opened', function (e) {
    var scope = e.detail && e.detail.scope;
    setTimeout(function () { nuInitSelect2(scope); }, 0);
  });

})();
