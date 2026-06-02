<?php
declare(strict_types=1);
require_once '../config.php';
require_once '../core/Database.php';
require_once '../core/Auth.php';

header('Content-Type: application/json');

$auth   = new NuAuth();
$db     = NuDatabase::getInstance();
$action = $_GET['action'] ?? '';

// ─── Helper: load active password policy ─────────────────────────────────────
function loadPolicy(NuDatabase $db): array {
    $row = $db->fetchOne("SELECT * FROM nu_password_policy WHERE policy_id = 1");
    return $row ?: [
        'policy_min_length'        => 8,
        'policy_require_uppercase' => 1,
        'policy_require_lowercase'  => 1,
        'policy_require_number'    => 1,
        'policy_require_special'   => 0,
        'policy_disallow_username' => 1,
        'policy_history_count'     => 5,
        'policy_expiry_days'       => 0,
        'policy_expiry_warning_days' => 7,
        'policy_force_change_on_first_login' => 1,
    ];
}

// ─── Helper: validate password against policy ────────────────────────────────
function validatePassword(string $password, array $policy, string $username = ''): array {
    $errors = [];
    if (strlen($password) < (int)$policy['policy_min_length']) {
        $errors[] = 'Password must be at least ' . $policy['policy_min_length'] . ' characters.';
    }
    if ($policy['policy_require_uppercase'] && !preg_match('/[A-Z]/', $password)) {
        $errors[] = 'Password must include at least one uppercase letter.';
    }
    if ($policy['policy_require_lowercase'] && !preg_match('/[a-z]/', $password)) {
        $errors[] = 'Password must include at least one lowercase letter.';
    }
    if ($policy['policy_require_number'] && !preg_match('/[0-9]/', $password)) {
        $errors[] = 'Password must include at least one number.';
    }
    if ($policy['policy_require_special'] && !preg_match('/[^A-Za-z0-9]/', $password)) {
        $errors[] = 'Password must include at least one special character.';
    }
    if ($policy['policy_disallow_username'] && $username !== '' && stripos($password, $username) !== false) {
        $errors[] = 'Password must not contain your username.';
    }
    return $errors;
}

// ─── Helper: check password history ─────────────────────────────────────────
function isPasswordReused(NuDatabase $db, int $userId, string $newPassword, int $historyCount): bool {
    if ($historyCount <= 0) return false;
    $rows = $db->fetchAll(
        "SELECT ph_hash FROM nu_password_history WHERE ph_user_id = :uid ORDER BY ph_created_at DESC LIMIT :lim",
        [':uid' => $userId, ':lim' => $historyCount]
    );
    foreach ($rows as $r) {
        if (password_verify($newPassword, $r['ph_hash'])) return true;
    }
    return false;
}

// ─── Helper: record password history ─────────────────────────────────────────
function recordPasswordHistory(NuDatabase $db, int $userId, string $hash, int $historyCount): void {
    if ($historyCount <= 0) return;
    $db->query(
        "INSERT INTO nu_password_history (ph_user_id, ph_hash) VALUES (:uid, :hash)",
        [':uid' => $userId, ':hash' => $hash]
    );
    // Prune old history beyond limit
    $db->query(
        "DELETE FROM nu_password_history WHERE ph_user_id = :uid
         AND ph_id NOT IN (
             SELECT ph_id FROM (
                 SELECT ph_id FROM nu_password_history WHERE ph_user_id = :uid2
                 ORDER BY ph_created_at DESC LIMIT :lim
             ) AS t
         )",
        [':uid' => $userId, ':uid2' => $userId, ':lim' => max(20, $historyCount)]
    );
}

