<?php
declare(strict_types=1);
/**
 * NuAuth - Authentication, Session Management & Permission Engine
 */

require_once __DIR__ . '/Audit.php';

class NuAuth {
    private $db;
    private $config;
    private static $instance = null;

    public function __construct() {
        global $nuConfig;
        $this->config = $nuConfig;
        $this->db = NuDatabase::getInstance();
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // --- Login ---
    public function login($username, $password, $otp = null) {
        $user = $this->db->fetchOne(
            "SELECT * FROM nu_users WHERE usr_username = :u AND usr_active = 1",
            [':u' => $username]
        );

        if (!$user) return ['success' => false, 'message' => 'Invalid credentials'];

        if ($user['usr_failed_attempts'] >= $this->config['maxLoginAttempts']) {
            $lastAttempt = strtotime($user['usr_last_attempt']);
            if (time() - $lastAttempt < $this->config['lockoutDuration']) {
                return ['success' => false, 'message' => 'Account locked. Try again later.'];
            }
            $this->resetAttempts($user['usr_id']);
        }

        if (!password_verify($password, $user['usr_password'])) {
            $this->incrementAttempts($user['usr_id']);
            return ['success' => false, 'message' => 'Invalid credentials'];
        }

        if ($this->config['enable2FA'] && $user['usr_2fa_secret']) {
            if (!$otp || !$this->verifyTOTP($user['usr_2fa_secret'], $otp)) {
                return ['success' => false, 'message' => 'Invalid or missing 2FA code', 'requires2FA' => true];
            }
        }

        $this->resetAttempts($user['usr_id']);
        $this->createSession($user);
        $this->logAudit('login', 'nu_users', $user['usr_id']);

        return ['success' => true, 'user' => $this->sanitizeUser($user)];
    }

    // --- Logout ---
    public function logout() {
        if (isset($_SESSION['nu_user_id'])) {
            $this->logAudit('logout', 'nu_users', $_SESSION['nu_user_id']);
        }
        session_destroy();
        return true;
    }

    // --- Session Check ---
    public function checkAuth() {
        if (empty($_SESSION['nu_user_id'])) return false;
        if (time() - $_SESSION['nu_last_activity'] > $this->config['sessionTimeout']) {
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
        if (!$this->checkAuth()) return null;
        return $this->db->fetchOne(
            "SELECT * FROM nu_users WHERE usr_id = :id",
            [':id' => $_SESSION['nu_user_id']]
        );
    }

    public function getCurrentRole(): string {
        return $_SESSION['nu_role'] ?? '';
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

    // --- Legacy permission check (module-level) ---
    public function hasPermission($permission) {
        $user = $this->getCurrentUser();
        if (!$user) return false;
        if ($user['usr_role'] === 'globeadmin') return true;

        $perms = $this->db->fetchAll(
            "SELECT p.perm_code FROM nu_permissions p
             JOIN nu_role_permissions rp ON p.perm_id = rp.rp_perm_id
             JOIN nu_roles r ON rp.rp_role_id = r.role_id
             WHERE r.role_code = :role",
            [':role' => $user['usr_role']]
        );

        $permCodes = array_column($perms, 'perm_code');
        return in_array($permission, $permCodes);
    }

    // --- Form-level permission check ---
    // action: 'view' | 'add' | 'edit' | 'delete' | 'export'
    public function canForm(string $formCode, string $action): bool {
        $user = $this->getCurrentUser();
        if (!$user) return false;
        if ($user['usr_role'] === 'globeadmin') return true;

        $role = $user['usr_role'];
        $col  = 'rfp_can_' . $action;

        // Exact form match first, then wildcard fallback
        $row = $this->db->fetchOne(
            "SELECT {$col} FROM nu_role_form_permissions
             WHERE rfp_role_code = :role AND rfp_form_code = :form",
            [':role' => $role, ':form' => $formCode]
        );

        if ($row !== null) return (bool)$row[$col];

        $wild = $this->db->fetchOne(
            "SELECT {$col} FROM nu_role_form_permissions
             WHERE rfp_role_code = :role AND rfp_form_code = '*'",
            [':role' => $role]
        );

        return $wild ? (bool)$wild[$col] : false;
    }

    // Returns all 5 flags for a form — used by FormRenderer to inject data attrs
    public function formPerms(string $formCode): array {
        $user = $this->getCurrentUser();
        if (!$user) return ['view'=>false,'add'=>false,'edit'=>false,'delete'=>false,'export'=>false];
        if ($user['usr_role'] === 'globeadmin') {
            return ['view'=>true,'add'=>true,'edit'=>true,'delete'=>true,'export'=>true];
        }

        $role = $user['usr_role'];
        $row  = $this->db->fetchOne(
            "SELECT * FROM nu_role_form_permissions
             WHERE rfp_role_code = :role AND rfp_form_code = :form",
            [':role' => $role, ':form' => $formCode]
        );
        if (!$row) {
            $row = $this->db->fetchOne(
                "SELECT * FROM nu_role_form_permissions
                 WHERE rfp_role_code = :role AND rfp_form_code = '*'",
                [':role' => $role]
            );
        }
        if (!$row) return ['view'=>false,'add'=>false,'edit'=>false,'delete'=>false,'export'=>false];

        return [
            'view'   => (bool)$row['rfp_can_view'],
            'add'    => (bool)$row['rfp_can_add'],
            'edit'   => (bool)$row['rfp_can_edit'],
            'delete' => (bool)$row['rfp_can_delete'],
            'export' => (bool)$row['rfp_can_export'],
        ];
    }

    // -------------------------------------------------------------------------
    // --- Global user meta: ##key## hash replacement in SQL ------------------
    // -------------------------------------------------------------------------

    /**
     * Replace ##key## placeholders in a SQL string with the current user's
     * global meta values stored in $_SESSION['nu_user_meta'].
     *
     * Only keys defined as 'global' => true in config.user_fields.php are
     * available. Values are escaped for safe embedding.
     *
     * Example:
     *   Input:  "SELECT * FROM sales WHERE station = '##station##'"
     *   Output: "SELECT * FROM sales WHERE station = 'North'"
     *
     * If a key has no value in session, the placeholder is replaced with empty string.
     */
    public function resolveHashes(string $sql): string {
        $meta = $_SESSION['nu_user_meta'] ?? [];
        if (empty($meta)) return $sql;

        foreach ($meta as $key => $value) {
            // Sanitise key to prevent regex injection
            $safeKey = preg_quote($key, '/');
            // Escape value for safe SQL embedding (PDO not available at this point)
            $safeVal = addslashes((string)$value);
            $sql = preg_replace('/##' . $safeKey . '##/', $safeVal, $sql);
        }
        return $sql;
    }

    /**
     * Get the current user's global meta as a key=>value array.
     * Useful for JS injection or debug.
     */
    public function getGlobalMeta(): array {
        return $_SESSION['nu_user_meta'] ?? [];
    }

    // -------------------------------------------------------------------------

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

    public function requireFormPerm(string $formCode, string $action) {
        $this->requireAuth();
        if (!$this->canForm($formCode, $action)) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'You do not have ' . $action . ' permission on this form.']);
            exit;
        }
    }

