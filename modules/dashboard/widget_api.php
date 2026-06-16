<?php
declare(strict_types=1);
/**
 * modules/dashboard/widget_api.php
 * Dashboard widget REST API.
 *
 * GET  ?action=list             — widgets for current user (personal overrides role defaults)
 * GET  ?action=list_tables      — tables available for widget config (globeadmin only)
 * POST action=add               — add a widget (personal or role-level if globeadmin)
 * POST action=remove            — remove a widget
 * POST action=reorder           — update widget positions after drag-drop
 * POST action=update            — update widget config / title / size
 * POST action=reset             — delete all personal widgets → revert to role defaults
 */
require_once dirname(__DIR__, 2) . '/core/module_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$db      = NuDatabase::getInstance();
$action  = $_GET['action'] ?? ($_POST['action'] ?? '');
$userId  = (int)($_SESSION['nu_user_id'] ?? 0);
$role    = strtolower((string)($_SESSION['nu_role'] ?? ''));
$isAdmin = in_array($role, ['globeadmin', 'admin'], true);

// ── helpers ────────────────────────────────────────────────────
function wu_json(array $data): never {
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function wu_safe_sql(string $sql, int $userId): string {
    return str_replace('{{user_id}}', (string)$userId, $sql);
}

// ── GET: list ─────────────────────────────────────────────────
if ($action === 'list') {
    $personal = $db->fetchAll(
        "SELECT * FROM nu_dashboard_widgets
         WHERE widget_user_id = ? AND widget_active = 1
         ORDER BY widget_position",
        [$userId]
    );
    if (!empty($personal)) {
        wu_json(['widgets' => $personal, 'source' => 'personal']);
    }
    $roleWidgets = $db->fetchAll(
        "SELECT * FROM nu_dashboard_widgets
         WHERE widget_user_id IS NULL AND widget_role = ? AND widget_active = 1
         ORDER BY widget_position",
        [$role]
    );
    wu_json(['widgets' => $roleWidgets, 'source' => 'role']);
}

// ── GET: list_tables ──────────────────────────────────────────
if ($action === 'list_tables') {
    if (!$isAdmin) wu_json(['error' => 'Forbidden']);
    try {
        $rows   = $db->fetchAll("SHOW TABLES");
        $tables = array_map(fn($r) => array_values($r)[0], $rows);
        wu_json(['tables' => $tables]);
    } catch (Throwable $e) {
        wu_json(['error' => $e->getMessage()]);
    }
}

// ── POST actions ──────────────────────────────────────────────
$body = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw  = file_get_contents('php://input');
    $body = json_decode($raw, true) ?? $_POST;
}

if ($action === 'add') {
    $type   = preg_replace('/[^a-z_]/', '', (string)($body['type'] ?? ''));
    $title  = substr((string)($body['title'] ?? 'Widget'), 0, 120);
    $config = $body['config'] ?? [];
    $width  = max(1, min(4, (int)($body['width']  ?? 2)));
    $height = max(1, min(3, (int)($body['height'] ?? 1)));
    $icon   = substr((string)($body['icon'] ?? ''), 0, 60);

    // Role-level widget: globeadmin only, pass target_role
    $targetRole = null;
    $targetUser = $userId;
    if ($isAdmin && !empty($body['target_role'])) {
        $targetRole = substr((string)$body['target_role'], 0, 60);
        $targetUser = null;
    }

    // FIX: position query matches the actual scope being inserted into
    if ($targetRole !== null) {
        // role-level widget
        $maxPos = (int)($db->fetchOne(
            "SELECT COALESCE(MAX(widget_position),0) as m FROM nu_dashboard_widgets
             WHERE widget_user_id IS NULL AND widget_role = ?",
            [$targetRole]
        )['m'] ?? 0);
    } else {
        // personal widget
        $maxPos = (int)($db->fetchOne(
            "SELECT COALESCE(MAX(widget_position),0) as m FROM nu_dashboard_widgets
             WHERE widget_user_id = ?",
            [$targetUser]
        )['m'] ?? 0);
    }

    $db->query(
        "INSERT INTO nu_dashboard_widgets
         (widget_user_id, widget_role, widget_type, widget_title, widget_icon, widget_config, widget_width, widget_height, widget_position)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [$targetUser, $targetRole, $type, $title, $icon ?: null,
         json_encode($config), $width, $height, $maxPos + 10]
    );
    wu_json(['ok' => true, 'id' => $db->lastInsertId()]);
}

if ($action === 'remove') {
    $id = (int)($body['id'] ?? 0);
    $db->query(
        "UPDATE nu_dashboard_widgets SET widget_active = 0
         WHERE widget_id = ? AND (widget_user_id = ? OR ? = 1)",
        [$id, $userId, (int)$isAdmin]
    );
    wu_json(['ok' => true]);
}

if ($action === 'reorder') {
    $order = (array)($body['order'] ?? []);
    foreach ($order as $item) {
        $id  = (int)($item['id']       ?? 0);
        $pos = (int)($item['position'] ?? 0);
        if (!$id) continue;
        $db->query(
            "UPDATE nu_dashboard_widgets SET widget_position = ?
             WHERE widget_id = ? AND (widget_user_id = ? OR ? = 1)",
            [$pos, $id, $userId, (int)$isAdmin]
        );
    }
    wu_json(['ok' => true]);
}

if ($action === 'update') {
    $id     = (int)($body['id'] ?? 0);
    $title  = substr((string)($body['title'] ?? ''), 0, 120);
    $config = json_encode($body['config'] ?? []);
    // FIX: default width/height to existing values — fetch first
    $existing = $db->fetchOne(
        "SELECT widget_width, widget_height FROM nu_dashboard_widgets WHERE widget_id = ?",
        [$id]
    );
    $width  = max(1, min(4, (int)($body['width']  ?? $existing['widget_width']  ?? 2)));
    $height = max(1, min(3, (int)($body['height'] ?? $existing['widget_height'] ?? 1)));
    $db->query(
        "UPDATE nu_dashboard_widgets
         SET widget_title=?, widget_config=?, widget_width=?, widget_height=?
         WHERE widget_id=? AND (widget_user_id=? OR ?=1)",
        [$title, $config, $width, $height, $id, $userId, (int)$isAdmin]
    );
    wu_json(['ok' => true]);
}

if ($action === 'reset') {
    $db->query(
        "UPDATE nu_dashboard_widgets SET widget_active=0
         WHERE widget_user_id=?",
        [$userId]
    );
    wu_json(['ok' => true]);
}

if ($action === 'run_sql') {
    $sql = wu_safe_sql((string)($body['sql'] ?? ''), $userId);
    if (!preg_match('/^\s*SELECT\b/i', $sql)) {
        wu_json(['error' => 'Only SELECT statements allowed']);
    }
    try {
        $rows = $db->fetchAll($sql);
        wu_json(['rows' => $rows]);
    } catch (Throwable $e) {
        wu_json(['error' => $e->getMessage()]);
    }
}

wu_json(['error' => 'Unknown action']);
