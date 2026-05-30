<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/core/module_bootstrap.php';

$db    = NuDatabase::getInstance();
$users = $db->fetchAll("SELECT * FROM nu_users ORDER BY usr_id DESC");
$roles = $db->fetchAll("SELECT * FROM nu_roles");
?>

<div class="nu-users">
    <div class="nu-card-header" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
        <h3 class="nu-card-title">Users</h3>
        <?php if ($auth->hasPermission('users', 'create')): ?>
        <button class="nu-btn nu-btn-primary" onclick="openUserModal()">+ New User</button>
        <?php endif; ?>
    </div>
    <div class="nu-table-wrap">
        <table class="nu-table">
            <thead>
                <tr><th>ID</th><th>Username</th><th>Email</th><th>Role</th><th>Active</th><th>Actions</th></tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                <tr>
                    <td><?php echo $u['usr_id']; ?></td>
                    <td><strong><?php echo htmlspecialchars($u['usr_username']); ?></strong></td>
                    <td><?php echo htmlspecialchars($u['usr_email'] ?? '-'); ?></td>
                    <td><span class="nu-status nu-status-<?php echo $u['usr_role'] === 'globeadmin' ? 'active' : 'inactive'; ?>"><?php echo htmlspecialchars($u['usr_role']); ?></span></td>
                    <td><?php echo $u['usr_active'] ? '<span class="nu-status nu-status-active">Yes</span>' : '<span class="nu-status nu-status-inactive">No</span>'; ?></td>
                    <td>
                        <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="editUser(<?php echo $u['usr_id']; ?>)" data-user='<?php echo json_encode($u); ?>'>Edit</button>
                        <?php if ($u['usr_username'] !== 'globeadmin'): ?>
                        <button class="nu-btn nu-btn-danger nu-btn-sm" onclick="deleteUser(<?php echo $u['usr_id']; ?>, '<?php echo htmlspecialchars($u['usr_username']); ?>')">Delete</button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="nu-modal-overlay" id="userModal">
    <div class="nu-modal" style="width:500px;">
        <div class="nu-modal-header">
            <h3 class="nu-modal-title" id="userModalTitle">New User</h3>
            <button class="nu-modal-close" onclick="closeUserModal()">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
        </div>
        <div class="nu-modal-body">
            <input type="hidden" id="editUserId" value="">
            <div class="nu-field">
                <label>Username</label>
                <input type="text" class="nu-input" id="userUsername" required>
            </div>
            <div class="nu-field">
                <label>Email</label>
                <input type="email" class="nu-input" id="userEmail">
            </div>
            <div class="nu-field">
                <label>Role</label>
                <select class="nu-input" id="userRole">
                    <?php foreach ($roles as $r): ?>
                    <option value="<?php echo htmlspecialchars($r['role_code']); ?>"><?php echo htmlspecialchars($r['role_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="nu-field">
                <label>Password <span id="pwdHint" style="font-size:12px;color:var(--text-tertiary);">(leave blank to keep current)</span></label>
                <input type="password" class="nu-input" id="userPassword">
            </div>
            <div class="nu-field">
                <label style="display:flex;align-items:center;gap:8px;">
                    <input type="checkbox" id="userActive" checked> Active
                </label>
            </div>
        </div>
        <div class="nu-modal-footer">
            <button class="nu-btn nu-btn-ghost" onclick="closeUserModal()">Cancel</button>
            <button class="nu-btn nu-btn-primary" onclick="saveUser()">Save</button>
        </div>
    </div>
</div>
