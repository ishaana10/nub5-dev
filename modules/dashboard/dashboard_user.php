<?php
declare(strict_types=1);
/**
 * modules/dashboard/dashboard_user.php
 * USER DASHBOARD — shown to all non-admin roles.
 * Clean welcome view: no system KPIs, no admin actions.
 */
require_once dirname(__DIR__, 2) . '/core/module_bootstrap.php';

ini_set('display_errors', '0');
error_reporting(E_ALL);

$db          = NuDatabase::getInstance();
$username    = htmlspecialchars((string)($_SESSION['nu_username'] ?? $_SESSION['nu_name'] ?? 'there'));
$userRole    = htmlspecialchars((string)($_SESSION['nu_role'] ?? ''));
$currentUser = $_SESSION['nu_user_id'] ?? 0;

// ── Only this user’s recent activity ────────────────────────────────────────
try {
    $myActivity = $db->fetchAll(
        "SELECT audit_action, audit_table, audit_timestamp
         FROM   nu_audit_log
         WHERE  audit_user_id = ?
         ORDER  BY audit_timestamp DESC
         LIMIT  6",
        [$currentUser]
    );
} catch (Throwable $e) {
    error_log('[dashboard_user] activity error: ' . $e->getMessage());
    $myActivity = [];
}

// ── How many accessible forms this user can open ──────────────────────────
try {
    $myFormCount = (int)($db->fetchOne(
        "SELECT COUNT(*) as total FROM nu_forms WHERE form_active = 1"
    )['total'] ?? 0);
} catch (Throwable) {
    $myFormCount = 0;
}

try {
    $myReportCount = (int)($db->fetchOne(
        "SELECT COUNT(*) as total FROM nu_reports WHERE report_active = 1"
    )['total'] ?? 0);
} catch (Throwable) {
    $myReportCount = 0;
}

// ── Greeting based on time of day ─────────────────────────────────────────
$hour = (int)date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
?>

<div class="nu-dashboard">

    <!-- Welcome card -->
    <div class="nu-card" style="margin-bottom:24px;background:linear-gradient(135deg,var(--color-primary,#01696f) 0%,var(--color-primary-hover,#0c4e54) 100%);border:none;">
        <div style="padding:8px 4px;">
            <div style="font-size:var(--text-lg,1.125rem);font-weight:700;color:#fff;margin-bottom:4px;">
                <?= $greeting ?>, <?= $username ?>! 👋
            </div>
            <div style="font-size:var(--text-sm,0.875rem);color:rgba(255,255,255,0.8);">
                You are signed in as <strong><?= $userRole ?></strong>. Here’s your workspace.
            </div>
        </div>
    </div>

    <!-- Quick stats — only what the user cares about -->
    <div class="nu-grid" style="margin-bottom:24px;">
        <div class="nu-kpi">
            <span class="nu-kpi-label">Available Forms</span>
            <span class="nu-kpi-value"><?= $myFormCount ?></span>
            <span class="nu-kpi-change up">Open</span>
        </div>
        <div class="nu-kpi">
            <span class="nu-kpi-label">Available Reports</span>
            <span class="nu-kpi-value"><?= $myReportCount ?></span>
            <span class="nu-kpi-change up">Open</span>
        </div>
    </div>

    <!-- Quick links — no admin actions -->
    <div style="margin-bottom:24px;">
        <div class="nu-card">
            <div class="nu-card-header"><h3 class="nu-card-title">Quick Links</h3></div>
            <div style="display:flex;gap:12px;flex-wrap:wrap;">
                <button class="nu-btn nu-btn-primary" onclick="NuApp.loadModule('forms')">
                    📄 Open Forms
                </button>
                <button class="nu-btn nu-btn-ghost" onclick="NuApp.loadModule('reports')">
                    📊 Open Reports
                </button>
                <button class="nu-btn nu-btn-ghost" onclick="NuApp.loadModule('calendar')">
                    📅 Calendar
                </button>
                <button class="nu-btn nu-btn-ghost" onclick="NuApp.loadModule('password')">
                    🔒 Change Password
                </button>
            </div>
        </div>
    </div>

    <!-- My recent activity — only own actions -->
    <div>
        <div class="nu-card">
            <div class="nu-card-header">
                <h3 class="nu-card-title">My Recent Activity</h3>
            </div>
            <?php if (empty($myActivity)): ?>
            <div style="padding:32px;text-align:center;color:var(--color-text-muted,#888);">
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin:0 auto 12px;display:block;opacity:.4;">
                    <circle cx="12" cy="12" r="10"/>
                    <polyline points="12 6 12 12 16 14"/>
                </svg>
                <p style="margin:0;font-size:var(--text-sm,0.875rem);">No activity yet — start by opening a form.</p>
            </div>
            <?php else: ?>
            <div class="nu-table-wrap">
                <table class="nu-table">
                    <thead>
                        <tr><th>Action</th><th>Area</th><th>Time</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($myActivity as $log): ?>
                        <tr>
                            <td>
                                <span class="nu-status nu-status-<?= $log['audit_action'] === 'delete' ? 'inactive' : ($log['audit_action'] === 'login' ? 'active' : 'pending') ?>">
                                    <?= ucfirst(htmlspecialchars($log['audit_action'])) ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars($log['audit_table']) ?></td>
                            <td><?= date('M j, g:i A', strtotime($log['audit_timestamp'])) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

</div>
