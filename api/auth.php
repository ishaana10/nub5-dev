<?php
require_once '../config.php';
require_once '../core/Database.php';
require_once '../core/Auth.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';
$auth = new NuAuth();

switch ($action) {
    case 'login':
        $input = json_decode(file_get_contents('php://input'), true);
        $result = $auth->login($input['username'] ?? '', $input['password'] ?? '', $input['otp'] ?? null);
        echo json_encode($result);
        break;

    case 'logout':
        $auth->logout();
        echo json_encode(['success' => true]);
        break;

    case 'me':
        if (!$auth->checkAuth()) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }
        $user = $auth->getCurrentUser();
        unset($user['usr_password']);
        unset($user['usr_2fa_secret']);
        echo json_encode(['success' => true, 'user' => $user]);
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Unknown action']);
}
?>
