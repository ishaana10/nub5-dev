<?php
require_once '../config.php';
require_once '../core/Database.php';
require_once '../core/Auth.php';
require_once '../core/QueryExecutor.php';

$auth = new NuAuth();
if (!$auth->checkAuth()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? 'execute';
$code = $_GET['code'] ?? '';
$params = [];
foreach ($_GET as $k => $v) {
    if (!in_array($k, ['action', 'code'])) {
        $params[$k] = $v;
    }
}

$executor = new NuQueryExecutor();

if ($action === 'export') {
    $result = $executor->exportCsv($code, $params);
    if (isset($result['error'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $result['error']]);
        exit;
    }
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $result['filename'] . '"');
    echo $result['csv'];
    exit;
}

// Execute and return JSON
header('Content-Type: application/json');
$result = $executor->execute($code, $params);
if (isset($result['error'])) {
    echo json_encode(['success' => false, 'error' => $result['error']]);
} else {
    echo json_encode(['success' => true, 'data' => $result]);
}
?>
