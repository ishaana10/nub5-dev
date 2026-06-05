/**
 * nb-form-edit.js
 * Adds nbFormBuilder.edit(formId) — fetches a saved form from the API
 * and restores ALL builder fields: name, code, table, form_type,
 * table_mode, pk_type, browse settings, events, php/css, and field canvas.
 *
 * Loaded after nubuilder-next.js.
 */
(function () {
  'use strict';

  function waitForBuilder(cb) {
    if (window.nbFormBuilder && typeof window.nbFormBuilder.open === 'function') {
      cb();
    } else {
      setTimeout(function () { waitForBuilder(cb); }, 50);
    }
  }

  waitForBuilder(function () {

    // ── nbFormBuilder.edit(formId) ──────────────────────────────────────
    window.nbFormBuilder.edit = async function (formId) {
      if (!formId) { NuApp.toast('No form ID', 'error'); return; }

      try {
        const res  = await NuApp.apiJson(
          'api/forms.php?action=get&id=' + encodeURIComponent(formId),
          { credentials: 'same-origin' }
        );
        if (!res.success || !res.form) {
          NuApp.toast(res.error || 'Form not found', 'error');
          return;
        }

        const f = res.form;

        // 1. Open the builder UI (reset then overwrite)
        window.nbFormBuilder.open();

        // Give the DOM a tick to finish rendering open()
        await new Promise(r => setTimeout(r, 0));

        // 2. Set hidden edit ID
        const editIdEl = document.getElementById('editFormId');
        if (editIdEl) editIdEl.value = f.form_id || formId;

        // Update builder title
        const titleEl = document.getElementById('builderTitle');
        if (titleEl) titleEl.textContent = 'Edit Form';

        // 3. Name + Code
        const nameEl = document.getElementById('builderFormName');
        if (nameEl) nameEl.value = f.form_name || '';

        const codeEl = document.getElementById('builderFormCode');
        if (codeEl) codeEl.value = f.form_code || '';

        // 4. Form Type
        const ftype = f.form_type || 'main';
        const ftypeRadio = document.querySelector('input[name="formType"][value="' + ftype + '"]');
        if (ftypeRadio) {
          const ftypeCard = ftypeRadio.closest('.nb-ftype-card');
          window.nbFormBuilder.selectFormType(ftype, ftypeCard);
        }

        // 5. Table Mode
        const tableMode = f.form_table_mode || 'new';
        const tModeRadio = document.querySelector('input[name="formTableMode"][value="' + tableMode + '"]');
        if (tModeRadio) {
          const tModeCard = tModeRadio.closest('.nb-tmode-card');
          window.nbFormBuilder.selectTableMode(tableMode, tModeCard);
        }

        // Set table name in the correct input
        const tableVal = f.form_table || '';
        const tableNewEl = document.getElementById('builderFormTable');
        if (tableNewEl) tableNewEl.value = tableVal;

        if (tableMode === 'existing') {
          const tableExistEl = document.getElementById('builderFormTableExisting');
          if (tableExistEl) {
            tableExistEl.value = tableVal;
            // If option not present (table removed from DB), add it
            if (tableExistEl.value !== tableVal && tableVal) {
              const opt = document.createElement('option');
              opt.value = tableVal;
              opt.textContent = tableVal + ' (current)';
              tableExistEl.prepend(opt);
              tableExistEl.value = tableVal;
            }
          }
        }

        // 6. PK Type
        const pkType = f.form_pk_type || 'autoincrement';
        const pkRadio = document.querySelector('input[name="formPkType"][value="' + pkType + '"]');
        if (pkRadio) {
          const pkCard = pkRadio.closest('.nb-pk-card');
          window.nbFormBuilder.selectPkType(pkType, pkCard);
        }

        // 7. Browse tab settings
        _setVal('formBrowseSql',               f.browse_sql               || '');
        _setVal('formBrowseColumns',            f.browse_columns           || '');
        _setVal('formBrowsePageSize',           f.browse_page_size         || 20);
        _setVal('formBrowseDefaultSort',        f.browse_default_sort      || '');
        _setVal('formBrowseSearchPlaceholder',  f.browse_search_placeholder|| '');
        _setVal('formBrowseSearchFields',       f.browse_search_fields     || '');
        _setChk('formBrowseSearchEnabled',      f.browse_search_enabled);

        // Browse display mode
        const bdm = f.browse_display_mode || 'inline';
        const bdmRadio = document.querySelector('input[name="browseDisplayMode"][value="' + bdm + '"]');
        if (bdmRadio) {
          const bdmCard = bdmRadio.closest('.nb-display-mode-card');
          if (window.nbFormBuilder.selectDisplayMode) {
            window.nbFormBuilder.selectDisplayMode(bdm, bdmCard);
          }
        }

        // 8. Events / JS tab
        _setVal('formCustomJs',      f.form_custom_js       || '');
        _setVal('formJsBeforeSave',  f.form_js_before_save  || '');
        _setVal('formJsAfterSave',   f.form_js_after_save   || '');

        // 9. PHP / CSS tab
        _setVal('formCustomPhp',     f.form_custom_php      || '');
        _setVal('formCustomCss',     f.form_custom_css      || '');

        // 10. Rebuild field canvas
        _rebuildCanvas(f.form_layout);

      } catch (err) {
        console.error('nbFormBuilder.edit error', err);
        NuApp.toast('Edit error: ' + err.message, 'error');
      }
    };

    // ── Helpers ────────────────────────────────────────────────────────
    function _setVal(id, val) {
      const el = document.getElementById(id);
      if (el) el.value = val;
    }
    function _setChk(id, val) {
      const el = document.getElementById(id);
      if (el) el.checked = !!(Number(val) || val === true);
    }

    // ─────────────────────────────────────────────────────────────────
    // _rebuildCanvas
    // After calling addField() for each saved field we stamp the full
    // field-object JSON onto the card element as data-field-json.
    // This is what _readSubformData() reads in nb-subform-fk-builder.js
    // to restore the Child Form / FK Field dropdowns on next edit.
    // ─────────────────────────────────────────────────────────────────
    function _rebuildCanvas(layoutJson) {
      const canvas = document.getElementById('formCanvas');
      const empty  = document.getElementById('canvasEmpty');
      if (!canvas) return;

      // Clear everything except the empty-placeholder
      canvas.querySelectorAll('.nb-cfield').forEach(function (el) { el.remove(); });

      let fields = [];
      try {
        fields = typeof layoutJson === 'string'
          ? JSON.parse(layoutJson)
          : (Array.isArray(layoutJson) ? layoutJson : []);
      } catch (e) { fields = []; }

      if (!fields.length) {
        if (empty) empty.style.display = 'block';
        return;
      }
      if (empty) empty.style.display = 'none';

      fields.forEach(function (f) {
        if (typeof window.nbFormBuilder.addField !== 'function') {
          console.warn('nbFormBuilder.addField not available; canvas not rebuilt');
          return;
        }

        // Snapshot the card count BEFORE adding so we can identify the new card.
        const beforeCount = canvas.querySelectorAll('.nb-cfield').length;

        window.nbFormBuilder.addField(f.type || f.fieldtype || 'text', f);

        // ── FIX: stamp data-field-json onto the newly added card ──────
        // _readSubformData() in nb-subform-fk-builder.js uses this as
        // its fallback source for subform.form_code and subform.fk_field.
        // We also stamp the fast-path data-sf-* attributes directly so
        // the primary branch in _readSubformData fires immediately.
        if ((f.type || f.fieldtype || '') === 'subform') {
          const allCards = canvas.querySelectorAll('.nb-cfield');
          // The newly added card is whichever appeared at index beforeCount
          const newCard = allCards[beforeCount] || allCards[allCards.length - 1];
          if (newCard) {
            // Fallback path: full JSON blob
            try {
              newCard.dataset.fieldJson = JSON.stringify(f);
            } catch (e) {}

            // Fast path: explicit data-sf-* attributes
            var sf = (f.subform && typeof f.subform === 'object') ? f.subform : {};
            var sfFormCode = sf.form_code || sf.formcode || '';
            var sfFkField  = sf.fk_field  || sf.fkfield  || '';
            if (sfFormCode) newCard.dataset.sfFormCode       = sfFormCode;
            if (sfFkField)  newCard.dataset.sfFkField        = sfFkField;
            if (f.is_fk)           newCard.dataset.sfIsFk           = '1';
            if (f.hide_in_grid)    newCard.dataset.sfHideInGrid     = '1';
            if (f.server_readonly) newCard.dataset.sfServerReadonly = '1';
          }
        }
      });
    }

    console.log('[nb-form-edit] nbFormBuilder.edit() registered.');
  });

})();
