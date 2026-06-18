/**
 * nb-form-builder.js
 * Single consolidated file replacing:
 *   nb-sf-data.js + nb-subform-fk-builder.js +
 *   nb-form-builder-layout.js + nb-form-edit.js
 *
 * Defines window.nbFormBuilder (the core builder object) and
 * window.saveForm. Subform panel injection, FK builder, layout
 * row/span patches, canvas rebuild, and edit restore are all here.
 *
 * Load after nubuilder-next.js, before nusubform.js.
 *
 * Class-naming convention:
 *   nu-field-*  per-field config inputs  (label, name, required, …)
 *   nu-lookup-* lookup-specific inputs   (table, display, store, filter, extra)
 *   nb-*        builder canvas structure (rows, card shell, span bar, …)
 */
(function (window) {
  'use strict';

  /* ════════════════════════════════════════════════════════════════════
     SECTION 1 — _nbSfData  (was nb-sf-data.js)
     Centralised read/write of data-sf-* attributes on canvas cards.
     Tracks subform_view (grid|form) and help_text.
  ═══════════════════════════════════════════════════════════════════ */
  (function () {
    function _sfRead(card) {
      var fc = card.dataset.sfFormCode || '';
      if (fc) {
        return {
          form_code:       fc,
          fk_field:        card.dataset.sfFkField        || '',
          subform_view:    card.dataset.sfSubformView    || 'grid',
          help_text:       card.dataset.sfHelpText       || '',
          is_fk:           card.dataset.sfIsFk           === '1',
          hide_in_grid:    card.dataset.sfHideInGrid     === '1',
          server_readonly: card.dataset.sfServerReadonly === '1'
        };
      }
      var raw = card.dataset.fieldJson || card.dataset.fieldData || '';
      if (raw) {
        try {
          var obj = JSON.parse(raw);
          var sf  = (obj.subform && typeof obj.subform === 'object') ? obj.subform : {};
          var fc2 = sf.form_code || sf.formcode || '';
          if (fc2) {
            _sfWrite(card, {
              form_code:       fc2,
              fk_field:        sf.fk_field || sf.fkfield || '',
              subform_view:    obj.subform_view || sf.subform_view || 'grid',
              help_text:       obj.help_text || obj.field_help_text || '',
              is_fk:           !!obj.is_fk,
              hide_in_grid:    !!obj.hide_in_grid,
              server_readonly: !!obj.server_readonly
            });
            return _sfRead(card);
          }
        } catch (e) {}
      }
      return {
        form_code:       card.dataset.subformFormCode || card.dataset.formCode || '',
        fk_field:        card.dataset.subformFkField  || card.dataset.fkField  || '',
        subform_view:    'grid',
        help_text:       '',
        is_fk: false, hide_in_grid: false, server_readonly: false
      };
    }

    function _sfWrite(card, obj) {
      if (!obj) return;
      if (obj.form_code)    card.dataset.sfFormCode       = obj.form_code;
      if (obj.fk_field)     card.dataset.sfFkField        = obj.fk_field;
      if (obj.subform_view) card.dataset.sfSubformView    = obj.subform_view;
      if (obj.help_text !== undefined) card.dataset.sfHelpText = obj.help_text;
      card.dataset.sfIsFk           = obj.is_fk           ? '1' : '0';
      card.dataset.sfHideInGrid     = obj.hide_in_grid    ? '1' : '0';
      card.dataset.sfServerReadonly = obj.server_readonly ? '1' : '0';
    }

    function _sfClear(card) {
      ['sfFormCode','sfFkField','sfSubformView','sfHelpText',
       'sfIsFk','sfHideInGrid','sfServerReadonly',
       'fieldJson','fieldData'].forEach(function (k) { delete card.dataset[k]; });
    }

    window._nbSfData = { read: _sfRead, write: _sfWrite, clear: _sfClear };
  }());


  /* ════════════════════════════════════════════════════════════════════
     SECTION 2 — nbFormBuilder core object
  ═══════════════════════════════════════════════════════════════════ */
  var _fieldCounter = 0;

  function _esc(s) {
    return String(s || '')
      .replace(/&/g,'&amp;').replace(/</g,'&lt;')
      .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  window.nbFormBuilder = {

    open: function () {
      var card = document.getElementById('formBuilderCard');
      var list = document.getElementById('formsListSection');
      if (card) card.style.display = 'block';
      if (list) list.style.display = 'none';
      var editId = document.getElementById('editFormId');
      if (editId) editId.value = '';
      var title = document.getElementById('builderTitle');
      if (title) title.textContent = 'New Form';
      this._clearForm();
    },

    close: function () {
      var card = document.getElementById('formBuilderCard');
      var list = document.getElementById('formsListSection');
      if (card) card.style.display = 'none';
      if (list) list.style.display = '';
    },

    _clearForm: function () {
      ['builderFormName','builderFormCode','builderFormTable',
       'formBrowseSql','formBrowseColumns','formBrowseDefaultSort',
       'formBrowseSearchPlaceholder','formBrowseSearchFields',
       'formCustomJs','formJsBeforeSave','formJsAfterSave',
       'formCustomPhp','formCustomCss'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) el.value = '';
      });
      var ps = document.getElementById('formBrowsePageSize');
      if (ps) ps.value = '20';
      var srch = document.getElementById('formBrowseSearchEnabled');
      if (srch) srch.checked = false;
      var canvas = document.getElementById('formCanvas');
      if (canvas) canvas.querySelectorAll('.nb-row').forEach(function (r) { r.remove(); });
      this._updateEmptyState();
      this.selectFormType('main',  document.querySelector('input[name="formType"][value="main"]')   ? document.querySelector('input[name="formType"][value="main"]').closest('.nb-ftype-card')  : null);
      this.selectTableMode('new',  document.querySelector('input[name="formTableMode"][value="new"]')? document.querySelector('input[name="formTableMode"][value="new"]').closest('.nb-tmode-card') : null);
      this.selectPkType('autoincrement', document.querySelector('input[name="formPkType"][value="autoincrement"]') ? document.querySelector('input[name="formPkType"][value="autoincrement"]').closest('.nb-pk-card') : null);
      this.selectDisplayMode('inline');
    },

    switchTab: function (btn) {
      if (!btn) return;
      document.querySelectorAll('.nb-tab').forEach(function (t) { t.classList.remove('active'); });
      document.querySelectorAll('.nb-tab-panel').forEach(function (p) { p.classList.remove('active'); });
      btn.classList.add('active');
      var panel = document.getElementById(btn.dataset.panel);
      if (panel) panel.classList.add('active');
    },

    selectFormType: function (type, card) {
      document.querySelectorAll('.nb-ftype-card').forEach(function (c) { c.classList.remove('selected'); });
      if (card) card.classList.add('selected');
      var radio = document.querySelector('input[name="formType"][value="' + type + '"]');
      if (radio) radio.checked = true;
      var browseTabEl = document.getElementById('browseTab');
      var browseNotice = document.getElementById('browseNotApplicable');
      var isBrowseable = (type === 'main' || type === 'popup');
      if (browseTabEl)  browseTabEl.style.opacity  = isBrowseable ? '1' : '0.4';
      if (browseNotice) browseNotice.style.display  = isBrowseable ? 'none' : 'block';
    },

    selectTableMode: function (mode, card) {
      document.querySelectorAll('.nb-tmode-card').forEach(function (c) { c.classList.remove('selected'); });
      if (card) card.classList.add('selected');
      var radio = document.querySelector('input[name="formTableMode"][value="' + mode + '"]');
      if (radio) radio.checked = true;
      var nw = document.getElementById('newTableWrap');
      var ex = document.getElementById('existingTableWrap');
      if (nw) nw.style.display = (mode === 'new')      ? '' : 'none';
      if (ex) ex.style.display = (mode === 'existing') ? '' : 'none';
    },

    selectPkType: function (type, card) {
      document.querySelectorAll('.nb-pk-card').forEach(function (c) { c.classList.remove('selected'); });
      if (card) card.classList.add('selected');
      var radio = document.querySelector('input[name="formPkType"][value="' + type + '"]');
      if (radio) radio.checked = true;
    },

    selectDisplayMode: function (mode) {
      var sel = document.getElementById('browseDisplayMode');
      if (sel) sel.value = mode || 'inline';
    },

    _updateEmptyState: function () {
      var canvas = document.getElementById('formCanvas');
      var empty  = document.getElementById('canvasEmpty');
      if (!canvas || !empty) return;
      empty.style.display = canvas.querySelector('.nb-cfield') ? 'none' : 'block';
    },

    // ── addRow ────────────────────────────────────────────
    addRow: function () {
      var canvas = document.getElementById('formCanvas');
      if (!canvas) return null;
      var empty = document.getElementById('canvasEmpty');
      if (empty) empty.style.display = 'none';

      var row = document.createElement('div');
      row.className = 'nb-row';
      row.innerHTML =
        '<div class="nb-row-header">'
          + '<span class="nb-row-drag" title="Drag row">⠇</span>'
          + '<span class="nb-row-label">Row</span>'
          + '<span class="nb-row-actions">'
            + '<button class="nb-row-btn del" onclick="this.closest(\'.nb-row\').remove();window.nbFormBuilder._updateEmptyState();">✕</button>'
          + '</span>'
        + '</div>'
        + '<div class="nb-row-body">'
          + '<div class="nb-row-drop-hint">Drop fields here</div>'
        + '</div>';
      canvas.appendChild(row);

      var body = row.querySelector('.nb-row-body');
      if (body) _attachRowBodyDrop(body);
      return row;
    },

    // ── addField ──────────────────────────────────────────
    addField: function (type, extraData) {
      var canvas = document.getElementById('formCanvas');
      if (!canvas) return null;
      var extra = extraData || {};
      var label = extra.label || extra.fieldlabel || (type.charAt(0).toUpperCase() + type.slice(1) + ' Field');
      var name  = extra.name  || extra.fieldname  || (type + '_' + (++_fieldCounter));
      var col   = parseInt(extra.col || extra.colspan, 10) || 12;

      var card = this._makeFieldCard(type, label, name, !!extra.required, extra);
      if (!card) return null;

      var rows = canvas.querySelectorAll('.nb-row-body');
      var targetBody = rows.length ? rows[rows.length - 1] : null;
      if (!targetBody) {
        var newRow = this.addRow();
        targetBody = newRow ? newRow.querySelector('.nb-row-body') : null;
      }
      if (!targetBody) { canvas.appendChild(card); return card; }

      var hint = targetBody.querySelector('.nb-row-drop-hint');
      if (hint) hint.remove();

      card.id = card.id || ('nb-card-' + Date.now() + '-' + Math.random().toString(36).slice(2,5));
      card.setAttribute('draggable','true');
      card.addEventListener('dragstart', function (ev) {
        ev.dataTransfer.setData('text/nb-card-id', card.id);
        card.classList.add('drag-source');
      });
      card.addEventListener('dragend', function () { card.classList.remove('drag-source'); });
      targetBody.appendChild(card);
      this._applyColSpan(card, col);
      return card;
    },

    // ── _makeFieldCard ─────────────────────────────────────
    _makeFieldCard: function (type, label, name, required, extra) {
      extra = extra || {};
      var col = parseInt(extra.col || extra.colspan, 10) || 12;

      // ── Normalise incoming type for the builder canvas ──────────
      // The form layout stores the real runtime type (select2, multiselect).
      // The builder canvas always uses 'select' as the card type so the
      // options panel renders; the Select Type dropdown records the sub-type.
      var canvasType  = type;
      var selectType  = extra.select_type || '';
      if (type === 'select2') {
        canvasType = 'select';
        selectType = selectType || 'select2';
      } else if (type === 'multiselect') {
        canvasType = 'select';
        selectType = selectType || 'multiselect';
      }

      var spanBtns = [3,4,6,8,12].map(function (n) {
        return '<button type="button" class="nb-span-btn' + (n === col ? ' active' : '') + '" data-span="' + n + '">' + n + '</button>';
      }).join('');

      var extraBody = '';

      if (canvasType === 'select' || canvasType === 'radio' || canvasType === 'checkbox_group') {
        // ── Select-type sub-selector (only for 'select') ──────────────
        var selectTypeHtml = '';
        if (canvasType === 'select') {
          var resolvedSelType = selectType || extra.select_type || 'select';
          var isS2     = resolvedSelType === 'select2'     ? 'selected' : '';
          var isMulti  = resolvedSelType === 'multiselect' ? 'selected' : '';
          var isSingle = (!isS2 && !isMulti)               ? 'selected' : '';
          selectTypeHtml =
            '<div class="nb-fp">'
              + '<label style="font-size:11px;font-weight:600;">Select Type</label>'
              + '<select class="nu-input nu-field-select-type" style="font-size:12px;">'
                + '<option value="select" '      + isSingle + '>Standard Select</option>'
                + '<option value="select2" '     + isS2     + '>Select2 (searchable)</option>'
                + '<option value="multiselect" ' + isMulti  + '>Multi-Select</option>'
              + '</select>'
            + '</div>';
        }

        // ── Options source: manual | from table ─────────────────
        var optSource    = extra.options_source || 'manual';
        var fromTable    = extra.options_table  || '';
        var fromValCol   = extra.options_value_col || '';
        var fromLabelCol = extra.options_label_col || '';
        var fromFilter   = extra.options_filter || '';
        var isFromTable  = (optSource === 'table');

        var opts = (extra.options || []).map(function (o) {
          return typeof o === 'object' ? (o.value + '|' + o.label) : o;
        }).join('\n');

        var fromTablePanel =
          '<div class="nb-select-from-table" style="' + (isFromTable ? '' : 'display:none;') + 'grid-column:1/-1;">'
            + '<div style="background:var(--bg-offset,#f0f4ff);border:1.5px solid var(--color-primary,#4f6bed);border-radius:8px;padding:12px 14px;">'
              + '<div style="font-size:11px;font-weight:700;letter-spacing:.06em;color:var(--color-primary,#4f6bed);margin-bottom:10px;">📋 OPTIONS FROM TABLE</div>'
              + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">'
                + '<div style="grid-column:1/-1;"><label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;">Table Name</label>'
                  + '<input type="text" class="nu-input nu-field-opt-table" value="' + _esc(fromTable) + '" placeholder="e.g. categories" style="font-size:12px;width:100%;box-sizing:border-box;"></div>'
                + '<div><label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;">Value Column <span style="font-weight:400;">(stored)</span></label>'
                  + '<input type="text" class="nu-input nu-field-opt-val-col" value="' + _esc(fromValCol) + '" placeholder="e.g. id" style="font-size:12px;"></div>'
                + '<div><label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;">Label Column <span style="font-weight:400;">(shown)</span></label>'
                  + '<input type="text" class="nu-input nu-field-opt-label-col" value="' + _esc(fromLabelCol) + '" placeholder="e.g. name" style="font-size:12px;"></div>'
                + '<div style="grid-column:1/-1;"><label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;">Filter SQL <span style="font-weight:400;">(optional WHERE)</span></label>'
                  + '<input type="text" class="nu-input nu-field-opt-filter" value="' + _esc(fromFilter) + '" placeholder="e.g. active=1" style="font-size:12px;width:100%;box-sizing:border-box;"></div>'
              + '</div>'
            + '</div>'
          + '</div>';

        var manualPanel =
          '<div class="nb-select-manual" style="' + (isFromTable ? 'display:none;' : '') + 'grid-column:1/-1;">'
            + '<label style="font-size:11px;font-weight:600;">Options <span style="font-weight:400;color:var(--text-muted);">(value|label per line)</span></label>'
            + '<textarea class="nu-input nu-field-options" rows="3" style="width:100%;box-sizing:border-box;">' + _esc(opts) + '</textarea>'
          + '</div>';

        var sourceSwitcherHtml =
          '<div class="nb-fp nb-fp-full" style="grid-column:1/-1;">'
            + '<label style="font-size:11px;font-weight:600;">Options Source</label>'
            + '<div style="display:flex;gap:8px;margin-top:4px;">'
              + '<label style="display:flex;align-items:center;gap:4px;cursor:pointer;font-size:12px;">'
                + '<input type="radio" name="opt-src-' + _esc(name) + '" class="nu-field-opt-src" value="manual"' + (isFromTable ? '' : ' checked') + '>'
                + ' Manual list'
              + '</label>'
              + '<label style="display:flex;align-items:center;gap:4px;cursor:pointer;font-size:12px;">'
                + '<input type="radio" name="opt-src-' + _esc(name) + '" class="nu-field-opt-src" value="table"' + (isFromTable ? ' checked' : '') + '>'
                + ' From table'
              + '</label>'
            + '</div>'
          + '</div>';

        extraBody += selectTypeHtml + sourceSwitcherHtml + manualPanel + fromTablePanel;
      }

      if (canvasType === 'calculated') {
        extraBody += '<div class="nb-fp nb-fp-full"><label>Formula / Expression</label>'
          + '<textarea class="nu-input nu-field-formula" rows="2" placeholder="e.g. {qty} * {price}">'
          + _esc(extra.formula || extra.calc_formula || '') + '</textarea></div>';
      }

      if (canvasType === 'lookup') {
        var lk       = (extra.lookup && typeof extra.lookup === 'object') ? extra.lookup : {};
        var lkTable  = lk.table          || lk.form_code       || extra.lookup_form    || '';
        var lkDisp   = lk.display_column || lk.displaycolumn   || extra.lookup_display || '';
        var lkStore  = lk.id_column      || lk.idcolumn        || extra.lookup_store   || '';
        var lkFilter = lk.filter         || extra.lookup_filter || '';
        var lkExtra  = lk.extra          || extra.lookup_extra  || '';

        extraBody +=
          '<div style="background:var(--bg-offset,#f0f4ff);border:1.5px solid var(--color-primary,#4f6bed);border-radius:8px;padding:12px 14px;margin-top:6px;grid-column:1/-1;">'
            + '<div style="font-size:11px;font-weight:700;letter-spacing:.06em;color:var(--color-primary,#4f6bed);margin-bottom:10px;">🔗 LOOKUP CONFIG <span style="font-weight:400;color:var(--text-muted,#888);">— links this field to another table</span></div>'
            + '<div style="margin-bottom:8px;">'
              + '<label style="font-size:11px;font-weight:600;color:var(--text-secondary);display:block;margin-bottom:3px;">Lookup Table <span style="font-weight:400;">— the DB table to search</span></label>'
              + '<input type="text" class="nu-input nu-lookup-table" value="' + _esc(lkTable) + '" placeholder="e.g. customers" style="font-size:12px;width:100%;box-sizing:border-box;">'
            + '</div>'
            + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px;">'
              + '<div>'
                + '<label style="font-size:11px;font-weight:600;color:var(--text-secondary);display:block;margin-bottom:3px;">Display Column <span style="font-weight:400;">(shown to user)</span></label>'
                + '<input type="text" class="nu-input nu-lookup-display" value="' + _esc(lkDisp) + '" placeholder="e.g. full_name" style="font-size:12px;">'
              + '</div>'
              + '<div>'
                + '<label style="font-size:11px;font-weight:600;color:var(--text-secondary);display:block;margin-bottom:3px;">Store Column <span style="font-weight:400;">(saved to DB)</span></label>'
                + '<input type="text" class="nu-input nu-lookup-store" value="' + _esc(lkStore) + '" placeholder="e.g. id" style="font-size:12px;">'
              + '</div>'
            + '</div>'
            + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">'
              + '<div>'
                + '<label style="font-size:11px;font-weight:600;color:var(--text-secondary);display:block;margin-bottom:3px;">Filter SQL <span style="font-weight:400;">(optional WHERE clause)</span></label>'
                + '<input type="text" class="nu-input nu-lookup-filter" value="' + _esc(lkFilter) + '" placeholder="e.g. active=1" style="font-size:12px;">'
              + '</div>'
              + '<div>'
                + '<label style="font-size:11px;font-weight:600;color:var(--text-secondary);display:block;margin-bottom:3px;">Extra Mapping <span style="font-weight:400;">(src:field, comma-sep)</span></label>'
                + '<input type="text" class="nu-input nu-lookup-extra" value="' + _esc(lkExtra) + '" placeholder="e.g. code:dept_code" style="font-size:12px;">'
              + '</div>'
            + '</div>'
          + '</div>';
      }

      if (canvasType === 'subform') {
        var sf          = (extra.subform && typeof extra.subform === 'object') ? extra.subform : {};
        var sfData = {
          form_code:       sf.form_code    || sf.formcode  || extra.sf_form_code    || '',
          fk_field:        sf.fk_field     || sf.fkfield   || extra.sf_fk_field     || '',
          subform_view:    extra.subform_view                                        || 'grid',
          help_text:       extra.help_text  || extra.field_help_text                || '',
          is_fk:           !!extra.is_fk,
          hide_in_grid:    !!extra.hide_in_grid,
          server_readonly: !!extra.server_readonly
        };
        extraBody += _subformPanelHTML(sfData);
      }

      var card = document.createElement('div');
      card.className = 'nb-cfield';
      // Store the normalised canvas type (always 'select' for all select variants)
      card.dataset.type = canvasType;
      // Also store the actual runtime type so getLayout() can read it back
      card.dataset.runtimeType = type;
      card.style.gridColumn = 'span ' + col;
      card.dataset.col = String(col);
      card.innerHTML =
        '<div class="nb-cfield-header">'
          + '<span class="nb-cfield-drag">⠇</span>'
          + '<span class="nb-cfield-type-badge">' + _esc(type) + '</span>'
          + '<span class="nb-cfield-label">' + _esc(label) + '</span>'
          + '<span class="nb-cfield-span-badge">' + col + '/12</span>'
          + '<span class="nb-cfield-actions">'
            + '<button class="nb-cfield-btn del" type="button">✕</button>'
          + '</span>'
        + '</div>'
        + '<div class="nb-span-bar">'
          + '<span class="nb-span-bar-label">Width</span>'
          + spanBtns
          + '<span class="nb-span-preview">' + col + '/12 cols</span>'
        + '</div>'
        + '<div class="nb-cfield-body">'
          + '<div class="nb-fp-grid">'
            + '<div class="nb-fp"><label>Label</label><input type="text" class="nu-input nu-field-label" value="' + _esc(label) + '"></div>'
            + '<div class="nb-fp"><label>Field Name</label><input type="text" class="nu-input nu-field-name" value="' + _esc(name) + '"></div>'
            + '<div class="nb-fp"><label class="nb-fp-check"><input type="checkbox" class="nu-field-required"' + (required ? ' checked' : '') + '> Required</label></div>'
            + (canvasType !== 'subform' ? '<div class="nb-fp"><label>Placeholder</label><input type="text" class="nu-input nu-field-placeholder" value="' + _esc(extra.placeholder || '') + '"></div>' : '')
            + (canvasType !== 'subform' ? '<div class="nb-fp"><label>Default Value</label><input type="text" class="nu-input nu-field-default" value="' + _esc(extra.default_value || extra.defaultvalue || '') + '"></div>' : '')
            + '<div class="nb-fp nb-fp-full"><label>Help Text</label><input type="text" class="nu-input nu-field-help" value="' + _esc(extra.help_text || extra.field_help_text || '') + '"></div>'
            + extraBody
          + '</div>'
        + '</div>';

      var header = card.querySelector('.nb-cfield-header');
      var body   = card.querySelector('.nb-cfield-body');
      if (header && body) {
        header.addEventListener('click', function (e) {
          if (e.target.closest('.nb-cfield-actions')) return;
          body.classList.toggle('open');
        });
      }

      var delBtn = card.querySelector('.nb-cfield-btn.del');
      if (delBtn) {
        delBtn.addEventListener('click', function (e) {
          e.stopPropagation();
          card.remove();
          window.nbFormBuilder._updateEmptyState();
        });
      }

      var self = this;
      card.querySelectorAll('.nb-span-btn').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
          e.stopPropagation();
          self._applyColSpan(card, parseInt(btn.dataset.span, 10) || 12);
        });
      });

      // Wire options-source radio toggle for select / radio / checkbox_group
      if (canvasType === 'select' || canvasType === 'radio' || canvasType === 'checkbox_group') {
        _attachSelectOptionsToggle(card);
      }

      if (canvasType === 'subform') {
        _attachSubformPanelEvents(card, sfData);
      }

      return card;
    },

    // ── _applyColSpan ───────────────────────────────────────
    _applyColSpan: function (card, col) {
      var c = parseInt(col, 10) || 12;
      if (c < 1 || c > 12) c = 12;
      card.style.gridColumn = 'span ' + c;
      card.dataset.col = String(c);
      var badge = card.querySelector('.nb-cfield-span-badge');
      if (badge) badge.textContent = c + '/12';
      var preview = card.querySelector('.nb-span-preview');
      if (preview) preview.textContent = c + '/12 cols';
      card.querySelectorAll('.nb-span-btn').forEach(function (btn) {
        btn.classList.toggle('active', parseInt(btn.dataset.span, 10) === c);
      });
    },

    // ── getLayout ──────────────────────────────────────────
    getLayout: function () {
      var canvas = document.getElementById('formCanvas');
      if (!canvas) return [];
      var fields = [];
      var rowIndex = 0;

      canvas.querySelectorAll('.nb-row').forEach(function (row) {
        row.querySelectorAll('.nb-cfield').forEach(function (card) {
          var canvasType = card.dataset.type || 'text';
          var labelEl    = card.querySelector('.nu-field-label');
          var nameEl     = card.querySelector('.nu-field-name');
          var reqEl      = card.querySelector('.nu-field-required');
          var phEl       = card.querySelector('.nu-field-placeholder');
          var defEl      = card.querySelector('.nu-field-default');
          var optsEl     = card.querySelector('.nu-field-options');
          var helpEl     = card.querySelector('.nu-field-help');
          var formulaEl  = card.querySelector('.nu-field-formula');

          var lkTableEl  = card.querySelector('.nu-lookup-table');
          var lkDispEl   = card.querySelector('.nu-lookup-display');
          var lkStoreEl  = card.querySelector('.nu-lookup-store');
          var lkFilterEl = card.querySelector('.nu-lookup-filter');
          var lkExtraEl  = card.querySelector('.nu-lookup-extra');

          // ── Resolve the real serialised type ───────────────────────
          // canvasType is always 'select' for all select variants.
          // Read the Select Type dropdown to get the real runtime type:
          //   'select'      → type: 'select'       (plain browser select)
          //   'select2'     → type: 'select2'      (Select2 enhanced)
          //   'multiselect' → type: 'select', multiple: true
          var runtimeType = canvasType;
          if (canvasType === 'select') {
            var selTypeEl = card.querySelector('.nu-field-select-type');
            var selTypeVal = selTypeEl ? (selTypeEl.value || 'select') : 'select';
            if (selTypeVal === 'select2') {
              runtimeType = 'select2';
            } else if (selTypeVal === 'multiselect') {
              runtimeType = 'select'; // stays 'select' but gets multiple:true below
            } else {
              runtimeType = 'select';
            }
          }

          var field = {
            type:          runtimeType,
            label:         labelEl ? labelEl.value  : '',
            name:          nameEl  ? nameEl.value   : '',
            required:      reqEl   ? reqEl.checked  : false,
            placeholder:   phEl    ? phEl.value     : '',
            default_value: defEl   ? defEl.value    : '',
            help_text:     helpEl  ? helpEl.value   : '',
            col:           parseInt(card.dataset.col, 10) || 12,
            row_index:     rowIndex
          };

          // ── select / select2 / multiselect ────────────────────
          if (canvasType === 'select' || canvasType === 'radio' || canvasType === 'checkbox_group') {
            if (canvasType === 'select') {
              var selTypeEl2  = card.querySelector('.nu-field-select-type');
              var selTypeVal2 = selTypeEl2 ? (selTypeEl2.value || 'select') : 'select';
              field.select_type = selTypeVal2;
              // Flags consumed by api/form.php and FormRenderer.php
              field.select2  = selTypeVal2 === 'select2';
              field.multiple = selTypeVal2 === 'multiselect';
              if (field.multiple) {
                field.type = 'select'; // multiselect stays as 'select' with multiple flag
              }
              // allow_clear for select2 — default true
              if (selTypeVal2 === 'select2') {
                field.allow_clear = true;
              }
            }

            // options source: manual list or from table
            var optSrcEl  = card.querySelector('.nu-field-opt-src:checked');
            var optSource = optSrcEl ? optSrcEl.value : 'manual';
            field.options_source = optSource;

            if (optSource === 'table') {
              var otEl = card.querySelector('.nu-field-opt-table');
              var ovEl = card.querySelector('.nu-field-opt-val-col');
              var olEl = card.querySelector('.nu-field-opt-label-col');
              var ofEl = card.querySelector('.nu-field-opt-filter');
              field.options_table     = otEl ? otEl.value.trim() : '';
              field.options_value_col = ovEl ? ovEl.value.trim() : '';
              field.options_label_col = olEl ? olEl.value.trim() : '';
              field.options_filter    = ofEl ? ofEl.value.trim() : '';
              field.options = [];
            } else if (optsEl) {
              field.options = optsEl.value.split('\n').map(function (l) {
                l = l.trim(); if (!l) return null;
                var parts = l.split('|');
                return parts.length >= 2
                  ? { value: parts[0].trim(), label: parts[1].trim() }
                  : { value: l, label: l };
              }).filter(Boolean);
            }
          }

          if (formulaEl) field.formula = formulaEl.value;

          if (canvasType === 'lookup' && lkTableEl) {
            field.lookup = {
              table:          lkTableEl  ? lkTableEl.value.trim()  : '',
              display_column: lkDispEl   ? (lkDispEl.value.trim()  || 'name') : 'name',
              id_column:      lkStoreEl  ? (lkStoreEl.value.trim() || 'id')   : 'id',
              filter:         lkFilterEl ? lkFilterEl.value.trim() : '',
              extra:          lkExtraEl  ? lkExtraEl.value.trim()  : ''
            };
          }

          if (canvasType === 'subform') field.subform = {};
          fields.push(field);
        });
        rowIndex++;
      });

      if (typeof window._nbSfAugmentLayout === 'function') {
        fields = window._nbSfAugmentLayout(fields);
      }
      return fields;
    },

    // ── saveForm ──────────────────────────────────────────
    saveForm: async function () {
      var nameEl  = document.getElementById('builderFormName');
      var codeEl  = document.getElementById('builderFormCode');
      var tableEl = document.getElementById('builderFormTable');
      var editEl  = document.getElementById('editFormId');

      var name   = nameEl  ? nameEl.value.trim()  : '';
      var code   = codeEl  ? codeEl.value.trim()  : '';
      var table  = tableEl ? tableEl.value.trim()  : '';
      var editId = editEl  ? editEl.value.trim()   : '';

      if (!name) { NuApp.toast('Form name is required', 'error'); return; }

      var _r = function (n) { var e = document.querySelector('input[name="' + n + '"]:checked'); return e ? e.value : ''; };
      var _v = function (id) { var e = document.getElementById(id); return e ? e.value : ''; };
      var _c = function (id) { var e = document.getElementById(id); return !!(e && e.checked); };

      var layout = this.getLayout();

      var payload = {
        form_name:       name,
        form_code:       code,
        form_table:      table,
        form_type:       _r('formType')        || 'main',
        form_table_mode: _r('formTableMode')   || 'new',
        form_pk_type:    _r('formPkType')      || 'autoincrement',
        form_layout:     JSON.stringify(layout),
        browse_display_mode:       (function () { var e = document.getElementById('browseDisplayMode'); return e ? e.value : 'inline'; }()),
        browse_sql:                _v('formBrowseSql'),
        browse_columns:            _v('formBrowseColumns'),
        browse_page_size:          _v('formBrowsePageSize'),
        browse_default_sort:       _v('formBrowseDefaultSort'),
        browse_search_enabled:     _c('formBrowseSearchEnabled') ? 1 : 0,
        browse_search_placeholder: _v('formBrowseSearchPlaceholder'),
        browse_search_fields:      _v('formBrowseSearchFields'),
        form_custom_js:            _v('formCustomJs'),
        form_js_before_save:       _v('formJsBeforeSave'),
        form_js_after_save:        _v('formJsAfterSave'),
        form_custom_php:           _v('formCustomPhp'),
        form_custom_css:           _v('formCustomCss')
      };

      if (editId) payload.form_id = editId;

      try {
        var res = await NuApp.apiJson('api/forms.php?action=save', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload)
        });
        if (res && res.success) {
          NuApp.toast(editId ? 'Form updated!' : 'Form created!', 'success');
          this.close();
          NuApp.loadModule('forms');
        } else {
          NuApp.toast((res && res.error) || 'Save failed', 'error');
        }
      } catch (err) {
        NuApp.toast('Save error: ' + err.message, 'error');
      }
    },

    _initAfterLoad: function () {
      _attachAllRowDrops();
    }
  };

  window.saveForm = function () { return window.nbFormBuilder.saveForm(); };


  /* ════════════════════════════════════════════════════════════════════
     SECTION 3 — Row/span canvas patches
  ═══════════════════════════════════════════════════════════════════ */

  if (!window.nuToggleContainer) {
    window.nuToggleContainer = function (btn) {
      if (!btn) return;
      var body = document.getElementById(btn.getAttribute('data-target'));
      if (!body) return;
      var hidden = body.style.display === 'none' || body.style.display === '';
      body.style.display = hidden ? 'block' : 'none';
      btn.innerHTML = hidden ? '&#9660;' : '&#9654;';
    };
  }

  // ── _attachSelectOptionsToggle ─────────────────────────────
  function _attachSelectOptionsToggle(card) {
    var radios = card.querySelectorAll('.nu-field-opt-src');
    var manualPanel    = card.querySelector('.nb-select-manual');
    var fromTablePanel = card.querySelector('.nb-select-from-table');
    if (!radios.length || !manualPanel || !fromTablePanel) return;
    radios.forEach(function (r) {
      r.addEventListener('change', function () {
        var isTable = r.value === 'table' && r.checked;
        manualPanel.style.display    = isTable ? 'none' : '';
        fromTablePanel.style.display = isTable ? ''     : 'none';
      });
    });
    var checked = card.querySelector('.nu-field-opt-src:checked');
    if (checked) {
      var isTable = checked.value === 'table';
      manualPanel.style.display    = isTable ? 'none' : '';
      fromTablePanel.style.display = isTable ? ''     : 'none';
    }
  }

  function _attachRowBodyDrop(rowBody) {
    if (rowBody._nuDropPatched) return;
    rowBody._nuDropPatched = true;

    rowBody.addEventListener('dragover', function (e) {
      e.preventDefault(); e.stopPropagation();
      rowBody.classList.add('drag-col-over');
    });
    rowBody.addEventListener('dragleave', function (e) {
      if (!rowBody.contains(e.relatedTarget)) rowBody.classList.remove('drag-col-over');
    });
    rowBody.addEventListener('drop', function (e) {
      e.preventDefault(); e.stopPropagation();
      rowBody.classList.remove('drag-col-over');

      var cardId = e.dataTransfer.getData('text/nb-card-id');
      if (cardId) {
        var existing = document.getElementById(cardId);
        if (existing) {
          var oldRow = existing.closest('.nb-row');
          rowBody.appendChild(existing);
          window.nbFormBuilder._applyColSpan(existing, existing.dataset.col || 12);
          if (oldRow && !oldRow.querySelector('.nb-cfield')) oldRow.remove();
          window.nbFormBuilder._updateEmptyState();
          return;
        }
      }
      var dtype = e.dataTransfer.getData('text/nb-type') || e.dataTransfer.getData('text/plain');
      if (dtype) {
        var card = window.nbFormBuilder.addField(dtype, { col: 6 });
        if (card) {
          var hint = rowBody.querySelector('.nb-row-drop-hint');
          if (hint) hint.remove();
          rowBody.appendChild(card);
          window.nbFormBuilder._applyColSpan(card, 6);
        }
        window.nbFormBuilder._updateEmptyState();
      }
    });
  }

  function _attachAllRowDrops() {
    var canvas = document.getElementById('formCanvas');
    if (!canvas) return;
    canvas.querySelectorAll('.nb-row-body').forEach(_attachRowBodyDrop);
  }

  document.addEventListener('dragstart', function (e) {
    var tool = e.target.closest ? e.target.closest('.nb-tool[data-type]') : null;
    if (tool) e.dataTransfer.setData('text/nb-type', tool.dataset.type);
    var card = e.target.closest ? e.target.closest('.nb-cfield[id]') : null;
    if (card) {
      if (!card.id) card.id = 'nb-card-' + Date.now();
      e.dataTransfer.setData('text/nb-card-id', card.id);
    }
  }, true);


  /* ════════════════════════════════════════════════════════════════════
     SECTION 4 — Subform FK panel
  ═══════════════════════════════════════════════════════════════════ */

  function _toggleRow(cls, dataKey, checkedAttr, label, hint) {
    return '<label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:12px;">'
      + '<input type="checkbox" class="' + cls + '" data-fk-flag="' + dataKey + '" ' + checkedAttr + '>'
      + '<span><strong>' + label + '</strong>'
      + (hint ? ' <span style="color:var(--text-muted,#999);font-size:11px;">— ' + hint + '</span>' : '')
      + '</span></label>';
  }

  function _subformPanelHTML(d) {
    var isFk     = d.is_fk           ? 'checked' : '';
    var hideGrid = d.hide_in_grid    ? 'checked' : '';
    var srvRo    = d.server_readonly ? 'checked' : '';
    var viewGrid = (!d.subform_view || d.subform_view === 'grid') ? 'selected' : '';
    var viewForm = (d.subform_view === 'form') ? 'selected' : '';
    return [
      '<div class="nb-sf-fk-panel" style="display:flex;flex-direction:column;gap:8px;padding:10px 0;grid-column:1/-1;">',
        '<div><label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;">Child Form</label>',
        '<select class="nu-input nb-sf-form-code" style="width:100%;"><option value="">— select form —</option></select></div>',
        '<div><label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;">FK Field (links child → parent)</label>',
        '<div style="display:flex;gap:6px;">',
          '<select class="nu-input nb-sf-fk-field" style="flex:1;"><option value="">— select FK field —</option></select>',
          '<button type="button" class="nu-btn nu-btn-ghost nu-btn-sm nb-sf-create-fk">＋ Create FK Field</button>',
        '</div></div>',
        '<div><label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;">Display Mode</label>',
        '<select class="nu-input nb-sf-view" style="width:100%;">',
          '<option value="grid" ' + viewGrid + '>Grid (table)</option>',
          '<option value="form" ' + viewForm + '>Form (stacked)</option>',
        '</select></div>',
        '<div style="display:flex;flex-direction:column;gap:4px;padding:6px 8px;background:var(--bg-elevated,#f8f9fa);border-radius:6px;border:1px solid var(--border,#e0e0e0);">',
          '<label style="font-size:11px;font-weight:700;color:var(--text-muted,#888);text-transform:uppercase;letter-spacing:.5px;margin-bottom:2px;">FK Field Flags</label>',
          _toggleRow('nb-sf-is-fk',           'is_fk',           isFk,     'FK field',        'Force hidden; builder locks this field'),
          _toggleRow('nb-sf-hide-in-grid',    'hide_in_grid',    hideGrid, 'Hide in grid',    'Excludes column from subform table'),
          _toggleRow('nb-sf-server-readonly', 'server_readonly', srvRo,    'Server readonly', 'PHP ignores POST value; always writes parent ID'),
        '</div>',
      '</div>'
    ].join('');
  }

  function _attachSubformPanelEvents(card, initialData) {
    var panel = card.querySelector('.nb-sf-fk-panel');
    if (!panel) return;

    var d = initialData || {};

    _populateFormDropdown(panel, d.form_code || '', function () {
      if (d.form_code) _populateFkDropdown(panel, d.form_code, d.fk_field || '');
    });

    var viewSel = panel.querySelector('.nb-sf-view');
    if (viewSel && d.subform_view) viewSel.value = d.subform_view;

    var formSel = panel.querySelector('.nb-sf-form-code');
    if (formSel) {
      formSel.addEventListener('change', function () {
        _populateFkDropdown(panel, formSel.value, '');
      });
    }

    var createBtn = panel.querySelector('.nb-sf-create-fk');
    if (createBtn) {
      createBtn.addEventListener('click', function () { _createFkField(panel); });
    }
  }

  function _populateFormDropdown(panel, selectedCode, cb) {
    var sel = panel.querySelector('.nb-sf-form-code');
    if (!sel) { if (cb) cb(); return; }
    fetch('api/forms.php?action=list', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (json) {
        var forms = (json && json.success && json.forms) ? json.forms : [];
        while (sel.options.length > 1) sel.remove(1);
        forms.forEach(function (f) {
          var opt = document.createElement('option');
          opt.value = f.form_code || f.code || '';
          opt.textContent = (f.form_name || f.name || opt.value) + ' (' + opt.value + ')';
          sel.appendChild(opt);
        });
        if (selectedCode) {
          sel.value = selectedCode;
          if (sel.value !== selectedCode) {
            var m = document.createElement('option');
            m.value = selectedCode; m.textContent = selectedCode + ' (saved)';
            sel.insertBefore(m, sel.options[1] || null);
            sel.value = selectedCode;
          }
        }
        if (cb) cb();
      })
      .catch(function () { if (cb) cb(); });
  }

  function _populateFkDropdown(panel, formCode, selectedFk) {
    var sel = panel.querySelector('.nb-sf-fk-field');
    if (!sel || !formCode) return;
    fetch('api/forms.php?action=get_by_code&code=' + encodeURIComponent(formCode), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (json) {
        if (!json.success || !json.form) return;
        var layout = [];
        try { layout = JSON.parse(json.form.form_layout || '[]'); } catch (e) { layout = []; }
        var fields = Array.isArray(layout) ? layout : [];
        while (sel.options.length > 1) sel.remove(1);
        fields.forEach(function (f) {
          var fname = f.name || f.fieldname || '';
          var ftype = f.type || f.fieldtype || 'text';
          if (!fname || ['html','heading','divider','fieldset','subform','button'].indexOf(ftype) !== -1) return;
          var opt = document.createElement('option');
          opt.value = fname;
          opt.textContent = (f.label || f.fieldlabel || fname) + ' [' + fname + ']';
          sel.appendChild(opt);
        });
        if (selectedFk) {
          sel.value = selectedFk;
          if (sel.value !== selectedFk) {
            var m = document.createElement('option');
            m.value = selectedFk; m.textContent = selectedFk + ' (saved)';
            sel.insertBefore(m, sel.options[1] || null);
            sel.value = selectedFk;
          }
        }
      })
      .catch(function () {});
  }

  function _createFkField(panel) {
    var formCodeSel = panel.querySelector('.nb-sf-form-code');
    var fkFieldSel  = panel.querySelector('.nb-sf-fk-field');
    var formCode = formCodeSel ? formCodeSel.value : '';
    var fkName   = fkFieldSel  ? fkFieldSel.value  : '';
    if (!formCode) { NuApp.toast('Select a child form first', 'error'); return; }
    if (!fkName) {
      fkName = window.prompt('Enter FK field name (e.g. order_id):');
      if (!fkName || !fkName.trim()) return;
      fkName = fkName.trim().replace(/[^a-zA-Z0-9_]/g, '_');
    }
    fetch('api/forms.php?action=get_by_code&code=' + encodeURIComponent(formCode), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (json) {
        if (!json.success || !json.form) throw new Error(json.error || 'Child form not found');
        var form = json.form;
        var layout = [];
        try { layout = JSON.parse(form.form_layout || '[]'); } catch (e) { layout = []; }
        if (!Array.isArray(layout)) layout = [];
        if (layout.some(function (f) { return (f.name || f.fieldname || '') === fkName; })) {
          NuApp.toast('Field "' + fkName + '" already exists in ' + formCode, 'error');
          return null;
        }
        layout.push({ name: fkName, label: fkName, type: 'hidden', is_fk: true, hide_in_grid: true, server_readonly: true });
        return fetch(
          'api/forms.php?action=patch_layout&id=' + encodeURIComponent(form.form_id || form.id || ''),
          { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ form_layout: JSON.stringify(layout) }) }
        ).then(function (r) { return r.json(); });
      })
      .then(function (saveJson) {
        if (!saveJson) return;
        if (!saveJson.success) { NuApp.toast(saveJson.error || 'Save failed', 'error'); return; }
        NuApp.toast('FK field "' + fkName + '" created in ' + formCode);
        _populateFkDropdown(panel, formCode, fkName);
      })
      .catch(function (e) { NuApp.toast('Error: ' + e.message, 'error'); });
  }

  function _readCardConfig(card) {
    var formCode = '', fkField = '', subformView = 'grid', helpText = '';
    var isFk = false, hideGrid = false, srvRo = false;
    var panel = card.querySelector('.nb-sf-fk-panel');
    if (panel) {
      var fcSel   = panel.querySelector('.nb-sf-form-code');
      var fkSel   = panel.querySelector('.nb-sf-fk-field');
      var viewSel = panel.querySelector('.nb-sf-view');
      var isFkC   = panel.querySelector('.nb-sf-is-fk');
      var hideC   = panel.querySelector('.nb-sf-hide-in-grid');
      var srvRoC  = panel.querySelector('.nb-sf-server-readonly');
      if (fcSel)   formCode    = fcSel.value   || '';
      if (fkSel)   fkField     = fkSel.value   || '';
      if (viewSel) subformView = viewSel.value  || 'grid';
      if (isFkC)   isFk        = isFkC.checked;
      if (hideC)   hideGrid    = hideC.checked;
      if (srvRoC)  srvRo       = srvRoC.checked;
    }
    var helpEl = card.querySelector('.nu-field-help');
    if (helpEl) helpText = helpEl.value || '';
    return { form_code: formCode, fk_field: fkField, subform_view: subformView,
             help_text: helpText, is_fk: isFk, hide_in_grid: hideGrid, server_readonly: srvRo };
  }

  function _getSubformCards() {
    var canvas = document.getElementById('formCanvas');
    if (!canvas) return [];
    return Array.prototype.slice.call(canvas.querySelectorAll('.nb-cfield')).filter(function (c) {
      return (c.dataset.type || '') === 'subform';
    });
  }

  function _augmentLayout(layout) {
    if (!Array.isArray(layout)) return layout;
    var sfCards = _getSubformCards();
    var sfIndex = 0;
    layout.forEach(function (fieldObj) {
      if ((fieldObj.type || fieldObj.fieldtype || '') !== 'subform') return;
      var card = sfCards[sfIndex++] || null;
      if (!card) return;
      var cfg = _readCardConfig(card);
      if (!fieldObj.subform) fieldObj.subform = {};
      if (cfg.form_code)    fieldObj.subform.form_code = cfg.form_code;
      if (cfg.fk_field)     fieldObj.subform.fk_field  = cfg.fk_field;
      fieldObj.subform_view = cfg.subform_view || 'grid';
      if (cfg.help_text)    fieldObj.help_text = cfg.help_text;
      if (cfg.is_fk)           fieldObj.is_fk           = true; else delete fieldObj.is_fk;
      if (cfg.hide_in_grid)    fieldObj.hide_in_grid    = true; else delete fieldObj.hide_in_grid;
      if (cfg.server_readonly) fieldObj.server_readonly = true; else delete fieldObj.server_readonly;
    });
    return layout;
  }
  window._nbSfAugmentLayout = _augmentLayout;
  window.nbCreateFkField = _createFkField;


  /* ════════════════════════════════════════════════════════════════════
     SECTION 5 — Edit restore
  ═══════════════════════════════════════════════════════════════════ */

  window.nbFormBuilder.edit = async function (formId) {
    if (!formId) { NuApp.toast('No form ID', 'error'); return; }
    try {
      var res = await NuApp.apiJson(
        'api/forms.php?action=get&id=' + encodeURIComponent(formId),
        { credentials: 'same-origin' }
      );
      if (!res.success || !res.form) { NuApp.toast(res.error || 'Form not found', 'error'); return; }

      var f = res.form;
      window.nbFormBuilder.open();
      await new Promise(function (r) { setTimeout(r, 0); });

      var _sv = function (id, val) { var e = document.getElementById(id); if (e) e.value = val; };
      var _sc = function (id, val) { var e = document.getElementById(id); if (e) e.checked = !!(Number(val) || val === true); };

      var editIdEl = document.getElementById('editFormId');
      if (editIdEl) editIdEl.value = f.form_id || formId;
      var titleEl = document.getElementById('builderTitle');
      if (titleEl) titleEl.textContent = 'Edit Form';

      _sv('builderFormName', f.form_name || '');
      _sv('builderFormCode', f.form_code || '');

      var ftype = f.form_type || 'main';
      var ftypeRadio = document.querySelector('input[name="formType"][value="' + ftype + '"]');
      window.nbFormBuilder.selectFormType(ftype, ftypeRadio ? ftypeRadio.closest('.nb-ftype-card') : null);

      var tableMode = f.form_table_mode || 'new';
      var tModeRadio = document.querySelector('input[name="formTableMode"][value="' + tableMode + '"]');
      window.nbFormBuilder.selectTableMode(tableMode, tModeRadio ? tModeRadio.closest('.nb-tmode-card') : null);

      _sv('builderFormTable', f.form_table || '');
      if (tableMode === 'existing') {
        var exEl = document.getElementById('builderFormTableExisting');
        if (exEl) {
          exEl.value = f.form_table || '';
          if (exEl.value !== (f.form_table || '') && f.form_table) {
            var opt = document.createElement('option');
            opt.value = f.form_table; opt.textContent = f.form_table + ' (current)';
            exEl.prepend(opt); exEl.value = f.form_table;
          }
        }
      }

      var pkType = f.form_pk_type || 'autoincrement';
      var pkRadio = document.querySelector('input[name="formPkType"][value="' + pkType + '"]');
      window.nbFormBuilder.selectPkType(pkType, pkRadio ? pkRadio.closest('.nb-pk-card') : null);

      _sv('formBrowseSql',               f.browse_sql                || '');
      _sv('formBrowseColumns',            f.browse_columns            || '');
      _sv('formBrowsePageSize',           f.browse_page_size          || 20);
      _sv('formBrowseDefaultSort',        f.browse_default_sort       || '');
      _sv('formBrowseSearchPlaceholder',  f.browse_search_placeholder || '');
      _sv('formBrowseSearchFields',       f.browse_search_fields      || '');
      _sc('formBrowseSearchEnabled',      f.browse_search_enabled);

      window.nbFormBuilder.selectDisplayMode(f.browse_display_mode || 'inline');

      _sv('formCustomJs',     f.form_custom_js      || '');
      _sv('formJsBeforeSave', f.form_js_before_save || '');
      _sv('formJsAfterSave',  f.form_js_after_save  || '');
      _sv('formCustomPhp',    f.form_custom_php     || '');
      _sv('formCustomCss',    f.form_custom_css     || '');

      _rebuildCanvas(f.form_layout);

    } catch (err) {
      console.error('nbFormBuilder.edit error', err);
      NuApp.toast('Edit error: ' + err.message, 'error');
    }
  };

  // ── _rebuildCanvas ──────────────────────────────────────────
  function _rebuildCanvas(layoutJson) {
    var canvas = document.getElementById('formCanvas');
    var empty  = document.getElementById('canvasEmpty');
    if (!canvas) return;

    canvas.querySelectorAll('.nb-row').forEach(function (r) { r.remove(); });

    var fields = [];
    try {
      fields = typeof layoutJson === 'string'
        ? JSON.parse(layoutJson)
        : (Array.isArray(layoutJson) ? layoutJson : []);
    } catch (e) { fields = []; }

    if (!fields.length) { if (empty) empty.style.display = 'block'; return; }
    if (empty) empty.style.display = 'none';

    var groups = {};
    var groupOrder = [];
    fields.forEach(function (f) {
      var ri = (f.row_index !== undefined && f.row_index !== null) ? f.row_index : -1;
      if (!groups[ri]) { groups[ri] = []; groupOrder.push(ri); }
      groups[ri].push(f);
    });

    groupOrder.sort(function (a, b) {
      if (a === -1) return 1;
      if (b === -1) return -1;
      return a - b;
    });

    groupOrder.forEach(function (ri) {
      var row = window.nbFormBuilder.addRow();
      var rowBody = row ? row.querySelector('.nb-row-body') : null;

      groups[ri].forEach(function (f) {
        // Pass the real stored type (e.g. 'select2') into _makeFieldCard
        // so it can normalise to canvasType='select' and pre-select the
        // correct Select Type dropdown value.
        var type = f.type || f.fieldtype || 'text';
        var card = window.nbFormBuilder._makeFieldCard(
          type,
          f.label || f.fieldlabel || '',
          f.name  || f.fieldname  || '',
          !!f.required,
          f
        );
        if (!card) return;

        card.id = 'nb-card-' + Date.now() + '-' + Math.random().toString(36).slice(2,5);
        card.setAttribute('draggable', 'true');
        card.addEventListener('dragstart', function (ev) {
          ev.dataTransfer.setData('text/nb-card-id', card.id);
          card.classList.add('drag-source');
        });
        card.addEventListener('dragend', function () { card.classList.remove('drag-source'); });

        if (rowBody) {
          var hint = rowBody.querySelector('.nb-row-drop-hint');
          if (hint) hint.remove();
          rowBody.appendChild(card);
        } else {
          canvas.appendChild(card);
        }

        window.nbFormBuilder._applyColSpan(card, parseInt(f.col, 10) || 12);

        if (type === 'select' || type === 'select2' || type === 'multiselect' ||
            type === 'radio'  || type === 'checkbox_group') {
          _attachSelectOptionsToggle(card);
        }
        var body = card.querySelector('.nb-cfield-body');
        if (body) body.classList.add('open');
      });
    });

    _attachAllRowDrops();
  }


  /* ════════════════════════════════════════════════════════════════════
     SECTION 6 — Init
  ═══════════════════════════════════════════════════════════════════ */
  function _init() {
    _attachAllRowDrops();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', _init);
  } else {
    _init();
  }

  console.log('[nb-form-builder] ready.');

}(window));
