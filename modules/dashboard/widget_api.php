<?php
declare(strict_types=1);
/**
 * modules/dashboard/widget_api.php
 */
require_once dirname(__DIR__, 2) . '/core/module_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$db      = NuDatabase::getInstance();
$action  = $_GET['action'] ?? ($_POST['action'] ?? '');
$userId  = (int)($_SESSION['nu_user_id'] ?? 0);
$role    = strtolower((string)($_SESSION['nu_role'] ?? ''));
$isAdmin      = in_array($role, ['globeadmin', 'admin'], true);
$isGlobeAdmin = ($role === 'globeadmin');

function wu_json(array $data): never {
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function wu_safe_sql(string $sql, int $userId): string {
    return str_replace('{{user_id}}', (string)$userId, $sql);
}

// ── GET: list ─────────────────────────────────────────────────────────────────
if ($action === 'list') {
    $personal = $db->fetchAll(
        "SELECT * FROM nu_dashboard_widgets WHERE widget_user_id=? AND widget_active=1 ORDER BY widget_position",
        [$userId]
    );
    if (!empty($personal)) wu_json(['widgets' => $personal, 'source' => 'personal']);
    $roleWidgets = $db->fetchAll(
        "SELECT * FROM nu_dashboard_widgets WHERE widget_user_id IS NULL AND widget_role=? AND widget_active=1 ORDER BY widget_position",
        [$role]
    );
    wu_json(['widgets' => $roleWidgets ?: [], 'source' => 'role']);
}

// ── GET: list_roles — dynamic roles from DB ───────────────────────────────────
if ($action === 'list_roles') {
    if (!$isGlobeAdmin) wu_json(['error' => 'Forbidden']);
    try {
        $rows  = $db->fetchAll("SELECT role_code, role_name FROM nu_roles ORDER BY role_name");
        // Exclude globeadmin from the assignable list
        $roles = array_filter($rows, fn($r) => strtolower($r['role_code']) !== 'globeadmin');
        wu_json(['roles' => array_values($roles)]);
    } catch (Throwable $e) {
        // Fallback if table name differs
        wu_json(['roles' => [
            ['role_code' => 'user',       'role_name' => 'User'],
            ['role_code' => 'manager',    'role_name' => 'Manager'],
            ['role_code' => 'supervisor', 'role_name' => 'Supervisor'],
            ['role_code' => 'admin',      'role_name' => 'Admin'],
        ]]);
    }
}

// ── GET: list_tables ──────────────────────────────────────────────────────────
if ($action === 'list_tables') {
    if (!$isAdmin) wu_json(['error' => 'Forbidden']);
    try {
        $rows   = $db->fetchAll("SHOW TABLES");
        $tables = array_map(fn($r) => array_values($r)[0], $rows);
        wu_json(['tables' => $tables]);
    } catch (Throwable $e) { wu_json(['error' => $e->getMessage()]); }
}

// ── POST ──────────────────────────────────────────────────────────────────────
$body = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
}

if ($action === 'add') {
    $type   = preg_replace('/[^a-z_]/', '', (string)($body['type'] ?? ''));
    $title  = substr((string)($body['title'] ?? 'Widget'), 0, 120);
    $config = $body['config'] ?? [];
    $width  = max(1, min(4, (int)($body['width']  ?? 2)));
    $height = max(1, min(3, (int)($body['height'] ?? 1)));
    $icon   = substr((string)($body['icon'] ?? ''), 0, 60);

    $targetRole = null;
    $targetUser = $userId;
    if ($isGlobeAdmin && !empty($body['target_role'])) {
        $targetRole = substr((string)$body['target_role'], 0, 60);
        $targetUser = null;
    }

    if ($targetRole !== null) {
        $maxPos = (int)($db->fetchOne(
            "SELECT COALESCE(MAX(widget_position),0) as m FROM nu_dashboard_widgets WHERE widget_user_id IS NULL AND widget_role=?",
            [$targetRole]
        )['m'] ?? 0);
    } else {
        $maxPos = (int)($db->fetchOne(
            "SELECT COALESCE(MAX(widget_position),0) as m FROM nu_dashboard_widgets WHERE widget_user_id=?",
            [$targetUser]
        )['m'] ?? 0);
    }

    $db->query(
        "INSERT INTO nu_dashboard_widgets (widget_user_id, widget_role, widget_type, widget_title, widget_icon, widget_config, widget_width, widget_height, widget_position) VALUES (?,?,?,?,?,?,?,?,?)",
        [$targetUser, $targetRole, $type, $title, $icon ?: null, json_encode($config), $width, $height, $maxPos + 10]
    );
    wu_json(['ok' => true, 'id' => $db->lastInsertId()]);
}

if ($action === 'remove') {
    $id = (int)($body['id'] ?? 0);
    $db->query(
        "UPDATE nu_dashboard_widgets SET widget_active=0 WHERE widget_id=? AND (widget_user_id=? OR ?=1)",
        [$id, $userId, (int)$isAdmin]
    );
    wu_json(['ok' => true]);
}

if ($action === 'reorder') {
    foreach ((array)($body['order'] ?? []) as $item) {
        $id  = (int)($item['id']       ?? 0);
        $pos = (int)($item['position'] ?? 0);
        if (!$id) continue;
        $db->query(
            "UPDATE nu_dashboard_widgets SET widget_position=? WHERE widget_id=? AND (widget_user_id=? OR ?=1)",
            [$pos, $id, $userId, (int)$isAdmin]
        );
    }
    wu_json(['ok' => true]);
}

if ($action === 'update') {
    $id       = (int)($body['id'] ?? 0);
    $title    = substr((string)($body['title'] ?? ''), 0, 120);
    $config   = json_encode($body['config'] ?? []);
    $existing = $db->fetchOne("SELECT widget_width, widget_height FROM nu_dashboard_widgets WHERE widget_id=?", [$id]);
    $width    = max(1, min(4, (int)($body['width']  ?? $existing['widget_width']  ?? 2)));
    $height   = max(1, min(3, (int)($body['height'] ?? $existing['widget_height'] ?? 1)));
    $db->query(
        "UPDATE nu_dashboard_widgets SET widget_title=?, widget_config=?, widget_width=?, widget_height=? WHERE widget_id=? AND (widget_user_id=? OR ?=1)",
        [$title, $config, $width, $height, $id, $userId, (int)$isAdmin]
    );
    wu_json(['ok' => true]);
}

if ($action === 'reset') {
    $db->query("UPDATE nu_dashboard_widgets SET widget_active=0 WHERE widget_user_id=?", [$userId]);
    wu_json(['ok' => true]);
}

if ($action === 'run_sql') {
    $sql = wu_safe_sql((string)($body['sql'] ?? ''), $userId);
    if (!preg_match('/^\s*SELECT\b/i', $sql)) wu_json(['error' => 'Only SELECT statements allowed']);
    try {
        wu_json(['rows' => $db->fetchAll($sql)]);
    } catch (Throwable $e) {
        wu_json(['error' => $e->getMessage()]);
    }
}

wu_json(['error' => 'Unknown action']);
