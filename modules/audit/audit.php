<?php
require_once '../../config.php';
require_once '../../core/Database.php';
require_once '../../core/Auth.php';

$auth = new NuAuth();
if (!$auth->checkAuth()) exit('Unauthorized');
if (!$auth->hasPermission('audit.view')) exit('Access denied');

$db = NuDatabase::getInstance();
$page = (int)($_GET['page'] ?? 1);
$perPage = 25;
$offset = ($page - 1) * $perPage;

$logs = $db->fetchAll("SELECT * FROM nu_audit_log ORDER BY audit_timestamp DESC LIMIT :limit OFFSET :offset", 
    [':limit' => $perPage, ':offset' => $offset]);
$total = $db->fetchOne("SELECT COUNT(*) as total FROM nu_audit_log")['total'];
$pages = ceil($total / $perPage);
?>

<div class="nu-audit">
    <div class="nu-card">
        <div class="nu-card-header">
            <h3 class="nu-card-title">Audit Trail</h3>
            <span style="color:var(--text-tertiary);font-size:13px;"><?php echo $total; ?> total records</span>
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
                    <?php foreach ($logs as $log): ?>
                    <tr>
                        <td><span class="nu-status nu-status-<?php echo $log['audit_action'] === 'delete' ? 'inactive' : ($log['audit_action'] === 'login' ? 'active' : 'pending'); ?>"><?php echo ucfirst($log['audit_action']); ?></span></td>
                        <td><?php echo htmlspecialchars($log['audit_table']); ?></td>
                        <td><?php echo $log['audit_record_id']; ?></td>
                        <td><?php echo htmlspecialchars($log['audit_username']); ?></td>
                        <td><?php echo htmlspecialchars($log['audit_ip']); ?></td>
                        <td><?php echo date('M j, Y g:i A', strtotime($log['audit_timestamp'])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($pages > 1): ?>
        <div style="display:flex;gap:8px;justify-content:center;padding:16px;">
            <?php for ($i = 1; $i <= $pages; $i++): ?>
            <button class="nu-btn <?php echo $i === $page ? 'nu-btn-primary' : 'nu-btn-ghost'; ?> nu-btn-sm" onclick="NuApp.loadModule('audit?page=<?php echo $i; ?>')"><?php echo $i; ?></button>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
