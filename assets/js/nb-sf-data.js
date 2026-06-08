/**
 * nb-sf-data.js
 * Shared utility: window._nbSfData
 *
 * Centralises all reads and writes of data-sf-* attributes on subform
 * field cards in the form builder canvas. Previously this logic was
 * duplicated across nb-form-edit.js and nb-subform-fk-builder.js,
 * causing subtle divergences that led to timing bugs.
 *
 * API:
 *   window._nbSfData.read(card)         -> { form_code, fk_field, is_fk,
 *                                            hide_in_grid, server_readonly }
 *   window._nbSfData.write(card, obj)   -> stamps data-sf-* from obj
 *   window._nbSfData.clear(card)        -> removes all data-sf-* attrs
 *                                          and resets _sfPanelUpgraded
 *
 * Load this file before nb-form-edit.js and nb-subform-fk-builder.js.
 */
(function (window) {
  'use strict';

  var EMPTY = {
    form_code: '', fk_field: '',
    is_fk: false, hide_in_grid: false, server_readonly: false
  };

  function read(card) {
    /* 1. Fast path: data-sf-* already stamped */
    var fc = card.dataset.sfFormCode || '';
    if (fc) {
      return {
        form_code:       fc,
        fk_field:        card.dataset.sfFkField        || '',
        is_fk:           card.dataset.sfIsFk           === '1',
        hide_in_grid:    card.dataset.sfHideInGrid     === '1',
        server_readonly: card.dataset.sfServerReadonly === '1'
      };
    }

    /* 2. Fallback: full field JSON blob stamped by _rebuildCanvas */
    var raw = card.dataset.fieldJson || card.dataset.fieldData || '';
    if (raw) {
      try {
        var obj = JSON.parse(raw);
        var sf  = (obj.subform && typeof obj.subform === 'object') ? obj.subform : {};
        var fc2 = sf.form_code || sf.formcode || '';
        var fk  = sf.fk_field  || sf.fkfield  || '';
        if (fc2) {
          /* Promote to fast path for future reads */
          write(card, {
            form_code:       fc2,
            fk_field:        fk,
            is_fk:           !!obj.is_fk,
            hide_in_grid:    !!obj.hide_in_grid,
            server_readonly: !!obj.server_readonly
          });
          return read(card);
        }
      } catch (e) {}
    }

    /* 3. Legacy attribute names */
    return {
      form_code:       card.dataset.subformFormCode || card.dataset.formCode || '',
      fk_field:        card.dataset.subformFkField  || card.dataset.fkField  || '',
      is_fk:           false,
      hide_in_grid:    false,
      server_readonly: false
    };
  }

  function write(card, obj) {
    if (!obj) return;
    if (obj.form_code)       card.dataset.sfFormCode       = obj.form_code;
    if (obj.fk_field)        card.dataset.sfFkField        = obj.fk_field;
    card.dataset.sfIsFk           = obj.is_fk           ? '1' : '0';
    card.dataset.sfHideInGrid     = obj.hide_in_grid    ? '1' : '0';
    card.dataset.sfServerReadonly = obj.server_readonly ? '1' : '0';
  }

  function clear(card) {
    delete card.dataset.sfFormCode;
    delete card.dataset.sfFkField;
    delete card.dataset.sfIsFk;
    delete card.dataset.sfHideInGrid;
    delete card.dataset.sfServerReadonly;
    delete card.dataset.fieldJson;
    delete card.dataset.fieldData;
    delete card._sfPanelUpgraded;
  }

  window._nbSfData = { read: read, write: write, clear: clear };

}(window));
