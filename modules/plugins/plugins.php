<?php
require_once '../../config.php';
require_once '../../core/Database.php';
require_once '../../core/Auth.php';

$auth = new NuAuth();
if (!$auth->checkAuth()) exit('Unauthorized');

$db = NuDatabase::getInstance();
$plugins = $db->fetchAll("SELECT * FROM nu_plugins ORDER BY plugin_installed_at DESC");
?>

<div class="nu-plugins">
    <div class="nu-card" style="margin-bottom: 24px;">
        <div class="nu-card-header">
            <h3 class="nu-card-title">Plugin Manager</h3>
            <button class="nu-btn nu-btn-primary" onclick="openPluginModal()">+ Install Plugin</button>
        </div>
        <div class="nu-table-wrap">
            <table class="nu-table">
                <thead>
                    <tr><th>Code</th><th>Name</th><th>Version</th><th>Author</th><th>Status</th><th>Actions</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($plugins as $p): ?>
                    <tr>
                        <td><code><?php echo htmlspecialchars($p['plugin_code']); ?></code></td>
                        <td><?php echo htmlspecialchars($p['plugin_name']); ?></td>
                        <td><?php echo htmlspecialchars($p['plugin_version']); ?></td>
                        <td><?php echo htmlspecialchars($p['plugin_author'] ?? '-'); ?></td>
                        <td><span class="nu-status nu-status-<?php echo $p['plugin_active'] ? 'active' : 'inactive'; ?>"><?php echo $p['plugin_active'] ? 'Active' : 'Inactive'; ?></span></td>
                        <td>
                            <?php if ($p['plugin_active']): ?>
                            <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="togglePlugin(<?php echo $p['plugin_id']; ?>, 0)">Deactivate</button>
                            <?php else: ?>
                            <button class="nu-btn nu-btn-primary nu-btn-sm" onclick="togglePlugin(<?php echo $p['plugin_id']; ?>, 1)">Activate</button>
                            <?php endif; ?>
                            <button class="nu-btn nu-btn-danger nu-btn-sm" onclick="deletePlugin(<?php echo $p['plugin_id']; ?>">Uninstall</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($plugins)): ?>
                    <tr><td colspan="6" style="text-align:center;color:var(--text-tertiary);">No plugins installed. Install your first plugin.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="nu-modal-overlay" id="pluginModal">
    <div class="nu-modal">
        <div class="nu-modal-header">
            <h3 class="nu-modal-title">Install Plugin</h3>
            <button class="nu-modal-close" onclick="closePluginModal()">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
        </div>
        <div class="nu-modal-body">
            <div class="nu-field">
                <label>Plugin Code</label>
                <input type="text" class="nu-input" id="pluginCode" placeholder="my_custom_plugin">
            </div>
            <div class="nu-field">
                <label>Name</label>
                <input type="text" class="nu-input" id="pluginName" placeholder="My Plugin">
            </div>
            <div class="nu-field">
                <label>Version</label>
                <input type="text" class="nu-input" id="pluginVersion" value="1.0.0">
            </div>
            <div class="nu-field">
                <label>Author</label>
                <input type="text" class="nu-input" id="pluginAuthor" placeholder="Your Name">
            </div>
            <div class="nu-field">
                <label>Description</label>
                <textarea class="nu-input" id="pluginDescription" rows="2"></textarea>
            </div>
            <div class="nu-field">
                <label>Hooks JSON</label>
                <textarea class="nu-input" id="pluginHooks" rows="3" placeholder='{"after_save": "myplugin_after_save"}'></textarea>
            </div>
        </div>
        <div class="nu-modal-footer">
            <button class="nu-btn nu-btn-ghost" onclick="closePluginModal()">Cancel</button>
            <button class="nu-btn nu-btn-primary" onclick="savePlugin()">Install</button>
        </div>
    </div>
</div>


