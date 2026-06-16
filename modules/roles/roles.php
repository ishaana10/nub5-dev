<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/core/module_bootstrap.php';

if (!$auth->hasPermission('roles.view')) {
    http_response_code(403);
    exit('Access denied');
}
?>
<div class="nu-roles" id="rolesRoot">

<!-- ═══ ROLE LIST PANEL ══════════════════════════════════════════════════ -->
<div id="roleListPanel">
  <div class="nu-card">
    <div class="nu-card-header" style="display:flex;justify-content:space-between;align-items:center;">
      <h3 class="nu-card-title" style="margin:0;">Roles</h3>
      <button class="nu-btn nu-btn-primary nu-btn-sm" onclick="Roles.openCreateModal()">+ New Role</button>
    </div>
    <div id="roleListBody" style="padding:8px 0;">Loading…</div>
  </div>
</div>

<!-- ═══ PERMISSIONS MATRIX PANEL ═════════════════════════════════════════════ -->
<div id="rolePermPanel" style="display:none;margin-top:24px;">
  <div class="nu-card">
    <div class="nu-card-header" style="display:flex;justify-content:space-between;align-items:center;">
      <div>
        <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="Roles.closePermPanel()" style="margin-right:8px;">← Back</button>
        <strong id="permPanelTitle">Permissions</strong>
      </div>
      <button class="nu-btn nu-btn-primary nu-btn-sm" onclick="Roles.savePerms()">Save Permissions</button>
    </div>
    <div style="padding:16px;">
      <p style="font-size:13px;color:#666;margin:0 0 12px;">Tick permissions per form. Use the <strong>* (All Forms)</strong> row as a default that specific rows override.</p>
      <div id="permMatrixWrap" style="overflow-x:auto;"></div>
    </div>
  </div>
</div>

<!-- ═══ CREATE / EDIT MODAL ════════════════════════════════════════════════════ -->
<div id="roleModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:10000;align-items:center;justify-content:center;">
  <div style="background:var(--card-bg,#fff);border-radius:12px;padding:28px;width:92%;max-width:480px;">
    <h4 id="roleModalTitle" style="margin:0 0 18px;">New Role</h4>
    <label style="display:block;margin-bottom:12px;font-size:13px;">
      Role Name *
      <input id="rmName" type="text" class="nu-input" style="margin-top:4px;" placeholder="e.g. Manager">
    </label>
    <label style="display:block;margin-bottom:12px;font-size:13px;">
      Role Code * <span style="color:#999;font-size:11px;">(lowercase, no spaces)</span>
      <input id="rmCode" type="text" class="nu-input" style="margin-top:4px;" placeholder="e.g. manager">
    </label>
    <label style="display:block;margin-bottom:20px;font-size:13px;">
      Description
      <input id="rmDesc" type="text" class="nu-input" style="margin-top:4px;" placeholder="Optional">
    </label>
    <div style="display:flex;gap:8px;justify-content:flex-end;">
      <button class="nu-btn nu-btn-ghost" onclick="Roles.closeModal()">Cancel</button>
      <button class="nu-btn nu-btn-primary" id="rmSaveBtn" onclick="Roles.saveRole()">Create Role</button>
    </div>
  </div>
</div>

