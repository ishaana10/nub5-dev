<?php
declare(strict_types=1);
// nuBuilder 5 - Master Configuration
// SECURITY: Never commit real credentials. Use environment variables or a local override.
// Copy this file to config.local.php for local overrides (add config.local.php to .gitignore)

define('NU_VERSION', '5.0.0');
define('NU_BUILD_DATE', '2026-05-27');
define('NU_ROOT', __DIR__);

// ─── Database ──────────────────────────────────────────────────────────────────
// Reads from environment variables first, falls back to local override file.
// Set these in your server environment or cPanel ENV variables.
$nuConfig['dbHost']    = getenv('NU_DB_HOST')    ?: 'localhost';
$nuConfig['dbName']    = getenv('NU_DB_NAME')    ?: 'your_db_name';
$nuConfig['dbUser']    = getenv('NU_DB_USER')    ?: 'your_db_user';
$nuConfig['dbPassword']= getenv('NU_DB_PASS')    ?: 'your_db_password';
$nuConfig['dbCharset'] = 'utf8mb4';
$nuConfig['dbPort']    = (int)(getenv('NU_DB_PORT') ?: 3306);

// ─── Security ──────────────────────────────────────────────────────────────────
$nuConfig['sessionTimeout']     = 3600;       // seconds
$nuConfig['maxLoginAttempts']   = 5;
$nuConfig['lockoutDuration']    = 900;        // 15 minutes
$nuConfig['csrfTokenName']      = 'nu_csrf';
$nuConfig['sessionCookieSecure']= true;       // set false only on local non-HTTPS dev
$nuConfig['sessionCookieHttpOnly'] = true;
$nuConfig['sessionCookieSameSite'] = 'Strict';
$nuConfig['passwordMinLength']  = 10;

// ─── Features ──────────────────────────────────────────────────────────────────
$nuConfig['enable2FA']          = false;      // enable when TOTP lib is ready
$nuConfig['enableAPI']          = true;
$nuConfig['enableAuditTrail']   = true;
$nuConfig['enableFileUploads']  = true;
$nuConfig['maxUploadSize']      = 10485760;   // 10 MB
$nuConfig['allowedFileTypes']   = ['pdf','doc','docx','xls','xlsx','jpg','jpeg','png','gif','csv'];

// ─── Paths ─────────────────────────────────────────────────────────────────────
$nuConfig['baseUrl']    = rtrim(getenv('NU_BASE_URL') ?: '/nb5u/', '/')  . '/';
$nuConfig['uploadPath'] = NU_ROOT . '/uploads/';
$nuConfig['logPath']    = NU_ROOT . '/logs/';

// ─── Display ───────────────────────────────────────────────────────────────────
$nuConfig['siteTitle']  = 'NuBuilder 5';
$nuConfig['theme']      = 'auto'; // auto | light | dark

// ─── API ───────────────────────────────────────────────────────────────────────
$nuConfig['apiRateLimit'] = 1000; // requests per hour per token
$nuConfig['apiKeyHeader'] = 'X-API-Key';

// ─── PHP Error Handling ────────────────────────────────────────────────────────
ini_set('display_errors', '0');   // NEVER show errors in production
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('error_log', $nuConfig['logPath'] . 'php_errors.log');

// ─── Session Hardening ─────────────────────────────────────────────────────────
// Only configure session if not already started
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', $nuConfig['sessionCookieSameSite']);
    if ($nuConfig['sessionCookieSecure']) {
        ini_set('session.cookie_secure', '1');
    }
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', (string)$nuConfig['sessionTimeout']);
    session_name('nu5sess');
}

// ─── Local Override ────────────────────────────────────────────────────────────
// Create config.local.php with real credentials. Never commit that file.
$_localOverride = NU_ROOT . '/config.local.php';
if (is_file($_localOverride)) {
    require $_localOverride;
}
unset($_localOverride);