switch ($action) {

    // ── Self-service: change own password ────────────────────────────────────
    case 'change_password':
        if (!$auth->checkAuth()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }

        $input       = json_decode(file_get_contents('php://input'), true) ?? [];
        $currentPwd  = trim($input['current_password'] ?? '');
        $newPwd      = trim($input['new_password'] ?? '');
        $confirmPwd  = trim($input['confirm_password'] ?? '');

        if ($newPwd !== $confirmPwd) { echo json_encode(['success'=>false,'message'=>'New passwords do not match.']); break; }

        $user = $auth->getCurrentUser();
        if (!password_verify($currentPwd, $user['usr_password'])) {
            echo json_encode(['success'=>false,'message'=>'Current password is incorrect.']);
            break;
        }

        $policy = loadPolicy($db);
        $errors = validatePassword($newPwd, $policy, $user['usr_username']);
        if ($errors) { echo json_encode(['success'=>false,'message'=>implode(' ', $errors)]); break; }

        if (isPasswordReused($db, (int)$user['usr_id'], $newPwd, (int)$policy['policy_history_count'])) {
            echo json_encode(['success'=>false,'message'=>'You cannot reuse a recent password. Choose a different one.']);
            break;
        }

        $newHash = password_hash($newPwd, PASSWORD_DEFAULT);
        recordPasswordHistory($db, (int)$user['usr_id'], $user['usr_password'], (int)$policy['policy_history_count']);
        $db->query(
            "UPDATE nu_users SET usr_password = :h, usr_password_changed_at = NOW(), usr_must_change_password = 0 WHERE usr_id = :id",
            [':h' => $newHash, ':id' => $user['usr_id']]
        );
        echo json_encode(['success'=>true,'message'=>'Password updated successfully.']);
        break;

    // ── Admin: reset another user's password ─────────────────────────────────
    case 'admin_reset':
        if (!$auth->checkAuth()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
        if (!$auth->hasPermission('users.edit')) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Forbidden']); exit; }

        $input    = json_decode(file_get_contents('php://input'), true) ?? [];
        $targetId = (int)($input['user_id'] ?? 0);
        $newPwd   = trim($input['new_password'] ?? '');
        $force    = (bool)($input['force_change'] ?? true);

        if (!$targetId || !$newPwd) { echo json_encode(['success'=>false,'message'=>'user_id and new_password required.']); break; }

        $target = $db->fetchOne("SELECT * FROM nu_users WHERE usr_id = :id", [':id' => $targetId]);
        if (!$target) { echo json_encode(['success'=>false,'message'=>'User not found.']); break; }

        $policy = loadPolicy($db);
        $errors = validatePassword($newPwd, $policy, $target['usr_username']);
        if ($errors) { echo json_encode(['success'=>false,'message'=>implode(' ', $errors)]); break; }

        $newHash = password_hash($newPwd, PASSWORD_DEFAULT);
        recordPasswordHistory($db, $targetId, $target['usr_password'], (int)$policy['policy_history_count']);
        $db->query(
            "UPDATE nu_users SET usr_password = :h, usr_password_changed_at = NOW(), usr_must_change_password = :f WHERE usr_id = :id",
            [':h' => $newHash, ':f' => $force ? 1 : 0, ':id' => $targetId]
        );
        echo json_encode(['success'=>true,'message'=>'Password reset for user #' . $targetId . '.']);
        break;

    // ── Get current password policy ──────────────────────────────────────────
    case 'get_policy':
        if (!$auth->checkAuth()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
        echo json_encode(['success'=>true,'policy'=>loadPolicy($db)]);
        break;

    // ── Save password policy (admin/globeadmin only) ──────────────────────────
    case 'save_policy':
        if (!$auth->checkAuth()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
        if (!$auth->hasPermission('system.config')) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Forbidden']); exit; }

        $input = json_decode(file_get_contents('php://input'), true) ?? [];

        $fields = [
            'policy_min_length'                  => max(6, (int)($input['policy_min_length'] ?? 8)),
            'policy_require_uppercase'           => (int)(bool)($input['policy_require_uppercase'] ?? 1),
            'policy_require_lowercase'            => (int)(bool)($input['policy_require_lowercase'] ?? 1),
            'policy_require_number'              => (int)(bool)($input['policy_require_number'] ?? 1),
            'policy_require_special'             => (int)(bool)($input['policy_require_special'] ?? 0),
            'policy_disallow_username'           => (int)(bool)($input['policy_disallow_username'] ?? 1),
            'policy_history_count'               => max(0, (int)($input['policy_history_count'] ?? 5)),
            'policy_expiry_days'                 => max(0, (int)($input['policy_expiry_days'] ?? 0)),
            'policy_expiry_warning_days'         => max(0, (int)($input['policy_expiry_warning_days'] ?? 7)),
            'policy_force_change_on_first_login' => (int)(bool)($input['policy_force_change_on_first_login'] ?? 1),
        ];

        $existing = $db->fetchOne("SELECT policy_id FROM nu_password_policy WHERE policy_id = 1");
        if ($existing) {
            $sets = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($fields)));
            $params = array_combine(array_map(fn($k) => ":$k", array_keys($fields)), array_values($fields));
            $params[':id'] = 1;
            $db->query("UPDATE nu_password_policy SET $sets WHERE policy_id = :id", $params);
        } else {
            $cols   = implode(', ', array_keys($fields));
            $phs    = implode(', ', array_map(fn($k) => ":$k", array_keys($fields)));
            $params = array_combine(array_map(fn($k) => ":$k", array_keys($fields)), array_values($fields));
            $db->query("INSERT INTO nu_password_policy ($cols) VALUES ($phs)", $params);
        }
        echo json_encode(['success'=>true,'message'=>'Password policy saved.']);
        break;

    // ── Check if current user's password has expired ──────────────────────────
    case 'check_expiry':
        if (!$auth->checkAuth()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
        $user   = $auth->getCurrentUser();
        $policy = loadPolicy($db);
        $expiry = (int)$policy['policy_expiry_days'];
        $result = ['expired'=>false,'warning'=>false,'days_remaining'=>null,'must_change'=>(bool)$user['usr_must_change_password']];
        if ($expiry > 0 && $user['usr_password_changed_at']) {
            $changed  = strtotime($user['usr_password_changed_at']);
            $expireAt = $changed + ($expiry * 86400);
            $now      = time();
            $remaining = (int)(($expireAt - $now) / 86400);
            $result['days_remaining'] = $remaining;
            if ($remaining <= 0)  { $result['expired'] = true; }
            elseif ($remaining <= (int)$policy['policy_expiry_warning_days']) { $result['warning'] = true; }
        }
        echo json_encode(['success'=>true] + $result);
        break;

    default:
        echo json_encode(['success'=>false,'message'=>'Unknown action.']);
}
?>
