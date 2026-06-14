<?php
/**
 * modules/dashboard/index.php
 * Role-based dashboard router.
 *
 * globeadmin / admin  → dashboard.php  (full platform admin view)
 * everyone else       → dashboard_user.php (user welcome view)
 */
require_once dirname(__DIR__, 2) . '/core/module_bootstrap.php';

$_dRole = strtolower((string)($_SESSION['nu_role'] ?? ''));

if ($_dRole === 'globeadmin' || $_dRole === 'admin') {
    require __DIR__ . '/dashboard.php';
} else {
    require __DIR__ . '/dashboard_user.php';
}
