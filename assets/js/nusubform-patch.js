/**
 * nusubform-patch.js
 * ─────────────────────────────────────────────────────────────────────────────
 * Fix — Bug 1: nu:parent:saved is never dispatched after the parent form saves.
 *
 * This patch wraps NuApp.apiJson so that any call to api/form.php?action=save
 * (parent form only — NOT action=subform_save) that succeeds automatically:
 *   1. Stamps parent_id on every .nu-subform-container in the active modal
 *   2. Fires the nu:parent:saved DOM event
 *
 * nusubform.js already has a listener for nu:parent:saved that calls
 * nuSubform.onParentSaved() — so we must NOT call it directly here too,
 * otherwise the pending queue is flushed twice → duplicate subform rows.
 *
 * URL match uses /[?&]action=save(&|$)/ to avoid matching action=subform_save.
 *
 * Load this file AFTER nubuilder-next.js and nusubform.js.
 * ─────────────────────────────────────────────────────────────────────────────
 */
(function (window) {
  'use strict';

  /* Matches ?action=save or &action=save but NOT action=subform_save */
  var PARENT_SAVE_RE = /[?&]action=save(&|$)/;

  function applyPatch() {
    var app = window.NuApp;
    if (!app || typeof app.apiJson !== 'function') return;

    var _origApiJson = app.apiJson.bind(app);

    app.apiJson = function (url, options) {
      return _origApiJson(url, options).then(function (json) {

        /* Only act on a successful PARENT-form save — not subform_save */
        if (
          typeof url === 'string' &&
          PARENT_SAVE_RE.test(url) &&
          json && json.success
        ) {
          var savedId = String(
            (json.data && (json.data.id || json.data.record_id))
              || json.id
              || json.record_id
              || ''
          );

          if (savedId) {
            /* Find the open modal overlay that contains subform containers */
            var box = null;
            var overlays = document.querySelectorAll('.nu-form-overlay');
            overlays.forEach(function (ov) {
              if (ov.querySelector('.nu-subform-container')) box = ov;
            });
            var scope = box || document;

            /* 1. Stamp parent_id onto every subform container in scope */
            if (typeof app._stampSubformParentId === 'function' && box) {
              app._stampSubformParentId(box, savedId);
            } else {
              scope.querySelectorAll('.nu-subform-container').forEach(function (el) {
                el.dataset.parentId = savedId;
              });
            }

            /* 2. Fire the DOM event — nusubform.js listener calls
               nuSubform.onParentSaved() from here. Do NOT call it directly
               as well or the pending queue will flush twice → duplicate rows. */
            document.dispatchEvent(new CustomEvent('nu:parent:saved', {
              detail: { id: savedId, scope: scope }
            }));
          }
        }

        return json;
      });
    };

    console.log('[nusubform-patch] nu:parent:saved dispatch patch applied.');
  }

  if (window.NuApp && window.NuApp.apiJson) {
    applyPatch();
  } else {
    document.addEventListener('DOMContentLoaded', applyPatch);
  }

}(window));
