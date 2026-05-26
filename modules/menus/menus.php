<?php
require_once '../../config.php';
require_once '../../core/Database.php';
require_once '../../core/Auth.php';

$auth = new NuAuth();
if (!$auth->checkAuth()) exit('Unauthorized');

$db = NuDatabase::getInstance();
$menus = $db->fetchAll("SELECT * FROM nu_menus WHERE menu_active = 1 ORDER BY menu_parent_id, menu_order");
?>

<div class="nu-menus">
    <div class="nu-card">
        <div class="nu-card-header">
            <h3 class="nu-card-title">Menu Builder</h3>
            <button class="nu-btn nu-btn-primary" onclick="openMenuModal()">+ New Menu Item</button>
        </div>
        <div class="nu-table-wrap">
            <table class="nu-table">
                <thead>
                    <tr><th>Label</th><th>Type</th><th>Target</th><th>Order</th><th>Actions</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($menus as $m): ?>
                    <tr>
                        <td><?php echo str_repeat('&mdash; ', $m['menu_parent_id'] > 0 ? 1 : 0); ?><strong><?php echo htmlspecialchars($m['menu_label']); ?></strong></td>
                        <td><span class="nu-status nu-status-active"><?php echo ucfirst($m['menu_type']); ?></span></td>
                        <td><?php echo htmlspecialchars($m['menu_target'] ?? '-'); ?></td>
                        <td><?php echo $m['menu_order']; ?></td>
                        <td>
                            <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="editMenu(<?php echo $m['menu_id']; ?>)">Edit</button>
                            <button class="nu-btn nu-btn-danger nu-btn-sm" onclick="deleteMenu(<?php echo $m['menu_id']; ?>)">Delete</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($menus)): ?>
                    <tr><td colspan="5" style="text-align:center;color:var(--text-tertiary);">No menu items. Add your first item.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="nu-modal-overlay" id="menuModal">
    <div class="nu-modal">
        <div class="nu-modal-header">
            <h3 class="nu-modal-title">Menu Item</h3>
            <button class="nu-modal-close" onclick="closeMenuModal()">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
        </div>
        <div class="nu-modal-body">
            <div class="nu-field">
                <label>Label</label>
                <input type="text" class="nu-input" id="menuLabel" placeholder="Dashboard">
            </div>
            <div class="nu-field">
                <label>Type</label>
                <select class="nu-input" id="menuType">
                    <option value="form">Form</option>
                    <option value="report">Report</option>
                    <option value="query">Query</option>
                    <option value="url">URL</option>
                    <option value="divider">Divider</option>
                </select>
            </div>
            <div class="nu-field">
                <label>Target (form code, URL, etc)</label>
                <input type="text" class="nu-input" id="menuTarget" placeholder="dashboard">
            </div>
            <div class="nu-field">
                <label>Parent Menu ID (0 = top level)</label>
                <input type="number" class="nu-input" id="menuParent" value="0">
            </div>
            <div class="nu-field">
                <label>Order</label>
                <input type="number" class="nu-input" id="menuOrder" value="0">
            </div>
        </div>
        <div class="nu-modal-footer">
            <button class="nu-btn nu-btn-ghost" onclick="closeMenuModal()">Cancel</button>
            <button class="nu-btn nu-btn-primary" onclick="saveMenu()">Save</button>
        </div>
    </div>
</div>