    // --- Private Helpers ---
    private function createSession($user) {
        session_regenerate_id(true);
        $_SESSION['nu_user_id']       = $user['usr_id'];  // preserved as-is: string (UUID) or int
        $_SESSION['nu_username']      = $user['usr_username'];
        $_SESSION['nu_role']          = $user['usr_role'];
        $_SESSION['nu_last_activity'] = time();
        $_SESSION['nu_csrf']          = bin2hex(random_bytes(32));

        // Pass usr_id without casting — loadGlobalMeta accepts string or int.
        // A (int) cast here would silently zero any UUID string.
        $_SESSION['nu_user_meta'] = $this->loadGlobalMeta($user['usr_id']);
    }

    /**
     * Load all 'global' meta values for a user from nu_user_meta.
     * Accepts string or int $userId to support both UUID and auto_increment modes.
     * Returns an associative array: ['station' => 'North', ...]
     *
     * @param mixed $userId  string (UUID) or int
     */
    private function loadGlobalMeta($userId): array {
        $configFile = NU_ROOT . '/config.user_fields.php';
        if (!file_exists($configFile)) return [];

        $fieldDefs = include $configFile;
        if (!is_array($fieldDefs)) return [];

        // Collect the keys that are flagged as global
        $globalKeys = [];
        foreach ($fieldDefs as $def) {
            if (!empty($def['global']) && !empty($def['key'])) {
                $globalKeys[] = $def['key'];
            }
        }
        if (empty($globalKeys)) return [];

        // Fetch from DB — $userId is passed directly to PDO, no cast needed
        $rows = $this->db->fetchAll(
            "SELECT umeta_key, umeta_value FROM nu_user_meta WHERE umeta_user_id = :id",
            [':id' => $userId]
        );

        $meta = [];
        foreach ($rows as $row) {
            if (in_array($row['umeta_key'], $globalKeys, true)) {
                $meta[$row['umeta_key']] = $row['umeta_value'];
            }
        }
        return $meta;
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
        if (!$this->config['enableAuditTrail']) return;
        $audit = new NuAudit();
        $audit->log($action, $table, $recordId);
    }

    private function sanitizeUser($user) {
        unset($user['usr_password']);
        unset($user['usr_2fa_secret']);
        return $user;
    }

    private function isApiRequest() {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        return (
            !empty($_SERVER['HTTP_X_REQUESTED_WITH']) ||
            strpos($uri, '/api/') !== false
        );
    }

    // --- TOTP 2FA ---
    private function verifyTOTP($secret, $otp) {
        $timeSlice = floor(time() / 30);
        for ($i = -1; $i <= 1; $i++) {
            $calculated = $this->generateTOTP($secret, $timeSlice + $i);
            if (hash_equals($calculated, $otp)) return true;
        }
        return false;
    }

    private function generateTOTP($secret, $timeSlice) {
        $secret = base32_decode($secret);
        $time   = pack('N*', 0) . pack('N*', $timeSlice);
        $hm     = hash_hmac('sha1', $time, $secret, true);
        $offset = ord(substr($hm, -1)) & 0x0F;
        $code   = ((ord($hm[$offset])   & 0x7F) << 24) |
                  ((ord($hm[$offset+1]) & 0xFF) << 16) |
                  ((ord($hm[$offset+2]) & 0xFF) <<  8) |
                   (ord($hm[$offset+3]) & 0xFF);
        return str_pad($code % 1000000, 6, '0', STR_PAD_LEFT);
    }

    private function __clone() {}
    public function __wakeup() { throw new RuntimeException('Cannot unserialize NuAuth.'); }
}

if (!class_exists('Auth')) {
    class_alias('NuAuth', 'Auth');
}

function base32_decode($input) {
    $map = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $input = strtoupper(str_replace('=', '', $input));
    $output = '';
    $buffer = 0;
    $bufferSize = 0;
    for ($i = 0; $i < strlen($input); $i++) {
        $val = strpos($map, $input[$i]);
        if ($val === false) continue;
        $buffer = ($buffer << 5) | $val;
        $bufferSize += 5;
        if ($bufferSize >= 8) {
            $bufferSize -= 8;
            $output .= chr(($buffer >> $bufferSize) & 0xFF);
        }
    }
    return $output;
}
