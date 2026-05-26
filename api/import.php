<?php
require_once '../config.php';
require_once '../core/Database.php';
require_once '../core/Auth.php';
require_once '../core/Audit.php';

header('Content-Type: application/json');

$auth = new NuAuth();
if (!$auth->checkAuth()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$table = preg_replace('/[^a-z0-9_]/', '', $_POST['table'] ?? '');
if (!$table || !isset($_FILES['file'])) {
    echo json_encode(['success' => false, 'error' => 'Table and file required']);
    exit;
}

$file = $_FILES['file'];
if ($file['type'] !== 'text/csv' && !str_ends_with($file['name'], '.csv')) {
    echo json_encode(['success' => false, 'error' => 'CSV file required']);
    exit;
}

$db = NuDatabase::getInstance();
$handle = fopen($file['tmp_name'], 'r');
$headers = fgetcsv($handle);
$imported = 0;

while (($row = fgetcsv($handle)) !== false) {
    if (count($row) !== count($headers)) continue;
    $data = array_combine($headers, $row);
    try {
        $db->insert($table, $data);
        $imported++;
    } catch (Exception $e) {
        // Skip invalid rows
    }
}
fclose($handle);

$audit = new NuAudit();
$audit->log('import', $table, 0, null, ['imported_rows' => $imported]);

echo json_encode(['success' => true, 'imported' => $imported]);
?>
