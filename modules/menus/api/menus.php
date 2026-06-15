<?php
declare(strict_types=1);
/**
 * modules/menus/api/menus.php
 * REST API for Menu Builder CRUD.
 *
 * GET  ?action=get&id=N   — fetch a single menu item
 * POST action=create      — create
 * POST action=update      — update
 * POST action=delete      — hard-delete item + its children
 * POST action=reorder     — bulk update menu_order after drag-drop
 */
require_once dirname(__DIR__, 3) . '/core/module_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$db      = NuDatabase::getInstance();
$action  = $_GET['action'] ?? '';
$role    = strtolower((string)($_SESSION['nu_role'] ?? ''));
$isAdmin = in_array($role, ['globeadmin', 'admin'], true);

if (!$isAdmin) {
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

function mu_json(array $data): void {
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Validate a single display-mode value: 'inline' or 'popup'. */
function mu_sanitise_display(string $raw, string $default = 'inline'): string {
    $v = strtolower(trim($raw));
    return in_array($v, ['inline', 'popup'], true) ? $v : $default;
}

/** Validate a view value: 'browse' or 'preview'. */
function mu_sanitise_view(string $raw, string $default = 'browse'): string {
    $v = strtolower(trim($raw));
    return in_array($v, ['browse', 'preview'], true) ? $v : $default;
}

// ── AUTO-MIGRATE: ensure all three new columns exist ────────────────────────
$autoMigrations = [
    "ALTER TABLE nu_menus ADD COLUMN menu_open_mode    VARCHAR(30) NOT NULL DEFAULT 'inline|browse'",
    "ALTER TABLE nu_menus ADD COLUMN menu_browse_mode  VARCHAR(10) NOT NULL DEFAULT 'inline'",
    "ALTER TABLE nu_menus ADD COLUMN menu_preview_mode VARCHAR(10) NOT NULL DEFAULT 'inline'",
    "ALTER TABLE nu_menus ADD COLUMN menu_default_view VARCHAR(10) NOT NULL DEFAULT 'browse'",
];
foreach ($autoMigrations as $sql) {
    try { $db->query($sql); } catch (Throwable $ignored) {}
}

// Read POST body
$body = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw  = file_get_contents('php://input');
    $body = json_decode($raw, true);
    if (!is_array($body)) $body = $_POST;
}

// ── GET single item ─────────────────────────────────────────────────────────
if ($action === 'get') {
    $id  = (int)($_GET['id'] ?? 0);
    $row = $db->fetchOne("SELECT * FROM nu_menus WHERE menu_id = ?", [$id]);
    if (!$row) mu_json(['success' => false, 'message' => 'Not found']);

    // Backfill if new columns are missing/empty (legacy rows)
    if (empty($row['menu_browse_mode']) || empty($row['menu_preview_mode'])) {
        $old = $row['menu_open_mode'] ?? 'inline|browse';
        if (strpos($old, '|') !== false) {
            [$disp, $view] = explode('|', $old, 2);
        } else {
            $disp = 'inline'; $view = 'browse';
        }
        $row['menu_browse_mode']  = mu_sanitise_display($disp);
        $row['menu_preview_mode'] = 'inline';
        $row['menu_default_view'] = mu_sanitise_view($view);
    }

    mu_json(['success' => true, 'menu' => $row]);
}

// ── CREATE ──────────────────────────────────────────────────────────────────
if ($action === 'create') {
    $label       = substr(trim((string)($body['label']        ?? '')), 0, 120);
    $type        = preg_replace('/[^a-z_]/', '', (string)($body['type'] ?? 'form'));
    $target      = substr(trim((string)($body['target']       ?? '')), 0, 200);
    $icon        = substr(trim((string)($body['icon']         ?? 'default')), 0, 60);
    $parent      = (int)($body['parent'] ?? 0);
    $order       = (int)($body['order']  ?? 0);
    $roles       = substr(trim((string)($body['roles']        ?? '')), 0, 255);
    $active      = isset($body['active']) ? (int)$body['active'] : 1;
    $browseMode  = mu_sanitise_display((string)($body['browse_mode']  ?? 'inline'));
    $previewMode = mu_sanitise_display((string)($body['preview_mode'] ?? 'inline'));
    $defaultView = mu_sanitise_view((string)($body['default_view']    ?? 'browse'));
    // Keep legacy column in sync
    $openMode    = $browseMode . '|' . $defaultView;

    if (!$label && $type !== 'divider') {
        mu_json(['success' => false, 'message' => 'Label is required']);
    }

    $code = trim(strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $label)), '_');

    try {
        $db->query(
            "INSERT INTO nu_menus
             (menu_code, menu_label, menu_type, menu_target, menu_icon, menu_order,
              menu_parent_id, menu_role_access, menu_active,
              menu_open_mode, menu_browse_mode, menu_preview_mode, menu_default_view)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $code, $label, $type, $target, $icon, $order,
                $parent ?: null, $roles ?: null, $active,
                $openMode, $browseMode, $previewMode, $defaultView
            ]
        );
        mu_json(['success' => true, 'id' => $db->lastInsertId()]);
    } catch (Throwable $e) {
        error_log('[menus api create] ' . $e->getMessage());
        mu_json(['success' => false, 'message' => $e->getMessage()]);
    }
}

// ── UPDATE ──────────────────────────────────────────────────────────────────
if ($action === 'update') {
    $id          = (int)($body['id']    ?? 0);
    $label       = substr(trim((string)($body['label']        ?? '')), 0, 120);
    $type        = preg_replace('/[^a-z_]/', '', (string)($body['type'] ?? 'form'));
    $target      = substr(trim((string)($body['target']       ?? '')), 0, 200);
    $icon        = substr(trim((string)($body['icon']         ?? 'default')), 0, 60);
    $parent      = (int)($body['parent'] ?? 0);
    $order       = (int)($body['order']  ?? 0);
    $roles       = substr(trim((string)($body['roles']        ?? '')), 0, 255);
    $active      = isset($body['active']) ? (int)$body['active'] : 1;
    $browseMode  = mu_sanitise_display((string)($body['browse_mode']  ?? 'inline'));
    $previewMode = mu_sanitise_display((string)($body['preview_mode'] ?? 'inline'));
    $defaultView = mu_sanitise_view((string)($body['default_view']    ?? 'browse'));
    $openMode    = $browseMode . '|' . $defaultView;

    if (!$id) mu_json(['success' => false, 'message' => 'Invalid ID']);

    try {
        $db->query(
            "UPDATE nu_menus SET
               menu_label        = ?,
               menu_type         = ?,
               menu_target       = ?,
               menu_icon         = ?,
               menu_order        = ?,
               menu_parent_id    = ?,
               menu_role_access  = ?,
               menu_active       = ?,
               menu_open_mode    = ?,
               menu_browse_mode  = ?,
               menu_preview_mode = ?,
               menu_default_view = ?
             WHERE menu_id = ?",
            [
                $label, $type, $target, $icon, $order,
                $parent ?: null, $roles ?: null, $active,
                $openMode, $browseMode, $previewMode, $defaultView,
                $id
            ]
        );
        mu_json(['success' => true]);
    } catch (Throwable $e) {
        error_log('[menus api update] ' . $e->getMessage());
        mu_json(['success' => false, 'message' => $e->getMessage()]);
    }
}

// ── DELETE ──────────────────────────────────────────────────────────────────
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

// ── REORDER ─────────────────────────────────────────────────────────────────
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
