<?php
require_once '../config.php';
require_once '../core/Database.php';
require_once '../core/Auth.php';

$auth = new NuAuth();
if (!$auth->checkAuth()) {
    http_response_code(401);
    echo 'Unauthorized';
    exit;
}

$table = preg_replace('/[^a-z0-9_]/', '', $_GET['table'] ?? '');
$format = $_GET['format'] ?? 'csv';

if (!$table) {
    echo 'Table required';
    exit;
}

$db = NuDatabase::getInstance();
$records = $db->fetchAll("SELECT * FROM {$table} LIMIT 10000");

if ($format === 'json') {
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="' . $table . '.json"');
    echo json_encode($records, JSON_PRETTY_PRINT);
    exit;
}

// CSV
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $table . '.csv"');

if (!empty($records)) {
    $output = fopen('php://output', 'w');
    fputcsv($output, array_keys($records[0]));
    foreach ($records as $row) {
        fputcsv($output, $row);
    }
    fclose($output);
}
?>
