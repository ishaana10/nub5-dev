/**
 * nb-subform-fk-builder.js
 * FK-aware subform panel patches for nbFormBuilder.
 *
 * Depends on: nubuilder-next.js, nb-sf-data.js, nb-form-builder-layout.js
 *
 * What this file does:
 *   GAP 1 — injects a rich config panel (child-form dropdown, FK field
 *             dropdown, ”＋ Create FK Field” button, 3 flag toggles) into
 *             every subform card on the builder canvas.
 *
 *   GAP 2 — registers window._nbSfAugmentLayout(layout) so that
 *             nb-form-builder-layout.js can call it from its serialiser
 *             hook, persisting panel values into the saved JSON without
 *             any fetch/saveForm intercept layer.
 *
 *   GAP 3 — _createFkField(): auto-adds a hidden FK field to the child
 *             form’s layout via the API.
 *
 * Changelog:
 *   2026-06-07  Initial saveForm intercept approach.
 *   2026-06-08a Dropdown sel.value fix for edit mode.
 *   2026-06-08b Re-entrant upgradeSubformPanel to handle timing race.
 *   2026-06-08c Refactor: replace saveForm/fetch double-wrap and blind
 *               serialiser loop with _nbSfAugmentLayout hook (called by
 *               nb-form-builder-layout.js). Use window._nbSfData for
 *               all data-sf-* reads/writes.
 */
