<?php
require_once '../config.php';
require_once '../core/Database.php';
require_once '../core/Auth.php';

$auth = new NuAuth();
if (!$auth->checkAuth()) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$table = $_GET['table'] ?? '';
$idCol = $_GET['id'] ?? $_GET['id_column'] ?? '';
$displayCol = $_GET['display'] ?? $_GET['display_column'] ?? 'name';
$filter = $_GET['filter'] ?? '';
$extra = $_GET['extra'] ?? '';

if (!$table || preg_match('/[^a-zA-Z0-9_]/', $table)) {
    echo json_encode(['success' => false, 'error' => 'Invalid table name']);
    exit;
}

$db = NuDatabase::getInstance();

function getPrimaryKeyColumn($db, $table) {
    try {
        $result = $db->fetchAll("SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'");
        if (!empty($result) && !empty($result[0]['Column_name'])) {
            return $result[0]['Column_name'];
        }
    } catch (Exception $e) {}
    return 'id';
}

if (empty($idCol)) {
    $idCol = getPrimaryKeyColumn($db, $table);
}

if (preg_match('/[^a-zA-Z0-9_]/', $idCol) || preg_match('/[^a-zA-Z0-9_]/', $displayCol)) {
    echo json_encode(['success' => false, 'error' => 'Invalid column name']);
    exit;
}

$extraCols = [];
if (!empty($extra)) {
    $pairs = explode(',', $extra);
    foreach ($pairs as $pair) {
        $parts = explode(':', $pair);
        if (count($parts) === 2) {
            $sourceCol = trim($parts[0]);
            if ($sourceCol !== '' && preg_match('/^[a-zA-Z0-9_]+$/', $sourceCol)) {
                $extraCols[] = $sourceCol;
            }
        }
    }
}

$selectCols = ["`{$idCol}`", "`{$displayCol}`"];
foreach ($extraCols as $col) {
    if ($col !== $idCol && $col !== $displayCol) {
        $selectCols[] = "`{$col}`";
    }
}

$filter = trim($filter);
if (stripos($filter, 'WHERE ') === 0) {
    $filter = trim(substr($filter, 6));
}

$sql = "SELECT " . implode(', ', $selectCols) . " FROM `{$table}`";
if ($filter !== '') {
    $sql .= " WHERE {$filter}";
}
$sql .= " ORDER BY `{$displayCol}` LIMIT 500";

try {
    $data = $db->fetchAll($sql);
    echo json_encode([
        'success' => true,
        'data' => $data,
        'debug_sql' => $sql
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'sql' => $sql
    ]);
}