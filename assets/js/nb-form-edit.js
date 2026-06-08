/**
 * nb-form-edit.js
 * Adds nbFormBuilder.edit(formId) — fetches a saved form from the API
 * and restores ALL builder fields: name, code, table, form_type,
 * table_mode, pk_type, browse settings, events, php/css, and field canvas.
 *
 * Depends on: nubuilder-next.js, nb-sf-data.js
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
        const res = await NuApp.apiJson(
          'api/forms.php?action=get&id=' + encodeURIComponent(formId),
          { credentials: 'same-origin' }
        );
        if (!res.success || !res.form) {
          NuApp.toast(res.error || 'Form not found', 'error');
          return;
        }

        const f = res.form;

        // 1. Open the builder UI
        window.nbFormBuilder.open();
        await new Promise(r => setTimeout(r, 0));

        // 2. Hidden edit ID + title
        const editIdEl = document.getElementById('editFormId');
        if (editIdEl) editIdEl.value = f.form_id || formId;
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
        if (ftypeRadio) window.nbFormBuilder.selectFormType(ftype, ftypeRadio.closest('.nb-ftype-card'));

        // 5. Table Mode
        const tableMode = f.form_table_mode || 'new';
        const tModeRadio = document.querySelector('input[name="formTableMode"][value="' + tableMode + '"]');
        if (tModeRadio) window.nbFormBuilder.selectTableMode(tableMode, tModeRadio.closest('.nb-tmode-card'));

        const tableVal = f.form_table || '';
        const tableNewEl = document.getElementById('builderFormTable');
        if (tableNewEl) tableNewEl.value = tableVal;

        if (tableMode === 'existing') {
          const tableExistEl = document.getElementById('builderFormTableExisting');
          if (tableExistEl) {
            tableExistEl.value = tableVal;
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
        if (pkRadio) window.nbFormBuilder.selectPkType(pkType, pkRadio.closest('.nb-pk-card'));

        // 7. Browse tab
        _setVal('formBrowseSql',               f.browse_sql                || '');
        _setVal('formBrowseColumns',            f.browse_columns            || '');
        _setVal('formBrowsePageSize',           f.browse_page_size          || 20);
        _setVal('formBrowseDefaultSort',        f.browse_default_sort       || '');
        _setVal('formBrowseSearchPlaceholder',  f.browse_search_placeholder || '');
        _setVal('formBrowseSearchFields',       f.browse_search_fields      || '');
        _setChk('formBrowseSearchEnabled',      f.browse_search_enabled);

        const bdm = f.browse_display_mode || 'inline';
        const bdmRadio = document.querySelector('input[name="browseDisplayMode"][value="' + bdm + '"]');
        if (bdmRadio && window.nbFormBuilder.selectDisplayMode)
          window.nbFormBuilder.selectDisplayMode(bdm, bdmRadio.closest('.nb-display-mode-card'));

        // 8. Events / JS tab
        _setVal('formCustomJs',     f.form_custom_js      || '');
        _setVal('formJsBeforeSave', f.form_js_before_save || '');
        _setVal('formJsAfterSave',  f.form_js_after_save  || '');

        // 9. PHP / CSS tab
        _setVal('formCustomPhp', f.form_custom_php || '');
        _setVal('formCustomCss', f.form_custom_css || '');

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

    // ── _rebuildCanvas ─────────────────────────────────────────────────
    // Restores all canvas fields from saved layout JSON.
    // Uses window._nbSfData.write() (from nb-sf-data.js) to stamp
    // data-sf-* attributes, then clears _sfPanelUpgraded so
    // nb-subform-fk-builder re-upgrades the panel with correct data.
    // ─────────────────────────────────────────────────────────────────
    function _rebuildCanvas(layoutJson) {
      const canvas = document.getElementById('formCanvas');
      const empty  = document.getElementById('canvasEmpty');
      if (!canvas) return;

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

        const beforeCount = canvas.querySelectorAll('.nb-cfield').length;
        window.nbFormBuilder.addField(f.type || f.fieldtype || 'text', f);

        if ((f.type || f.fieldtype || '') === 'subform') {
          const allCards = canvas.querySelectorAll('.nb-cfield');
          const newCard  = allCards[beforeCount] || allCards[allCards.length - 1];
          if (newCard) {
            // Stamp full JSON blob as belt-and-braces fallback
            try { newCard.dataset.fieldJson = JSON.stringify(f); } catch (e) {}

            // Use shared utility to write data-sf-* attributes
            const sf = (f.subform && typeof f.subform === 'object') ? f.subform : {};
            if (window._nbSfData) {
              window._nbSfData.write(newCard, {
                form_code:       sf.form_code || sf.formcode || '',
                fk_field:        sf.fk_field  || sf.fkfield  || '',
                is_fk:           !!f.is_fk,
                hide_in_grid:    !!f.hide_in_grid,
                server_readonly: !!f.server_readonly
              });
            }

            // Clear upgrade lock so nb-subform-fk-builder re-runs with
            // correct data now that attributes are stamped.
            delete newCard._sfPanelUpgraded;
          }
        }
      });

      // Trigger re-upgrade of all subform panels after canvas is complete.
      setTimeout(function () {
        if (typeof window._nbSfUpgradeAll === 'function') window._nbSfUpgradeAll();
      }, 80);
    }

    console.log('[nb-form-edit] nbFormBuilder.edit() registered.');
  });

})();
