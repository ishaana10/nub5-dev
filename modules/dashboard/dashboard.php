<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

ini_set('display_errors', '0');
error_reporting(E_ALL);

// ─── Debug logger ─────────────────────────────────────────────────────────────
function nu_dash_log(string $label, $value = null): void {
    if (is_array($value) || is_object($value)) {
        $value = json_encode($value);
    }
    error_log('[dashboard] ' . $label . ($value !== null ? ': ' . $value : ''));
}

nu_dash_log('===== REQUEST START =====');
nu_dash_log('time', date('Y-m-d H:i:s'));
nu_dash_log('session_status', session_status());
nu_dash_log('session_id', session_id());
nu_dash_log('session_name', session_name());
nu_dash_log('request_uri', $_SERVER['REQUEST_URI'] ?? '');
nu_dash_log('http_host', $_SERVER['HTTP_HOST'] ?? '');
nu_dash_log('remote_addr', $_SERVER['REMOTE_ADDR'] ?? '');
nu_dash_log('cookies_received', $_COOKIE ?? []);
nu_dash_log('session_at_start', $_SESSION ?? []);

// ─── Bootstrap ────────────────────────────────────────────────────────────────
try {
    require_once dirname(__DIR__, 2) . '/config.php';
    $dbFile   = dirname(__DIR__, 2) . '/core/Database.php';
    $authFile = dirname(__DIR__, 2) . '/core/Auth.php';
    if (is_file($dbFile))   require_once $dbFile;
    if (is_file($authFile)) require_once $authFile;
    nu_dash_log('bootstrap', 'ok');
} catch (Throwable $e) {
    nu_dash_log('bootstrap_error', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    die('Module bootstrap error: ' . htmlspecialchars($e->getMessage()));
}

nu_dash_log('session_after_bootstrap', $_SESSION ?? []);
nu_dash_log('NuAuth_exists', class_exists('NuAuth') ? 'yes' : 'no');
nu_dash_log('getInstance_exists', (class_exists('NuAuth') && method_exists('NuAuth', 'getInstance')) ? 'yes' : 'no');

// ─── Auth ─────────────────────────────────────────────────────────────────────
try {
    if (class_exists('NuAuth') && method_exists('NuAuth', 'getInstance')) {
        $auth = NuAuth::getInstance();
        nu_dash_log('auth_mode', 'NuAuth::getInstance()');
    } else {
        $auth = new NuAuth();
        nu_dash_log('auth_mode', 'new NuAuth()');
    }

    nu_dash_log('session_after_auth_create', $_SESSION ?? []);

    $ok = $auth->checkAuth();

    nu_dash_log('checkAuth_result', $ok ? 'PASS' : 'FAIL');
    nu_dash_log('nu_user_id', $_SESSION['nu_user_id'] ?? 'NOT SET');
    nu_dash_log('nu_username', $_SESSION['nu_username'] ?? 'NOT SET');
    nu_dash_log('nu_role', $_SESSION['nu_role'] ?? 'NOT SET');
    nu_dash_log('nu_last_activity', $_SESSION['nu_last_activity'] ?? 'NOT SET');
    nu_dash_log('sessionTimeout', $GLOBALS['nuConfig']['sessionTimeout'] ?? 'NOT SET');

    if (!$ok) {
        $debug = [
            'session_id'       => session_id(),
            'session_name'     => session_name(),
            'nu_user_id'       => $_SESSION['nu_user_id'] ?? null,
            'nu_username'      => $_SESSION['nu_username'] ?? null,
            'nu_last_activity' => $_SESSION['nu_last_activity'] ?? null,
            'time_now'         => time(),
            'timeout'          => $GLOBALS['nuConfig']['sessionTimeout'] ?? null,
            'cookie_names'     => array_keys($_COOKIE ?? []),
            'session_keys'     => array_keys($_SESSION ?? []),
        ];
        nu_dash_log('UNAUTHORIZED_DEBUG', $debug);
        http_response_code(401);
        exit('Unauthorized | ' . htmlspecialchars(json_encode($debug)));
    }

} catch (Throwable $e) {
    nu_dash_log('auth_exception', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    exit('Dashboard auth exception: ' . htmlspecialchars($e->getMessage()));
}

nu_dash_log('auth_passed — loading dashboard');

// ─── Data ─────────────────────────────────────────────────────────────────────
$db = NuDatabase::getInstance();

function nu_safe_count(NuDatabase $db, string $sql): int {
    try {
        $r = $db->fetchOne($sql);
        return (int)($r['total'] ?? 0);
    } catch (Throwable $e) {
        error_log('[dashboard] count_error: ' . $e->getMessage() . ' SQL=' . $sql);
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
                            <td><span class="nu-status nu-status-<?= $log['audit_action'] === 'delete' ? 'inactive' : ($log['audit_action'] === 'login' ? 'active' : 'pending') ?>"><?= ucfirst(htmlspecialchars($log['audit_action'])) ?></span></td>
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

    <div style="margin-top:24px;">
        <div class="nu-card">
            <div class="nu-card-header"><h3 class="nu-card-title">Quick Actions</h3></div>
            <div style="display:flex;gap:12px;flex-wrap:wrap;">
                <button class="nu-btn nu-btn-primary" onclick="NuApp.loadModule('forms')">+ New Form</button>
                <button class="nu-btn nu-btn-ghost"   onclick="NuApp.loadModule('reports')">+ New Report</button>
                <button class="nu-btn nu-btn-ghost"   onclick="NuApp.loadModule('queries')">+ New Query</button>
                <button class="nu-btn nu-btn-ghost"   onclick="NuApp.loadModule('users')">+ New User</button>
            </div>
        </div>
    </div>
</div>
