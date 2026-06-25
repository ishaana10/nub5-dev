/**
 * nb-form-builder.js  — PATCHED v4
 *
 * v4 Fixes:
 *   FIX-E  Canvas dragover/drop now intercepts text/nb-type so dragging a
 *          Group or Tab tool from the toolbar lands directly on the canvas
 *          (no row needed).  Regular field drops still go to a row-body.
 *          Row lives only inside group/tab — canvas rows are still supported
 *          for backward compat but group/tab are the primary containers.
 * (v3 fixes retained below)
 *   FIX-A  Tab/Group added directly to canvas (no row needed)
 *   FIX-B  Field body always opens when card is first created (new + restore)
 *   FIX-C  saveForm passes create_table flag; getLayout row_index is stable
 *   FIX-D  Label in header updates live as user types in the label input
 */
(function (window) {
  'use strict';

  /* ══════════════════════════════════════════════════════════════════
     CSS injection
  ══════════════════════════════════════════════════════════════════ */
  (function _injectCSS() {
    if (document.getElementById('nb-container-css')) return;
    var s = document.createElement('style');
    s.id = 'nb-container-css';
    s.textContent = [
      '.nb-container{border:2px solid var(--color-primary,#4f6bed);border-radius:10px;margin:8px 0;background:var(--bg-card,#fff);overflow:hidden;}',
      '.nb-container-header{display:flex;align-items:center;gap:8px;padding:7px 10px;background:var(--color-primary,#4f6bed);color:#fff;cursor:default;}',
      '.nb-container-header .nb-row-drag{font-size:16px;cursor:grab;opacity:.8;}',
      '.nb-container-header .nb-container-label-input{flex:1;background:rgba(255,255,255,.18);border:1px solid rgba(255,255,255,.4);border-radius:5px;color:#fff;font-size:12px;padding:2px 7px;}',
      '.nb-container-header .nb-container-label-input::placeholder{color:rgba(255,255,255,.7);}',
      '.nb-container-type-badge{font-size:10px;font-weight:700;letter-spacing:.06em;background:rgba(255,255,255,.22);padding:2px 7px;border-radius:4px;}',
      '.nb-container-type-badge-tab{background:rgba(255,200,0,.35);}',
      '.nb-container-group-body{padding:8px 10px;display:flex;flex-direction:column;gap:6px;min-height:48px;}',
      '.nb-cfield-tab-nav{display:flex;flex-wrap:wrap;align-items:center;gap:0;border-bottom:2px solid var(--color-primary,#4f6bed);padding:0 8px;}',
      '.nb-cfield-tab-nav-item{display:flex;align-items:center;gap:2px;padding:5px 12px;cursor:pointer;font-size:13px;border-radius:6px 6px 0 0;border:1px solid transparent;margin-bottom:-2px;color:var(--text-secondary,#555);}',
      '.nb-cfield-tab-nav-item.active{background:#fff;border-color:var(--color-primary,#4f6bed);border-bottom-color:#fff;font-weight:600;color:var(--color-primary,#4f6bed);}',
      '.nb-cfield-tab-add-btn{margin-left:4px;padding:3px 10px;font-size:11px;border:1px dashed var(--color-primary,#4f6bed);border-radius:5px;background:none;color:var(--color-primary,#4f6bed);cursor:pointer;}',
      '.nb-container-tab-panels{padding:0;}',
      '.nb-cfield-tab-panel{display:none;flex-direction:column;}',
      '.nb-cfield-tab-panel.active{display:flex;}',
      '.nb-tab-panel-rows{padding:8px 10px;display:flex;flex-direction:column;gap:6px;min-height:52px;}',
      '.nb-inner-row{border:1px solid var(--border,#e0e4ef);border-radius:7px;background:var(--bg-offset,#f8faff);margin:2px 0;}',
      '.nb-inner-row .nb-row-header{background:var(--bg-offset2,#edf0fc);border-radius:6px 6px 0 0;padding:4px 8px;}',
      '.nb-row.drag-row-over,.nb-container.drag-row-over{outline:2px dashed var(--color-primary,#4f6bed);outline-offset:2px;}',
      '.nb-row.drag-row-source,.nb-container.drag-row-source{opacity:.45;}',
      '.nb-row-drop-hint{color:var(--text-muted,#aaa);font-size:12px;text-align:center;padding:10px 0;grid-column:1/-1;}',
      /* FIX-B: field body hidden by default, .open shows it */
      '.nb-cfield-body{display:none;}.nb-cfield-body.open{display:block;}',
      /* FIX-E: canvas drag-over highlight when a tool (group/tab/field) is dragged */
      '#formCanvas.nb-canvas-tool-over{outline:2px dashed var(--color-primary,#4f6bed);outline-offset:3px;}'
    ].join('');
    (document.head || document.documentElement).appendChild(s);
  }());


  /* ════════════════════════════════════════════════════════════════════
     _nbSfData
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
     Core
  ═══════════════════════════════════════════════════════════════════ */
  var _fieldCounter = 0;

  function _esc(s) {
    return String(s || '')
      .replace(/&/g,'&amp;').replace(/</g,'&lt;')
      .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  function _visibilityFlagsHTML(extra) {
    extra = extra || {};
    return '<div class="nb-fp nb-fp-full nb-vis-flags" style="grid-column:1/-1;display:flex;flex-wrap:wrap;gap:10px 18px;padding:8px 10px;background:var(--bg-offset,#f5f7ff);border:1px solid var(--border,#e0e4ef);border-radius:7px;margin-top:4px;">'
      + '<label style="font-size:11px;font-weight:700;color:var(--text-muted,#888);text-transform:uppercase;letter-spacing:.5px;flex-basis:100%;margin-bottom:2px;">Field Options</label>'
      + '<label class="nb-fp-check" style="font-size:12px;cursor:pointer;display:flex;align-items:center;gap:5px;"><input type="checkbox" class="nu-field-required"'  + (extra.required              ? ' checked' : '') + '> Required</label>'
      + '<label class="nb-fp-check" style="font-size:12px;cursor:pointer;display:flex;align-items:center;gap:5px;"><input type="checkbox" class="nu-field-no-duplicate"' + (extra.no_duplicate          ? ' checked' : '') + '> No Duplicate</label>'
      + '<label class="nb-fp-check" style="font-size:12px;cursor:pointer;display:flex;align-items:center;gap:5px;"><input type="checkbox" class="nu-field-readonly"'    + (extra.readonly              ? ' checked' : '') + '> Readonly</label>'
      + '<label class="nb-fp-check" style="font-size:12px;cursor:pointer;display:flex;align-items:center;gap:5px;"><input type="checkbox" class="nu-field-hidden"'      + (extra.hidden                ? ' checked' : '') + '> Hidden</label>'
      + '<label class="nb-fp-check" style="font-size:12px;cursor:pointer;display:flex;align-items:center;gap:5px;"><input type="checkbox" class="nu-field-hidden-normal"'+ (extra.hidden_for_normal_users ? ' checked' : '') + '> Hidden for normal users</label>'
      + '</div>';
  }

  /* ── Row drag helpers ───────────────────────────────────────────── */
  function _wireRowDrag(rowEl) {
    var handle = rowEl.querySelector(':scope > .nb-row-header > .nb-row-drag, :scope > .nb-container-header > .nb-row-drag');
    if (!handle || rowEl._nbRowDragWired) return;
    rowEl._nbRowDragWired = true;
    rowEl.setAttribute('draggable', 'true');
    rowEl.addEventListener('dragstart', function (e) {
      if (!e.target.classList.contains('nb-row-drag')) return;
      e.stopPropagation();
      e.dataTransfer.setData('text/nb-row-id', rowEl.id || (rowEl.id = 'nb-row-' + Date.now()));
      e.dataTransfer.effectAllowed = 'move';
      rowEl.classList.add('drag-row-source');
    });
    rowEl.addEventListener('dragend', function () {
      rowEl.classList.remove('drag-row-source');
      document.querySelectorAll('.drag-row-over').forEach(function (el) { el.classList.remove('drag-row-over'); });
    });
  }

  /* ── FIX-E: _attachCanvasRowDrop now ALSO handles text/nb-type for group/tab ── */
  function _attachCanvasRowDrop(canvas) {
    if (canvas._nbCanvasRowDropWired) return;
    canvas._nbCanvasRowDropWired = true;

    canvas.addEventListener('dragover', function (e) {
      var types = e.dataTransfer.types;
      if (!types) return;
      var hasRowId  = Array.prototype.indexOf.call(types, 'text/nb-row-id')  !== -1;
      var hasNbType = Array.prototype.indexOf.call(types, 'text/nb-type')    !== -1;
      var hasPlain  = Array.prototype.indexOf.call(types, 'text/plain')      !== -1;

      if (hasNbType || hasPlain) {
        /* Only allow direct canvas drop for group/tab — regular fields must go to a row-body */
        e.preventDefault();
        e.stopPropagation();
        canvas.classList.add('nb-canvas-tool-over');
        return;
      }

      if (!hasRowId) return;
      e.preventDefault(); e.stopPropagation();
      canvas.classList.remove('nb-canvas-tool-over');
      var target = e.target;
      while (target && target.parentNode !== canvas) target = target.parentNode;
      if (!target || target === canvas || target.classList.contains('drag-row-source')) return;
      document.querySelectorAll('.drag-row-over').forEach(function (el) { el.classList.remove('drag-row-over'); });
      target.classList.add('drag-row-over');
    });

    canvas.addEventListener('dragleave', function (e) {
      if (!canvas.contains(e.relatedTarget)) {
        canvas.classList.remove('nb-canvas-tool-over');
        document.querySelectorAll('.drag-row-over').forEach(function (el) { el.classList.remove('drag-row-over'); });
      }
    });

    canvas.addEventListener('drop', function (e) {
      canvas.classList.remove('nb-canvas-tool-over');
      document.querySelectorAll('.drag-row-over').forEach(function (el) { el.classList.remove('drag-row-over'); });

      /* ── FIX-E: toolbar tool drop (text/nb-type) → group/tab go to canvas ── */
      var dtype = e.dataTransfer.getData('text/nb-type') || '';
      if (!dtype) dtype = e.dataTransfer.getData('text/plain') || '';
      if (dtype) {
        if (dtype === 'group' || dtype === 'tab') {
          e.preventDefault(); e.stopPropagation();
          window.nbFormBuilder.addField(dtype, {});
          return;
        }
        /* Regular field dropped directly on canvas — create a row for it */
        e.preventDefault(); e.stopPropagation();
        var row = window.nbFormBuilder.addRow();
        var rb  = row ? row.querySelector('.nb-row-body') : null;
        if (rb) {
          var newCard = window.nbFormBuilder._makeFieldCard(dtype, dtype + ' field', dtype + '_' + Date.now(), false, { col: 6 });
          if (newCard) {
            _prepCard(newCard);
            var hint = rb.querySelector('.nb-row-drop-hint');
            if (hint) hint.remove();
            rb.appendChild(newCard);
            window.nbFormBuilder._applyColSpan(newCard, 6);
            var cb = newCard.querySelector('.nb-cfield-body');
            if (cb) cb.classList.add('open');
          }
        }
        window.nbFormBuilder._updateEmptyState();
        return;
      }

      /* ── Row reorder (text/nb-row-id) ── */
      var rowId = e.dataTransfer.getData('text/nb-row-id');
      if (!rowId) return;
      e.preventDefault(); e.stopPropagation();
      var draggedRow = document.getElementById(rowId);
      if (!draggedRow || draggedRow.parentNode !== canvas) return;
      var target = e.target;
      while (target && target.parentNode !== canvas) target = target.parentNode;
      if (!target || target === draggedRow) return;
      var rect = target.getBoundingClientRect();
      if (e.clientY < rect.top + rect.height / 2) canvas.insertBefore(draggedRow, target);
      else canvas.insertBefore(draggedRow, target.nextSibling);
    });
  }

  /* ════════════════════════════════════════════════════════════════════
     GROUP container
  ═══════════════════════════════════════════════════════════════════ */
  function _makeGroupContainer(extra) {
    extra = extra || {};
    var label = extra.label || 'Group';
    var id = 'nb-group-' + Date.now() + '-' + Math.random().toString(36).slice(2,5);
    var wrap = document.createElement('div');
    wrap.className = 'nb-container nb-container-group';
    wrap.id = id;
    wrap.dataset.containerType = 'group';
    wrap.innerHTML =
      '<div class="nb-container-header">'
        + '<span class="nb-row-drag" title="Drag group">⠇</span>'
        + '<span class="nb-container-type-badge">GROUP</span>'
        + '<input type="text" class="nb-container-label-input nu-input" value="' + _esc(label) + '" placeholder="Group label">'
        + '<button type="button" class="nb-row-btn" onclick="window.nbFormBuilder._addRowToContainer(this.closest(\'.nb-container\'))" title="Add row">+ Row</button>'
        + '<button type="button" class="nb-row-btn del" onclick="this.closest(\'.nb-container\').remove();window.nbFormBuilder._updateEmptyState();">✕</button>'
      + '</div>'
      + '<div class="nb-container-body nb-container-group-body">'
        + '<div class="nb-row-drop-hint">Click "+ Row" to add a row, then drop fields in</div>'
      + '</div>';

    if (extra.rows && extra.rows.length) {
      var body = wrap.querySelector('.nb-container-group-body');
      if (body) {
        var hint = body.querySelector('.nb-row-drop-hint');
        if (hint) hint.remove();
        extra.rows.forEach(function (rowDef) { _addRowToContainer(body, rowDef.fields || [], true); });
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
    var id = 'nb-tab-' + Date.now() + '-' + Math.random().toString(36).slice(2,5);

    var wrap = document.createElement('div');
    wrap.className = 'nb-container nb-container-tab';
    wrap.id = id;
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

    /* Temporarily attach to DOM so getElementById works inside _addTabPanel */
    document.body.appendChild(wrap);
    var nav    = document.getElementById(id + '-nav');
    var panels = document.getElementById(id + '-panels');

    tabs.forEach(function (tab, i) {
      _addTabPanel(wrap, nav, panels, tab.name || ('Tab ' + (i+1)), i === 0, tab.rows || []);
    });

    var addBtn = document.createElement('button');
    addBtn.type = 'button';
    addBtn.className = 'nb-cfield-tab-add-btn';
    addBtn.textContent = '+ Tab';
    addBtn.addEventListener('click', function () {
      var idx = nav.querySelectorAll('.nb-cfield-tab-nav-item').length;
      _addTabPanel(wrap, nav, panels, 'Tab ' + (idx + 1), false, []);
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
      '<input type="text" class="nb-tab-name-input" value="' + _esc(tabName) + '" '
        + 'style="background:none;border:none;outline:none;font:inherit;cursor:pointer;width:' + Math.max(50, tabName.length * 8) + 'px;min-width:40px;" '
        + 'onclick="event.stopPropagation()">'
      + '<span class="nb-tab-nav-del" style="font-size:10px;cursor:pointer;color:rgba(0,0,0,.4);margin-left:2px;" title="Remove tab">×</span>';

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
      '<div style="display:flex;align-items:center;justify-content:flex-end;padding:4px 8px 2px;border-bottom:1px solid var(--border,#e0e4ef);">'
        + '<button type="button" class="nb-row-btn" onclick="window.nbFormBuilder._addRowToContainer(this.closest(\'.nb-cfield-tab-panel\'))">+ Row</button>'
      + '</div>'
      + '<div class="nb-tab-panel-rows"></div>';
    panels.appendChild(panel);

    var rowsBody = panel.querySelector('.nb-tab-panel-rows');
    if (rows && rows.length) {
      rows.forEach(function (rowDef) { _addRowToContainer(rowsBody, rowDef.fields || [], true); });
    } else {
      rowsBody.innerHTML = '<div class="nb-row-drop-hint">Click "+ Row" to add a row, then drop fields in</div>';
    }
    return panel;
  }

  /* ── _addRowToContainer ─────────────────────────────────────────── */
  function _addRowToContainer(target, fields, isRestore) {
    /* Resolve the correct rows wrapper */
    var rowsWrap = target;
    if (target) {
      if (target.classList.contains('nb-cfield-tab-panel')) {
        rowsWrap = target.querySelector('.nb-tab-panel-rows');
      } else if (target.classList.contains('nb-container')) {
        rowsWrap = target.querySelector('.nb-container-group-body');
      }
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
          + '<button class="nb-row-btn del" onclick="'
            + 'var r=this.closest(\'.nb-row\');'
            + 'var p=r.parentNode;'
            + 'r.remove();'
            + 'if(!p.querySelector(\'.nb-row\')){p.innerHTML=\'<div class=\\\"nb-row-drop-hint\\\">Click &quot;+ Row&quot; to add a row, then drop fields in</div>\';}' 
            + 'window.nbFormBuilder._updateEmptyState();">✕</button>'
        + '</span>'
      + '</div>'
      + '<div class="nb-row-body">'
        + '<div class="nb-row-drop-hint">Drop fields here</div>'
      + '</div>';
    rowsWrap.appendChild(row);
    _wireRowDrag(row);

    var body = row.querySelector('.nb-row-body');
    if (body) _attachRowBodyDrop(body);

    if (fields && fields.length) {
      fields.forEach(function (f) {
        var card = window.nbFormBuilder._makeFieldCard(
          f.type || 'text', f.label || '', f.name || '', !!f.required, f
        );
        if (!card) return;
        var dropHint = body.querySelector('.nb-row-drop-hint');
        if (dropHint) dropHint.remove();
        _prepCard(card);
        body.appendChild(card);
        window.nbFormBuilder._applyColSpan(card, parseInt(f.col, 10) || 12);
        _restoreFieldState(card, f);
        /* FIX-B: always open body on restore */
        var cb = card.querySelector('.nb-cfield-body');
        if (cb) cb.classList.add('open');
      });
    }
    return row;
  }

  /* ── Attach drag events to a card ──────────────────────────────── */
  function _prepCard(card) {
    if (card._nbCardPrepped) return;
    card._nbCardPrepped = true;
    if (!card.id) card.id = 'nb-card-' + Date.now() + '-' + Math.random().toString(36).slice(2,5);
    card.setAttribute('draggable', 'true');
    card.addEventListener('dragstart', function (ev) {
      ev.dataTransfer.setData('text/nb-card-id', card.id);
      card.classList.add('drag-source');
    });
    card.addEventListener('dragend', function () { card.classList.remove('drag-source'); });
  }

  function _restoreFieldState(card, f) {
    if (!card || !f) return;
    var type = card.dataset.type || '';
    if (type === 'select' || type === 'select2') {
      var selModeEl = card.querySelector('.nu-field-select-mode');
      if (selModeEl) {
        var isMulti = f.multiple === true || f.multiple === 'true' || f.multiple === 1 || f.select_type === 'multiselect';
        selModeEl.value = isMulti ? 'multi' : 'single';
      }
    }
    var map = {
      '.nu-field-required':      'required',
      '.nu-field-no-duplicate':  'no_duplicate',
      '.nu-field-readonly':      'readonly',
      '.nu-field-hidden':        'hidden',
      '.nu-field-hidden-normal': 'hidden_for_normal_users'
    };
    Object.keys(map).forEach(function (sel) {
      var el = card.querySelector(sel);
      if (el) el.checked = !!f[map[sel]];
    });
  }


  /* ════════════════════════════════════════════════════════════════════
     nbFormBuilder public API
  ═══════════════════════════════════════════════════════════════════ */
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
        var el = document.getElementById(id); if (el) el.value = '';
      });
      var ps = document.getElementById('formBrowsePageSize');
      if (ps) ps.value = '20';
      var srch = document.getElementById('formBrowseSearchEnabled');
      if (srch) srch.checked = false;
      var canvas = document.getElementById('formCanvas');
      if (canvas) canvas.querySelectorAll('.nb-row,.nb-container').forEach(function (r) { r.remove(); });
      this._updateEmptyState();
      this.selectFormType('main',         document.querySelector('input[name="formType"][value="main"]')         ? document.querySelector('input[name="formType"][value="main"]').closest('.nb-ftype-card')         : null);
      this.selectTableMode('new',         document.querySelector('input[name="formTableMode"][value="new"]')     ? document.querySelector('input[name="formTableMode"][value="new"]').closest('.nb-tmode-card')     : null);
      this.selectPkType('autoincrement',  document.querySelector('input[name="formPkType"][value="autoincrement"]') ? document.querySelector('input[name="formPkType"][value="autoincrement"]').closest('.nb-pk-card') : null);
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

    /* ── addRow (top-level canvas row) ────────────────────────────── */
    addRow: function () {
      var canvas = document.getElementById('formCanvas');
      if (!canvas) return null;
      var empty = document.getElementById('canvasEmpty');
      if (empty) empty.style.display = 'none';
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
      _wireRowDrag(row);
      var body = row.querySelector('.nb-row-body');
      if (body) _attachRowBodyDrop(body);
      return row;
    },

    /* Public wrapper so onclick="window.nbFormBuilder._addRowToContainer(...)" works */
    _addRowToContainer: function (target) {
      return _addRowToContainer(target, [], false);
    },

    /* ── addField routes group/tab DIRECTLY to canvas ──────── */
    addField: function (type, extraData) {
      var canvas = document.getElementById('formCanvas');
      if (!canvas) return null;
      var extra = extraData || {};
      _attachCanvasRowDrop(canvas);

      /* ── Group/Tab go directly onto canvas — NO row needed ── */
      if (type === 'group') {
        var grp = _makeGroupContainer(extra);
        canvas.appendChild(grp);
        var emptyG = document.getElementById('canvasEmpty');
        if (emptyG) emptyG.style.display = 'none';
        _wireRowDrag(grp);
        return grp;
      }
      if (type === 'tab') {
        var tab = _makeTabContainer(extra);
        canvas.appendChild(tab);
        var emptyT = document.getElementById('canvasEmpty');
        if (emptyT) emptyT.style.display = 'none';
        _wireRowDrag(tab);
        return tab;
      }

      /* ── Regular field — find or create the last canvas row ── */
      var label = extra.label || extra.fieldlabel || (type.charAt(0).toUpperCase() + type.slice(1) + ' Field');
      var name  = extra.name  || extra.fieldname  || (type + '_' + (++_fieldCounter));
      var col   = parseInt(extra.col || extra.colspan, 10) || 12;

      var card = this._makeFieldCard(type, label, name, !!extra.required, extra);
      if (!card) return null;

      /* Find last top-level canvas row (not containers) */
      var canvasRows = canvas.querySelectorAll(':scope > .nb-row');
      var targetBody = canvasRows.length ? canvasRows[canvasRows.length - 1].querySelector('.nb-row-body') : null;
      if (!targetBody) {
        var newRow = this.addRow();
        targetBody = newRow ? newRow.querySelector('.nb-row-body') : null;
      }
      if (!targetBody) { canvas.appendChild(card); return card; }

      var hint = targetBody.querySelector('.nb-row-drop-hint');
      if (hint) hint.remove();

      _prepCard(card);
      targetBody.appendChild(card);
      this._applyColSpan(card, col);

      /* FIX-B: open body immediately so label/name are visible */
      var cb = card.querySelector('.nb-cfield-body');
      if (cb) cb.classList.add('open');

      return card;
    },

    /* ── _makeFieldCard ─────────────────────────────────────────────── */
    _makeFieldCard: function (type, label, name, required, extra) {
      extra = extra || {};
      extra = Object.assign({}, extra, { required: required || !!extra.required });
      var col = parseInt(extra.col || extra.colspan, 10) || 12;

      var canvasType = type;
      if (type === 'multiselect') { canvasType = 'select2'; if (!extra.multiple) extra = Object.assign({}, extra, { multiple: true }); }
      if (type === 'select' && (extra.select2 === true || extra.select2 === 'true' || extra.select2 === 1)) canvasType = 'select2';
      if (type === 'group' || type === 'tab') return null;

      var spanBtns = [3,4,6,8,12].map(function (n) {
        return '<button type="button" class="nb-span-btn' + (n === col ? ' active' : '') + '" data-span="' + n + '">' + n + '</button>';
      }).join('');

      var extraBody = '';

      /* SELECT */
      if (canvasType === 'select') {
        var selIsMulti  = extra.multiple === true || extra.multiple === 'true' || extra.multiple === 1 || extra.select_type === 'multiselect';
        var optSource   = extra.options_source || 'manual';
        var isFromTable = (optSource === 'table');
        var opts = (extra.options || []).map(function (o) { return typeof o === 'object' ? (o.value + '|' + o.label) : o; }).join('\n');
        extraBody +=
          '<div class="nb-fp"><label style="font-size:11px;font-weight:600;">Select Mode</label>'
            + '<select class="nu-input nu-field-select-mode" style="font-size:12px;">'
              + '<option value="single"' + (!selIsMulti ? ' selected' : '') + '>Single</option>'
              + '<option value="multi"'  + ( selIsMulti ? ' selected' : '') + '>Multi-Select</option>'
            + '</select></div>'
          + _optionsSourceHTML(name, isFromTable, opts, extra);
      }

      /* SELECT2 */
      if (canvasType === 'select2') {
        var s2IsMulti     = extra.multiple === true || extra.multiple === 'true' || extra.multiple === 1 || extra.select_type === 'multiselect';
        var allowClearChk = (extra.allow_clear === false || extra.allow_clear === 'false') ? '' : 'checked';
        var s2IsFromTable = (extra.options_source === 'table');
        var s2Opts = (extra.options || []).map(function (o) { return typeof o === 'object' ? (o.value + '|' + o.label) : o; }).join('\n');
        extraBody +=
          '<div style="background:var(--bg-offset,#eef2ff);border:1.5px solid var(--color-primary,#4f6bed);border-radius:8px;padding:12px 14px;grid-column:1/-1;margin-bottom:4px;">'
            + '<div style="font-size:11px;font-weight:700;letter-spacing:.06em;color:var(--color-primary,#4f6bed);margin-bottom:10px;">🔍 SELECT2 CONFIG</div>'
            + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">'
              + '<div><label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;">Mode</label>'
                + '<select class="nu-input nu-field-select-mode" style="font-size:12px;">'
                  + '<option value="single"' + (!s2IsMulti ? ' selected' : '') + '>Single</option>'
                  + '<option value="multi"'  + ( s2IsMulti ? ' selected' : '') + '>Multi-Select</option>'
                + '</select></div>'
              + '<div style="display:flex;align-items:flex-end;padding-bottom:4px;">'
                + '<label class="nb-fp-check" style="font-size:11px;"><input type="checkbox" class="nu-field-allow-clear"' + (allowClearChk ? ' checked' : '') + '> Allow Clear</label>'
              + '</div>'
            + '</div>'
          + '</div>'
          + _optionsSourceHTML(name, s2IsFromTable, s2Opts, extra);
      }

      /* RADIO / CHECKBOX */
      if (canvasType === 'radio' || canvasType === 'checkbox_group') {
        var rcIsFromTable = (extra.options_source === 'table');
        var rcOpts = (extra.options || []).map(function (o) { return typeof o === 'object' ? (o.value + '|' + o.label) : o; }).join('\n');
        extraBody += _optionsSourceHTML(name, rcIsFromTable, rcOpts, extra);
      }

      /* CALCULATED */
      if (canvasType === 'calculated') {
        extraBody += '<div class="nb-fp nb-fp-full"><label>Formula</label><textarea class="nu-input nu-field-formula" rows="2" placeholder="{qty} * {price}">' + _esc(extra.formula || extra.calc_formula || '') + '</textarea></div>';
      }

      /* LOOKUP */
      if (canvasType === 'lookup') {
        var lk = (extra.lookup && typeof extra.lookup === 'object') ? extra.lookup : {};
        extraBody +=
          '<div style="background:var(--bg-offset,#f0f4ff);border:1.5px solid var(--color-primary,#4f6bed);border-radius:8px;padding:12px 14px;margin-top:6px;grid-column:1/-1;">'
            + '<div style="font-size:11px;font-weight:700;letter-spacing:.06em;color:var(--color-primary,#4f6bed);margin-bottom:10px;">🔗 LOOKUP CONFIG</div>'
            + '<div style="margin-bottom:8px;"><label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;">Lookup Table</label><input type="text" class="nu-input nu-lookup-table" value="' + _esc(lk.table || extra.lookup_form || '') + '" placeholder="e.g. customers" style="font-size:12px;width:100%;box-sizing:border-box;"></div>'
            + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px;">'
              + '<div><label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;">Display Col</label><input type="text" class="nu-input nu-lookup-display" value="' + _esc(lk.display_column || extra.lookup_display || '') + '" placeholder="full_name" style="font-size:12px;"></div>'
              + '<div><label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;">Store Col</label><input type="text" class="nu-input nu-lookup-store" value="' + _esc(lk.id_column || extra.lookup_store || '') + '" placeholder="id" style="font-size:12px;"></div>'
            + '</div>'
            + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">'
              + '<div><label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;">Filter SQL</label><input type="text" class="nu-input nu-lookup-filter" value="' + _esc(lk.filter || extra.lookup_filter || '') + '" placeholder="active=1" style="font-size:12px;"></div>'
              + '<div><label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;">Extra Mapping</label><input type="text" class="nu-input nu-lookup-extra" value="' + _esc(lk.extra || extra.lookup_extra || '') + '" placeholder="code:dept_code" style="font-size:12px;"></div>'
            + '</div>'
          + '</div>';
      }

      /* SUBFORM */
      var sfData;
      if (canvasType === 'subform') {
        var sf = (extra.subform && typeof extra.subform === 'object') ? extra.subform : {};
        sfData = {
          form_code:       sf.form_code    || extra.sf_form_code    || '',
          fk_field:        sf.fk_field     || extra.sf_fk_field     || '',
          subform_view:    extra.subform_view || 'grid',
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
          + '<span class="nb-cfield-actions"><button class="nb-cfield-btn del" type="button">✕</button></span>'
        + '</div>'
        + '<div class="nb-span-bar">'
          + '<span class="nb-span-bar-label">Width</span>'
          + spanBtns
          + '<span class="nb-span-preview">' + col + '/12 cols</span>'
        + '</div>'
        + '<div class="nb-cfield-body">'   /* FIX-B: starts hidden, JS adds .open */
          + '<div class="n