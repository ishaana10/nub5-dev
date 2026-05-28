<?php
declare(strict_types=1);
/**
 * NuAuth - Authentication, Session Management & Permission Engine
 * PHP 7.4 compatible
 */

require_once __DIR__ . '/Audit.php';

class NuAuth {
    private $db;
    private $config;
    private static $instance = null;

    public function __construct() {
        global $nuConfig;
        $this->config = $nuConfig ?? [];
        $this->db     = NuDatabase::getInstance();
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // --- Login ---
    public function login($username, $password, $otp = '') {
        $username = trim($username);
        if ($username === '' || $password === '') {
            return $this->fail('Username and password are required.');
        }

        $user = $this->db->fetchOne(
            "SELECT * FROM nu_users WHERE usr_username = :u AND usr_active = 1 LIMIT 1",
            [':u' => $username]
        );

        if (!$user) {
            password_verify($password, '$2y$12$invalidhashpadding...............');
            return $this->fail('Invalid credentials.');
        }

        $maxAttempts    = (int)($this->config['maxLoginAttempts'] ?? 5);
        $lockoutSeconds = (int)($this->config['lockoutDuration']  ?? 900);

        if ((int)$user['usr_failed_attempts'] >= $maxAttempts) {
            $lastAttempt = strtotime($user['usr_last_attempt'] ?? '0');
            if (time() - $lastAttempt < $lockoutSeconds) {
                $wait = (int)(($lastAttempt + $lockoutSeconds - time()) / 60) + 1;
                return $this->fail("Account locked. Try again in {$wait} minute(s).");
            }
            $this->resetAttempts((int)$user['usr_id']);
        }

        if (!password_verify($password, $user['usr_password'])) {
            $this->incrementAttempts((int)$user['usr_id']);
            return $this->fail('Invalid credentials.');
        }

        if (password_needs_rehash($user['usr_password'], PASSWORD_BCRYPT, ['cost' => 12])) {
            $newHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            $this->db->query(
                "UPDATE nu_users SET usr_password = :p WHERE usr_id = :id",
                [':p' => $newHash, ':id' => $user['usr_id']]
            );
        }

        if (!empty($this->config['enable2FA']) && !empty($user['usr_2fa_secret'])) {
            if ($otp === '' || !$this->verifyTOTP($user['usr_2fa_secret'], $otp)) {
                return ['success' => false, 'message' => 'Invalid or missing 2FA code.', 'requires2FA' => true];
            }
        }

        $this->resetAttempts((int)$user['usr_id']);
        $this->createSession($user);
        $this->logAudit('login', 'nu_users', (int)$user['usr_id']);

        return ['success' => true, 'user' => $this->sanitizeUser($user)];
    }

    // --- Logout ---
    public function logout() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (!empty($_SESSION['nu_user_id'])) {
            $this->logAudit('logout', 'nu_users', (int)$_SESSION['nu_user_id']);
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        return true;
    }

    // --- Session Check ---
    public function checkAuth() {
        if (empty($_SESSION['nu_user_id'])) return false;
        $timeout = (int)($this->config['sessionTimeout'] ?? 3600);
        if (time() - (int)($_SESSION['nu_last_activity'] ?? 0) > $timeout) {
            $this->logout();
            return false;
        }
        $_SESSION['nu_last_activity'] = time();
        return true;
    }

    public function isLoggedIn() {
        return $this->checkAuth();
    }

    // --- Current User ---
    public function getCurrentUser() {
        if (empty($_SESSION['nu_user_id'])) return null;
        $user = $this->db->fetchOne(
            "SELECT usr_id, usr_username, usr_name, usr_email, usr_role, usr_active FROM nu_users WHERE usr_id = :id LIMIT 1",
            [':id' => $_SESSION['nu_user_id']]
        );
        return $user ?: null;
    }

    // --- CSRF ---
    public function getCsrfToken() {
        if (empty($_SESSION['nu_csrf'])) {
            $_SESSION['nu_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['nu_csrf'];
    }

    public function verifyCsrf($token) {
        return isset($_SESSION['nu_csrf']) && hash_equals($_SESSION['nu_csrf'], $token);
    }

    // --- Permissions ---
    public function hasPermission($permission) {
        $user = $this->getCurrentUser();
        if (!$user) return false;
        if ($user['usr_role'] === 'globeadmin' || $user['usr_role'] === 'admin') return true;

        $perms = $this->db->fetchAll(
            "SELECT p.perm_code FROM nu_permissions p
             JOIN nu_role_permissions rp ON p.perm_id = rp.rp_perm_id
             JOIN nu_roles r ON rp.rp_role_id = r.role_id
             WHERE r.role_code = :role",
            [':role' => $user['usr_role']]
        );
        return in_array($permission, array_column($perms, 'perm_code'), true);
    }

    public function requireAuth() {
        if (!$this->checkAuth()) {
            if ($this->isApiRequest()) {
                http_response_code(401);
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => 'Unauthorized']);
                exit;
            }
            header('Location: ../index.php');
            exit;
        }
    }

    public function requirePermission($permission) {
        $this->requireAuth();
        if (!$this->hasPermission($permission)) {
            if ($this->isApiRequest()) {
                http_response_code(403);
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => 'Forbidden']);
                exit;
            }
            http_response_code(403);
            exit('Access denied.');
        }
    }

    // --- Private Helpers ---
    private function createSession($user) {
        // Regenerate session ID to replace any stale browser cookie and
        // prevent session fixation. DO NOT call session_write_close() here -
        // the session must stay open so index.php can read $_SESSION after
        // the redirect. PHP closes and writes the session automatically at
        // the end of the request.
        session_regenerate_id(true);

        $_SESSION['nu_user_id']       = $user['usr_id'];
        $_SESSION['nu_username']      = $user['usr_username'];
        $_SESSION['nu_role']          = $user['usr_role'];
        $_SESSION['nu_last_activity'] = time();
        $_SESSION['nu_csrf']          = bin2hex(random_bytes(32));
        // Session is written to disk automatically when PHP exits this request.
    }

    private function incrementAttempts($userId) {
        $this->db->query(
            "UPDATE nu_users SET usr_failed_attempts = usr_failed_attempts + 1, usr_last_attempt = NOW() WHERE usr_id = :id",
            [':id' => $userId]
        );
    }

    private function resetAttempts($userId) {
        $this->db->query(
            "UPDATE nu_users SET usr_failed_attempts = 0, usr_last_attempt = NULL WHERE usr_id = :id",
            [':id' => $userId]
        );
    }

    private function logAudit($action, $table, $recordId) {
        if (empty($this->config['enableAuditTrail'])) return;
        try {
            $audit = new NuAudit();
            $audit->log($action, $table, $recordId);
        } catch (Throwable $e) {
            error_log('[NuAuth] Audit log failed: ' . $e->getMessage());
        }
    }

    private function sanitizeUser($user) {
        unset($user['usr_password'], $user['usr_2fa_secret'], $user['usr_failed_attempts'], $user['usr_last_attempt']);
        return $user;
    }

    private function isApiRequest() {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        return (
            !empty($_SERVER['HTTP_X_REQUESTED_WITH']) ||
            strpos($uri, '/api/') !== false
        );
    }

    private function fail($message) {
        return ['success' => false, 'message' => $message];
    }

    // --- TOTP 2FA ---
    private function verifyTOTP($secret, $otp) {
        $timeSlice = (int)floor(time() / 30);
        for ($i = -1; $i <= 1; $i++) {
            if (hash_equals($this->generateTOTP($secret, $timeSlice + $i), $otp)) return true;
        }
        return false;
    }

    private function generateTOTP($secret, $timeSlice) {
        $secret = $this->base32Decode($secret);
        $time   = pack('N*', 0) . pack('N*', $timeSlice);
        $hm     = hash_hmac('sha1', $time, $secret, true);
        $offset = ord(substr($hm, -1)) & 0x0F;
        $code   = ((ord($hm[$offset])   & 0x7F) << 24) |
                  ((ord($hm[$offset+1]) & 0xFF) << 16) |
                  ((ord($hm[$offset+2]) & 0xFF) <<  8) |
                   (ord($hm[$offset+3]) & 0xFF);
        return str_pad((string)($code % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private function base32Decode($input) {
        $map    = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $input  = strtoupper(str_replace('=', '', $input));
        $output = '';
        $buffer = 0;
        $bsize  = 0;
        for ($i = 0, $len = strlen($input); $i < $len; $i++) {
            $val = strpos($map, $input[$i]);
            if ($val === false) continue;
            $buffer = ($buffer << 5) | $val;
            $bsize += 5;
            if ($bsize >= 8) {
                $bsize  -= 8;
                $output .= chr(($buffer >> $bsize) & 0xFF);
            }
        }
        return $output;
    }

    private function __clone() {}
    public function __wakeup() { throw new RuntimeException('Cannot unserialize NuAuth.'); }
}

if (!class_exists('Auth')) {
    class_alias('NuAuth', 'Auth');
}
