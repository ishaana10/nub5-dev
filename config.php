<?php
declare(strict_types=1);
// nuBuilder 5 - Master Configuration

define('NU_VERSION', '5.0.0');
define('NU_BUILD_DATE', '2026-05-27');
define('NU_ROOT', __DIR__);

// ─── Database ──────────────────────────────────────────────────────────────────
$nuConfig['dbHost']     = getenv('NU_DB_HOST')    ?: 'localhost';
$nuConfig['dbName']     = getenv('NU_DB_NAME')    ?: 'your_db_name';
$nuConfig['dbUser']     = getenv('NU_DB_USER')    ?: 'your_db_user';
$nuConfig['dbPassword'] = getenv('NU_DB_PASS')    ?: 'your_db_password';
$nuConfig['dbCharset']  = 'utf8mb4';
$nuConfig['dbPort']     = (int)(getenv('NU_DB_PORT') ?: 3306);

// ─── Security ──────────────────────────────────────────────────────────────────
$nuConfig['sessionTimeout']        = 3600;
$nuConfig['maxLoginAttempts']      = 5;
$nuConfig['lockoutDuration']       = 900;
$nuConfig['csrfTokenName']         = 'nu_csrf';
$nuConfig['sessionCookieSecure']   = true;
$nuConfig['sessionCookieHttpOnly'] = true;
// Lax (NOT Strict) - Strict blocks cookie on POST->redirect
$nuConfig['sessionCookieSameSite'] = 'Lax';
$nuConfig['passwordMinLength']     = 10;

// ─── Features ──────────────────────────────────────────────────────────────────
$nuConfig['enable2FA']         = false;
$nuConfig['enableAPI']         = true;
$nuConfig['enableAuditTrail']  = true;
$nuConfig['enableFileUploads'] = true;
$nuConfig['maxUploadSize']     = 10485760;
$nuConfig['allowedFileTypes']  = ['pdf','doc','docx','xls','xlsx','jpg','jpeg','png','gif','csv'];

// ─── Paths ─────────────────────────────────────────────────────────────────────
$nuConfig['baseUrl']      = rtrim(getenv('NU_BASE_URL') ?: '/nbv5u/m/', '/') . '/';
$nuConfig['uploadPath']   = NU_ROOT . '/uploads/';
$nuConfig['logPath']      = NU_ROOT . '/logs/';
$nuConfig['sessionPath']  = NU_ROOT . '/sessions/';

// ─── Display ───────────────────────────────────────────────────────────────────
$nuConfig['siteTitle'] = 'NuBuilder 5';
$nuConfig['theme']     = 'auto';

// ─── API ───────────────────────────────────────────────────────────────────────
$nuConfig['apiRateLimit'] = 1000;
$nuConfig['apiKeyHeader'] = 'X-API-Key';

// ─── PHP Error Handling ────────────────────────────────────────────────────────
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);
ini_set('log_errors', '1');

// ─── Session Save Path ─────────────────────────────────────────────────────────
// Use app-local sessions/ dir instead of /tmp
// Shared hosts often isolate /tmp per-process, causing session data loss on redirect
$sessionPath = NU_ROOT . '/sessions';
if (!is_dir($sessionPath)) {
    mkdir($sessionPath, 0700, true);
}

// ─── Session Hardening + Start ─────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode',  '1');
    ini_set('session.cookie_httponly',  '1');
    ini_set('session.cookie_samesite',  'Lax');
    ini_set('session.cookie_secure',    $nuConfig['sessionCookieSecure'] ? '1' : '0');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime',   (string)$nuConfig['sessionTimeout']);
    ini_set('session.cookie_path',      '/');
    session_save_path($sessionPath);
    session_name('nu5sess');
    session_start();
}

// ─── Local Override ────────────────────────────────────────────────────────────
$_localOverride = NU_ROOT . '/config.local.php';
if (is_file($_localOverride)) {
    require $_localOverride;
}
unset($_localOverride);
