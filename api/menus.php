<?php
/**
 * api/menus.php
 * CRUD + reorder for nu_menus (Menu Builder records).
 * Actions: get, create, update, delete, reorder
 */
header('Content-Type: application/json');
require_once '../config.php';
require_once '../core/Database.php';
require_once '../core/Auth.php';

$auth = new NuAuth();
if (!$auth->checkAuth()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$db     = NuDatabase::getInstance();
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'get':     actionGet($db, $auth);     break;
    case 'create':  actionCreate($db, $auth);  break;
    case 'update':  actionUpdate($db, $auth);  break;
    case 'delete':  actionDelete($db, $auth);  break;
    case 'reorder': actionReorder($db, $auth); break;
    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action: ' . htmlspecialchars($action)]);
}

// ── GET single ────────────────────────────────────────────────────────────
function actionGet($db, $auth) {
    if (!$auth->hasPermission('menus', 'view')) {
        echo json_encode(['success' => false, 'error' => 'Permission denied']); return;
    }
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) {
        echo json_encode(['success' => false, 'error' => 'Missing id']); return;
    }
    try {
        $row = $db->fetchOne('SELECT * FROM nu_menus WHERE menu_id = ?', [$id]);
        if (!$row) {
            echo json_encode(['success' => false, 'error' => 'Menu item not found']); return;
        }
        echo json_encode(['success' => true, 'menu' => $row]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

// ── CREATE ────────────────────────────────────────────────────────────────
function actionCreate($db, $auth) {
    if (!$auth->hasPermission('menus', 'create')) {
        echo json_encode(['success' => false, 'error' => 'Permission denied']); return;
    }
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        echo json_encode(['success' => false, 'error' => 'Invalid JSON']); return;
    }

    $label  = trim($data['label']  ?? '');
    $type   = trim($data['type']   ?? 'form');
    $target = trim($data['target'] ?? '');
    $parent = (int)($data['parent'] ?? 0);
    $order  = (int)($data['order']  ?? 0);
    $roles  = trim($data['roles']  ?? '');
    $active = isset($data['active']) ? (int)$data['active'] : 1;
    $icon   = trim($data['icon']   ?? '☰');

    $allowedTypes = ['form', 'report', 'query', 'url', 'group', 'divider'];
    if (!in_array($type, $allowedTypes, true)) {
        echo json_encode(['success' => false, 'error' => 'Invalid menu type']); return;
    }
    if (!$label && $type !== 'divider') {
        echo json_encode(['success' => false, 'error' => 'Label is required']); return;
    }
    if ($parent > 0) {
        $parentRow = $db->fetchOne('SELECT menu_id, menu_parent_id FROM nu_menus WHERE menu_id = ?', [$parent]);
        if (!$parentRow) {
            echo json_encode(['success' => false, 'error' => 'Parent menu item not found']); return;
        }
        // Only allow one level of nesting
        if ((int)$parentRow['menu_parent_id'] > 0) {
            echo json_encode(['success' => false, 'error' => 'Cannot nest more than one level deep']); return;
        }
    }

    $row = [
        'menu_label'     => $label,
        'menu_type'      => $type,
        'menu_target'    => $target,
        'menu_parent_id' => $parent,
        'menu_order'     => $order,
        'menu_roles'     => $roles,
        'menu_active'    => $active,
        'menu_icon'      => $icon,
    ];

    try {
        $db->insert('nu_menus', $row);
        $newId = $db->lastInsertId();
        echo json_encode(['success' => true, 'id' => $newId]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

// ── UPDATE ────────────────────────────────────────────────────────────────
function actionUpdate($db, $auth) {
    if (!$auth->hasPermission('menus', 'edit')) {
        echo json_encode(['success' => false, 'error' => 'Permission denied']); return;
    }
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        echo json_encode(['success' => false, 'error' => 'Invalid JSON']); return;
    }

    $id     = (int)($data['id']     ?? 0);
    $label  = trim($data['label']   ?? '');
    $type   = trim($data['type']    ?? 'form');
    $target = trim($data['target']  ?? '');
    $parent = (int)($data['parent'] ?? 0);
    $order  = (int)($data['order']  ?? 0);
    $roles  = trim($data['roles']   ?? '');
    $active = isset($data['active']) ? (int)$data['active'] : 1;
    $icon   = trim($data['icon']    ?? '☰');

    if (!$id) {
        echo json_encode(['success' => false, 'error' => 'Missing id']); return;
    }
    $allowedTypes = ['form', 'report', 'query', 'url', 'group', 'divider'];
    if (!in_array($type, $allowedTypes, true)) {
        echo json_encode(['success' => false, 'error' => 'Invalid menu type']); return;
    }
    if (!$label && $type !== 'divider') {
        echo json_encode(['success' => false, 'error' => 'Label is required']); return;
    }
    // Prevent a menu item from being its own parent
    if ($parent === $id) {
        echo json_encode(['success' => false, 'error' => 'A menu item cannot be its own parent']); return;
    }
    if ($parent > 0) {
        $parentRow = $db->fetchOne('SELECT menu_id, menu_parent_id FROM nu_menus WHERE menu_id = ?', [$parent]);
        if (!$parentRow) {
            echo json_encode(['success' => false, 'error' => 'Parent menu item not found']); return;
        }
        if ((int)$parentRow['menu_parent_id'] > 0) {
            echo json_encode(['success' => false, 'error' => 'Cannot nest more than one level deep']); return;
        }
    }

    $row = [
        'menu_label'     => $label,
        'menu_type'      => $type,
        'menu_target'    => $target,
        'menu_parent_id' => $parent,
        'menu_order'     => $order,
        'menu_roles'     => $roles,
        'menu_active'    => $active,
        'menu_icon'      => $icon,
    ];

    try {
        $existing = $db->fetchOne('SELECT menu_id FROM nu_menus WHERE menu_id = ?', [$id]);
        if (!$existing) {
            echo json_encode(['success' => false, 'error' => 'Menu item not found']); return;
        }
        $db->update('nu_menus', $row, 'menu_id = ?', [$id]);
        echo json_encode(['success' => true, 'id' => $id]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

// ── DELETE ────────────────────────────────────────────────────────────────
function actionDelete($db, $auth) {
    if (!$auth->hasPermission('menus', 'delete')) {
        echo json_encode(['success' => false, 'error' => 'Permission denied']); return;
    }
    $data = json_decode(file_get_contents('php://input'), true);
    $id   = (int)($data['id'] ?? 0);
    if (!$id) {
        echo json_encode(['success' => false, 'error' => 'Missing id']); return;
    }
    try {
        // Cascade: remove children first, then the item itself
        $db->query('DELETE FROM nu_menus WHERE menu_parent_id = ?', [$id]);
        $db->query('DELETE FROM nu_menus WHERE menu_id = ?',        [$id]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

// ── REORDER ───────────────────────────────────────────────────────────────
// Accepts: { items: [ { id: int, order: int }, ... ] }
function actionReorder($db, $auth) {
    if (!$auth->hasPermission('menus', 'edit')) {
        echo json_encode(['success' => false, 'error' => 'Permission denied']); return;
    }
    $data  = json_decode(file_get_contents('php://input'), true);
    $items = $data['items'] ?? [];
    if (!is_array($items) || empty($items)) {
        echo json_encode(['success' => false, 'error' => 'No items provided']); return;
    }
    try {
        foreach ($items as $item) {
            $itemId    = (int)($item['id']    ?? 0);
            $itemOrder = (int)($item['order'] ?? 0);
            if (!$itemId) continue;
            $db->update('nu_menus', ['menu_order' => $itemOrder], 'menu_id = ?', [$itemId]);
        }
        echo json_encode(['success' => true, 'updated' => count($items)]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}
