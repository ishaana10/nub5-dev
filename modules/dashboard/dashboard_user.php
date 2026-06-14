<?php
declare(strict_types=1);
/**
 * modules/dashboard/dashboard_user.php
 * Non-admin dashboard — shows widget builder + a welcome card on first visit.
 */
require_once dirname(__DIR__, 2) . '/core/module_bootstrap.php';

ini_set('display_errors', '0');
error_reporting(E_ALL);

$username = htmlspecialchars((string)($_SESSION['nu_username'] ?? $_SESSION['nu_name'] ?? 'there'));
$role     = strtolower((string)($_SESSION['nu_role'] ?? ''));
$hour     = (int)date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
?>

<div class="nu-dashboard">
    <!-- Greeting banner -->
    <div class="nu-card" style="margin-bottom:20px;background:linear-gradient(135deg,var(--color-primary,#01696f) 0%,var(--color-primary-hover,#0c4e54) 100%);border:none;">
        <div style="padding:4px;">
            <div style="font-size:var(--text-lg,1.125rem);font-weight:700;color:#fff;margin-bottom:2px;">
                <?= $greeting ?>, <?= $username ?>! 👋
            </div>
            <div style="font-size:var(--text-sm,.875rem);color:rgba(255,255,255,.8);">
                Role: <strong><?= htmlspecialchars($role) ?></strong> &mdash; Customise your dashboard using the buttons below.
            </div>
        </div>
    </div>

    <!-- Widget builder (includes toolbar + grid + modal + JS) -->
    <?php require __DIR__ . '/../widgets/widgets.php'; ?>
</div>
