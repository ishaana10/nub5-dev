<?php
declare(strict_types=1);

// ─── Bootstrap ────────────────────────────────────────────────────────────────
$bootError  = '';
$loginError = '';
$isLoggedIn = false;
$currentUser = null;
$auth = null;

function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

try {
    require_once __DIR__ . '/config.php';
    require_once __DIR__ . '/core/Database.php';
    require_once __DIR__ . '/core/Auth.php';
    require_once __DIR__ . '/core/MenuRenderer.php';
    // ErrorLogger registered AFTER Database + Auth are loaded
    require_once __DIR__ . '/core/ErrorLogger.php';
    NuErrorLogger::register();
    $auth = NuAuth::getInstance();
} catch (Throwable $e) {
    // Show the REAL error message so you can diagnose it
    $realMsg = get_class($e) . ': ' . $e->getMessage()
             . ' in ' . $e->getFile() . ' on line ' . $e->getLine();
    error_log('[index.php boot] ' . $realMsg);
    $bootError = 'Application failed to start: ' . $realMsg;
}

// ─── Logout ───────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['logout'])) {
    if ($auth) $auth->logout();
    else { $_SESSION = []; session_destroy(); }
    header('Location: index.php');
    exit;
}

// ─── Login ────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_submit'])) {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    if ($username === '' || $password === '') {
        $loginError = 'Username and password are required.';
    } elseif ($auth === null) {
        $loginError = 'Authentication service unavailable. Check server configuration.';
    } else {
        try {
            $result = $auth->login($username, $password);
            if (!empty($result['success'])) {
                header('Location: index.php');
                exit;
            }
            $loginError = $result['message'] ?? 'Login failed.';
        } catch (Throwable $e) {
            error_log('[index.php login] ' . $e->getMessage());
            $loginError = 'Login error. Please try again.';
        }
    }
}

// ─── Auth Check ───────────────────────────────────────────────────────────────
if ($auth) {
    try {
        $isLoggedIn  = $auth->checkAuth();
        $currentUser = $isLoggedIn ? $auth->getCurrentUser() : null;
    } catch (Throwable $e) {
        error_log('[index.php auth check] ' . $e->getMessage());
        $isLoggedIn = false;
    }
}

$csrfToken   = $auth ? $auth->getCsrfToken() : '';
$userDisplay = 'User';
if (is_array($currentUser)) {
    $userDisplay = $currentUser['usr_name'] ?? $currentUser['usr_username'] ?? 'User';
}
// ── Inspector is visible to globeadmin OR admin ──────────────────────────────
$_role   = strtolower((string)($currentUser['usr_role'] ?? ''));
$isAdmin = ($_role === 'globeadmin' || $_role === 'admin');

// ─── Asset helpers ────────────────────────────────────────────────────────────
function nu_asset(string $path): string {
    $full = __DIR__ . '/' . ltrim($path, '/');
    $v    = is_file($full) ? filemtime($full) : time();
    return h(ltrim($path, '/')) . '?v=' . $v;
}

