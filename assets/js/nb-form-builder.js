/**
 * nb-form-builder.js  — PATCHED v2
 *
 * Fixes applied:
 *   FIX-1  Inject CSS for .nb-container / group / tab (was missing → groups/tabs invisible)
 *   FIX-2  Edit mode: force .nb-cfield-body.open so label+name inputs are visible on restore
 *   FIX-3  row_index preservation — pass real ri to _collectRowFields so multi-row layouts survive save/reload
 *   FIX-4  Row drag-reorder — wire dragstart on rows + dragover/drop on canvas to reorder rows & containers
 */
(function (window) {
  'use strict';

  /* ══════════════════════════════════════════════════════════════════
     FIX-1 — Inject container CSS once (group, tab, inner-row styles)
  ══════════════════════════════════════════════════════════════════ */
  (function _injectContainerCSS() {
    if (document.getElementById('nb-container-css')) return;
    var s = document.createElement('style');
    s.id = 'nb-container-css';
    s.textContent = [
      /* ── Shared container wrapper ── */
      '.nb-container{border:2px solid var(--color-primary,#4f6bed);border-radius:10px;margin:8px 0;background:var(--bg-card,#fff);overflow:hidden;}',
      '.nb-container-header{display:flex;align-items:center;gap:8px;padding:7px 10px;background:var(--color-primary,#4f6bed);color:#fff;cursor:default;}',
      '.nb-container-header .nb-row-drag{font-size:16px;cursor:grab;opacity:.8;}',
      '.nb-container-header .nb-container-label-input{flex:1;background:rgba(255,255,255,.18);border:1px solid rgba(255,255,255,.4);border-radius:5px;color:#fff;font-size:12px;padding:2px 7px;}',
      '.nb-container-header .nb-container-label-input::placeholder{color:rgba(255,255,255,.7);}',
      '.nb-container-type-badge{font-size:10px;font-weight:700;letter-spacing:.06em;background:rgba(255,255,255,.22);padding:2px 7px;border-radius:4px;}',
      '.nb-container-type-badge-tab{background:rgba(255,200,0,.35);}',
      /* ── Group body ── */
      '.nb-container-group-body{padding:8px 10px;display:flex;flex-direction:column;gap:6px;min-height:48px;}',
      /* ── Tab nav ── */
      '.nb-cfield-tab-nav{display:flex;flex-wrap:wrap;align-items:center;gap:0;border-bottom:2px solid var(--color-primary,#4f6bed);padding:0 8px;}',
      '.nb-cfield-tab-nav-item{display:flex;align-items:center;gap:2px;padding:5px 12px;cursor:pointer;font-size:13px;border-radius:6px 6px 0 0;border:1px solid transparent;margin-bottom:-2px;color:var(--text-secondary,#555);}',
      '.nb-cfield-tab-nav-item.active{background:#fff;border-color:var(--color-primary,#4f6bed);border-bottom-color:#fff;font-weight:600;color:var(--color-primary,#4f6bed);}',
      '.nb-cfield-tab-add-btn{margin-left:4px;padding:3px 10px;font-size:11px;border:1px dashed var(--color-primary,#4f6bed);border-radius:5px;background:none;color:var(--color-primary,#4f6bed);cursor:pointer;}',
      /* ── Tab panels ── */
      '.nb-container-tab-panels{padding:0;}',
      '.nb-cfield-tab-panel{display:none;flex-direction:column;}',
      '.nb-cfield-tab-panel.active{display:flex;}',
      '.nb-tab-panel-rows{padding:8px 10px;display:flex;flex-direction:column;gap:6px;min-height:52px;}',
      /* ── Inner rows (inside group/tab) ── */
      '.nb-inner-row{border:1px solid var(--border,#e0e4ef);border-radius:7px;background:var(--bg-offset,#f8faff);margin:2px 0;}',
      '.nb-inner-row .nb-row-header{background:var(--bg-offset2,#edf0fc);border-radius:6px 6px 0 0;padding:4px 8px;}',
      /* ── Row drag-over highlight ── */
      '.nb-row.drag-row-over,.nb-container.drag-row-over{outline:2px dashed var(--color-primary,#4f6bed);outline-offset:2px;}',
      /* ── Row being dragged ── */
      '.nb-row.drag-row-source,.nb-container.drag-row-source{opacity:.45;}',
      /* ── Drop hint ── */
      '.nb-row-drop-hint{color:var(--text-muted,#aaa);font-size:12px;text-align:center;padding:10px 0;grid-column:1/-1;}'
    ].join('');
    (document.head || document.documentElement).appendChild(s);
  }());


  /* ════════════════════════════════════════════════════════════════════
     SECTION 1 — _nbSfData
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
              is_fk:           !!sf.is_fk,
              hide_in_grid:    !!sf.hide_in_grid,
              server_readonly: !!sf.server_readonly
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
     SECTION 2 — nbFormBuilder core
  ═══════════════════════════════════════════════════════════════════ */
  var _fieldCounter = 0;

  function _esc(s) {
    return String(s || '')
      .replace(/&/g,'&amp;').replace(/</g,'&lt;')
      .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  function _visibilityFlagsHTML(extra) {
    extra = extra || {};
    var isNoDup = extra.no_duplicate ? ' checked' : '';
    return '<div class="nb-fp nb-fp-full nb-vis-flags" style="grid-column:1/-1;display:flex;flex-wrap:wrap;gap:10px 18px;padding:8px 10px;background:var(--bg-offset,#f5f7ff);border:1px solid var(--border,#e0e4ef);border-radius:7px;margin-top:4px;">'
      + '<label style="font-size:11px;font-weight:700;color:var(--text-muted,#888);text-transform:uppercase;letter-spacing:.5px;flex-basis:100%;margin-bottom:2px;">Field Options</label>'
      + '<label class="nb-fp-check" style="font-size:12px;cursor:pointer;display:flex;align-items:center;gap:5px;">'
        + '<input type="checkbox" class="nu-field-required"' + (extra.required ? ' checked' : '') + '> Required'
      + '</label>'
      + '<label class="nb-fp-check" style="font-size:12px;cursor:pointer;display:flex;align-items:center;gap:5px;">'
        + '<input type="checkbox" class="nu-field-no-duplicate"' + isNoDup + '> No Duplicate'
      + '</label>'
      + '<label class="nb-fp-check" style="font-size:12px;cursor:pointer;display:flex;align-items:center;gap:5px;">'
        + '<input type="checkbox" class="nu-field-readonly"' + (extra.readonly ? ' checked' : '') + '> Readonly'
      + '</label>'
      + '<label class="nb-fp-check" style="font-size:12px;cursor:pointer;display:flex;align-items:center;gap:5px;">'
        + '<input type="checkbox" class="nu-field-hidden"' + (extra.hidden ? ' checked' : '') + '> Hidden'
      + '</label>'
      + '<label class="nb-fp-check" style="font-size:12px;cursor:pointer;display:flex;align-items:center;gap:5px;">'
        + '<input type="checkbox" class="nu-field-hidden-normal"' + (extra.hidden_for_normal_users ? ' checked' : '') + '> Hidden for normal users'
      + '</label>'
    + '</div>';
  }

  /* ══ FIX-4 helper: wire row-level drag on any .nb-row or .nb-container ══ */
  function _wireRowDrag(rowEl) {
    var handle = rowEl.querySelector(':scope > .nb-row-header > .nb-row-drag, :scope > .nb-container-header > .nb-row-drag');
    if (!handle || rowEl._nbRowDragWired) return;
    rowEl._nbRowDragWired = true;

    rowEl.setAttribute('draggable', 'true');

    rowEl.addEventListener('dragstart', function (e) {
      // Only start row-drag when handle is the origin
      if (!e.target.classList.contains('nb-row-drag')) return;
      e.stopPropagation();
      e.dataTransfer.setData('text/nb-row-id', rowEl.id || (rowEl.id = 'nb-row-' + Date.now()));
      e.dataTransfer.effectAllowed = 'move';
      rowEl.classList.add('drag-row-source');
    });

    rowEl.addEventListener('dragend', function () {
      rowEl.classList.remove('drag-row-source');
      // clear all over hints
      document.querySelectorAll('.drag-row-over').forEach(function (el) { el.classList.remove('drag-row-over'); });
    });
  }

  /* ══ FIX-4: canvas-level dragover/drop for row reordering ══ */
  function _attachCanvasRowDrop(canvas) {
    if (canvas._nbCanvasRowDropWired) return;
    canvas._nbCanvasRowDropWired = true;

    canvas.addEventListener('dragover', function (e) {
      var rowId = e.dataTransfer.types && (
        Array.prototype.indexOf.call(e.dataTransfer.types, 'text/nb-row-id') !== -1
      );
      if (!rowId) return;
      e.preventDefault();
      e.stopPropagation();

      // Find which top-level child we're hovering over
      var target = e.target;
      while (target && target.parentNode !== canvas) target = target.parentNode;
      if (!target || target === canvas) return;
      if (target.classList.contains('drag-row-source')) return;

      document.querySelectorAll('.drag-row-over').forEach(function (el) { el.classList.remove('drag-row-over'); });
      target.classList.add('drag-row-over');
    });

    canvas.addEventListener('dragleave', function (e) {
      if (!canvas.contains(e.relatedTarget)) {
        document.querySelectorAll('.drag-row-over').forEach(function (el) { el.classList.remove('drag-row-over'); });
      }
    });

    canvas.addEventListener('drop', function (e) {
      var rowId = e.dataTransfer.getData('text/nb-row-id');
      if (!rowId) return;
      e.preventDefault();
      e.stopPropagation();

      document.querySelectorAll('.drag-row-over').forEach(function (el) { el.classList.remove('drag-row-over'); });

      var draggedRow = document.getElementById(rowId);
      if (!draggedRow || draggedRow.parentNode !== canvas) return;

      // Find the drop target child
      var target = e.target;
      while (target && target.parentNode !== canvas) target = target.parentNode;
      if (!target || target === draggedRow) return;

      // Insert before or after depending on mouse position
      var rect   = target.getBoundingClientRect();
      var middle = rect.top + rect.height / 2;
      if (e.clientY < middle) {
        canvas.insertBefore(draggedRow, target);
      } else {
        canvas.insertBefore(draggedRow, target.nextSibling);
      }
    });
  }

  /* ════════════════════════════════════════════════════════════════════
     GROUP container
  ═══════════════════════════════════════════════════════════════════ */
  function _makeGroupContainer(extra) {
    extra = extra || {};
    var label = extra.label || 'Group';
    var id    = 'nb-group-' + Date.now() + '-' + Math.random().toString(36).slice(2,5);

    var wrap = document.createElement('div');
    wrap.className   = 'nb-container nb-container-group';
    wrap.id          = id;
    wrap.dataset.containerType = 'group';
    wrap.innerHTML =
      '<div class="nb-container-header">'
        + '<span class="nb-row-drag" title="Drag group">⠇</span>'
        + '<span class="nb-container-type-badge">GROUP</span>'
        + '<input type="text" class="nb-container-label-input nu-input" value="' + _esc(label) + '" placeholder="Group label">'
        + '<button type="button" class="nb-row-btn" onclick="window.nbFormBuilder._addRowToContainer(this.closest(\'.nb-container\'))" title="Add row inside group">+ Row</button>'
        + '<button type="button" class="nb-row-btn del" onclick="this.closest(\'.nb-container\').remove();window.nbFormBuilder._updateEmptyState();">✕</button>'
      + '</div>'
      + '<div class="nb-container-body nb-container-group-body">'
        + '<div class="nb-row-drop-hint">Add a row, then drop fields in</div>'
      + '</div>';

    if (extra.rows && extra.rows.length) {
      var body = wrap.querySelector('.nb-container-group-body');
      if (body) {
        var hint = body.querySelector('.nb-row-drop-hint');
        if (hint) hint.remove();
        extra.rows.forEach(function (rowDef) {
          _addRowToContainer(body, rowDef.fields || [], true);
        });
      }
    }

    _wireRowDrag(wrap);
    return wrap;
  }

  /* ════════════════════════════════════════════════════════════════════
     TAB container
  ═══════════════════════════════════════════════════════════════════ */
  function _makeTabContainer(extra) {
    extra = extra || {};
    var tabs = (extra.tabs && extra.tabs.length) ? extra.tabs : [{ name: 'Tab 1' }];
    var id   = 'nb-tab-' + Date.now() + '-' + Math.random().toString(36).slice(2,5);

    var wrap = document.createElement('div');
    wrap.className   = 'nb-container nb-container-tab';
    wrap.id          = id;
    wrap.dataset.containerType = 'tab';
    wrap.innerHTML =
      '<div class="nb-container-header">'
        + '<span class="nb-row-drag" title="Drag tab container">⠇</span>'
        + '<span class="nb-container-type-badge nb-container-type-badge-tab">TAB</span>'
        + '<span style="font-size:11px;color:rgba(255,255,255,.8);flex:1;">Tab Container</span>'
        + '<button type="button" class="nb-row-btn del" onclick="this.closest(\'.nb-container\').remove();window.nbFormBuilder._updateEmptyState();">✕</button>'
      + '</div>'
      + '<div class="nb-cfield-tab-nav" id="' + id + '-nav"></div>'
      + '<div class="nb-container-tab-panels" id="' + id + '-panels"></div>';

    document.body.appendChild(wrap);
    var nav    = wrap.querySelector('#' + id + '-nav');
    var panels = wrap.querySelector('#' + id + '-panels');

    tabs.forEach(function (tab, i) {
      _addTabPanel(wrap, nav, panels, tab.name || ('Tab ' + (i+1)), i === 0, tab.rows || []);
    });

    var addBtn = document.createElement('button');
    addBtn.type = 'button';
    addBtn.className = 'nb-cfield-tab-add-btn';
    addBtn.textContent = '+ Tab';
    addBtn.addEventListener('click', function () {
      var idx = nav.querySelectorAll('.nb-cfield-tab-nav-item').length;
      _addTabPanel(wrap, nav, panels, 'Tab ' + (idx+1), false, []);
    });
    nav.appendChild(addBtn);

    document.body.removeChild(wrap);
    _wireRowDrag(wrap);
    return wrap;
  }

  function _addTabPanel(container, nav, panels, tabName, isActive, rows) {
    var panelId = 'nb-tabpanel-' + Date.now() + '-' + Math.random().toString(36).slice(2,5);

    var navItem = document.createElement('div');
    navItem.className = 'nb-cfield-tab-nav-item' + (isActive ? ' active' : '');
    navItem.dataset.panelTarget = panelId;
    navItem.innerHTML =
      '<input type="text" class="nb-tab-name-input" value="' + _esc(tabName) + '" style="background:none;border:none;outline:none;font:inherit;cursor:pointer;width:' + Math.max(50, tabName.length * 8) + 'px;min-width:40px;" onclick="event.stopPropagation()">'
      + ' <span class="nb-tab-nav-del" style="font-size:10px;cursor:pointer;color:rgba(255,255,255,.7);margin-left:2px;" title="Remove tab">×</span>';

    navItem.addEventListener('click', function (e) {
      if (e.target.classList.contains('nb-tab-nav-del')) {
        var panel = document.getElementById(panelId);
        if (panel) panel.remove();
        navItem.remove();
        var firstNav = nav.querySelector('.nb-cfield-tab-nav-item');
        if (firstNav) {
          firstNav.classList.add('active');
          var fp = document.getElementById(firstNav.dataset.panelTarget);
          if (fp) fp.classList.add('active');
        }
        return;
      }
      nav.querySelectorAll('.nb-cfield-tab-nav-item').forEach(function (n) { n.classList.remove('active'); });
      panels.querySelectorAll('.nb-cfield-tab-panel').forEach(function (p) { p.classList.remove('active'); });
      navItem.classList.add('active');
      var tp = document.getElementById(panelId);
      if (tp) tp.classList.add('active');
    });

    var addBtn = nav.querySelector('.nb-cfield-tab-add-btn');
    if (addBtn) nav.insertBefore(navItem, addBtn);
    else nav.appendChild(navItem);

    var panel = document.createElement('div');
    panel.className = 'nb-cfield-tab-panel' + (isActive ? ' active' : '');
    panel.id = panelId;
    panel.innerHTML =
      '<div style="display:flex;align-items:center;justify-content:flex-end;padding:4px 8px 2px;border-bottom:1px solid var(--border-color);">'
        + '<button type="button" class="nb-row-btn" onclick="window.nbFormBuilder._addRowToContainer(this.closest(\'.nb-cfield-tab-panel\'))">+ Row</button>'
      + '</div>'
      + '<div class="nb-tab-panel-rows"></div>';
    panels.appendChild(panel);

    var rowsBody = panel.querySelector('.nb-tab-panel-rows');
    if (rows && rows.length) {
      rows.forEach(function (rowDef) {
        _addRowToContainer(rowsBody, rowDef.fields || [], true);
      });
    } else {
      rowsBody.innerHTML = '<div class="nb-row-drop-hint">Add a row, then drop fields in</div>';
    }

    return panel;
  }

  /* ── _addRowToContainer ──────────────────────────────────────────
     isRestore=true → open body panels so label/name are visible (FIX-2)
  ─────────────────────────────────────────────────────────────────── */
  function _addRowToContainer(target, fields, isRestore) {
    var rowsWrap = target;
    if (target && target.classList.contains('nb-cfield-tab-panel')) {
      rowsWrap = target.querySelector('.nb-tab-panel-rows');
    }
    if (target && target.classList.contains('nb-container')) {
      rowsWrap = target.querySelector('.nb-container-group-body');
    }
    if (!rowsWrap) return null;

    var hint = rowsWrap.querySelector(':scope > .nb-row-drop-hint');
    if (hint) hint.remove();

    var rowId = 'nb-row-' + Date.now() + '-' + Math.random().toString(36).slice(2,5);
    var row = document.createElement('div');
    row.className = 'nb-row nb-inner-row';
    row.id = rowId;
    row.innerHTML =
      '<div class="nb-row-header">'
        + '<span class="nb-row-drag" title="Drag row">⠇</span>'
        + '<span class="nb-row-label">Row</span>'
        + '<span class="nb-row-actions">'
          + '<button class="nb-row-btn del" onclick="var r=this.closest(\'.nb-row\');var p=r.parentNode;r.remove();if(!p.querySelector(\'.nb-row\')){p.innerHTML=\'<div class=\\\"nb-row-drop-hint\\\">Add a row, then drop fields in</div>\'}; window.nbFormBuilder._updateEmptyState();">✕</button>'
        + '</span>'
      + '</div>'
      + '<div class="nb-row-body">'
        + '<div class="nb-row-drop-hint">Drop fields here</div>'
      + '</div>';
    rowsWrap.appendChild(row);

    /* FIX-4: wire inner-row drag */
    _wireRowDrag(row);

    var body = row.querySelector('.nb-row-body');
    if (body) _attachRowBodyDrop(body);

    if (fields && fields.length) {
      fields.forEach(function (f) {
        var type = f.type || 'text';
        var card = window.nbFormBuilder._makeFieldCard(type, f.label || '', f.name || '', !!f.required, f);
        if (card) {
          var dropHint = body.querySelector('.nb-row-drop-hint');
          if (dropHint) dropHint.remove();
          card.id = 'nb-card-' + Date.now() + '-' + Math.random().toString(36).slice(2,5);
          card.setAttribute('draggable','true');
          card.addEventListener('dragstart', function (ev) { ev.dataTransfer.setData('text/nb-card-id', card.id); card.classList.add('drag-source'); });
          card.addEventListener('dragend',   function ()   { card.classList.remove('drag-source'); });
          body.appendChild(card);
          window.nbFormBuilder._applyColSpan(card, parseInt(f.col, 10) || 12);
          _restoreFieldState(card, f);
          /* FIX-2: keep body open when restoring so label/name are visible */
          if (isRestore) {
            var cb = card.querySelector('.nb-cfield-body');
            if (cb) cb.classList.add('open');
          }
        }
      });
    }

    return row;
  }

  function _restoreFieldState(card, f) {
    if (!card || !f) return;
    var type = card.dataset.type || '';
    if (type === 'select' || type === 'select2' || type === 'multiselect') {
      var selModeEl = card.querySelector('.nu-field-select-mode');
      if (selModeEl) {
        var isMulti = f.multiple === true || f.multiple === 'true' || f.multiple === 1
                   || f.select_type === 'multiselect';
        selModeEl.value = isMulti ? 'multi' : 'single';
      }
    }
    var reqEl   = card.querySelector('.nu-field-required');
    var noDupEl = card.querySelector('.nu-field-no-duplicate');
    var rdEl    = card.querySelector('.nu-field-readonly');
    var hidEl   = card.querySelector('.nu-field-hidden');
    var hidNEl  = card.querySelector('.nu-field-hidden-normal');
    if (reqEl)   reqEl.checked   = !!f.required;
    if (noDupEl) noDupEl.checked = !!f.no_duplicate;
    if (rdEl)    rdEl.checked    = !!f.readonly;
    if (hidEl)   hidEl.checked   = !!f.hidden;
    if (hidNEl)  hidNEl.checked  = !!f.hidden_for_normal_users;
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
      if (canvas) canvas.querySelectorAll('.nb-row,.nb-container').forEach(function (r) { r.remove(); });
      this._updateEmptyState();
      this.selectFormType('main', document.querySelector('input[name="formType"][value="main"]') ? document.querySelector('input[name="formType"][value="main"]').closest('.nb-ftype-card') : null);
      this.selectTableMode('new', document.querySelector('input[name="formTableMode"][value="new"]') ? document.querySelector('input[name="formTableMode"][value="new"]').closest('.nb-tmode-card') : null);
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
      var browseTabEl  = document.getElementById('browseTab');
      var browseNotice = document.getElementById('browseNotApplicable');
      var isBrowseable = (type === 'main' || type === 'popup');
      if (browseTabEl)  browseTabEl.style.opacity = isBrowseable ? '1' : '0.4';
      if (browseNotice) browseNotice.style.display = isBrowseable ? 'none' : 'block';
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
      var hasContent = canvas.querySelector('.nb-cfield') || canvas.querySelector('.nb-container');
      empty.style.display = hasContent ? 'none' : 'block';
    },

    /* ── addRow (top-level canvas row) ─────────────────────── */
    addRow: function () {
      var canvas = document.getElementById('formCanvas');
      if (!canvas) return null;
      var empty = document.getElementById('canvasEmpty');
      if (empty) empty.style.display = 'none';

      /* FIX-4: wire canvas drop once */
      _attachCanvasRowDrop(canvas);

      var rowId = 'nb-row-' + Date.now() + '-' + Math.random().toString(36).slice(2,5);
      var row = document.createElement('div');
      row.className = 'nb-row';
      row.id = rowId;
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

      /* FIX-4 */
      _wireRowDrag(row);

      var body = row.querySelector('.nb-row-body');
      if (body) _attachRowBodyDrop(body);
      return row;
    },

    _addRowToContainer: function (target) {
      return _addRowToContainer(target, [], false);
    },

    /* ── addField ────────────────────────────────────────────── */
    addField: function (type, extraData) {
      var canvas = document.getElementById('formCanvas');
      if (!canvas) return null;
      var extra = extraData || {};

      /* FIX-4: wire canvas drop once */
      _attachCanvasRowDrop(canvas);

      if (type === 'group') {
        var grp = _makeGroupContainer(extra);
        canvas.appendChild(grp);
        var emptyG = document.getElementById('canvasEmpty');
        if (emptyG) emptyG.style.display = 'none';
        return grp;
      }
      if (type === 'tab') {
        var tab = _makeTabContainer(extra);
        canvas.appendChild(tab);
        var emptyT = document.getElementById('canvasEmpty');
        if (emptyT) emptyT.style.display = 'none';
        return tab;
      }

      var label = extra.label || extra.fieldlabel || (type.charAt(0).toUpperCase() + type.slice(1) + ' Field');
      var name  = extra.name  || extra.fieldname  || (type + '_' + (++_fieldCounter));
      var col   = parseInt(extra.col || extra.colspan, 10) || 12;

      var card = this._makeFieldCard(type, label, name, !!extra.required, extra);
      if (!card) return null;

      var rows = canvas.querySelectorAll(':scope > .nb-row > .nb-row-body');
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

    /* ── _makeFieldCard ──────────────────────────────────────── */
    _makeFieldCard: function (type, label, name, required, extra) {
      extra = extra || {};
      extra = Object.assign({}, extra, { required: required || !!extra.required });
      var col = parseInt(extra.col || extra.colspan, 10) || 12;

      var canvasType = type;
      if (type === 'multiselect') {
        canvasType = 'select2';
        if (!extra.multiple) extra = Object.assign({}, extra, { multiple: true });
      }
      if (type === 'select' && (extra.select2 === true || extra.select2 === 'true' || extra.select2 === 1)) {
        canvasType = 'select2';
      }
      if (type === 'group' || type === 'tab') return null;

      var spanBtns = [3,4,6,8,12].map(function (n) {
        return '<button type="button" class="nb-span-btn' + (n === col ? ' active' : '') + '" data-span="' + n + '">' + n + '</button>';
      }).join('');

      var extraBody = '';

      /* ── SELECT ── */
      if (canvasType === 'select') {
        var selIsMulti   = extra.multiple === true || extra.multiple === 'true' || extra.multiple === 1 || extra.select_type === 'multiselect';
        var optSource    = extra.options_source || 'manual';
        var fromTable    = extra.options_table  || '';
        var fromValCol   = extra.options_value_col || '';
        var fromLabelCol = extra.options_label_col || '';
        var fromFilter   = extra.options_filter || '';
        var isFromTable  = (optSource === 'table');
        var opts = (extra.options || []).map(function (o) { return typeof o === 'object' ? (o.value + '|' + o.label) : o; }).join('\n');

        extraBody +=
          '<div class="nb-fp"><label style="font-size:11px;font-weight:600;">Select Mode</label>'
            + '<select class="nu-input nu-field-select-mode" style="font-size:12px;">'
              + '<option value="single"' + (!selIsMulti ? ' selected' : '') + '>Single</option>'
              + '<option value="multi"'  + ( selIsMulti ? ' selected' : '') + '>Multi-Select</option>'
            + '</select></div>'
          + '<div class="nb-fp nb-fp-full" style="grid-column:1/-1;">'
            + '<label style="font-size:11px;font-weight:600;">Options Source</label>'
            + '<div style="display:flex;gap:8px;margin-top:4px;">'
              + '<label style="display:flex;align-items:center;gap:4px;cursor:pointer;font-size:12px;"><input type="radio" name="opt-src-' + _esc(name) + '" class="nu-field-opt-src" value="manual"' + (isFromTable ? '' : ' checked') + '> Manual list</label>'
              + '<label style="display:flex;align-items:center;gap:4px;cursor:pointer;font-size:12px;"><input type="radio" name="opt-src-' + _esc(name) + '" class="nu-field-opt-src" value="table"' + (isFromTable ? ' checked' : '') + '> From table</label>'
            + '</div></div>'
          + '<div class="nb-select-manual" style="' + (isFromTable ? 'display:none;' : '') + 'grid-column:1/-1;">'
            + '<label style="font-size:11px;font-weight:600;">Options</label>'
            + '<textarea class="nu-input nu-field-options" rows="3" style="width:100%;box-sizing:border-box;">' + _esc(opts) + '</textarea>'
          + '</div>'
          + '<div class="nb-select-from-table" style="' + (isFromTable ? '' : 'display:none;') + 'grid-column:1/-1;">'
            + '<div style="background:var(--bg-offset,#f0f4ff);border:1.5px solid var(--color-primary,#4f6bed);border-radius:8px;padding:12px 14px;">'
              + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">'
                + '<div style="grid-column:1/-1;"><label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;">Table Name</label><input type="text" class="nu-input nu-field-opt-table" value="' + _esc(fromTable) + '" placeholder="e.g. categories" style="font-size:12px;width:100%;box-sizing:border-box;"></div>'
                + '<div><label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;">Value Col</label><input type="text" class="nu-input nu-field-opt-val-col" value="' + _esc(fromValCol) + '" placeholder="e.g. id" style="font-size:12px;"></div>'
                + '<div><label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;">Label Col</label><input type="text" class="nu-input nu-field-opt-label-col" value="' + _esc(fromLabelCol) + '" placeholder="e.g. name" style="font-size:12px;"></div>'
                + '<div style="grid-column:1/-1;"><label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;">Filter SQL</label><input type="text" class="nu-input nu-field-opt-filter" value="' + _esc(fromFilter) + '" placeholder="e.g. active=1" style="font-size:12px;width:100%;box-sizing:border-box;"></div>'
              + '</div>'
            + '</div>'
          + '</div>';
      }

      /* ── SELECT2 ── */
      if (canvasType === 'select2') {
        var s2IsMulti      = extra.multiple === true || extra.multiple === 'true' || extra.multiple === 1 || extra.select_type === 'multiselect';
        var allowClearChk  = (extra.allow_clear === false || extra.allow_clear === 'false') ? '' : 'checked';
        var s2OptSource    = extra.options_source || 'manual';
        var s2FromTable    = extra.options_table  || '';
        var s2FromValCol   = extra.options_value_col || '';
        var s2FromLabelCol = extra.options_label_col || '';
        var s2FromFilter   = extra.options_filter || '';
        var s2IsFromTable  = (s2OptSource === 'table');
        var s2Opts = (extra.options || []).map(function (o) { return typeof o === 'object' ? (o.value + '|' + o.label) : o; }).join('\n');

        extraBody +=
          '<div style="background:var(--bg-offset,#eef2ff);border:1.5px solid var(--color-primary,#4f6bed);border-radius:8px;padding:12px 14px;grid-column:1/-1;margin-bottom:4px;">'
            + '<div style="font-size:11px;font-weight:700;letter-spacing:.06em;color:var(--color-primary,#4f6bed);margin-bottom:10px;">🔍 SELECT2 CONFIG</div>'
            + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">'
              + '<div><label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;">Mode</label><select class="nu-input nu-field-select-mode" style="font-size:12px;"><option value="single"' + (!s2IsMulti ? ' selected' : '') + '>Single</option><option value="multi"' + (s2IsMulti ? ' selected' : '') + '>Multi-Select</option></select></div>'
              + '<div style="display:flex;align-items:flex-end;padding-bottom:4px;"><label class="nb-fp-check" style="font-size:11px;"><input type="checkbox" class="nu-field-allow-clear"' + (allowClearChk ? ' checked' : '') + '> Allow Clear</label></div>'
            + '</div>'
          + '</div>'
          + '<div class="nb-fp nb-fp-full" style="grid-column:1/-1;"><label style="font-size:11px;font-weight:600;">Options Source</label>'
            + '<div style="display:flex;gap:8px;margin-top:4px;">'
              + '<label style="display:flex;align-items:center;gap:4px;cursor:pointer;font-size:12px;"><input type="radio" name="opt-src-' + _esc(name) + '" class="nu-field-opt-src" value="manual"' + (s2IsFromTable ? '' : ' checked') + '> Manual</label>'
              + '<label style="display:flex;align-items:center;gap:4px;cursor:pointer;font-size:12px;"><input type="radio" name="opt-src-' + _esc(name) + '" class="nu-field-opt-src" value="table"' + (s2IsFromTable ? ' checked' : '') + '> From table</label>'
            + '</div></div>'
          + '<div class="nb-select-manual" style="' + (s2IsFromTable ? 'display:none;' : '') + 'grid-column:1/-1;">'
            + '<label style="font-size:11px;font-weight:600;">Options</label><textarea class="nu-input nu-field-options" rows="3" style="width:100%;box-sizing:border-box;">' + _esc(s2Opts) + '</textarea>'
          + '</div>'
          + '<div class="nb-select-from-table" style="' + (s2IsFromTable ? '' : 'display:none;') + 'grid-column:1/-1;">'
            + '<div style="background:var(--bg-offset,#f0f4ff);border:1.5px solid var(--color-primary,#4f6bed);border-radius:8px;padding:12px 14px;">'
              + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">'
                + '<div style="grid-column:1/-1;"><label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;">Table</label><input type="text" class="nu-input nu-field-opt-table" value="' + _esc(s2FromTable) + '" placeholder="e.g. categories" style="font-size:12px;width:100%;box-sizing:border-box;"></div>'
                + '<div><label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;">Value Col</label><input type="text" class="nu-input nu-field-opt-val-col" value="' + _esc(s2FromValCol) + '" placeholder="id" style="font-size:12px;"></div>'
                + '<div><label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;">Label Col</label><input type="text" class="nu-input nu-field-opt-label-col" value="' + _esc(s2FromLabelCol) + '" placeholder="name" style="font-size:12px;"></div>'
                + '<div style="grid-column:1/-1;"><label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;">Filter SQL</label><input type="text" class="nu-input nu-field-opt-filter" value="' + _esc(s2FromFilter) + '" placeholder="active=1" style="font-size:12px;width:100%;box-sizing:border-box;"></div>'
              + '</div>'
            + '</div>'
          + '</div>';
      }

      /* ── RADIO / CHECKBOX GROUP ── */
      if (canvasType === 'radio' || canvasType === 'checkbox_group') {
        var rcOptSource    = extra.options_source || 'manual';
        var rcFromTable    = extra.options_table  || '';
        var rcFromValCol   = extra.options_value_col || '';
        var rcFromLabelCol = extra.options_label_col || '';
        var rcFromFilter   = extra.options_filter || '';
        var rcIsFromTable  = (rcOptSource === 'table');
        var rcOpts = (extra.options || []).map(function (o) { return typeof o === 'object' ? (o.value + '|' + o.label) : o; }).join('\n');

        extraBody +=
          '<div class="nb-fp nb-fp-full" style="grid-column:1/-1;"><label style="font-size:11px;font-weight:600;">Options Source</label>'
            + '<div style="display:flex;gap:8px;margin-top:4px;">'
              + '<label style="display:flex;align-items:center;gap:4px;cursor:pointer;font-size:12px;"><input type="radio" name="opt-src-' + _esc(name) + '" class="nu-field-opt-src" value="manual"' + (rcIsFromTable ? '' : ' checked') + '> Manual</label>'
              + '<label style="display:flex;align-items:center;gap:4px;cursor:pointer;font-size:12px;"><input type="radio" name="opt-src-' + _esc(name) + '" class="nu-field-opt-src" value="table"' + (rcIsFromTable ? ' checked' : '') + '> From table</label>'
            + '</div></div>'
          + '<div class="nb-select-manual" style="' + (rcIsFromTable ? 'display:none;' : '') + 'grid-column:1/-1;">'
            + '<label style="font-size:11px;font-weight:600;">Options</label><textarea class="nu-input nu-field-options" rows="3" style="width:100%;box-sizing:border-box;">' + _esc(rcOpts) + '</textarea>'
          + '</div>'
          + '<div class="nb-select-from-table" style="' + (rcIsFromTable ? '' : 'display:none;') + 'grid-column:1/-1;">'
            + '<div style="background:var(--bg-offset,#f0f4ff);border:1.5px solid var(--color-primary,#4f6bed);border-radius:8px;padding:12px 14px;">'
              + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">'
                + '<div style="grid-column:1/-1;"><label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;">Table</label><input type="text" class="nu-input nu-field-opt-table" value="' + _esc(rcFromTable) + '" placeholder="e.g. categories" style="font-size:12px;width:100%;box-sizing:border-box;"></div>'
                + '<div><label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;">Value Col</label><input type="text" class="nu-input nu-field-opt-val-col" value="' + _esc(rcFromValCol) + '" placeholder="id" style="font-size:12px;"></div>'
                + '<div><label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;">Label Col</label><input type="text" class="nu-input nu-field-opt-label-col" value="' + _esc(rcFromLabelCol) + '" placeholder="name" style="font-size:12px;"></div>'
                + '<div style="grid-column:1/-1;"><label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;">Filter SQL</label><input type="text" class="nu-input nu-field-opt-filter" value="' + _esc(rcFromFilter) + '" placeholder="active=1" style="font-size:12px;width:100%;box-sizing:border-box;"></div>'
              + '</div>'
            + '</div>'
          + '</div>';
      }

      /* ── CALCULATED ── */
      if (canvasType === 'calculated') {
        extraBody += '<div class="nb-fp nb-fp-full"><label>Formula</label><textarea class="nu-input nu-field-formula" rows="2" placeholder="{qty} * {price}">' + _esc(extra.formula || extra.calc_formula || '') + '</textarea></div>';
      }

      /* ── LOOKUP ── */
      if (canvasType === 'lookup') {
        var lk       = (extra.lookup && typeof extra.lookup === 'object') ? extra.lookup : {};
        var lkTable  = lk.table          || extra.lookup_form    || '';
        var lkDisp   = lk.display_column || extra.lookup_display || '';
        var lkStore  = lk.id_column      || extra.lookup_store   || '';
        var lkFilter = lk.filter         || extra.lookup_filter  || '';
        var lkExtra  = lk.extra          || extra.lookup_extra   || '';

        extraBody +=
          '<div style="background:var(--bg-offset,#f0f4ff);border:1.5px solid var(--color-primary,#4f6bed);border-radius:8px;padding:12px 14px;margin-top:6px;grid-column:1/-1;">'
            + '<div style="font-size:11px;font-weight:700;letter-spacing:.06em;color:var(--color-primary,#4f6bed);margin-bottom:10px;">🔗 LOOKUP CONFIG</div>'
            + '<div style="margin-bottom:8px;"><label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;">Lookup Table</label><input type="text" class="nu-input nu-lookup-table" value="' + _esc(lkTable) + '" placeholder="e.g. customers" style="font-size:12px;width:100%;box-sizing:border-box;"></div>'
            + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px;">'
              + '<div><label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;">Display Col</label><input type="text" class="nu-input nu-lookup-display" value="' + _esc(lkDisp) + '" placeholder="full_name" style="font-size:12px;"></div>'
              + '<div><label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;">Store Col</label><input type="text" class="nu-input nu-lookup-store" value="' + _esc(lkStore) + '" placeholder="id" style="font-size:12px;"></div>'
            + '</div>'
            + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">'
              + '<div><label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;">Filter SQL</label><input type="text" class="nu-input nu-lookup-filter" value="' + _esc(lkFilter) + '" placeholder="active=1" style="font-size:12px;"></div>'
              + '<div><label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;">Extra Mapping</label><input type="text" class="nu-input nu-lookup-extra" value="' + _esc(lkExtra) + '" placeholder="code:dept_code" style="font-size:12px;"></div>'
            + '</div>'
          + '</div>';
      }

      /* ── SUBFORM ── */
      var sfData;
      if (canvasType === 'subform') {
        var sf = (extra.subform && typeof extra.subform === 'object') ? extra.subform : {};
        sfData = {
          form_code:       sf.form_code    || extra.sf_form_code    || '',
          fk_field:        sf.fk_field     || extra.sf_fk_field     || '',
          subform_view:    extra.subform_view                        || 'grid',
          help_text:       extra.help_text  || extra.field_help_text || '',
          is_fk:           !!sf.is_fk,
          hide_in_grid:    !!sf.hide_in_grid,
          server_readonly: !!sf.server_readonly
        };
        extraBody += _subformPanelHTML(sfData);
      }

      var card = document.createElement('div');
      card.className = 'nb-cfield';
      card.dataset.type = canvasType;
      card.dataset.runtimeType = type;
      card.style.gridColumn = 'span ' + col;
      card.dataset.col = String(col);
      card.innerHTML =
        '<div class="nb-cfield-header">'
          + '<span class="nb-cfield-drag">⠇</span>'
          + '<span class="nb-cfield-type-badge">' + _esc(canvasType) + '</span>'
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
            + (canvasType !== 'subform' ? '<div class="nb-fp"><label>Placeholder</label><input type="text" class="nu-input nu-field-placeholder" value="' + _esc(extra.placeholder || '') + '"></div>' : '')
            + (canvasType !== 'subform' ? '<div class="nb-fp"><label>Default Value</label><input type="text" class="nu-input nu-field-default" value="' + _esc(extra.default_value || extra.defaultvalue || '') + '"></div>' : '')
            + '<div class="nb-fp nb-fp-full"><label>Help Text</label><input type="text" class="nu-input nu-field-help" value="' + _esc(extra.help_text || extra.field_help_text || '') + '"></div>'
            + extraBody
            + _visibilityFlagsHTML(extra)
          + '</div>'
        + '</div>';

      var header = card.querySelector('.nb-cfield-header');
      var body   = card.querySelector('.nb-cfield-body');
      if (header && body) {
        header.addEventListener('click', function (e) {
          if (e.target.closest('.nb-cfield-actions')) return;
          body.classList.toggle('open');
          /* FIX-2: update header label from input when closing */
          var lbl = card.querySelector('.nu-field-label');
          var hdr = card.querySelector('.nb-cfield-label');
          if (lbl && hdr) hdr.textContent = lbl.value || hdr.textContent;
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

      if (canvasType === 'select' || canvasType === 'select2' || canvasType === 'radio' || canvasType === 'checkbox_group') {
        _attachSelectOptionsToggle(card);
      }
      if (canvasType === 'subform') {
        _attachSubformPanelEvents(card, sfData);
      }

      return card;
    },

    /* ── _applyColSpan ─────────────────────────────────────── */
    _applyColSpan: function (card, col) {
      var c = parseInt(col, 10) || 12;
      if (c < 1 || c > 12) c = 12;
      card.style.gridColumn = 'span ' + c;
      card.dataset.col = String(c);
      var badge   = card.querySelector('.nb-cfield-span-badge');
      var preview = card.querySelector('.nb-span-preview');
      if (badge)   badge.textContent   = c + '/12';
      if (preview) preview.textContent = c + '/12 cols';
      card.querySelectorAll('.nb-span-btn').forEach(function (btn) {
        btn.classList.toggle('active', parseInt(btn.dataset.span, 10) === c);
      });
    },

    /* ════════════════════════════════════════════════════════════
       getLayout — FIX-3: pass real row index so multi-row layouts
       survive save/reload
    ════════════════════════════════════════════════════════════ */
    getLayout: function () {
      var canvas = document.getElementById('formCanvas');
      if (!canvas) return [];
      var layout = [];

      Array.prototype.forEach.call(canvas.children, function (el) {
        if (el.classList.contains('nb-row')) {
          /* FIX-3: pass actual index of this row among canvas rows */
          var allRows = Array.prototype.slice.call(canvas.querySelectorAll(':scope > .nb-row'));
          var ri = allRows.indexOf(el);
          var rowFields = _collectRowFields(el, ri);
          rowFields.forEach(function (f) { layout.push(f); });

        } else if (el.classList.contains('nb-container')) {
          var ctype = el.dataset.containerType;

          if (ctype === 'group') {
            var groupLabel = '';
            var labelInput = el.querySelector('.nb-container-label-input');
            if (labelInput) groupLabel = labelInput.value || '';
            var groupRows  = _collectContainerRows(el.querySelector('.nb-container-group-body'));
            layout.push({ type: 'group', label: groupLabel, name: 'group_' + Date.now(), rows: groupRows, col: 12, row_index: -1 });

          } else if (ctype === 'tab') {
            var tabsData = [];
            var tabNav    = el.querySelector('[id$="-nav"]');
            var tabPanels = el.querySelector('[id$="-panels"]');
            if (tabNav && tabPanels) {
              tabNav.querySelectorAll('.nb-cfield-tab-nav-item').forEach(function (navItem) {
                var nameInput = navItem.querySelector('.nb-tab-name-input');
                var tabName   = nameInput ? nameInput.value : 'Tab';
                var panelId   = navItem.dataset.panelTarget;
                var panelEl   = panelId ? document.getElementById(panelId) : null;
                var panelRows = panelEl ? _collectContainerRows(panelEl.querySelector('.nb-tab-panel-rows')) : [];
                tabsData.push({ name: tabName, rows: panelRows });
              });
            }
            layout.push({ type: 'tab', name: 'tab_' + Date.now(), tabs: tabsData, col: 12, row_index: -1 });
          }
        }
      });

      if (typeof window._nbSfAugmentLayout === 'function') {
        layout = window._nbSfAugmentLayout(layout);
      }
      return layout;
    },

    /* ── saveForm ───────────────────────────────────────────── */
    saveForm: async function () {
      var nameEl  = document.getElementById('builderFormName');
      var codeEl  = document.getElementById('builderFormCode');
      var tableEl = document.getElementById('builderFormTable');
      var editEl  = document.getElementById('editFormId');

      var name   = nameEl  ? nameEl.value.trim()  : '';
      var code   = codeEl  ? codeEl.value.trim()  : '';
      var table  = tableEl ? tableEl.value.trim() : '';
      var editId = editEl  ? editEl.value.trim()  : '';

      if (!name) { NuApp.toast('Form name is required', 'error'); return; }

      var _r = function (n) { var e = document.querySelector('input[name="' + n + '"]:checked'); return e ? e.value : ''; };
      var _v = function (id) { var e = document.getElementById(id); return e ? e.value : ''; };
      var _c = function (id) { var e = document.getElementById(id); return !!(e && e.checked); };

      var layout = this.getLayout();
      var payload = {
        form_name:       name,
        form_code:       code,
        form_table:      table,
        form_type:       _r('formType')      || 'main',
        form_table_mode: _r('formTableMode') || 'new',
        form_pk_type:    _r('formPkType')    || 'autoincrement',
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
          method: 'POST', credentials: 'same-origin',
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
      var canvas = document.getElementById('formCanvas');
      if (canvas) _attachCanvasRowDrop(canvas);
    }
  };

  window.saveForm = function () { return window.nbFormBuilder.saveForm(); };

  /* ── _collectRowFields ───────────────────────────────────── */
  function _collectRowFields(rowEl, rowIndex) {
    /* FIX-3: rowIndex is now always passed as real position */
    var ri = (rowIndex !== undefined && rowIndex !== null) ? rowIndex : -1;
    var fields = [];
    rowEl.querySelectorAll(':scope > .nb-row-body > .nb-cfield').forEach(function (card) {
      var f = _readFieldCard(card, ri);
      if (f) fields.push(f);
    });
    return fields;
  }

  /* ── _collectContainerRows ───────────────────────────────── */
  function _collectContainerRows(bodyEl) {
    if (!bodyEl) return [];
    var rows = [];
    bodyEl.querySelectorAll('.nb-inner-row').forEach(function (rowEl, ri) {
      var rowFields = [];
      rowEl.querySelectorAll(':scope > .nb-row-body > .nb-cfield').forEach(function (card) {
        var f = _readFieldCard(card, ri);
        if (f) rowFields.push(f);
      });
      rows.push({ fields: rowFields });
    });
    return rows;
  }

  /* ── _readFieldCard ──────────────────────────────────────── */
  function _readFieldCard(card, rowIndex) {
    var canvasType = card.dataset.type || 'text';
    var labelEl    = card.querySelector('.nu-field-label');
    var nameEl     = card.querySelector('.nu-field-name');
    var reqEl      = card.querySelector('.nu-field-required');
    var noDupEl    = card.querySelector('.nu-field-no-duplicate');
    var readonlyEl = card.querySelector('.nu-field-readonly');
    var hiddenEl   = card.querySelector('.nu-field-hidden');
    var hidNormEl  = card.querySelector('.nu-field-hidden-normal');
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

    var selModeEl = card.querySelector('.nu-field-select-mode');
    var isMultiSel = false;
    if (canvasType === 'select' || canvasType === 'select2') {
      isMultiSel = selModeEl && selModeEl.value === 'multi';
    }

    var field = {
      type:                    canvasType,
      label:                   labelEl    ? labelEl.value    : '',
      name:                    nameEl     ? nameEl.value     : '',
      required:                reqEl      ? reqEl.checked    : false,
      no_duplicate:            noDupEl    ? noDupEl.checked  : false,
      readonly:                readonlyEl ? readonlyEl.checked : false,
      hidden:                  hiddenEl   ? hiddenEl.checked : false,
      hidden_for_normal_users: hidNormEl  ? hidNormEl.checked : false,
      placeholder:             phEl       ? phEl.value       : '',
      default_value:           defEl      ? defEl.value      : '',
      help_text:               helpEl     ? helpEl.value     : '',
      col:                     parseInt(card.dataset.col, 10) || 12,
      row_index:               (rowIndex !== undefined && rowIndex !== null) ? rowIndex : -1
    };

    if (canvasType === 'select') {
      field.multiple    = isMultiSel;
      field.select2     = false;
      field.select_type = isMultiSel ? 'multiselect' : 'select';
    }
    if (canvasType === 'select2') {
      field.select2     = true;
      field.multiple    = isMultiSel;
      field.select_type = 'select2';
      var allowClearEl  = card.querySelector('.nu-field-allow-clear');
      field.allow_clear = allowClearEl ? allowClearEl.checked : true;
    }

    if (canvasType === 'select' || canvasType === 'select2' || canvasType === 'radio' || canvasType === 'checkbox_group') {
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
          return parts.length >= 2 ? { value: parts[0].trim(), label: parts[1].trim() } : { value: l, label: l };
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

    return field;
  }


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

  function _attachSelectOptionsToggle(card) {
    var radios         = card.querySelectorAll('.nu-field-opt-src');
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
      /* ignore row-drag events so they bubble to canvas */
      if (e.dataTransfer.types && Array.prototype.indexOf.call(e.dataTransfer.types, 'text/nb-row-id') !== -1) return;
      e.preventDefault(); e.stopPropagation();
      rowBody.classList.add('drag-col-over');
    });
    rowBody.addEventListener('dragleave', function (e) {
      if (!rowBody.contains(e.relatedTarget)) rowBody.classList.remove('drag-col-over');
    });
    rowBody.addEventListener('drop', function (e) {
      if (e.dataTransfer.types && Array.prototype.indexOf.call(e.dataTransfer.types, 'text/nb-row-id') !== -1) return;
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
        '<div><label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;">FK Field</label>',
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
      formSel.addEventListener('change', function () { _populateFkDropdown(panel, formSel.value, ''); });
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
        var form   = json.form;
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
      if (cfg.form_code) fieldObj.subform.form_code = cfg.form_code;
      if (cfg.fk_field)  fieldObj.subform.fk_field  = cfg.fk_field;
      fieldObj.subform_view = cfg.subform_view || 'grid';
      if (cfg.help_text) fieldObj.help_text = cfg.help_text;
      if (cfg.is_fk)           fieldObj.subform.is_fk           = true; else delete fieldObj.subform.is_fk;
      if (cfg.hide_in_grid)    fieldObj.subform.hide_in_grid    = true; else delete fieldObj.subform.hide_in_grid;
      if (cfg.server_readonly) fieldObj.subform.server_readonly = true; else delete fieldObj.subform.server_readonly;
      delete fieldObj.is_fk; delete fieldObj.hide_in_grid; delete fieldObj.server_readonly;
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

      var ftype      = f.form_type || 'main';
      var ftypeRadio = document.querySelector('input[name="formType"][value="' + ftype + '"]');
      window.nbFormBuilder.selectFormType(ftype, ftypeRadio ? ftypeRadio.closest('.nb-ftype-card') : null);

      var tableMode  = f.form_table_mode || 'new';
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

      var pkType  = f.form_pk_type || 'autoincrement';
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

  /* ── _rebuildCanvas ─────────────────────────────────────── */
  function _rebuildCanvas(layoutJson) {
    var canvas = document.getElementById('formCanvas');
    var empty  = document.getElementById('canvasEmpty');
    if (!canvas) return;

    canvas.querySelectorAll('.nb-row,.nb-container').forEach(function (r) { r.remove(); });

    var fields = [];
    try {
      fields = typeof layoutJson === 'string'
        ? JSON.parse(layoutJson)
        : (Array.isArray(layoutJson) ? layoutJson : []);
    } catch (e) { fields = []; }

    if (!fields.length) { if (empty) empty.style.display = 'block'; return; }
    if (empty) empty.style.display = 'none';

    /* FIX-4: wire canvas drop on rebuild */
    _attachCanvasRowDrop(canvas);

    var regularFields = [];
    fields.forEach(function (f) {
      var type = f.type || f.fieldtype || 'text';

      if (type === 'group') {
        if (regularFields.length) { _rebuildRows(canvas, regularFields); regularFields = []; }
        var grp = _makeGroupContainer(f);
        canvas.appendChild(grp);
        return;
      }
      if (type === 'tab') {
        if (regularFields.length) { _rebuildRows(canvas, regularFields); regularFields = []; }
        var tab = _makeTabContainer(f);
        canvas.appendChild(tab);
        return;
      }
      regularFields.push(f);
    });

    if (regularFields.length) _rebuildRows(canvas, regularFields);
    window.nbFormBuilder._updateEmptyState();
  }

  /* ── _rebuildRows ── FIX-3: group by real row_index then sort ── */
  function _rebuildRows(canvas, fields) {
    var groups     = {};
    var groupOrder = [];

    fields.forEach(function (f) {
      var ri = (f.row_index !== undefined && f.row_index !== null) ? f.row_index : -1;
      if (!groups[ri]) { groups[ri] = []; groupOrder.push(ri); }
      groups[ri].push(f);
    });

    /* Sort: numeric indices ascending, -1 (ungrouped) last */
    groupOrder.sort(function (a, b) {
            if (a === -1) return 1;
      if (b === -1) return -1;
      return a - b;
    });

    groupOrder.forEach(function (ri) {
      var row     = window.nbFormBuilder.addRow();
      var rowBody = row ? row.querySelector('.nb-row-body') : null;

      groups[ri].forEach(function (f) {
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
        card.addEventListener('dragend', function () {
          card.classList.remove('drag-source');
        });

        if (rowBody) {
          var hint = rowBody.querySelector('.nb-row-drop-hint');
          if (hint) hint.remove();
          rowBody.appendChild(card);
        } else {
          canvas.appendChild(card);
        }

        window.nbFormBuilder._applyColSpan(card, parseInt(f.col, 10) || 12);
        _restoreFieldState(card, f);

        /* FIX-2: open body panel so label+name inputs are visible on edit restore */
        var cb = card.querySelector('.nb-cfield-body');
        if (cb) cb.classList.add('open');
      });
    });
  }

}(window));
