/**
 * nb-subform-fk-builder.js
 * FK-aware subform panel patches for nbFormBuilder.
 *
 * 2026-06-08g  Root-cause fix: broadened subform card detection to use
 *              ALL possible data-type variants plus data-fieldJson fallback.
 *              _augmentLayout now reads live panel values directly
 *              (not just data-sf-* which may not be stamped yet).
 *              saveForm wrap kept as belt-and-braces flush.
 */
(function () {
  'use strict';

  /* ══════════════════════════════════════════════════════════════════
     Utilities
  ═══════════════════════════════════════════════════════════════════ */

  /** Return true if this canvas card is a subform field, regardless of
   *  which data-* attribute nubuilder-next.js happens to use.
   */
  function _isSubformCard(card) {
    // Try every known attribute
    var t = card.dataset.type
         || card.dataset.fieldtype
         || card.dataset.fieldType
         || card.dataset.nbType
         || card.dataset.ftype
         || '';
    if (t === 'subform') return true;

    // Badge text inside card
    var badge = card.querySelector('.nb-cfield-type-badge');
    if (badge && badge.textContent.trim().toLowerCase() === 'subform') return true;

    // data-fieldJson set by _rebuildCanvas
    var raw = card.dataset.fieldJson || card.dataset.fieldData || '';
    if (raw) {
      try {
        var obj = JSON.parse(raw);
        if ((obj.type || obj.fieldtype || '') === 'subform') return true;
      } catch (e) {}
    }
    return false;
  }

  /** Get all subform cards from the canvas. */
  function _getSubformCards() {
    var canvas = document.getElementById('formCanvas');
    if (!canvas) return [];
    return Array.prototype.slice.call(canvas.querySelectorAll('.nb-cfield'))
      .filter(_isSubformCard);
  }

  /** Read subform config from a card — live panel values take priority,
   *  then data-sf-*, then data-fieldJson. */
  function _readCardConfig(card) {
    var formCode = '', fkField = '', isFk = false, hideGrid = false, srvRo = false;

    // 1. Live panel UI (most authoritative)
    var panel = card.querySelector('.nb-sf-fk-panel');
    if (panel) {
      var formCodeSel = panel.querySelector('.nb-sf-form-code');
      var fkFieldSel  = panel.querySelector('.nb-sf-fk-field');
      var isFkChk     = panel.querySelector('.nb-sf-is-fk');
      var hideChk     = panel.querySelector('.nb-sf-hide-in-grid');
      var srvRoChk    = panel.querySelector('.nb-sf-server-readonly');
      if (formCodeSel) formCode = formCodeSel.value || '';
      if (fkFieldSel)  fkField  = fkFieldSel.value  || '';
      if (isFkChk)  isFk     = isFkChk.checked;
      if (hideChk)  hideGrid = hideChk.checked;
      if (srvRoChk) srvRo    = srvRoChk.checked;
    }

    // 2. data-sf-* fallback
    if (!formCode) formCode = card.dataset.sfFormCode || '';
    if (!fkField)  fkField  = card.dataset.sfFkField  || '';
    if (!isFk)     isFk     = card.dataset.sfIsFk           === '1';
    if (!hideGrid) hideGrid = card.dataset.sfHideInGrid     === '1';
    if (!srvRo)    srvRo    = card.dataset.sfServerReadonly === '1';

    // 3. data-fieldJson fallback
    if (!formCode) {
      var raw = card.dataset.fieldJson || card.dataset.fieldData || '';
      if (raw) {
        try {
          var obj = JSON.parse(raw);
          var sf = (obj.subform && typeof obj.subform === 'object') ? obj.subform : {};
          formCode = sf.form_code || sf.formcode || '';
          fkField  = sf.fk_field  || sf.fkfield  || '';
          if (!isFk)     isFk     = !!obj.is_fk;
          if (!hideGrid) hideGrid = !!obj.hide_in_grid;
          if (!srvRo)    srvRo    = !!obj.server_readonly;
        } catch (e) {}
      }
    }

    return { form_code: formCode, fk_field: fkField, is_fk: isFk, hide_in_grid: hideGrid, server_readonly: srvRo };
  }

  /** Flush panel values into data-sf-* on every subform card. */
  function _flushAllPanels() {
    _getSubformCards().forEach(function (card) {
      var cfg = _readCardConfig(card);
      card.dataset.sfFormCode       = cfg.form_code;
      card.dataset.sfFkField        = cfg.fk_field;
      card.dataset.sfIsFk           = cfg.is_fk     ? '1' : '0';
      card.dataset.sfHideInGrid     = cfg.hide_in_grid    ? '1' : '0';
      card.dataset.sfServerReadonly = cfg.server_readonly ? '1' : '0';
      if (window._nbSfData) window._nbSfData.write(card, cfg);
    });
  }

  /* ══════════════════════════════════════════════════════════════════
     _nbSfAugmentLayout — called by the getLayout wrapper in
     nb-form-builder-layout.js (and by _flushAndSave as belt-and-braces).
     Positionally matches subform entries in the layout array to
     subform cards on the canvas, then injects config.
  ═══════════════════════════════════════════════════════════════════ */
  function _augmentLayout(layout) {
    if (!Array.isArray(layout)) return layout;
    var sfCards = _getSubformCards();
    var sfIndex = 0;
    layout.forEach(function (fieldObj) {
      if ((fieldObj.type || fieldObj.fieldtype || '') !== 'subform') return;
      var card = sfCards[sfIndex++] || null;
      if (!card) return;

      var cfg = _readCardConfig(card);
      console.log('[nb-subform-fk-builder] augment subform[' + (sfIndex-1) + ']', cfg);

      if (!fieldObj.subform) fieldObj.subform = {};
      if (cfg.form_code) fieldObj.subform.form_code = cfg.form_code;
      if (cfg.fk_field)  fieldObj.subform.fk_field  = cfg.fk_field;
      if (cfg.is_fk)     fieldObj.is_fk           = true; else delete fieldObj.is_fk;
      if (cfg.hide_in_grid)    fieldObj.hide_in_grid    = true; else delete fieldObj.hide_in_grid;
      if (cfg.server_readonly) fieldObj.server_readonly = true; else delete fieldObj.server_readonly;
    });
    return layout;
  }
  window._nbSfAugmentLayout = _augmentLayout;

  /* ══════════════════════════════════════════════════════════════════
     waitForBuilder + main init
  ═══════════════════════════════════════════════════════════════════ */
  function waitForBuilder(cb) {
    if (window.nbFormBuilder && typeof window.nbFormBuilder.addField === 'function') {
      cb();
    } else {
      setTimeout(function () { waitForBuilder(cb); }, 60);
    }
  }

  waitForBuilder(function () {
    var fb = window.nbFormBuilder;

    /* ══ Wrap saveForm (belt-and-braces) ════════════════════════════════════ */
    function _wrapSaveForm(target, key) {
      if (!target || typeof target[key] !== 'function') return false;
      if (target[key]._sfWrapped) return true;
      var _orig = target[key].bind(target);
      target[key] = function () {
        _flushAllPanels();
        return _orig.apply(target, arguments);
      };
      target[key]._sfWrapped = true;
      return true;
    }
    _wrapSaveForm(fb, 'saveForm');
    var _sfPollCount = 0;
    var _sfPoll = setInterval(function () {
      if (_wrapSaveForm(window, 'saveForm')) { clearInterval(_sfPoll); return; }
      if (++_sfPollCount > 100) clearInterval(_sfPoll);
    }, 50);

    /* ══ Also wrap getLayout so augment fires even without saveForm wrap ══ */
    if (typeof fb.getLayout === 'function' && !fb.getLayout._sfWrapped) {
      var _origGL = fb.getLayout.bind(fb);
      fb.getLayout = function () {
        var layout = _origGL.apply(fb, arguments);
        return _augmentLayout(layout);
      };
      fb.getLayout._sfWrapped = true;
    }

    /* ══ Panel injection ══════════════════════════════════════════════ */
    function upgradeSubformPanel(card) {
      if (!_isSubformCard(card)) return;
      if (card._sfPanelUpgraded) {
        var existingPanel = card.querySelector('.nb-sf-fk-panel');
        if (existingPanel) {
          var existingSel = existingPanel.querySelector('.nb-sf-form-code');
          if (existingSel && existingSel.value) return;
          existingPanel.remove();
        }
        card._sfPanelUpgraded = false;
      }
      card._sfPanelUpgraded = true;

      var oldInput = card.querySelector('.nu-subform-config, input[placeholder*="order_id"], input[data-sf-config]');
      var panelTarget = oldInput
        ? oldInput.parentElement
        : card.querySelector('.nb-field-config, .nb-cfield-config, .nb-sf-config');
      if (!panelTarget) panelTarget = card.querySelector('.nb-cfield-body') || card;
      if (oldInput) oldInput.remove();

      var existingData = window._nbSfData ? window._nbSfData.read(card) : _readCardConfig(card);
      panelTarget.insertAdjacentHTML('beforeend', _subformPanelHTML(existingData));

      var panel = panelTarget.querySelector('.nb-sf-fk-panel');
      if (!panel) return;

      _populateFormDropdown(panel, existingData.form_code, function () {
        if (existingData.form_code) _populateFkDropdown(panel, existingData.form_code, existingData.fk_field);
      });

      var formSel = panel.querySelector('.nb-sf-form-code');
      if (formSel) {
        formSel.addEventListener('change', function () {
          _populateFkDropdown(panel, formSel.value, '');
        });
      }
      var createBtn = panel.querySelector('.nb-sf-create-fk');
      if (createBtn) createBtn.addEventListener('click', function () { _createFkField(panel); });
    }

    function _subformPanelHTML(d) {
      var isFk     = d.is_fk           ? 'checked' : '';
      var hideGrid = d.hide_in_grid    ? 'checked' : '';
      var srvRo    = d.server_readonly ? 'checked' : '';
      return [
        '<div class="nb-sf-fk-panel" style="display:flex;flex-direction:column;gap:8px;padding:10px 0;">',
          '<div>',
            '<label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;color:var(--text-muted,#666);">Child Form</label>',
            '<select class="nu-input nb-sf-form-code" style="width:100%;"><option value="">— select form —</option></select>',
          '</div>',
          '<div>',
            '<label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;color:var(--text-muted,#666);">FK Field (links child → parent)</label>',
            '<div style="display:flex;gap:6px;">',
              '<select class="nu-input nb-sf-fk-field" style="flex:1;"><option value="">— select FK field —</option></select>',
              '<button type="button" class="nu-btn nu-btn-ghost nu-btn-sm nb-sf-create-fk" title="Auto-create hidden FK field in child form">＋ Create FK Field</button>',
            '</div>',
          '</div>',
          '<div style="display:flex;flex-direction:column;gap:4px;padding:6px 8px;background:var(--bg-elevated,#f8f9fa);border-radius:6px;border:1px solid var(--border,#e0e0e0);">',
            '<label style="font-size:11px;font-weight:700;color:var(--text-muted,#888);text-transform:uppercase;letter-spacing:.5px;margin-bottom:2px;">FK Field Flags</label>',
            _toggleRow('nb-sf-is-fk',           'is_fk',           isFk,     'FK field',        'Force hidden; builder locks this field'),
            _toggleRow('nb-sf-hide-in-grid',    'hide_in_grid',    hideGrid, 'Hide in grid',    'Excludes column from subform table'),
            _toggleRow('nb-sf-server-readonly', 'server_readonly', srvRo,    'Server readonly', 'PHP ignores POST value; always writes parent ID'),
          '</div>',
        '</div>'
      ].join('');
    }

    function _toggleRow(cls, dataKey, checkedAttr, label, hint) {
      return '<label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:12px;">'
        + '<input type="checkbox" class="' + cls + '" data-fk-flag="' + dataKey + '" ' + checkedAttr + '>'
        + '<span><strong>' + label + '</strong>'
        + (hint ? ' <span style="color:var(--text-muted,#999);font-size:11px;">— ' + hint + '</span>' : '')
        + '</span></label>';
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
            opt.textContent = (f.form_name || f.name || f.form_code || '') + ' (' + opt.value + ')';
            sel.appendChild(opt);
          });
          if (selectedCode) {
            sel.value = selectedCode;
            if (sel.value !== selectedCode) {
              var missing = document.createElement('option');
              missing.value = selectedCode;
              missing.textContent = selectedCode + ' (saved)';
              sel.insertBefore(missing, sel.options[1] || null);
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
      fetch('api/form.php?action=subform_fields&code=' + encodeURIComponent(formCode), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (json) {
          var fields = (json && json.success && json.data)
            ? (json.data.all_fields || json.data.layout || []) : [];
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
              var missing = document.createElement('option');
              missing.value = selectedFk;
              missing.textContent = selectedFk + ' (saved)';
              sel.insertBefore(missing, sel.options[1] || null);
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
      if (!formCode) { _sfToast('Select a child form first', 'error'); return; }
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
            _sfToast('Field "' + fkName + '" already exists in ' + formCode, 'error');
            return null;
          }
          layout.push({ name: fkName, label: fkName, type: 'hidden', is_fk: true, hide_in_grid: true, server_readonly: true });
          return fetch(
            'api/forms.php?action=patch_layout&id=' + encodeURIComponent(form.form_id || form.id || ''),
            { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ form_layout: JSON.stringify(layout) }) }
          ).then(function (r) { return r.json(); });
        })
        .then(function (saveJson) {
          if (!saveJson) return;
          if (!saveJson.success) { _sfToast(saveJson.error || 'Save failed', 'error'); return; }
          _sfToast('FK field "' + fkName + '" created in child form ' + formCode);
          _populateFkDropdown(panel, formCode, fkName);
        })
        .catch(function (e) { _sfToast('Error: ' + e.message, 'error'); });
    }

    function _sfToast(msg, type) {
      if (window.NuApp && NuApp.toast) { NuApp.toast(msg, type); return; }
      alert(msg);
    }

    /* ══ MutationObserver auto-upgrade ══════════════════════════════════════ */
    function upgradeAllSubformCards() {
      var canvas = document.getElementById('formCanvas');
      if (!canvas) return;
      canvas.querySelectorAll('.nb-cfield').forEach(upgradeSubformPanel);
    }
    window._nbSfUpgradeAll = upgradeAllSubformCards;

    var _obs = new MutationObserver(function (mutations) {
      mutations.forEach(function (m) {
        m.addedNodes.forEach(function (node) {
          if (node.nodeType !== 1) return;
          if (_isSubformCard(node)) upgradeSubformPanel(node);
          if (node.querySelectorAll)
            node.querySelectorAll('.nb-cfield').forEach(function (c) {
              if (_isSubformCard(c)) upgradeSubformPanel(c);
            });
        });
      });
    });

    function attachObserver() {
      var canvas = document.getElementById('formCanvas');
      if (!canvas) return;
      try { _obs.disconnect(); } catch (e) {}
      _obs.observe(canvas, { childList: true, subtree: true });
    }

    attachObserver();
    upgradeAllSubformCards();

    document.addEventListener('nu:form:opened', function () {
      setTimeout(function () { attachObserver(); upgradeAllSubformCards(); }, 150);
    });

    if (typeof fb._initAfterLoad === 'function') {
      var _origInit = fb._initAfterLoad.bind(fb);
      fb._initAfterLoad = function () {
        var result = _origInit.apply(fb, arguments);
        setTimeout(function () { attachObserver(); upgradeAllSubformCards(); }, 200);
        return result;
      };
    }

    window.nbCreateFkField = _createFkField;
    console.log('[nb-subform-fk-builder] ready. _isSubformCard uses badge+fieldJson fallback.');
  });

})();
