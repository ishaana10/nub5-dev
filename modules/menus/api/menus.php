<?php
declare(strict_types=1);
/**
 * modules/menus/api/menus.php
 * REST API for Menu Builder CRUD.
 *
 * GET  ?action=get&id=N   — fetch a single menu item
 * POST action=create      — create a new menu item
 * POST action=update      — update an existing menu item
 * POST action=delete      — soft-delete (set menu_active=0) or hard-delete
 * POST action=reorder     — bulk update menu_order after drag-drop
 * POST action=migrate     — add menu_open_mode column if missing
 */
require_once dirname(__DIR__, 3) . '/core/module_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$db     = NuDatabase::getInstance();
$action = $_GET['action'] ?? '';
$userId = (int)($_SESSION['nu_user_id'] ?? 0);
$role   = strtolower((string)($_SESSION['nu_role'] ?? ''));
$isAdmin = in_array($role, ['globeadmin', 'admin'], true);

if (!$isAdmin) {
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

function mu_json(array $data): void {
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Valid open modes
$validOpenModes = ['inline', 'popup', 'preview', 'browse'];

// Read POST body
$body = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw  = file_get_contents('php://input');
    $body = json_decode($raw, true);
    if (!is_array($body)) $body = $_POST;
}

// ── AUTO-MIGRATE: ensure menu_open_mode column exists ─────────────────────
try {
    $db->query("ALTER TABLE nu_menus ADD COLUMN menu_open_mode VARCHAR(20) NOT NULL DEFAULT 'inline'");
} catch (Throwable $ignored) {
    // Column already exists — ignore
}

// ── GET single item ────────────────────────────────────────────
if ($action === 'get') {
    $id  = (int)($_GET['id'] ?? 0);
    $row = $db->fetchOne("SELECT * FROM nu_menus WHERE menu_id = ?", [$id]);
    if (!$row) mu_json(['success' => false, 'message' => 'Not found']);
    // Ensure open_mode has a default if column did not exist before
    $row['menu_open_mode'] = $row['menu_open_mode'] ?? 'inline';
    mu_json(['success' => true, 'menu' => $row]);
}

// ── CREATE ─────────────────────────────────────────────────────
if ($action === 'create') {
    $label    = substr(trim((string)($body['label']     ?? '')), 0, 120);
    $type     = preg_replace('/[^a-z_]/', '', (string)($body['type']      ?? 'form'));
    $target   = substr(trim((string)($body['target']   ?? '')), 0, 200);
    $icon     = substr(trim((string)($body['icon']     ?? '☰')), 0, 60);
    $parent   = (int)($body['parent']   ?? 0);
    $order    = (int)($body['order']    ?? 0);
    $roles    = substr(trim((string)($body['roles']    ?? '')), 0, 255);
    $active   = isset($body['active']) ? (int)$body['active'] : 1;
    $rawMode  = trim((string)($body['open_mode'] ?? 'inline'));
    $openMode = in_array($rawMode, $GLOBALS['validOpenModes'] ?? ['inline','popup','preview','browse'], true) ? $rawMode : 'inline';

    if (!$label && $type !== 'divider') {
        mu_json(['success' => false, 'message' => 'Label is required']);
    }

    // Auto-generate menu_code from label
    $code = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $label));
    $code = trim($code, '_');

    try {
        $db->query(
            "INSERT INTO nu_menus
             (menu_code, menu_label, menu_type, menu_target, menu_icon, menu_order,
              menu_parent_id, menu_role_access, menu_active, menu_open_mode)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [$code, $label, $type, $target, $icon, $order, $parent ?: null, $roles ?: null, $active, $openMode]
        );
        $newId = $db->lastInsertId();
        mu_json(['success' => true, 'id' => $newId]);
    } catch (Throwable $e) {
        error_log('[menus api create] ' . $e->getMessage());
        mu_json(['success' => false, 'message' => $e->getMessage()]);
    }
}

// ── UPDATE ─────────────────────────────────────────────────────
if ($action === 'update') {
    $id       = (int)($body['id']        ?? 0);
    $label    = substr(trim((string)($body['label']     ?? '')), 0, 120);
    $type     = preg_replace('/[^a-z_]/', '', (string)($body['type']      ?? 'form'));
    $target   = substr(trim((string)($body['target']   ?? '')), 0, 200);
    $icon     = substr(trim((string)($body['icon']     ?? '☰')), 0, 60);
    $parent   = (int)($body['parent']   ?? 0);
    $order    = (int)($body['order']    ?? 0);
    $roles    = substr(trim((string)($body['roles']    ?? '')), 0, 255);
    $active   = isset($body['active']) ? (int)$body['active'] : 1;
    $rawMode  = trim((string)($body['open_mode'] ?? 'inline'));
    $openMode = in_array($rawMode, ['inline','popup','preview','browse'], true) ? $rawMode : 'inline';

    if (!$id) mu_json(['success' => false, 'message' => 'Invalid ID']);

    try {
        $db->query(
            "UPDATE nu_menus SET
               menu_label       = ?,
               menu_type        = ?,
               menu_target      = ?,
               menu_icon        = ?,
               menu_order       = ?,
               menu_parent_id   = ?,
               menu_role_access = ?,
               menu_active      = ?,
               menu_open_mode   = ?
             WHERE menu_id = ?",
            [$label, $type, $target, $icon, $order, $parent ?: null, $roles ?: null, $active, $openMode, $id]
        );
        mu_json(['success' => true]);
    } catch (Throwable $e) {
        error_log('[menus api update] ' . $e->getMessage());
        mu_json(['success' => false, 'message' => $e->getMessage()]);
    }
}

// ── DELETE ─────────────────────────────────────────────────────
if ($action === 'delete') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) mu_json(['success' => false, 'message' => 'Invalid ID']);
    try {
        $db->query("DELETE FROM nu_menus WHERE menu_id = ? OR menu_parent_id = ?", [$id, $id]);
        mu_json(['success' => true]);
    } catch (Throwable $e) {
        error_log('[menus api delete] ' . $e->getMessage());
        mu_json(['success' => false, 'message' => $e->getMessage()]);
    }
}

// ── REORDER ────────────────────────────────────────────────────
if ($action === 'reorder') {
    $items = (array)($body['items'] ?? []);
    try {
        foreach ($items as $i => $item) {
            $id  = (int)($item['id']    ?? 0);
            $ord = (int)($item['order'] ?? $i);
            if (!$id) continue;
            $db->query("UPDATE nu_menus SET menu_order = ? WHERE menu_id = ?", [$ord, $id]);
        }
        mu_json(['success' => true]);
    } catch (Throwable $e) {
        error_log('[menus api reorder] ' . $e->getMessage());
        mu_json(['success' => false, 'message' => $e->getMessage()]);
    }
}

mu_json(['success' => false, 'message' => 'Unknown action']);
