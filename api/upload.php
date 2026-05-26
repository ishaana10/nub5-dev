<?php
require_once '../config.php';
require_once '../core/Database.php';
require_once '../core/Auth.php';
require_once '../core/Audit.php';

header('Content-Type: application/json');

$auth = new NuAuth();
if (!$auth->checkAuth()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if (!$auth->hasPermission('files.upload')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Permission denied']);
    exit;
}

if (!isset($_FILES['file'])) {
    echo json_encode(['success' => false, 'error' => 'No file provided']);
    exit;
}

$file = $_FILES['file'];
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

if (!in_array($ext, $nuConfig['allowedFileTypes'])) {
    echo json_encode(['success' => false, 'error' => 'File type not allowed']);
    exit;
}

if ($file['size'] > $nuConfig['maxUploadSize']) {
    echo json_encode(['success' => false, 'error' => 'File too large']);
    exit;
}

$filename = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $file['name']);
$destination = $nuConfig['uploadPath'] . $filename;

if (!is_dir($nuConfig['uploadPath'])) {
    mkdir($nuConfig['uploadPath'], 0755, true);
}

if (move_uploaded_file($file['tmp_name'], $destination)) {
    $db = NuDatabase::getInstance();
    $fileId = $db->insert('nu_files', [
        'file_name' => $filename,
        'file_original_name' => $file['name'],
        'file_mime_type' => $file['type'],
        'file_size' => $file['size'],
        'file_path' => $destination,
        'file_uploaded_by' => $_SESSION['nu_user_id'] ?? null
    ]);

    $audit = new NuAudit();
    $audit->log('upload', 'nu_files', $fileId);

    echo json_encode(['success' => true, 'file_id' => $fileId, 'name' => $filename]);
} else {
    echo json_encode(['success' => false, 'error' => 'Upload failed']);
}
?>
