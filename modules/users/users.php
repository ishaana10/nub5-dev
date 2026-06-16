<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/core/module_bootstrap.php';

if (!$auth->hasPermission('users.view')) {
    http_response_code(403);
    exit('Access denied');
}

$db    = NuDatabase::getInstance();
$users = $db->fetchAll("SELECT * FROM nu_users ORDER BY usr_id DESC");
$roles = $db->fetchAll("SELECT role_code, role_name FROM nu_roles ORDER BY role_name");

// Load developer-defined custom user fields
$metaFields = [];
$metaConfigFile = dirname(__DIR__, 2) . '/config.user_fields.php';
if (file_exists($metaConfigFile)) {
    $metaFields = include $metaConfigFile;
}
?>
<div class="nu-users" id="usersRoot">

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
    <h3 class="nu-card-title" style="margin:0;">Users</h3>
    <?php if ($auth->hasPermission('users.create')): ?>
    <button class="nu-btn nu-btn-primary" onclick="Users.openCreate()">+ New User</button>
    <?php endif; ?>
</div>

<div class="nu-table-wrap">
    <table class="nu-table" style="width:100%;">
        <thead>
            <tr>
                <th>ID</th><th>Username</th><th>Email</th><th>Role</th><th>Active</th>
                <?php foreach ($metaFields as $mf): ?>
                <th><?php echo htmlspecialchars($mf['label']); ?></th>
                <?php endforeach; ?>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $u):
                $meta = [];
                if (!empty($metaFields)) {
                    $metaRows = $db->fetchAll(
                        "SELECT umeta_key, umeta_value FROM nu_user_meta WHERE umeta_user_id = :id",
                        [':id' => $u['usr_id']]
                    );
                    foreach ($metaRows as $mr) $meta[$mr['umeta_key']] = $mr['umeta_value'];
                }
            ?>
            <tr>
                <td><?php echo $u['usr_id']; ?></td>
                <td><strong><?php echo htmlspecialchars($u['usr_username']); ?></strong></td>
                <td><?php echo htmlspecialchars($u['usr_email'] ?? '-'); ?></td>
                <td><?php echo htmlspecialchars($u['usr_role']); ?></td>
                <td><?php echo $u['usr_active'] ? '<span class="nu-status nu-status-active">Yes</span>' : '<span class="nu-status nu-status-inactive">No</span>'; ?></td>
                <?php foreach ($metaFields as $mf): ?>
                <td><?php echo htmlspecialchars($meta[$mf['key']] ?? '-'); ?></td>
                <?php endforeach; ?>
                <td style="display:flex;gap:6px;">
                    <?php if ($auth->hasPermission('users.edit')): ?>
                    <button class="nu-btn nu-btn-ghost nu-btn-sm"
                        onclick="Users.openEdit(<?php echo $u['usr_id']; ?>)"
                        data-user='<?php echo htmlspecialchars(json_encode($u), ENT_QUOTES); ?>'
                        data-meta='<?php echo htmlspecialchars(json_encode($meta), ENT_QUOTES); ?>'>
                        ✎ Edit
                    </button>
                    <?php endif; ?>
                    <?php if ($auth->hasPermission('users.delete') && $u['usr_username'] !== 'globeadmin'): ?>
                    <button class="nu-btn nu-btn-danger nu-btn-sm"
                        onclick="Users.deleteUser(<?php echo $u['usr_id']; ?>, '<?php echo htmlspecialchars($u['usr_username']); ?>')">✕</button>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- ═══ MODAL ═══════════════════════════════════════════════════════════════ -->
