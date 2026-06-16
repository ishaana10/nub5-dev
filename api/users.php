<?php
declare(strict_types=1);
/**
 * api/users.php — User CRUD + meta fields
 *
 * Actions:
 *   POST ?action=create   Create user + meta
 *   POST ?action=update   Update user + meta
 *   POST ?action=delete   Delete user
 */
header('Content-Type: application/json');
require_once '../config.php';
require_once '../core/Database.php';
require_once '../core/Auth.php';

$auth = new NuAuth();
if (!$auth->checkAuth()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$db     = NuDatabase::getInstance();
$action = $_GET['action'] ?? '';
$body   = json_decode(file_get_contents('php://input'), true) ?? [];

try {
    switch ($action) {

        case 'create': {
            if (!$auth->hasPermission('users.create')) throw new Exception('Access denied');

            $username = trim($body['username'] ?? '');
            $email    = trim($body['email']    ?? '');
            $role     = trim($body['role']     ?? 'user');
            $password = $body['password'] ?? '';
            $active   = isset($body['active']) ? (int)$body['active'] : 1;
            $meta     = $body['meta'] ?? [];

            if (!$username) throw new Exception('Username is required');
            if (!$password) throw new Exception('Password is required for new users');

            $exists = $db->fetchOne('SELECT usr_id FROM nu_users WHERE usr_username = :u', [':u' => $username]);
            if ($exists) throw new Exception('Username already exists');

            $hash = password_hash($password, PASSWORD_BCRYPT);
            $db->insert('nu_users', [
                'usr_username' => $username,
                'usr_email'    => $email,
                'usr_role'     => $role,
                'usr_password' => $hash,
                'usr_active'   => $active,
            ]);
            $newId = $db->lastInsertId();

            saveMeta($db, $newId, $meta);
            echo json_encode(['success' => true, 'usr_id' => $newId]);
            break;
        }

        case 'update': {
            if (!$auth->hasPermission('users.edit')) throw new Exception('Access denied');

            $id     = (int)($body['id'] ?? 0);
            $email  = trim($body['email']  ?? '');
            $role   = trim($body['role']   ?? '');
            $active = isset($body['active']) ? (int)$body['active'] : 1;
            $meta   = $body['meta'] ?? [];

            if (!$id) throw new Exception('User ID required');

            $user = $db->fetchOne('SELECT * FROM nu_users WHERE usr_id = :id', [':id' => $id]);
            if (!$user) throw new Exception('User not found');

            $update = [
                'usr_email'  => $email,
                'usr_role'   => $role,
                'usr_active' => $active,
            ];

            // Only update password if provided
            if (!empty($body['password'])) {
                $update['usr_password'] = password_hash($body['password'], PASSWORD_BCRYPT);
            }

            $db->update('nu_users', $update, 'usr_id = :id', [':id' => $id]);
            saveMeta($db, $id, $meta);

            echo json_encode(['success' => true]);
            break;
        }

        case 'delete': {
            if (!$auth->hasPermission('users.delete')) throw new Exception('Access denied');

            $id = (int)($body['id'] ?? 0);
            if (!$id) throw new Exception('User ID required');

            $user = $db->fetchOne('SELECT usr_username FROM nu_users WHERE usr_id = :id', [':id' => $id]);
            if (!$user) throw new Exception('User not found');
            if ($user['usr_username'] === 'globeadmin') throw new Exception('Cannot delete globeadmin');

            $db->delete('nu_user_meta', 'umeta_user_id = :id', [':id' => $id]);
            $db->delete('nu_users', 'usr_id = :id', [':id' => $id]);

            echo json_encode(['success' => true]);
            break;
        }

        default:
            throw new Exception('Unknown action: ' . $action);
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

/**
 * Upsert meta key/value pairs for a user.
 * Skips empty values (won't overwrite existing with blank).
 */
function saveMeta(NuDatabase $db, int $userId, array $meta): void {
    foreach ($meta as $key => $value) {
        $key   = preg_replace('/[^a-zA-Z0-9_]/', '', $key);
        $value = trim((string)$value);
        if ($key === '') continue;

        $existing = $db->fetchOne(
            'SELECT umeta_id FROM nu_user_meta WHERE umeta_user_id = :uid AND umeta_key = :k',
            [':uid' => $userId, ':k' => $key]
        );

        if ($existing) {
            $db->update('nu_user_meta',
                ['umeta_value' => $value],
                'umeta_user_id = :uid AND umeta_key = :k',
                [':uid' => $userId, ':k' => $key]
            );
        } else {
            $db->insert('nu_user_meta', [
                'umeta_user_id' => $userId,
                'umeta_key'     => $key,
                'umeta_value'   => $value,
            ]);
        }
    }
}
