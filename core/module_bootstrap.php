<?php
declare(strict_types=1);
/**
 * module_bootstrap.php
 *
 * Include this as the VERY FIRST statement in every module PHP file.
 * It MUST come before any session_start() call.
 *
 * What it does:
 *  1. Loads config.php  — which sets session_name('nu5sess') then calls
 *     session_start(). This is the only correct entry point for sessions.
 *  2. Loads Database.php and Auth.php.
 *  3. Calls $auth->requireAuth() — returns 401 JSON/text and exits if the
 *     user is not logged in.
 *
 * Usage in a module:
 *   <?php
 *   require_once dirname(__DIR__, 2) . '/core/module_bootstrap.php';
 *   // $auth and $nuConfig are now available.
 *   // Session is open under the correct 'nu5sess' name.
 */

$_mbRoot = dirname(__DIR__);

require_once $_mbRoot . '/config.php';      // sets session_name + session_start
require_once $_mbRoot . '/core/Database.php';
require_once $_mbRoot . '/core/Auth.php';

$auth = NuAuth::getInstance();

// Enforce authentication — exits with 401 if not logged in.
$auth->requireAuth();

unset($_mbRoot);
