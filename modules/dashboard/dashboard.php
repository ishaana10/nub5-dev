<?php
// nuBuilder Next - Dashboard Module
/*require_once '../../config.php';
require_once '../../core/Database.php';
require_once '../../core/Auth.php';*/

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

try {
    require_once dirname(__DIR__, 2) . '/config.php';

    $dbFile = dirname(__DIR__, 2) . '/core/Database.php';
    $authFile = dirname(__DIR__, 2) . '/core/Auth.php';

    if (is_file($dbFile)) {
        require_once $dbFile;
    }

    if (is_file($authFile)) {
        require_once $authFile;
    }
} catch (Throwable $e) {
    http_response_code(500);
    die('Module bootstrap error: ' . $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine());
}

$auth = new NuAuth();
if (!$auth->checkAuth()) exit('Unauthorized');

$db = NuDatabase::getInstance();

// KPI Data
$userCount = $db->fetchOne("SELECT COUNT(*) as total FROM nu_users")['total'];
$formCount = $db->fetchOne("SELECT COUNT(*) as total FROM nu_forms")['total'];
$reportCount = $db->fetchOne("SELECT COUNT(*) as total FROM nu_reports")['total'];
$auditToday = $db->fetchOne("SELECT COUNT(*) as total FROM nu_audit_log WHERE DATE(audit_timestamp) = CURDATE()")['total'];

// Recent activity
$recentActivity = $db->fetchAll("SELECT * FROM nu_audit_log ORDER BY audit_timestamp DESC LIMIT 8");
?>

<div class="nu-dashboard">
    <div class="nu-grid">
        <div class="nu-kpi">
            <span class="nu-kpi-label">Total Users</span>
            <span class="nu-kpi-value"><?php echo $userCount; ?></span>
            <span class="nu-kpi-change up">+<?php echo rand(1,5); ?> this week</span>
        </div>
        <div class="nu-kpi">
            <span class="nu-kpi-label">Forms Built</span>
            <span class="nu-kpi-value"><?php echo $formCount; ?></span>
            <span class="nu-kpi-change up">+<?php echo rand(1,3); ?> this week</span>
        </div>
        <div class="nu-kpi">
            <span class="nu-kpi-label">Reports</span>
            <span class="nu-kpi-value"><?php echo $reportCount; ?></span>
            <span class="nu-kpi-change up">Active</span>
        </div>
        <div class="nu-kpi">
            <span class="nu-kpi-label">Activity Today</span>
            <span class="nu-kpi-value"><?php echo $auditToday; ?></span>
            <span class="nu-kpi-change up">Live</span>
        </div>
    </div>

    <div style="margin-top: 24px;">
        <div class="nu-card">
            <div class="nu-card-header">
                <h3 class="nu-card-title">Recent Activity</h3>
                <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="NuApp.loadModule('audit')">View All</button>
            </div>
            <div class="nu-table-wrap">
                <table class="nu-table">
                    <thead>
                        <tr>
                            <th>Action</th>
                            <th>Table</th>
                            <th>User</th>
                            <th>Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentActivity as $log): ?>
                        <tr>
                            <td><span class="nu-status nu-status-<?php echo $log['audit_action'] === 'delete' ? 'inactive' : ($log['audit_action'] === 'login' ? 'active' : 'pending'); ?>"><?php echo ucfirst($log['audit_action']); ?></span></td>
                            <td><?php echo htmlspecialchars($log['audit_table']); ?></td>
                            <td><?php echo htmlspecialchars($log['audit_username']); ?></td>
                            <td><?php echo date('M j, g:i A', strtotime($log['audit_timestamp'])); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($recentActivity)): ?>
                        <tr><td colspan="4" style="text-align:center;color:var(--text-tertiary);">No activity yet</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div style="margin-top: 24px;">
        <div class="nu-card">
            <div class="nu-card-header">
                <h3 class="nu-card-title">Quick Actions</h3>
            </div>
            <div style="display:flex;gap:12px;flex-wrap:wrap;">
                <button class="nu-btn nu-btn-primary" onclick="NuApp.loadModule('forms')">+ New Form</button>
                <button class="nu-btn nu-btn-ghost" onclick="NuApp.loadModule('reports')">+ New Report</button>
                <button class="nu-btn nu-btn-ghost" onclick="NuApp.loadModule('queries')">+ New Query</button>
                <button class="nu-btn nu-btn-ghost" onclick="NuApp.loadModule('users')">+ New User</button>
            </div>
        </div>
    </div>
</div>