<script>
const Roles = (() => {
  let _roles     = [];
  let _forms     = [];
  let _editCode  = null;
  let _permCode  = null;
  let _permData  = {}; // { form_code: { can_view, can_add, can_edit, can_delete, can_export } }

  // ── helpers ──────────────────────────────────────────────────────────────────
  const $  = id => document.getElementById(id);
  const esc = s => (s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');

  async function api(url, opts) {
    const r = await fetch(url, opts || {});
    const j = await r.json();
    if (!j.success) throw new Error(j.error || 'API error');
    return j;
  }

  function toast(msg, type) {
    if (window.NuApp && NuApp.toast) NuApp.toast(msg, type);
    else alert(msg);
  }

  // ── Load & render role list ────────────────────────────────────────────────
  async function loadRoles() {
    try {
      const j = await api('api/roles.php?action=list');
      _roles = j.roles;
      renderRoleList();
    } catch(e) { $('roleListBody').innerHTML = '<p style="color:red;padding:12px;">' + esc(e.message) + '</p>'; }
  }

  function renderRoleList() {
    if (!_roles.length) {
      $('roleListBody').innerHTML = '<p style="padding:16px;color:#666;">No roles found.</p>';
      return;
    }
    let html = '<table class="nu-table" style="width:100%;">';
    html += '<thead><tr><th>Role</th><th>Code</th><th>Description</th><th>Type</th><th>Actions</th></tr></thead><tbody>';
    _roles.forEach(r => {
      const isSystem = r.role_is_system == 1;
      html += `<tr>
        <td><strong>${esc(r.role_name)}</strong></td>
        <td><code>${esc(r.role_code)}</code></td>
        <td>${esc(r.role_description || '\u2014')}</td>
        <td>${isSystem
          ? '<span class="nu-status" style="background:#e8f4fd;color:#1a6fad;">System</span>'
          : '<span class="nu-status nu-status-active">Custom</span>'
        }</td>
        <td style="display:flex;gap:6px;">
          ${!isSystem ? `<button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="Roles.openPermPanel('${esc(r.role_code)}', '${esc(r.role_name)}')">\u2699 Permissions</button>` : ''}
          ${!isSystem ? `<button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="Roles.openEditModal('${esc(r.role_code)}')">\u270e Edit</button>` : ''}
          ${!isSystem ? `<button class="nu-btn nu-btn-danger nu-btn-sm" onclick="Roles.deleteRole('${esc(r.role_code)}', '${esc(r.role_name)}')">\u2715</button>` : ''}
          ${isSystem ? '<span style="color:#999;font-size:12px;">Protected</span>' : ''}
        </td>
      </tr>`;
    });
    html += '</tbody></table>';
    $('roleListBody').innerHTML = html;
  }

  // ── Permissions panel ─────────────────────────────────────────────────────
  async function openPermPanel(roleCode, roleName) {
    _permCode = roleCode;
    $('permPanelTitle').textContent = 'Permissions \u2014 ' + roleName;
    $('permMatrixWrap').innerHTML = 'Loading…';
    $('rolePermPanel').style.display = 'block';
    $('roleListPanel').style.display = 'none';

    try {
      const [jRole, jForms] = await Promise.all([
        api('api/roles.php?action=get&role_code=' + encodeURIComponent(roleCode)),
        api('api/roles.php?action=forms')
      ]);
      _forms = jForms.forms;
      _permData = {};

      // FIX: PDO returns integers as strings ("0"/"1").
      // Using !! would make !!("0") === true, which is wrong.
      // Use == 1 (loose equality) so both the integer 1 and string "1" match.
      jRole.permissions.forEach(p => {
        _permData[p.rfp_form_code] = {
          can_view:   p.rfp_can_view   == 1,
          can_add:    p.rfp_can_add    == 1,
          can_edit:   p.rfp_can_edit   == 1,
          can_delete: p.rfp_can_delete == 1,
          can_export: p.rfp_can_export == 1,
        };
      });
      renderMatrix();
    } catch(e) {
      $('permMatrixWrap').innerHTML = '<p style="color:red;">' + esc(e.message) + '</p>';
    }
  }

  function renderMatrix() {
    const cols   = ['can_view','can_add','can_edit','can_delete','can_export'];
    const labels = ['View','Add','Edit','Delete','Export'];
    const rows   = [{ form_code: '*', form_name: '* All Forms (default)' }, ..._forms];

    let html = '<table class="nu-table" style="width:100%;min-width:560px;">';
    html += '<thead><tr><th style="min-width:180px;">Form</th>';
    labels.forEach(l => { html += `<th style="text-align:center;">${l}</th>`; });
    html += '</tr></thead><tbody>';

    rows.forEach(row => {
      const fc    = row.form_code;
      const perms = _permData[fc] || {};
      html += `<tr data-fc="${esc(fc)}"><td>${esc(row.form_name || fc)}<br><small style="color:#999;">${esc(fc)}</small></td>`;
      cols.forEach(col => {
        const checked = perms[col] ? 'checked' : '';
        html += `<td style="text-align:center;"><input type="checkbox" data-fc="${esc(fc)}" data-col="${col}" ${checked} onchange="Roles._onCheck(this)"></td>`;
      });
      html += '</tr>';
    });
    html += '</tbody></table>';
    $('permMatrixWrap').innerHTML = html;
  }

  function _onCheck(cb) {
    const fc  = cb.dataset.fc;
    const col = cb.dataset.col;
    if (!_permData[fc]) _permData[fc] = {};
    _permData[fc][col] = cb.checked;
  }

  async function savePerms() {
    const permissions = [];
    const allCodes = ['*', ..._forms.map(f => f.form_code)];
    allCodes.forEach(fc => {
      const p = _permData[fc] || {};
      permissions.push({
        form_code:  fc,
        can_view:   p.can_view   ? 1 : 0,
        can_add:    p.can_add    ? 1 : 0,
        can_edit:   p.can_edit   ? 1 : 0,
        can_delete: p.can_delete ? 1 : 0,
        can_export: p.can_export ? 1 : 0,
      });
    });
    try {
      await api('api/roles.php?action=save_perms&role_code=' + encodeURIComponent(_permCode), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ permissions })
      });
      toast('Permissions saved');
    } catch(e) { toast(e.message, 'error'); }
  }

  function closePermPanel() {
    $('rolePermPanel').style.display = 'none';
    $('roleListPanel').style.display = 'block';
    _permCode = null;
  }

  // ── Create / Edit modal ────────────────────────────────────────────────────
  function openCreateModal() {
    _editCode = null;
    $('roleModalTitle').textContent = 'New Role';
    $('rmSaveBtn').textContent = 'Create Role';
    $('rmName').value = $('rmCode').value = $('rmDesc').value = '';
    $('rmCode').disabled = false;
    $('roleModal').style.display = 'flex';
  }

  function openEditModal(code) {
    const role = _roles.find(r => r.role_code === code);
    if (!role) return;
    _editCode = code;
    $('roleModalTitle').textContent = 'Edit Role';
    $('rmSaveBtn').textContent = 'Save Changes';
    $('rmName').value = role.role_name;
    $('rmCode').value = role.role_code;
    $('rmCode').disabled = true;
    $('rmDesc').value = role.role_description || '';
    $('roleModal').style.display = 'flex';
  }

  function closeModal() {
    $('roleModal').style.display = 'none';
  }

  async function saveRole() {
    const name = $('rmName').value.trim();
    const code = $('rmCode').value.trim();
    const desc = $('rmDesc').value.trim();
    if (!name) { toast('Role name is required', 'error'); return; }

    try {
      if (_editCode) {
        await api('api/roles.php?action=update&role_code=' + encodeURIComponent(_editCode), {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ role_name: name, role_description: desc })
        });
        toast('Role updated');
      } else {
        if (!code) { toast('Role code is required', 'error'); return; }
        await api('api/roles.php?action=create', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ role_name: name, role_code: code, role_description: desc })
        });
        toast('Role created');
      }
      closeModal();
      loadRoles();
    } catch(e) { toast(e.message, 'error'); }
  }

  async function deleteRole(code, name) {
    if (!confirm(`Delete role "${name}"? This cannot be undone.`)) return;
    try {
      await api('api/roles.php?action=delete&role_code=' + encodeURIComponent(code), { method: 'POST' });
      toast('Role deleted');
      loadRoles();
    } catch(e) { toast(e.message, 'error'); }
  }

  // ── Auto-slug role name into code ───────────────────────────────────────────
  document.addEventListener('DOMContentLoaded', () => {
    const nameInput = $('rmName');
    const codeInput = $('rmCode');
    if (nameInput && codeInput) {
      nameInput.addEventListener('input', () => {
        if (!_editCode) {
          codeInput.value = nameInput.value.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '');
        }
      });
    }
  });

  return { loadRoles, openCreateModal, openEditModal, closeModal, saveRole, deleteRole, openPermPanel, closePermPanel, savePerms, _onCheck };
})();

Roles.loadRoles();
</script>
</div>