<div id="userModalOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:10000;align-items:center;justify-content:center;">
  <div style="background:var(--card-bg,#fff);border-radius:12px;padding:28px;width:92%;max-width:520px;max-height:90vh;overflow-y:auto;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;">
      <h4 id="userModalTitle" style="margin:0;">New User</h4>
      <button onclick="Users.closeModal()" style="background:none;border:none;font-size:20px;cursor:pointer;color:#666;">✕</button>
    </div>

    <input type="hidden" id="umEditId">

    <div class="nu-field" style="margin-bottom:12px;">
      <label style="font-size:13px;display:block;margin-bottom:4px;">Username *</label>
      <input type="text" class="nu-input" id="umUsername">
    </div>
    <div class="nu-field" style="margin-bottom:12px;">
      <label style="font-size:13px;display:block;margin-bottom:4px;">Email</label>
      <input type="email" class="nu-input" id="umEmail">
    </div>
    <div class="nu-field" style="margin-bottom:12px;">
      <label style="font-size:13px;display:block;margin-bottom:4px;">Role</label>
      <select class="nu-input" id="umRole">
        <?php foreach ($roles as $r): ?>
        <option value="<?php echo htmlspecialchars($r['role_code']); ?>"><?php echo htmlspecialchars($r['role_name']); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="nu-field" style="margin-bottom:12px;">
      <label style="font-size:13px;display:block;margin-bottom:4px;">Password <small id="umPwdHint" style="color:#999;">(leave blank to keep current)</small></label>
      <input type="password" class="nu-input" id="umPassword" autocomplete="new-password">
    </div>

    <?php foreach ($metaFields as $mf): ?>
    <div class="nu-field" style="margin-bottom:12px;">
      <label style="font-size:13px;display:block;margin-bottom:4px;"><?php echo htmlspecialchars($mf['label']); ?><?php echo !empty($mf['global']) ? ' <span style="font-size:11px;color:#1a6fad;">(global)</span>' : ''; ?></label>
      <?php if (!empty($mf['options'])): ?>
      <select class="nu-input" id="um_meta_<?php echo htmlspecialchars($mf['key']); ?>" data-meta-key="<?php echo htmlspecialchars($mf['key']); ?>">
        <option value="">— select —</option>
        <?php foreach ($mf['options'] as $opt): ?>
        <option value="<?php echo htmlspecialchars($opt); ?>"><?php echo htmlspecialchars($opt); ?></option>
        <?php endforeach; ?>
      </select>
      <?php else: ?>
      <input type="<?php echo htmlspecialchars($mf['type'] ?? 'text'); ?>" class="nu-input" id="um_meta_<?php echo htmlspecialchars($mf['key']); ?>" data-meta-key="<?php echo htmlspecialchars($mf['key']); ?>">
      <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <div class="nu-field" style="margin-bottom:20px;">
      <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;">
        <input type="checkbox" id="umActive" checked> Active
      </label>
    </div>

    <div style="display:flex;gap:8px;justify-content:flex-end;">
      <button class="nu-btn nu-btn-ghost" onclick="Users.closeModal()">Cancel</button>
      <button class="nu-btn nu-btn-primary" onclick="Users.saveUser()">Save</button>
    </div>
  </div>
</div>

<script>
const Users = (() => {
  const $ = id => document.getElementById(id);

  function toast(msg, type) {
    if (window.NuApp && NuApp.toast) NuApp.toast(msg, type);
    else alert(msg);
  }

  async function api(url, opts) {
    const r = await fetch(url, opts || {});
    const j = await r.json();
    if (!j.success) throw new Error(j.error || 'API error');
    return j;
  }

  function openCreate() {
    $('userModalTitle').textContent = 'New User';
    $('umEditId').value = '';
    $('umUsername').value = '';
    $('umUsername').disabled = false;
    $('umEmail').value = '';
    $('umRole').value = $('umRole').options[0]?.value || '';
    $('umPassword').value = '';
    $('umPwdHint').style.display = 'none';
    $('umActive').checked = true;
    // Clear meta fields
    document.querySelectorAll('[data-meta-key]').forEach(el => el.value = '');
    $('userModalOverlay').style.display = 'flex';
  }

  function openEdit(id) {
    const btn = document.querySelector(`[onclick="Users.openEdit(${id})"]`);
    const user = JSON.parse(btn.dataset.user);
    const meta = JSON.parse(btn.dataset.meta || '{}');
    $('userModalTitle').textContent = 'Edit User';
    $('umEditId').value = user.usr_id;
    $('umUsername').value = user.usr_username;
    $('umUsername').disabled = true; // username immutable after creation
    $('umEmail').value = user.usr_email || '';
    $('umRole').value = user.usr_role || '';
    $('umPassword').value = '';
    $('umPwdHint').style.display = '';
    $('umActive').checked = user.usr_active == 1;
    // Populate meta fields
    document.querySelectorAll('[data-meta-key]').forEach(el => {
      el.value = meta[el.dataset.metaKey] || '';
    });
    $('userModalOverlay').style.display = 'flex';
  }

  function closeModal() {
    $('userModalOverlay').style.display = 'none';
  }

  async function saveUser() {
    const id       = $('umEditId').value;
    const username = $('umUsername').value.trim();
    const email    = $('umEmail').value.trim();
    const role     = $('umRole').value;
    const password = $('umPassword').value;
    const active   = $('umActive').checked ? 1 : 0;

    if (!username) { toast('Username is required', 'error'); return; }

    // Collect meta fields
    const meta = {};
    document.querySelectorAll('[data-meta-key]').forEach(el => {
      meta[el.dataset.metaKey] = el.value;
    });

    const payload = { username, email, role, active, meta };
    if (password) payload.password = password;
    if (id) payload.id = parseInt(id);

    try {
      await api('api/users.php?action=' + (id ? 'update' : 'create'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });
      toast(id ? 'User updated' : 'User created');
      closeModal();
      setTimeout(() => location.reload(), 600);
    } catch(e) { toast(e.message, 'error'); }
  }

  async function deleteUser(id, username) {
    if (!confirm(`Delete user "${username}"? This cannot be undone.`)) return;
    try {
      await api('api/users.php?action=delete', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id })
      });
      toast('User deleted');
      setTimeout(() => location.reload(), 600);
    } catch(e) { toast(e.message, 'error'); }
  }

  // Close on overlay click
  $('userModalOverlay').addEventListener('click', e => {
    if (e.target === $('userModalOverlay')) closeModal();
  });

  return { openCreate, openEdit, closeModal, saveUser, deleteUser };
})();
</script>
</div>
