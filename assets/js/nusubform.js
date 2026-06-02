/**
 * nuSubform — runtime subform engine
 * Supports three views: grid | form | inline
 * Each view has: Add Row button, Edit (modal), Delete row
 * All field types rendered via the same nu_render_field pipeline (PHP)
 * or via nuSubform._buildFieldHtml (JS fallback for inline)
 *
 * Bug fixes (2026-06-03):
 *  1. onParentSaved(newId) — called by nubuilder-next.js after the parent
 *     record saves. Writes newId into every .nu-subform-container[data-parent-id]
 *     on the page and reloads them via subform_list so saved rows appear.
 *  2. load() modal-save callback now always re-reads dataset.parentId AFTER
 *     onParentSaved has had a chance to run.
 *  3. nu:parent:saved DOM event also triggers onParentSaved for loose integrations.
 */
(function (window) {
  'use strict';

  /* ── tiny helpers ─────────────────────────────────────────────────── */
  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/"/g, '&quot;')
      .replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }
  function apiJson(url, opts) {
    return fetch(url, opts || {}).then(function (r) { return r.json(); });
  }
  function toast(msg, type) {
    if (window.NuApp && window.NuApp.toast) { window.NuApp.toast(msg, type); return; }
    alert(msg);
  }

  /* ── get container meta ───────────────────────────────────────────── */
  function meta(container) {
    return {
      code:     container.dataset.subformCode || '',
      fk:       container.dataset.subformFk   || '',
      view:     container.dataset.subformView  || 'grid',
      parentId: container.dataset.parentId    || ''
    };
  }

  /* ── load & render rows ───────────────────────────────────────────── */
  function load(container) {
    var m    = meta(container);
    var body = container.querySelector('.nu-subform-body');
    if (!body) return;

    // If no parent_id yet (new unsaved parent), show empty grid shell only
    if (!m.parentId) {
      apiJson('api/form.php?action=subform_fields&code=' + encodeURIComponent(m.code))
        .then(function (json) {
          if (!json.success) { body.innerHTML = '<div style="padding:12px;color:red;">' + esc(json.error) + '</div>'; return; }
          render(container, json.data.layout, [], json.data.pk || 'id');
        })
        .catch(function (e) { body.innerHTML = '<div style="padding:12px;color:red;">' + esc(e.message) + '</div>'; });
      return;
    }

    body.innerHTML = '<div style="padding:20px;text-align:center;color:#666;font-size:13px;">Loading...</div>';

    apiJson('api/form.php?action=subform_list&code=' + encodeURIComponent(m.code)
      + '&fk='       + encodeURIComponent(m.fk)
      + '&parent_id=' + encodeURIComponent(m.parentId))
    .then(function (json) {
      if (!json.success) { body.innerHTML = '<div style="padding:12px;color:red;">' + esc(json.error) + '</div>'; return; }
      render(container, json.data.layout, json.data.records, json.data.pk || 'id');
    })
    .catch(function (e) { body.innerHTML = '<div style="padding:12px;color:red;">' + esc(e.message) + '</div>'; });
  }

  function render(container, layout, records, pk) {
    var m    = meta(container);
    var body = container.querySelector('.nu-subform-body');
    if (!body) return;

    var displayLayout = layout.filter(function (f) {
      var t = f.type || f.fieldtype || 'text';
      return !['html','heading','divider','fieldset','subform','button'].includes(t);
    });

    if (m.view === 'grid')        body.innerHTML = renderGrid(displayLayout, records, pk, m);
    else if (m.view === 'inline') body.innerHTML = renderInline(displayLayout, records, pk, m);
    else                          body.innerHTML = renderFormList(displayLayout, records, pk, m);

    body.querySelectorAll('[data-sf-delete]').forEach(function (btn) {
      btn.addEventListener('click', function () { deleteRow(container, btn.dataset.sfDelete, pk); });
    });
    body.querySelectorAll('[data-sf-edit]').forEach(function (btn) {
      btn.addEventListener('click', function () { openModal(container, layout, pk, btn.dataset.sfEdit, records); });
    });
    body.querySelectorAll('[data-sf-inline-save]').forEach(function (btn) {
      btn.addEventListener('click', function () { saveInlineRow(container, btn, pk); });
    });
  }

  /* ── grid view ────────────────────────────────────────────────────── */
  function renderGrid(layout, records, pk, m) {
    var html = '<table style="width:100%;border-collapse:collapse;font-size:13px;">';
    html += '<thead><tr style="background:var(--bg-elevated,#f8f9fa);">';
    layout.forEach(function (f) {
      html += '<th style="padding:8px 10px;text-align:left;font-weight:600;border-bottom:1px solid #ddd;">' + esc(f.label || f.fieldlabel || f.name || f.fieldname || '') + '</th>';
    });
    html += '<th style="padding:8px 10px;width:100px;">Actions</th></tr></thead><tbody>';

    if (!records.length) {
      html += '<tr><td colspan="' + (layout.length + 1) + '" style="padding:20px;text-align:center;color:#999;">No rows yet</td></tr>';
    } else {
      records.forEach(function (row) {
        var id = row[pk];
        html += '<tr style="border-bottom:1px solid #eee;">';
        layout.forEach(function (f) {
          var fname = f.name || f.fieldname || '';
          var type  = f.type || f.fieldtype || 'text';
          var val   = row[fname + '_display'] !== undefined ? row[fname + '_display'] : (row[fname] !== undefined ? row[fname] : '');
          html += '<td style="padding:8px 10px;">' + cellDisplay(type, val) + '</td>';
        });
        html += '<td style="padding:8px 10px;white-space:nowrap;">';
        html += '<button type="button" class="nu-btn nu-btn-ghost nu-btn-sm" data-sf-edit="' + esc(id) + '" style="margin-right:4px;">Edit</button>';
        html += '<button type="button" class="nu-btn nu-btn-ghost nu-btn-sm" data-sf-delete="' + esc(id) + '" style="color:#c00;">Del</button>';
        html += '</td></tr>';
      });
    }
    html += '</tbody></table>';
    return html;
  }

  /* ── form-card list view ──────────────────────────────────────────── */
  function renderFormList(layout, records, pk, m) {
    if (!records.length) return '<div style="padding:20px;text-align:center;color:#999;font-size:13px;">No rows yet</div>';
    var html = '<div style="display:grid;gap:8px;padding:8px;">';
    records.forEach(function (row) {
      var id = row[pk];
      html += '<div style="border:1px solid #ddd;border-radius:8px;padding:12px;">';
      layout.slice(0, 3).forEach(function (f) {
        var fname = f.name || f.fieldname || '';
        var val   = row[fname + '_display'] !== undefined ? row[fname + '_display'] : (row[fname] || '');
        html += '<div style="font-size:12px;"><strong>' + esc(f.label || f.fieldlabel || fname) + ':</strong> ' + esc(val) + '</div>';
      });
      html += '<div style="display:flex;gap:8px;margin-top:8px;">';
      html += '<button type="button" class="nu-btn nu-btn-ghost nu-btn-sm" data-sf-edit="' + esc(id) + '">Edit</button>';
      html += '<button type="button" class="nu-btn nu-btn-ghost nu-btn-sm" data-sf-delete="' + esc(id) + '" style="color:#c00;">Delete</button>';
      html += '</div></div>';
    });
    html += '</div>';
    return html;
  }

  /* ── inline editable view ─────────────────────────────────────────── */
  function renderInline(layout, records, pk, m) {
    var html = '<div style="padding:8px;">';
    if (!records.length) {
      html += '<div style="padding:12px;text-align:center;color:#999;font-size:13px;">No rows yet</div>';
    }
    records.forEach(function (row) {
      var id = row[pk];
      html += '<div class="nu-sf-inline-row" data-sf-row-id="' + esc(id) + '" style="border:1px solid #ddd;border-radius:8px;padding:12px;margin-bottom:8px;display:grid;gap:8px;">';
      html += '<div style="display:flex;flex-wrap:wrap;gap:8px;">';
      layout.forEach(function (f) {
        var fname = f.name || f.fieldname || '';
        var val   = row[fname] !== undefined ? row[fname] : '';
        var type  = f.type || f.fieldtype || 'text';
        html += '<div style="flex:1;min-width:120px;">';
        html += '<label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;">' + esc(f.label || f.fieldlabel || fname) + '</label>';
        html += buildInlineInput(type, fname, val, f);
        html += '</div>';
      });
      html += '</div>';
      html += '<div style="display:flex;gap:6px;margin-top:4px;">';
      html += '<button type="button" class="nu-btn nu-btn-primary nu-btn-sm" data-sf-inline-save="' + esc(id) + '">Save</button>';
      html += '<button type="button" class="nu-btn nu-btn-ghost nu-btn-sm" data-sf-delete="' + esc(id) + '" style="color:#c00;">Delete</button>';
      html += '</div></div>';
    });
    html += '</div>';
    return html;
  }

  function buildInlineInput(type, name, value, field) {
    var base = 'class="nu-input" name="' + esc(name) + '" style="width:100%;"';
    if (type === 'textarea') return '<textarea ' + base + ' rows="2">' + esc(value) + '</textarea>';
    if (type === 'select') {
      var opts = '<option value="">—</option>';
      (field.options || []).forEach(function (o) {
        var sel = String(value) === String(o.value) ? ' selected' : '';
        opts += '<option value="' + esc(o.value) + '"' + sel + '>' + esc(o.label || o.value) + '</option>';
      });
      return '<select ' + base + '>' + opts + '</select>';
    }
    if (type === 'checkbox') return '<input type="checkbox" name="' + esc(name) + '" value="1"' + (value ? ' checked' : '') + '>';
    if (type === 'date')     return '<input type="date" '           + base + ' value="' + esc(value) + '">';
    if (type === 'time')     return '<input type="time" '           + base + ' value="' + esc(value) + '">';
    if (type === 'datetime') {
      var v = value ? String(value).replace(' ', 'T').substring(0, 16) : '';
      return '<input type="datetime-local" ' + base + ' value="' + esc(v) + '">';
    }
    if (type === 'number') return '<input type="number" ' + base + ' value="' + esc(value) + '">';
    return '<input type="text" ' + base + ' value="' + esc(value) + '">';
  }

  function cellDisplay(type, val) {
    if (type === 'checkbox') return val ? '&#10003;' : '&mdash;';
    return esc(val == null ? '' : val);
  }

  /* ── modal for add/edit ───────────────────────────────────────────── */
  function openModal(container, layout, pk, rowId, records) {
    var row = {};
    if (rowId) {
      records.forEach(function (r) { if (String(r[pk]) === String(rowId)) row = r; });
    }

    var overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:20000;display:flex;align-items:center;justify-content:center;';
    overlay.setAttribute('data-sf-overlay', '1');

    var box = document.createElement('div');
    box.style.cssText = 'background:#fff;border-radius:12px;padding:24px;max-width:640px;width:94%;max-height:90vh;overflow-y:auto;';

    var title = rowId ? 'Edit Row' : 'Add Row';
    box.innerHTML = '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">'
      + '<h3 style="margin:0;">' + title + '</h3>'
      + '<button type="button" style="background:none;border:none;font-size:22px;cursor:pointer;line-height:1;" onclick="this.closest(\'[data-sf-overlay]\').remove()">&times;</button>'
      + '</div>';

    var fieldHtml = '<div style="display:flex;flex-wrap:wrap;gap:12px;">';
    layout.forEach(function (f) {
      var fname = f.name || f.fieldname || '';
      var ftype = f.type || f.fieldtype || 'text';
      var skip  = ['html','heading','divider','fieldset','subform','button'];
      if (skip.includes(ftype) || !fname) return;
      var val   = row[fname] !== undefined ? row[fname] : (f.default_value || f.defaultvalue || '');
      var width = f.width || '100%';
      fieldHtml += '<div style="flex:1;min-width:calc(' + esc(width) + ' - 12px);">';
      fieldHtml += '<label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">'
        + esc(f.label || f.fieldlabel || fname) + (f.required ? ' <span style="color:red">*</span>' : '') + '</label>';
      fieldHtml += buildInlineInput(ftype, fname, val, f);
      if (f.help_text || f.helptext) {
        fieldHtml += '<div style="font-size:11px;color:#888;margin-top:3px;">' + esc(f.help_text || f.helptext) + '</div>';
      }
      fieldHtml += '</div>';
    });
    fieldHtml += '</div>';
    box.innerHTML += fieldHtml;

    var footer = document.createElement('div');
    footer.style.cssText = 'display:flex;gap:8px;justify-content:flex-end;margin-top:20px;';

    var cancelBtn = document.createElement('button');
    cancelBtn.type = 'button';
    cancelBtn.className = 'nu-btn nu-btn-ghost';
    cancelBtn.textContent = 'Cancel';
    cancelBtn.onclick = function () { overlay.remove(); };

    var saveBtn = document.createElement('button');
    saveBtn.type = 'button';
    saveBtn.className = 'nu-btn nu-btn-primary';
    saveBtn.textContent = 'Save';
    saveBtn.onclick = function () {
      var data = {};
      layout.forEach(function (f) {
        var fname = f.name || f.fieldname || '';
        var ftype = f.type || f.fieldtype || 'text';
        var skip  = ['html','heading','divider','fieldset','subform','button'];
        if (skip.includes(ftype) || !fname) return;
        var el = box.querySelector('[name="' + CSS.escape(fname) + '"]');
        if (!el) return;
        data[fname] = (ftype === 'checkbox') ? (el.checked ? 1 : 0) : el.value;
      });

      // ── FIX Bug 2/3: always re-read parentId from dataset at save time.
      // onParentSaved() may have updated it between modal open and save click.
      var m = meta(container);

      var url = 'api/form.php?action=subform_save'
              + '&code='      + encodeURIComponent(m.code)
              + '&fk='        + encodeURIComponent(m.fk)
              + '&parent_id=' + encodeURIComponent(m.parentId)
              + (rowId ? '&id=' + encodeURIComponent(rowId) : '');

      saveBtn.disabled = true;
      saveBtn.textContent = 'Saving...';

      apiJson(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) })
        .then(function (json) {
          if (!json.success) {
            toast(json.error || 'Save failed', 'error');
            saveBtn.disabled = false;
            saveBtn.textContent = 'Save';
            return;
          }
          overlay.remove();
          // Re-read meta again — parentId may have just been written above
          load(container);
          toast(rowId ? 'Row updated' : 'Row added');
        })
        .catch(function (e) { toast('Error: ' + e.message, 'error'); saveBtn.disabled = false; saveBtn.textContent = 'Save'; });
    };

    footer.appendChild(cancelBtn);
    footer.appendChild(saveBtn);
    box.appendChild(footer);
    overlay.appendChild(box);
    document.body.appendChild(overlay);
  }

  /* ── delete row ───────────────────────────────────────────────────── */
  function deleteRow(container, rowId, pk) {
    if (!confirm('Delete this row?')) return;
    var m = meta(container);
    apiJson('api/form.php?action=subform_delete&code=' + encodeURIComponent(m.code) + '&id=' + encodeURIComponent(rowId), { method: 'DELETE' })
      .then(function (json) {
        if (!json.success) { toast(json.error || 'Delete failed', 'error'); return; }
        load(container);
        toast('Row deleted');
      })
      .catch(function (e) { toast('Error: ' + e.message, 'error'); });
  }

  /* ── save inline row ──────────────────────────────────────────────── */
  function saveInlineRow(container, btn, pk) {
    var rowEl = btn.closest('.nu-sf-inline-row');
    var rowId = rowEl ? rowEl.dataset.sfRowId : '';
    var data  = {};
    if (rowEl) {
      rowEl.querySelectorAll('[name]').forEach(function (el) {
        data[el.name] = (el.type === 'checkbox') ? (el.checked ? 1 : 0) : el.value;
      });
    }
    btn.disabled = true;
    var m = meta(container);
    apiJson('api/form.php?action=subform_save'
      + '&code='      + encodeURIComponent(m.code)
      + '&fk='        + encodeURIComponent(m.fk)
      + '&parent_id=' + encodeURIComponent(m.parentId)
      + (rowId ? '&id=' + encodeURIComponent(rowId) : ''),
      { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) })
    .then(function (json) {
      btn.disabled = false;
      if (!json.success) { toast(json.error || 'Save failed', 'error'); return; }
      load(container);
      toast('Saved');
    })
    .catch(function (e) { btn.disabled = false; toast('Error: ' + e.message, 'error'); });
  }

  /* ── public API ───────────────────────────────────────────────────── */
  var nuSubform = {

    initAll: function (scope) {
      scope = scope || document;
      scope.querySelectorAll('.nu-subform-container').forEach(function (el) {
        if (!el.dataset.sfInit) {
          el.dataset.sfInit = '1';
          load(el);
        }
      });
    },

    load: load,

    addRow: function (btn) {
      var container = btn.closest('.nu-subform-container');
      if (!container) return;
      var m = meta(container);
      if (!m.code) { toast('Subform not configured (missing form code)', 'error'); return; }

      apiJson('api/form.php?action=subform_fields&code=' + encodeURIComponent(m.code))
        .then(function (json) {
          if (!json.success) { toast(json.error || 'Failed to load subform', 'error'); return; }
          openModal(container, json.data.layout, json.data.pk || 'id', null, []);
        })
        .catch(function (e) { toast('Error: ' + e.message, 'error'); });
    },

    setView: function (container, view) {
      if (!['grid','form','inline'].includes(view)) return;
      container.dataset.subformView = view;
      load(container);
    },

    /**
     * ── FIX Bug 2 ────────────────────────────────────────────────────────────
     * Called by nubuilder-next.js (submitNuForm) immediately after the parent
     * record is saved and a new id is returned.
     *
     * Writes newId into data-parent-id on EVERY .nu-subform-container inside
     * the given scope (defaults to document), then reloads each one via
     * subform_list so previously-saved rows become visible and new rows can
     * be linked to the correct parent.
     *
     * @param {string|number} newId   - the newly-saved parent record id
     * @param {Element}       [scope] - form element or any ancestor; defaults to document
     */
    onParentSaved: function (newId, scope) {
      scope = scope || document;
      var id = String(newId || '');
      if (!id) return;
      scope.querySelectorAll('.nu-subform-container').forEach(function (el) {
        el.dataset.parentId = id;
        // Remove sfInit flag so initAll can re-init if needed
        delete el.dataset.sfInit;
        load(el);
      });
    }
  };

  window.nuSubform = nuSubform;

  /* ── auto-init after parent form opens ───────────────────────────── */
  document.addEventListener('nu:form:opened', function (e) {
    var scope = e.detail && e.detail.scope ? e.detail.scope : document;
    nuSubform.initAll(scope);
  });

  /* ── listen for parent save event (loose integration) ────────────── */
  document.addEventListener('nu:parent:saved', function (e) {
    var detail = e.detail || {};
    nuSubform.onParentSaved(detail.id, detail.scope || document);
  });

}(window));