// ─── Build sidebar nav ────────────────────────────────────────────────────────
// NuMenuRenderer::render() returns HTML from nu_menus filtered by user role.
// If nu_menus is empty (not yet populated), $dynNav is '' and the static
// fallback nav below is shown instead.
$dynNav = '';
if ($isLoggedIn && $currentUser) {
    try {
        $dynNav = NuMenuRenderer::render($currentUser);
    } catch (Throwable $e) {
        error_log('[index.php menu] ' . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= h($nuConfig['theme'] ?? 'auto') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= h($nuConfig['siteTitle'] ?? 'NuBuilder 5') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap">
    <link rel="stylesheet" href="<?= nu_asset('assets/css/nubuilder-next.css') ?>">
    <link rel="stylesheet" href="<?= nu_asset('assets/css/select2.min.css') ?>">
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#0b1020">
</head>
<body>
<?php if (!$isLoggedIn): ?>
<!-- ══════════════════════════════════ LOGIN PAGE ══════════════════════════════════ -->
<div class="nu-login">
    <div class="nu-login-card">
        <div class="nu-login-brand">
            <div class="nu-logo">nu</div>
            <h1><?= h($nuConfig['siteTitle'] ?? 'NuBuilder 5') ?></h1>
            <p>Modern Low-Code Platform</p>
        </div>

        <?php if ($bootError !== ''): ?>
            <div class="nu-login-error" style="background:rgba(255,193,7,.12);border-color:rgba(255,193,7,.4);color:#ffe9a8;display:block;word-break:break-word;font-size:0.8rem;">
                ⚠ <?= h($bootError) ?>
            </div>
        <?php endif; ?>

        <?php if ($loginError !== ''): ?>
            <div class="nu-login-error" style="display:block">
                <?= h($loginError) ?>
            </div>
        <?php endif; ?>

        <form method="post" action="index.php" autocomplete="off" novalidate>
            <input type="hidden" name="nu_csrf" value="<?= h($csrfToken) ?>">
            <div class="nu-field">
                <label for="nu_username">Username</label>
                <input id="nu_username" name="username" type="text"
                       class="nu-input" autocomplete="username"
                       value="" required autofocus spellcheck="false">
            </div>
            <div class="nu-field">
                <label for="nu_password">Password</label>
                <input id="nu_password" name="password" type="password"
                       class="nu-input" autocomplete="current-password" required>
            </div>
            <button type="submit" name="login_submit" value="1"
                    class="nu-btn nu-btn-primary nu-btn-block">
                Sign In
            </button>
        </form>
    </div>
</div>

<?php else: ?>
<!-- ════════════════════════════════ APP SHELL ════════════════════════════════ -->
<div class="nu-app" id="nuApp">

    <!-- Sidebar -->
    <aside class="nu-sidebar" id="sidebar">
        <div class="nu-sidebar-header">
            <div class="nu-logo">nu</div>
            <span class="nu-version">v<?= NU_VERSION ?></span>
        </div>

        <?php if ($dynNav !== ''): ?>
            <?= $dynNav ?>
        <?php else: ?>
        <!-- ══ Static fallback nav (shown until nu_menus is populated) ══ -->
        <nav class="nu-nav">

            <!-- ── Main ── -->
            <div style="margin:12px 8px 4px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-muted,#888);padding:0 4px;">Main</div>
            <a href="#dashboard" class="nu-nav-item" data-module="dashboard"
               onclick="NuApp.loadModule('dashboard'); return false;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/>
                    <rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>
                </svg>
                <span>Dashboard</span>
            </a>
            <a href="#forms" class="nu-nav-item" data-module="forms"
               onclick="NuApp.loadModule('forms'); return false;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                    <polyline points="14 2 14 8 20 8"/>
                    <line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>
                </svg>
                <span>Forms</span>
            </a>
            <a href="#reports" class="nu-nav-item" data-module="reports"
               onclick="NuApp.loadModule('reports'); return false;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21.21 15.89A10 10 0 1 1 8 2.83"/><path d="M22 12A10 10 0 0 0 12 2v10z"/>
                </svg>
                <span>Reports</span>
            </a>
            <a href="#queries" class="nu-nav-item" data-module="queries"
               onclick="NuApp.loadModule('queries'); return false;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <ellipse cx="12" cy="5" rx="9" ry="3"/>
                    <path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/>
                    <path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/>
                </svg>
                <span>Queries</span>
            </a>
            <a href="#calendar" class="nu-nav-item" data-module="calendar"
               onclick="NuApp.loadModule('calendar'); return false;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                    <line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/>
                    <line x1="3" y1="10" x2="21" y2="10"/>
                </svg>
                <span>Calendar</span>
            </a>
            <a href="#ai" class="nu-nav-item" data-module="ai"
               onclick="NuApp.loadModule('ai'); return false;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 2a10 10 0 1 0 10 10H12V2z"/>
                    <path d="M12 2a10 10 0 0 1 10 10"/>
                </svg>
                <span>AI Assistant</span>
            </a>
            <a href="#integrations" class="nu-nav-item" data-module="integrations"
               onclick="NuApp.loadModule('integrations'); return false;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/>
                    <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>
                </svg>
                <span>Integrations</span>
            </a>

            <!-- ── Admin Tools section ── -->
            <?php if ($isAdmin): ?>
            <div style="margin:12px 8px 4px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-muted,#888);padding:0 4px;">Admin Tools</div>
            <a href="#menus" class="nu-nav-item" data-module="menus"
               onclick="NuApp.loadModule('menus'); return false;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="3" y1="12" x2="21" y2="12"/>
                    <line x1="3" y1="6" x2="21" y2="6"/>
                    <line x1="3" y1="18" x2="21" y2="18"/>
                    <circle cx="20" cy="6" r="2" fill="currentColor" stroke="none"/>
                    <circle cx="20" cy="12" r="2" fill="currentColor" stroke="none"/>
                    <circle cx="20" cy="18" r="2" fill="currentColor" stroke="none"/>
                </svg>
                <span>Menus</span>
            </a>
            <a href="#users" class="nu-nav-item" data-module="users"
               onclick="NuApp.loadModule('users'); return false;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                    <circle cx="9" cy="7" r="4"/>
                    <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                    <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                </svg>
                <span>Users</span>
            </a>
            <a href="#roles" class="nu-nav-item" data-module="roles"
               onclick="NuApp.loadModule('roles'); return false;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                    <line x1="12" y1="8" x2="12" y2="12"/>
                    <line x1="12" y1="16" x2="12.01" y2="16"/>
                </svg>
                <span>Roles</span>
            </a>
            <a href="#audit" class="nu-nav-item" data-module="audit"
               onclick="NuApp.loadModule('audit'); return false;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                    <polyline points="14 2 14 8 20 8"/>
                    <line x1="16" y1="13" x2="8" y2="13"/>
                    <line x1="16" y1="17" x2="8" y2="17"/>
                    <circle cx="17" cy="17" r="3"/>
                    <line x1="21" y1="21" x2="19.1" y2="19.1"/>
                </svg>
                <span>Audit Trail</span>
            </a>
            <a href="#files" class="nu-nav-item" data-module="files"
               onclick="NuApp.loadModule('files'); return false;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>
                </svg>
                <span>Files</span>
            </a>
            <a href="#workflow" class="nu-nav-item" data-module="workflow"
               onclick="NuApp.loadModule('workflow'); return false;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
                </svg>
                <span>Workflow</span>
            </a>
            <a href="#inspector" class="nu-nav-item" data-module="inspector"
               onclick="NuApp.loadModule('inspector'); return false;"
               style="color:var(--warning,#f59e0b);">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <ellipse cx="12" cy="5" rx="9" ry="3"/>
                    <path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/>
                    <path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/>
                    <line x1="19" y1="19" x2="23" y2="23"/>
                    <circle cx="19" cy="19" r="3"/>
                </svg>
                <span>DB &amp; Server Inspector</span>
            </a>
            <a href="#errorlog" class="nu-nav-item" data-module="errorlog"
               onclick="NuApp.loadModule('errorlog'); return false;"
               style="color:var(--warning,#f59e0b);">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="12" y1="8" x2="12" y2="12"/>
                    <line x1="12" y1="16" x2="12.01" y2="16"/>
                </svg>
                <span>Error Log</span>
            </a>
            <a href="#password_policy" class="nu-nav-item" data-module="password_policy"
               onclick="NuApp.loadModule('password_policy'); return false;"
               style="color:var(--warning,#f59e0b);">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                    <polyline points="9 12 11 14 15 10"/>
                </svg>
                <span>Password Policy</span>
            </a>
            <a href="#appcloner" class="nu-nav-item" data-module="appcloner"
               onclick="NuApp.loadModule('appcloner'); return false;"
               style="color:var(--warning,#f59e0b);">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="9" y="9" width="13" height="13" rx="2" ry="2"/>
                    <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>
                </svg>
                <span>App Cloner</span>
            </a>
            <?php endif; ?>

            <!-- ── Personal section (every user) ── -->
            <div style="margin:12px 8px 4px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-muted,#888);padding:0 4px;">Personal</div>
            <a href="#password" class="nu-nav-item" data-module="password"
               onclick="NuApp.loadModule('password'); return false;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                    <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                    <line x1="12" y1="16" x2="12" y2="16"/>
                    <circle cx="12" cy="16" r="1" fill="currentColor"/>
                </svg>
                <span>Change Password</span>
            </a>

        </nav>
        <?php endif; ?>

        <div class="nu-sidebar-footer">
            <div class="nu-user-info">
                <div class="nu-user-name"><?= h($userDisplay) ?></div>
                <div class="nu-user-role"><?= h($currentUser['usr_role'] ?? '') ?></div>
            </div>
            <form method="post" action="index.php" style="margin:0">
                <input type="hidden" name="nu_csrf" value="<?= h($csrfToken) ?>">
                <button type="submit" name="logout" value="1"
                        class="nu-btn nu-btn-ghost nu-btn-sm" style="margin-top:8px;width:100%">
                    Logout
                </button>
            </form>
        </div>
    </aside>

    <!-- Main -->
    <main class="nu-main">
        <header class="nu-header">
            <button class="nu-menu-btn" id="menuBtn" title="Toggle sidebar"
                    onclick="(function(){
                        var app = document.getElementById('nuApp');
                        var isMobile = window.innerWidth <= 768;
                        if (isMobile) {
                            document.getElementById('sidebar').classList.toggle('open');
                            document.getElementById('overlay').classList.toggle('open');
                        } else {
                            app.classList.toggle('sidebar-collapsed');
                            try { localStorage.setItem('nu-sidebar-collapsed', app.classList.contains('sidebar-collapsed') ? '1' : '0'); } catch(e){}
                        }
                    })()">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="3" y1="12" x2="21" y2="12"/>
                    <line x1="3" y1="6" x2="21" y2="6"/>
                    <line x1="3" y1="18" x2="21" y2="18"/>
                </svg>
            </button>
            <h2 class="nu-page-title" id="pageTitle">Dashboard</h2>
            <div class="nu-header-actions">
                <button class="nu-btn nu-btn-ghost" title="Toggle theme"
                        onclick="(function(){
                            var t=document.documentElement.getAttribute('data-theme');
                            var n=t==='light'?'dark':t==='dark'?'auto':'light';
                            document.documentElement.setAttribute('data-theme',n);
                            try{localStorage.setItem('nu-theme',n);}catch(e){}
                        })()">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="5"/>
                        <line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/>
                        <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/>
                        <line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/>
                        <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>
                    </svg>
                </button>
            </div>
        </header>

        <div class="nu-content" id="contentArea">
            <div class="nu-spinner" style="margin:40px auto"></div>
        </div>
    </main>

    <div class="nu-overlay" id="overlay"
         onclick="document.getElementById('sidebar').classList.remove('open');this.classList.remove('open')"></div>
