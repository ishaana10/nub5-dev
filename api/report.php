<?php
require_once '../config.php';
require_once '../core/Database.php';
require_once '../core/Auth.php';
require_once '../core/ReportRenderer.php';

$auth = new NuAuth();
if (!$auth->checkAuth()) {
    http_response_code(401);
    echo 'Unauthorized';
    exit;
}

$code = $_GET['code'] ?? '';
$params = [];
foreach ($_GET as $k => $v) {
    if (!in_array($k, ['code'])) {
        $params[$k] = $v;
    }
}

$renderer = new NuReportRenderer();

// Check if chart data requested
if ($_GET['format'] === 'json') {
    header('Content-Type: application/json');
    $result = $renderer->renderChartData($code, $params);
    echo json_encode($result);
    exit;
}

// Default HTML output
$html = $renderer->renderTable($code, $params);
echo $html;
?>
