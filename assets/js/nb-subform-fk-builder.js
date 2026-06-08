/**
 * nb-subform-fk-builder.js
 * FK-aware subform panel patches for nbFormBuilder.
 *
 * GAP 1 — _fieldPanel() subform section
 *   Replaces the single bare text-input with a proper panel:
 *     • Child form dropdown  (fetched from api/forms.php?action=list)
 *     • FK field dropdown    (fetched from that child form's layout)
 *     • "＋ Create FK Field" button
 *     • 3 flag toggles: is_fk, hide_in_grid, server_readonly
 *
 * GAP 2 — layout serialiser (_readFieldCard)
 *   Persists new flags when collecting layout JSON.
 *   Also writes data-sf-* back onto the card element after serialising
 *   so the fast-path in _readSubformData fires cleanly on next edit.
 *
 * GAP 3 — createFkField()
 *   Auto-adds a hidden FK field to the child form's layout.
 *
 * FIX (2026-06-07) — saveForm intercept
 *   The actual save path calls window.saveForm() which reads the canvas
 *   directly. We intercept window.saveForm and post-process the layout
 *   JSON string inside the payload before the fetch fires.
 *
 * FIX (2026-06-08a) — dropdown population in edit mode
 *   _populateFormDropdown and _populateFkDropdown now call sel.value
 *   after appending options so the browser honours the selection.
 *
 * FIX (2026-06-08b) — panel missing entirely in edit mode
 *   upgradeSubformPanel was locking _sfPanelUpgraded=true before
 *   _rebuildCanvas in nb-form-edit.js had a chance to stamp data-sf-*
 *   on the card (the MutationObserver fires synchronously during addField).
 *   Fix: upgradeSubformPanel is now re-entrant — it clears its own lock
 *   and removes any existing .nb-sf-fk-panel before rebuilding.
 *   nb-form-edit.js deletes _sfPanelUpgraded after stamping data-sf-*
 *   and calls window._nbSfUpgradeAll() to trigger a clean re-upgrade.
 */
