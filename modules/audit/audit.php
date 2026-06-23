<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/core/module_bootstrap.php';

// Only globeadmin sees audit; fall back gracefully for roles without the permission.
$user = $auth->getCurrentUser();
if (!$user || ($user['usr_role'] !== 'globeadmin' && !$auth->hasPermission('audit.view'))) {
    http_response_code(403);
    exit('Access denied');
}

$db      = NuDatabase::getInstance();
$audit   = new NuAudit();

// Ensure the audit log table exists before querying it.
$tableExists = (bool) $db->fetchOne("SHOW TABLES LIKE 'nu_audit_log'");
if (!$tableExists) {
    $db->exec("
        CREATE TABLE IF NOT EXISTS nu_audit_log (
            audit_id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            audit_action     VARCHAR(32)   NOT NULL,
            audit_table      VARCHAR(128)  NOT NULL DEFAULT '',
            audit_record_id  VARCHAR(64)   DEFAULT NULL,
            audit_old_data   LONGTEXT      DEFAULT NULL,
            audit_new_data   LONGTEXT      DEFAULT NULL,
            audit_user_id    VARCHAR(64)   DEFAULT NULL,
            audit_username   VARCHAR(128)  NOT NULL DEFAULT 'system',
            audit_ip         VARCHAR(45)   NOT NULL DEFAULT '',
            audit_user_agent VARCHAR(512)  NOT NULL DEFAULT '',
            audit_timestamp  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_audit_ts     (audit_timestamp),
            INDEX idx_audit_user   (audit_user_id),
            INDEX idx_audit_action (audit_action)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset  = ($page - 1) * $perPage;

// Use NuAudit methods — they handle all edge cases correctly.
$total = $audit->getLogCount();
$pages = $total > 0 ? (int)ceil($total / $perPage) : 1;
$logs  = $audit->getLogs([], $perPage, $offset);
?>

<div class="nu-audit">
    <div class="nu-card">
        <div class="nu-card-header">
            <h3 class="nu-card-title">Audit Trail</h3>
            <span style="color:var(--text-tertiary);font-size:13px;"><?php echo (int)$total; ?> total records</span>
        </div>
        <div class="nu-table-wrap">
            <table class="nu-table">
                <thead>
                    <tr>
                        <th>Action</th>
                        <th>Table</th>
                        <th>Record</th>
                        <th>User</th>
                        <th>IP</th>
                        <th>Time</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                    <tr><td colspan="6" style="text-align:center;color:var(--text-tertiary);padding:24px;">No audit records yet.</td></tr>
                    <?php else: ?>
                    <?php foreach ($logs as $log): ?>
                    <tr>
                        <td>
                            <?php
                            $action = $log['audit_action'] ?? '';
                            $statusClass = $action === 'delete' ? 'inactive' : ($action === 'login' ? 'active' : 'pending');
                            ?>
                            <span class="nu-status nu-status-<?php echo htmlspecialchars($statusClass); ?>">
                                <?php echo htmlspecialchars(ucfirst($action)); ?>
                            </span>
                        </td>
                        <td><?php echo htmlspecialchars($log['audit_table'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars((string)($log['audit_record_id'] ?? '')); ?></td>
                        <td><?php echo htmlspecialchars($log['audit_username'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($log['audit_ip'] ?? ''); ?></td>
                        <td>
                            <?php
                            $ts = $log['audit_timestamp'] ?? '';
                            echo $ts ? htmlspecialchars(date('M j, Y g:i A', strtotime($ts))) : '';
                            ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($pages > 1): ?>
        <div style="display:flex;gap:8px;justify-content:center;padding:16px;flex-wrap:wrap;">
            <?php for ($i = 1; $i <= $pages; $i++): ?>
            <button
                class="nu-btn <?php echo $i === $page ? 'nu-btn-primary' : 'nu-btn-ghost'; ?> nu-btn-sm"
                onclick="NuApp.loadModule('audit', 'browse', 'inline'); setTimeout(function(){ history.pushState(null,'','?page=<?php echo $i; ?>'); location.search='?page=<?php echo $i; ?>'; },0);"
            ><?php echo $i; ?></button>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