</div>

<?php endif; ?>

<?php if ($isLoggedIn): ?>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="<?= nu_asset('assets/js/select2.min.js') ?>"></script>
<script src="<?= nu_asset('assets/js/nu-select2-init.js') ?>"></script>
<script src="<?= nu_asset('assets/js/nubuilder-next.js') ?>"></script>
<script src="<?= nu_asset('assets/js/nb-form-builder.js') ?>"></script>
<script src="<?= nu_asset('assets/js/nusubform.js') ?>"></script>
<script src="<?= nu_asset('assets/js/nu-errorlogger.js') ?>"></script>
<script>
(function () {
    // Restore theme
    try {
        var t = localStorage.getItem('nu-theme');
        if (t) document.documentElement.setAttribute('data-theme', t);
    } catch (e) {}

    // Restore sidebar collapsed state
    try {
        if (localStorage.getItem('nu-sidebar-collapsed') === '1') {
            var app = document.getElementById('nuApp');
            if (app) app.classList.add('sidebar-collapsed');
        }
    } catch (e) {}

    // NOTE: Nav group toggle is handled entirely by NuApp.bindEvents()
    // in nubuilder-next.js via event delegation on .nu-nav.
    // Do NOT set display:none on .nu-nav-children here — the CSS
    // max-height transition controls visibility instead.

    function _boot() {
        if (!window.NuApp) return;
        var hash = (location.hash || '').replace('#', '');
        NuApp.loadModule(hash || 'dashboard');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', _boot);
    } else {
        _boot();
    }
})();
</script>
<?php endif; ?>

<?php if (!$isLoggedIn): ?>
<script>
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('sw.js').catch(function () {});
}
</script>
<?php endif; ?>

</body>
</html>
