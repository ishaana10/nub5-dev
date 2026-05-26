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

$action = $_GET['action'] ?? '';
$db = NuDatabase::getInstance();

switch ($action) {
    case 'upload':
        if (!isset($_FILES['file'])) {
            echo json_encode(['success' => false, 'error' => 'No file provided']);
            exit;
        }
        $file = $_FILES['file'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['pdf','doc','docx','xls','xlsx','jpg','jpeg','png'];
        if (!in_array($ext, $allowed)) {
            echo json_encode(['success' => false, 'error' => 'Invalid file type']);
            exit;
        }

        $filename = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $file['name']);
        $dest = $nuConfig['uploadPath'] . $filename;
        if (!is_dir($nuConfig['uploadPath'])) mkdir($nuConfig['uploadPath'], 0755, true);

        if (move_uploaded_file($file['tmp_name'], $dest)) {
            $fileId = $db->insert('nu_files', [
                'file_name' => $filename,
                'file_original_name' => $file['name'],
                'file_mime_type' => $file['type'],
                'file_size' => $file['size'],
                'file_path' => $dest,
                'file_uploaded_by' => $_SESSION['nu_user_id']
            ]);

            $docId = $db->insert('nu_documents', [
                'doc_title' => $_POST['title'] ?? 'Untitled',
                'doc_description' => $_POST['description'] ?? '',
                'doc_file_id' => $fileId,
                'doc_category' => $_POST['category'] ?? 'other',
                'doc_status' => 'draft',
                'doc_created_by' => $_SESSION['nu_user_id']
            ]);

            $audit = new NuAudit();
            $audit->log('document_upload', 'nu_documents', $docId);

            echo json_encode(['success' => true, 'document_id' => $docId, 'file_id' => $fileId]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Upload failed']);
        }
        break;

    case 'sign':
        $docId = $_GET['id'] ?? 0;
        $data = json_decode(file_get_contents('php://input'), true);
        $sigData = $data['signature'] ?? '';

        if (!$sigData) {
            echo json_encode(['success' => false, 'error' => 'No signature data']);
            exit;
        }

        // Save signature
        $db->insert('nu_signatures', [
            'sig_document_id' => $docId,
            'sig_user_id' => $_SESSION['nu_user_id'],
            'sig_data' => $sigData,
            'sig_ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            'sig_user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);

        // Update document status
        $db->update('nu_documents', ['doc_status' => 'approved'], 'doc_id = :id', [':id' => $docId]);

        $audit = new NuAudit();
        $audit->log('document_sign', 'nu_documents', $docId);

        echo json_encode(['success' => true]);
        break;

    case 'delete':
        $id = $_GET['id'] ?? 0;
        $db->delete('nu_documents', 'doc_id = :id', [':id' => $id]);
        $audit = new NuAudit();
        $audit->log('document_delete', 'nu_documents', $id);
        echo json_encode(['success' => true]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
}
?>
