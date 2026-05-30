<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/core/module_bootstrap.php';

$db       = NuDatabase::getInstance();
$webhooks = $db->fetchAll("SELECT * FROM nu_webhooks WHERE webhook_active = 1 ORDER BY webhook_created_at DESC");
$apiKeys  = $db->fetchAll("SELECT * FROM nu_api_tokens ORDER BY token_created_at DESC LIMIT 20");
?>

<div class="nu-integrations">
    <div class="nu-card" style="margin-bottom: 24px;">
        <div class="nu-card-header">
            <h3 class="nu-card-title">Integrations & Webhooks</h3>
            <button class="nu-btn nu-btn-primary" onclick="openWebhookModal()">+ New Webhook</button>
        </div>
        <div class="nu-table-wrap">
            <table class="nu-table">
                <thead>
                    <tr><th>Name</th><th>URL</th><th>Events</th><th>Status</th><th>Last Triggered</th><th>Actions</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($webhooks as $wh): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($wh['webhook_name']); ?></strong></td>
                        <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;"><?php echo htmlspecialchars($wh['webhook_url']); ?></td>
                        <td><?php echo str_replace(',', ', ', htmlspecialchars($wh['webhook_events'] ?? '')); ?></td>
                        <td><span class="nu-status nu-status-<?php echo $wh['webhook_active'] ? 'active' : 'inactive'; ?>"><?php echo $wh['webhook_active'] ? 'Active' : 'Inactive'; ?></span></td>
                        <td><?php echo $wh['webhook_last_triggered'] ? date('M j, H:i', strtotime($wh['webhook_last_triggered'])) : 'Never'; ?></td>
                        <td>
                            <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="testWebhook(<?php echo $wh['webhook_id']; ?>)">Test</button>
                            <button class="nu-btn nu-btn-danger nu-btn-sm" onclick="deleteWebhook(<?php echo $wh['webhook_id']; ?>)">Delete</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($webhooks)): ?>
                    <tr><td colspan="6" style="text-align:center;color:var(--text-tertiary);">No webhooks configured. Add your first integration.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="nu-card">
        <div class="nu-card-header">
            <h3 class="nu-card-title">API Keys</h3>
            <button class="nu-btn nu-btn-primary" onclick="generateApiKey()">Generate New Key</button>
        </div>
        <div class="nu-table-wrap">
            <table class="nu-table">
                <thead>
                    <tr><th>Token</th><th>Name</th><th>User</th><th>Created</th><th>Expires</th><th>Actions</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($apiKeys as $key): ?>
                    <tr>
                        <td><code><?php echo substr(htmlspecialchars($key['token_key']), 0, 20); ?>...</code></td>
                        <td><?php echo htmlspecialchars($key['token_name'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars($key['token_user_id'] ?? '-'); ?></td>
                        <td><?php echo date('M j, Y', strtotime($key['token_created_at'])); ?></td>
                        <td><?php echo $key['token_expires_at'] ? date('M j, Y', strtotime($key['token_expires_at'])) : 'Never'; ?></td>
                        <td>
                            <button class="nu-btn nu-btn-danger nu-btn-sm" onclick="revokeKey(<?php echo $key['token_id']; ?>)">Revoke</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($apiKeys)): ?>
                    <tr><td colspan="6" style="text-align:center;color:var(--text-tertiary);">No API keys generated yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="nu-modal-overlay" id="webhookModal">
    <div class="nu-modal">
        <div class="nu-modal-header">
            <h3 class="nu-modal-title">New Webhook</h3>
            <button class="nu-modal-close" onclick="closeWebhookModal()">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
        </div>
        <div class="nu-modal-body">
            <div class="nu-field">
                <label>Name</label>
                <input type="text" class="nu-input" id="whName" placeholder="Slack Notifications">
            </div>
            <div class="nu-field">
                <label>Webhook URL</label>
                <input type="url" class="nu-input" id="whUrl" placeholder="https://hooks.slack.com/services/...">
            </div>
            <div class="nu-field">
                <label>Events (comma-separated)</label>
                <input type="text" class="nu-input" id="whEvents" placeholder="form_save,workflow_approve,document_upload">
            </div>
            <div class="nu-field">
                <label>Secret (for HMAC verification)</label>
                <input type="text" class="nu-input" id="whSecret" placeholder="optional-secret-key">
            </div>
        </div>
        <div class="nu-modal-footer">
            <button class="nu-btn nu-btn-ghost" onclick="closeWebhookModal()">Cancel</button>
            <button class="nu-btn nu-btn-primary" onclick="saveWebhook()">Save</button>
        </div>
    </div>
</div>