(function () {
  'use strict';

  /* ── wait for nbFormBuilder ─────────────────────────────────────── */
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
       GAP 1 — patch _fieldPanel() subform section
    ═══════════════════════════════════════════════════════════════════ */

    // ─────────────────────────────────────────────────────────────────
    // upgradeSubformPanel
    // RE-ENTRANT (FIX 2026-06-08b):
    // Previously gated by card._sfPanelUpgraded which was set the first
    // time the MutationObserver fired (during addField, before data-sf-*
    // were available). Now we allow a second pass: if the card has
    // data-sf-* attributes but the panel dropdowns are empty, we tear
    // down the old panel and rebuild with correct data.
    // nb-form-edit.js deletes _sfPanelUpgraded after stamping to trigger
    // this re-upgrade path.
    // ─────────────────────────────────────────────────────────────────
    function upgradeSubformPanel(card) {
      // If already upgraded AND the panel exists AND the form-code select
      // already has a non-blank value, nothing to do.
      if (card._sfPanelUpgraded) {
        var existingPanel = card.querySelector('.nb-sf-fk-panel');
        if (existingPanel) {
          var existingSel = existingPanel.querySelector('.nb-sf-form-code');
          if (existingSel && existingSel.value) {
            // Panel is already correctly populated — skip.
            return;
          }
          // Panel exists but is blank — tear it down for a fresh build.
          existingPanel.remove();
        }
        // Clear the lock so we rebuild below.
        card._sfPanelUpgraded = false;
      }

      card._sfPanelUpgraded = true;

      var oldInput = card.querySelector('.nu-subform-config, input[placeholder*="order_id"], input[data-sf-config]');
      var panelTarget = oldInput
        ? oldInput.parentElement
        : card.querySelector('.nb-field-config, .nb-cfield-config, .nb-sf-config');
      if (!panelTarget) {
        panelTarget = card.querySelector('.nb-cfield-body') || card;
      }
      if (oldInput) oldInput.remove();

      var existingData = _readSubformData(card);
      panelTarget.insertAdjacentHTML('beforeend', _subformPanelHTML(existingData));

      var panel = panelTarget.querySelector('.nb-sf-fk-panel');
      if (!panel) return;

      _populateFormDropdown(panel, existingData.form_code, function () {
        if (existingData.form_code) {
          _populateFkDropdown(panel, existingData.form_code, existingData.fk_field);
        }
      });

      var formSel = panel.querySelector('.nb-sf-form-code');
      if (formSel) {
        formSel.addEventListener('change', function () {
          _populateFkDropdown(panel, formSel.value, '');
        });
      }

      var createBtn = panel.querySelector('.nb-sf-create-fk');
      if (createBtn) {
        createBtn.addEventListener('click', function () {
          _createFkField(panel);
        });
      }
    }

    function _subformPanelHTML(d) {
      var isFk     = d.is_fk           ? 'checked' : '';
      var hideGrid = d.hide_in_grid    ? 'checked' : '';
      var srvRo    = d.server_readonly ? 'checked' : '';
      return [
        '<div class="nb-sf-fk-panel" style="display:flex;flex-direction:column;gap:8px;padding:10px 0;">',
          '<div>',
            '<label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;color:var(--text-muted,#666);">Child Form</label>',
            '<select class="nu-input nb-sf-form-code" style="width:100%;">',
              '<option value="">— select form —</option>',
            '</select>',
          '</div>',
          '<div>',
            '<label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;color:var(--text-muted,#666);">FK Field (links child → parent)</label>',
            '<div style="display:flex;gap:6px;">',
              '<select class="nu-input nb-sf-fk-field" style="flex:1;">',
                '<option value="">— select FK field —</option>',
              '</select>',
              '<button type="button" class="nu-btn nu-btn-ghost nu-btn-sm nb-sf-create-fk" title="Auto-create hidden FK field in child form">＋ Create FK Field</button>',
            '</div>',
          '</div>',
          '<div style="display:flex;flex-direction:column;gap:4px;padding:6px 8px;background:var(--bg-elevated,#f8f9fa);border-radius:6px;border:1px solid var(--border,#e0e0e0);">',
            '<label style="font-size:11px;font-weight:700;color:var(--text-muted,#888);text-transform:uppercase;letter-spacing:.5px;margin-bottom:2px;">FK Field Flags</label>',
            _toggleRow('is_fk',           'nb-sf-is-fk',           'is_fk',           isFk,     'FK field',        'Force hidden; builder locks this field'),
            _toggleRow('hide_in_grid',    'nb-sf-hide-in-grid',    'hide_in_grid',    hideGrid,  'Hide in grid',    'Excludes column from subform table'),
            _toggleRow('server_readonly', 'nb-sf-server-readonly', 'server_readonly', srvRo,     'Server readonly', 'PHP ignores POST value; always writes parent ID'),
          '</div>',
        '</div>'
      ].join('');
    }

    function _toggleRow(key, cls, dataKey, checkedAttr, label, hint) {
      return [
        '<label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:12px;">',
          '<input type="checkbox" class="' + cls + '" data-fk-flag="' + dataKey + '" ' + checkedAttr + '>',
          '<span><strong>' + label + '</strong>',
            hint ? ' <span style="color:var(--text-muted,#999);font-size:11px;">— ' + hint + '</span>' : '',
          '</span>',
        '</label>'
      ].join('');
    }

    // ─────────────────────────────────────────────────────────────────
    // _populateFormDropdown
    // FIX (2026-06-08a): explicitly set sel.value after all options are
    // appended so the browser honours the selection.
    // ─────────────────────────────────────────────────────────────────
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
          // FIX: force the value after all options are in the DOM
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

    // ─────────────────────────────────────────────────────────────────
    // _populateFkDropdown
    // FIX (2026-06-08a): explicitly set sel.value after all options.
    // ─────────────────────────────────────────────────────────────────
    function _populateFkDropdown(panel, formCode, selectedFk) {
      var sel = panel.querySelector('.nb-sf-fk-field');
      if (!sel || !formCode) return;
      fetch('api/form.php?action=subform_fields&code=' + encodeURIComponent(formCode), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (json) {
          var fields = (json && json.success && json.data)
            ? (json.data.all_fields || json.data.layout || [])
            : [];
          while (sel.options.length > 1) sel.remove(1);
          fields.forEach(function (f) {
            var fname = f.name || f.fieldname || '';
            var ftype = f.type || f.fieldtype || 'text';
            if (!fname) return;
            if (['html','heading','divider','fieldset','subform','button'].indexOf(ftype) !== -1) return;
            var opt = document.createElement('option');
            opt.value = fname;
            opt.textContent = (f.label || f.fieldlabel || fname) + ' [' + fname + ']';
            sel.appendChild(opt);
          });
          // FIX: force the value after all options are in the DOM
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

    // ─────────────────────────────────────────────────────────────────
    // _readSubformData — 3-tier fallback
    // ─────────────────────────────────────────────────────────────────
    function _readSubformData(card) {
      /* 1. Fast path: data-sf-* */
      var sfFormCode = card.dataset.sfFormCode || '';
      if (sfFormCode) {
        return {
          form_code:       sfFormCode,
          fk_field:        card.dataset.sfFkField        || '',
          is_fk:           card.dataset.sfIsFk           === '1',
          hide_in_grid:    card.dataset.sfHideInGrid     === '1',
          server_readonly: card.dataset.sfServerReadonly === '1',
        };
      }

      /* 2. Fallback: full field JSON blob stamped by _rebuildCanvas */
      var raw = card.dataset.fieldJson || card.dataset.fieldData || '';
      if (raw) {
        try {
          var obj = JSON.parse(raw);
          var sf  = (obj.subform && typeof obj.subform === 'object') ? obj.subform : {};
          var fc  = sf.form_code || sf.formcode || '';
          var fk  = sf.fk_field  || sf.fkfield  || '';
          if (fc) {
            card.dataset.sfFormCode = fc;
            if (fk)                  card.dataset.sfFkField        = fk;
            if (obj.is_fk)           card.dataset.sfIsFk           = '1';
            if (obj.hide_in_grid)    card.dataset.sfHideInGrid     = '1';
            if (obj.server_readonly) card.dataset.sfServerReadonly = '1';
            return {
              form_code:       fc,
              fk_field:        fk,
              is_fk:           !!obj.is_fk,
              hide_in_grid:    !!obj.hide_in_grid,
              server_readonly: !!obj.server_readonly,
            };
          }
        } catch (e) {}
      }

      /* 3. Legacy attribute names */
      return {
        form_code:       card.dataset.subformFormCode || card.dataset.formCode || '',
        fk_field:        card.dataset.subformFkField  || card.dataset.fkField  || '',
        is_fk:           false,
        hide_in_grid:    false,
        server_readonly: false,
      };
    }

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

          var exists = layout.some(function (f) {
            return (f.name || f.fieldname || '') === fkName;
          });
          if (exists) {
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
       GAP 2 — patch layout serialiser
    ═══════════════════════════════════════════════════════════════════ */
    function _augmentSubformData(fieldObj, card) {
      if ((fieldObj.type || fieldObj.fieldtype || '') !== 'subform') return fieldObj;
      var panel       = card.querySelector('.nb-sf-fk-panel');
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

      if (formCode) card.dataset.sfFormCode       = formCode;
      if (fkField)  card.dataset.sfFkField        = fkField;
      card.dataset.sfIsFk           = isFkChk  && isFkChk.checked  ? '1' : '0';
      card.dataset.sfHideInGrid     = hideChk   && hideChk.checked  ? '1' : '0';
      card.dataset.sfServerReadonly = srvRoChk  && srvRoChk.checked ? '1' : '0';

      return fieldObj;
    }

    function _injectSubformDataIntoLayout(layout) {
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

    var _serializerNames = [
      'getLayout', 'collectLayout', '_serializeCanvas', 'serializeLayout', '_getLayout',
      'readLayout', 'buildLayout', 'exportLayout', 'toJSON', 'getFieldLayout'
    ];
    _serializerNames.forEach(function (methodName) {
      if (typeof fb[methodName] !== 'function') return;
      var _orig = fb[methodName].bind(fb);
      fb[methodName] = function () {
        var layout = _orig.apply(fb, arguments);
        return _injectSubformDataIntoLayout(layout);
      };
    });

    /* ══════════════════════════════════════════════════════════════════
       PRIMARY FIX — intercept window.saveForm
    ═══════════════════════════════════════════════════════════════════ */
    function _installSaveFormIntercept() {
      if (!window.saveForm || window._nbSfSaveFormPatched) return;
      window._nbSfSaveFormPatched = true;

      var _origSaveForm = window.saveForm;

      window.saveForm = function () {
        var _origFetch = window.fetch;
        var _intercepted = false;

        window.fetch = function (url, options) {
          if (!_intercepted && typeof url === 'string' && url.indexOf('forms.php') !== -1 && url.indexOf('action=save') !== -1) {
            _intercepted = true;
            window.fetch = _origFetch;

            try {
              if (options && options.body) {
                var body = typeof options.body === 'string' ? JSON.parse(options.body) : options.body;
                if (body && body.form_layout) {
                  var layoutArr;
                  try {
                    layoutArr = typeof body.form_layout === 'string'
                      ? JSON.parse(body.form_layout)
                      : body.form_layout;
                  } catch (e) { layoutArr = null; }
                  if (Array.isArray(layoutArr)) {
                    layoutArr = _injectSubformDataIntoLayout(layoutArr);
                    body.form_layout = JSON.stringify(layoutArr);
                    options = Object.assign({}, options, { body: JSON.stringify(body) });
                  }
                }
              }
            } catch (e) {
              console.warn('[nb-subform-fk-builder] saveForm intercept error:', e);
            }

            return _origFetch.call(window, url, options);
          }

          return _origFetch.apply(window, arguments);
        };

        return _origSaveForm.apply(this, arguments);
      };

      console.log('[nb-subform-fk-builder] saveForm intercept installed.');
    }

    if (window.saveForm) {
      _installSaveFormIntercept();
    } else {
      var _sfPollCount = 0;
      var _sfPoll = setInterval(function () {
        _sfPollCount++;
        if (window.saveForm) {
          clearInterval(_sfPoll);
          _installSaveFormIntercept();
        } else if (_sfPollCount > 100) {
          clearInterval(_sfPoll);
          console.warn('[nb-subform-fk-builder] saveForm not found after polling.');
        }
      }, 100);
    }

    /* ══════════════════════════════════════════════════════════════════
       Auto-upgrade: find every subform card and inject the panel
    ═══════════════════════════════════════════════════════════════════ */
    function upgradeAllSubformCards() {
      var canvas = document.getElementById('formCanvas');
      if (!canvas) return;
      canvas.querySelectorAll(
        '.nb-cfield[data-type="subform"], .nb-cfield[data-fieldtype="subform"]'
      ).forEach(upgradeSubformPanel);
    }

    // Expose so nb-form-edit.js can call it after _rebuildCanvas.
    window._nbSfUpgradeAll = upgradeAllSubformCards;

    /* ── MutationObserver: upgrade cards as they are added ─────────── */
    var _obs = new MutationObserver(function (mutations) {
      mutations.forEach(function (m) {
        m.addedNodes.forEach(function (node) {
          if (node.nodeType !== 1) return;
          var type = node.dataset && (node.dataset.type || node.dataset.fieldtype || '');
          if (type === 'subform') upgradeSubformPanel(node);
          if (node.querySelectorAll) {
            node.querySelectorAll(
              '.nb-cfield[data-type="subform"], .nb-cfield[data-fieldtype="subform"]'
            ).forEach(upgradeSubformPanel);
          }
        });
      });
    });

    function attachObserver() {
      var canvas = document.getElementById('formCanvas');
      if (!canvas) return;
      try { _obs.disconnect(); } catch(e) {}
      _obs.observe(canvas, { childList: true, subtree: true });
    }

    attachObserver();
    upgradeAllSubformCards();

    document.addEventListener('nu:form:opened', function () {
      setTimeout(function () {
        attachObserver();
        upgradeAllSubformCards();
        _installSaveFormIntercept();
      }, 150);
    });

    if (typeof fb._rebuildCanvas === 'function') {
      var _origRebuild = fb._rebuildCanvas.bind(fb);
      fb._rebuildCanvas = function () {
        var result = _origRebuild.apply(fb, arguments);
        setTimeout(function () {
          attachObserver();
          upgradeAllSubformCards();
        }, 120);
        if (result && typeof result.then === 'function') {
          result.then(function () {
            setTimeout(function () {
              attachObserver();
              upgradeAllSubformCards();
            }, 120);
          });
        }
        return result;
      };
    }

    if (typeof fb._initAfterLoad === 'function') {
      var _origInit = fb._initAfterLoad.bind(fb);
      fb._initAfterLoad = function () {
        var result = _origInit.apply(fb, arguments);
        setTimeout(function () {
          attachObserver();
          upgradeAllSubformCards();
          _installSaveFormIntercept();
        }, 200);
        return result;
      };
    }

    window.nbCreateFkField = _createFkField;
    console.log('[nb-subform-fk-builder] FK-aware subform panel patches applied.');
  });

})();