(function () {
  'use strict';

  function waitForBuilder(cb) {
    if (window.nbFormBuilder && typeof window.nbFormBuilder.addField === 'function') {
      cb();
    } else {
      setTimeout(function () { waitForBuilder(cb); }, 60);
    }
  }

  waitForBuilder(function () {
    var fb = window.nbFormBuilder;

    /* ══════════════════════════════════════════════════════════════════
       GAP 1 — subform panel
    ═══════════════════════════════════════════════════════════════════ */

    // Re-entrant: if panel exists but form-code select is blank,
    // tear down and rebuild now that data-sf-* are available.
    function upgradeSubformPanel(card) {
      if (card._sfPanelUpgraded) {
        var existingPanel = card.querySelector('.nb-sf-fk-panel');
        if (existingPanel) {
          var existingSel = existingPanel.querySelector('.nb-sf-form-code');
          if (existingSel && existingSel.value) return; // already populated
          existingPanel.remove();
        }
        card._sfPanelUpgraded = false;
      }

      card._sfPanelUpgraded = true;

      // Remove legacy bare text input if present
      var oldInput = card.querySelector('.nu-subform-config, input[placeholder*="order_id"], input[data-sf-config]');
      var panelTarget = oldInput
        ? oldInput.parentElement
        : card.querySelector('.nb-field-config, .nb-cfield-config, .nb-sf-config');
      if (!panelTarget) panelTarget = card.querySelector('.nb-cfield-body') || card;
      if (oldInput) oldInput.remove();

      // Read saved data via shared utility
      var existingData = window._nbSfData ? window._nbSfData.read(card) : _localRead(card);
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

    // Minimal local read fallback if nb-sf-data.js hasn't loaded yet
    function _localRead(card) {
      return {
        form_code:       card.dataset.sfFormCode || '',
        fk_field:        card.dataset.sfFkField  || '',
        is_fk:           card.dataset.sfIsFk           === '1',
        hide_in_grid:    card.dataset.sfHideInGrid     === '1',
        server_readonly: card.dataset.sfServerReadonly === '1'
      };
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

    /* ══════════════════════════════════════════════════════════════════
       GAP 2 — register _nbSfAugmentLayout hook
       Called by nb-form-builder-layout.js from its layout serialiser.
    ═══════════════════════════════════════════════════════════════════ */
    function _augmentSubformData(fieldObj, card) {
      if ((fieldObj.type || fieldObj.fieldtype || '') !== 'subform') return fieldObj;
      var panel = card.querySelector('.nb-sf-fk-panel');
      if (!panel) return fieldObj;

      var formCodeSel = panel.querySelector('.nb-sf-form-code');
      var fkFieldSel  = panel.querySelector('.nb-sf-fk-field');
      var isFkChk     = panel.querySelector('.nb-sf-is-fk');
      var hideChk     = panel.querySelector('.nb-sf-hide-in-grid');
      var srvRoChk    = panel.querySelector('.nb-sf-server-readonly');
      var formCode    = formCodeSel ? (formCodeSel.value || '') : '';
      var fkField     = fkFieldSel  ? (fkFieldSel.value  || '') : '';

      if (!fieldObj.subform) fieldObj.subform = {};
      if (formCode) fieldObj.subform.form_code = formCode;
      if (fkField)  fieldObj.subform.fk_field  = fkField;
      if (isFkChk)  { if (isFkChk.checked)  fieldObj.is_fk           = true; else delete fieldObj.is_fk;           }
      if (hideChk)  { if (hideChk.checked)   fieldObj.hide_in_grid    = true; else delete fieldObj.hide_in_grid;    }
      if (srvRoChk) { if (srvRoChk.checked)  fieldObj.server_readonly = true; else delete fieldObj.server_readonly; }

      // Write back to shared data store
      if (window._nbSfData) {
        window._nbSfData.write(card, {
          form_code:       formCode,
          fk_field:        fkField,
          is_fk:           !!(isFkChk  && isFkChk.checked),
          hide_in_grid:    !!(hideChk  && hideChk.checked),
          server_readonly: !!(srvRoChk && srvRoChk.checked)
        });
      }

      return fieldObj;
    }

    function _augmentLayout(layout) {
      if (!Array.isArray(layout)) return layout;
      var canvas = document.getElementById('formCanvas');
      if (!canvas) return layout;
      var cards = canvas.querySelectorAll('.nb-cfield[data-type="subform"], .nb-cfield[data-fieldtype="subform"]');
      layout.forEach(function (fieldObj) {
        if ((fieldObj.type || '') !== 'subform') return;
        var fname = fieldObj.name || fieldObj.fieldname || '';
        var matchCard = null;
        cards.forEach(function (c) {
          if ((c.dataset.fieldName || c.dataset.name || '') === fname) matchCard = c;
        });
        if (!matchCard && cards.length === 1) matchCard = cards[0];
        if (matchCard) _augmentSubformData(fieldObj, matchCard);
      });
      return layout;
    }

    // Expose as the hook nb-form-builder-layout.js will call
    window._nbSfAugmentLayout = _augmentLayout;

    /* ══════════════════════════════════════════════════════════════════
       GAP 3 — createFkField()
    ═══════════════════════════════════════════════════════════════════ */
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

          layout.push({
            name: fkName, label: fkName, type: 'hidden',
            is_fk: true, hide_in_grid: true, server_readonly: true
          });

          return fetch(
            'api/forms.php?action=patch_layout&id=' + encodeURIComponent(form.form_id || form.id || ''),
            {
              method: 'POST', credentials: 'same-origin',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ form_layout: JSON.stringify(layout) })
            }
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

    /* ══════════════════════════════════════════════════════════════════
       Auto-upgrade: MutationObserver + event hooks
    ═══════════════════════════════════════════════════════════════════ */
    function upgradeAllSubformCards() {
      var canvas = document.getElementById('formCanvas');
      if (!canvas) return;
      canvas.querySelectorAll(
        '.nb-cfield[data-type="subform"], .nb-cfield[data-fieldtype="subform"]'
      ).forEach(upgradeSubformPanel);
    }

    window._nbSfUpgradeAll = upgradeAllSubformCards;

    var _obs = new MutationObserver(function (mutations) {
      mutations.forEach(function (m) {
        m.addedNodes.forEach(function (node) {
          if (node.nodeType !== 1) return;
          if (node.dataset && (node.dataset.type || node.dataset.fieldtype || '') === 'subform')
            upgradeSubformPanel(node);
          if (node.querySelectorAll)
            node.querySelectorAll('.nb-cfield[data-type="subform"], .nb-cfield[data-fieldtype="subform"]')
              .forEach(upgradeSubformPanel);
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
      setTimeout(function () {
        attachObserver();
        upgradeAllSubformCards();
      }, 150);
    });

    if (typeof fb._rebuildCanvas === 'function') {
      var _origRebuild = fb._rebuildCanvas.bind(fb);
      fb._rebuildCanvas = function () {
        var result = _origRebuild.apply(fb, arguments);
        setTimeout(function () { attachObserver(); upgradeAllSubformCards(); }, 120);
        if (result && typeof result.then === 'function')
          result.then(function () { setTimeout(function () { attachObserver(); upgradeAllSubformCards(); }, 120); });
        return result;
      };
    }

    if (typeof fb._initAfterLoad === 'function') {
      var _origInit = fb._initAfterLoad.bind(fb);
      fb._initAfterLoad = function () {
        var result = _origInit.apply(fb, arguments);
        setTimeout(function () { attachObserver(); upgradeAllSubformCards(); }, 200);
        return result;
      };
    }

    window.nbCreateFkField = _createFkField;
    console.log('[nb-subform-fk-builder] FK-aware subform panel patches applied.');
  });

})();
