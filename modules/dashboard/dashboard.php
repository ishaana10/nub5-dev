<?php
declare(strict_types=1);
/**
 * modules/dashboard/dashboard.php
 * ADMIN DASHBOARD — globeadmin and admin only.
 * Reached via index.php router; access guard below is a safety net.
 */
require_once dirname(__DIR__, 2) . '/core/module_bootstrap.php';

ini_set('display_errors', '0');
error_reporting(E_ALL);

// ── Guard — only globeadmin and admin ────────────────────────────────────────
$_dashRole = strtolower((string)($_SESSION['nu_role'] ?? ''));
if ($_dashRole !== 'globeadmin' && $_dashRole !== 'admin') {
    // Redirect non-admin users to their own dashboard
    require __DIR__ . '/dashboard_user.php';
    return;
}

$isGlobeAdmin = ($_dashRole === 'globeadmin');

// ── Data ────────────────────────────────────────────────────────────────────
$db = NuDatabase::getInstance();

function nu_safe_count(NuDatabase $db, string $sql): int {
    try {
        $r = $db->fetchOne($sql);
        return (int)($r['total'] ?? 0);
    } catch (Throwable $e) {
        error_log('[dashboard] count_error: ' . $e->getMessage());
        return 0;
    }
}

$userCount   = nu_safe_count($db, "SELECT COUNT(*) as total FROM nu_users");
$formCount   = nu_safe_count($db, "SELECT COUNT(*) as total FROM nu_forms");
$reportCount = nu_safe_count($db, "SELECT COUNT(*) as total FROM nu_reports");
$auditToday  = nu_safe_count($db, "SELECT COUNT(*) as total FROM nu_audit_log WHERE DATE(audit_timestamp) = CURDATE()");

try {
    $recentActivity = $db->fetchAll("SELECT * FROM nu_audit_log ORDER BY audit_timestamp DESC LIMIT 8");
} catch (Throwable $e) {
    error_log('[dashboard] recentActivity error: ' . $e->getMessage());
    $recentActivity = [];
}
?>

<div class="nu-dashboard">

    <!-- Role badge -->
    <div style="margin-bottom:16px;display:flex;align-items:center;gap:10px;">
        <span style="font-size:var(--text-sm,0.875rem);font-weight:600;color:var(--color-text-muted,#888);">
            <?= $isGlobeAdmin ? '🛡️ Globe Admin Dashboard' : '📊 Admin Dashboard' ?>
        </span>
    </div>

    <!-- KPI row -->
    <div class="nu-grid">
        <div class="nu-kpi">
            <span class="nu-kpi-label">Total Users</span>
            <span class="nu-kpi-value"><?= $userCount ?></span>
            <span class="nu-kpi-change up">Registered</span>
        </div>
        <div class="nu-kpi">
            <span class="nu-kpi-label">Forms Built</span>
            <span class="nu-kpi-value"><?= $formCount ?></span>
            <span class="nu-kpi-change up">Active</span>
        </div>
        <div class="nu-kpi">
            <span class="nu-kpi-label">Reports</span>
            <span class="nu-kpi-value"><?= $reportCount ?></span>
            <span class="nu-kpi-change up">Active</span>
        </div>
        <div class="nu-kpi">
            <span class="nu-kpi-label">Activity Today</span>
            <span class="nu-kpi-value"><?= $auditToday ?></span>
            <span class="nu-kpi-change up">Live</span>
        </div>
    </div>

    <!-- Recent Activity -->
    <div style="margin-top:24px;">
        <div class="nu-card">
            <div class="nu-card-header">
                <h3 class="nu-card-title">Recent Activity</h3>
                <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="NuApp.loadModule('audit')">View All</button>
            </div>
            <div class="nu-table-wrap">
                <table class="nu-table">
                    <thead>
                        <tr><th>Action</th><th>Table</th><th>User</th><th>Time</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentActivity as $log): ?>
                        <tr>
                            <td>
                                <span class="nu-status nu-status-<?= $log['audit_action'] === 'delete' ? 'inactive' : ($log['audit_action'] === 'login' ? 'active' : 'pending') ?>">
                                    <?= ucfirst(htmlspecialchars($log['audit_action'])) ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars($log['audit_table']) ?></td>
                            <td><?= htmlspecialchars($log['audit_username']) ?></td>
                            <td><?= date('M j, g:i A', strtotime($log['audit_timestamp'])) ?></td>
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

    <!-- Quick Actions -->
    <div style="margin-top:24px;">
        <div class="nu-card">
            <div class="nu-card-header"><h3 class="nu-card-title">Quick Actions</h3></div>
            <div style="display:flex;gap:12px;flex-wrap:wrap;">
                <button class="nu-btn nu-btn-primary" onclick="NuApp.loadModule('forms')">+ New Form</button>
                <button class="nu-btn nu-btn-ghost"   onclick="NuApp.loadModule('reports')">+ New Report</button>
                <button class="nu-btn nu-btn-ghost"   onclick="NuApp.loadModule('queries')">+ New Query</button>
                <button class="nu-btn nu-btn-ghost"   onclick="NuApp.loadModule('users')">+ New User</button>
                <?php if ($isGlobeAdmin): ?>
                <!-- Edit Form mode — globeadmin only -->
                <button class="nu-btn nu-btn-ghost" style="color:var(--color-warning,#f59e0b);"
                        onclick="NuApp.loadModule('forms','__editmode__')">
                    ✏️ Edit Form Mode
                </button>
                <button class="nu-btn nu-btn-ghost" style="color:var(--color-warning,#f59e0b);"
                        onclick="NuApp.loadModule('inspector')">
                    🔍 DB Inspector
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>
