<?php
// nuBuilder Next - Audit Trail

class NuAudit {
    private $db;

    public function __construct() {
        $this->db = NuDatabase::getInstance();
    }

    public function log($action, $table, $recordId, $oldData = null, $newData = null) {
        // Silently skip if audit log table does not exist
        try {
            // Check table exists first
            $check = $this->db->fetchOne(
                "SHOW TABLES LIKE 'nu_audit_log'"
            );
            if (!$check) {
                return; // Table not created yet - skip silently
            }

            $userId    = $_SESSION['nu_user_id'] ?? null;
            $username  = $_SESSION['nu_username'] ?? 'system';
            $ip        = $_SERVER['REMOTE_ADDR'] ?? 'cli';
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

            $this->db->insert('nu_audit_log', [
                'audit_action'     => $action,
                'audit_table'      => $table,
                'audit_record_id'  => $recordId,
                'audit_old_data'   => $oldData ? json_encode($oldData) : null,
                'audit_new_data'   => $newData ? json_encode($newData) : null,
                'audit_user_id'    => $userId,
                'audit_username'   => $username,
                'audit_ip'         => $ip,
                'audit_user_agent' => $userAgent,
                'audit_timestamp'  => date('Y-m-d H:i:s')
            ]);
        } catch (Throwable $e) {
            // Never let audit failure affect the session or request
            error_log('[NuAudit] ' . $e->getMessage());
        }
    }

    public function getLogs($filters = [], $limit = 100, $offset = 0) {
        $where  = ['1=1'];
        $params = [];
        if (!empty($filters['action'])) { $where[] = 'audit_action = :action'; $params[':action'] = $filters['action']; }
        if (!empty($filters['table']))  { $where[] = 'audit_table = :table';   $params[':table']  = $filters['table'];  }
        if (!empty($filters['user_id'])){ $where[] = 'audit_user_id = :user_id'; $params[':user_id'] = $filters['user_id']; }
        if (!empty($filters['from']))   { $where[] = 'audit_timestamp >= :from'; $params[':from'] = $filters['from']; }
        if (!empty($filters['to']))     { $where[] = 'audit_timestamp <= :to';   $params[':to']   = $filters['to'];   }
        $sql = 'SELECT * FROM nu_audit_log WHERE ' . implode(' AND ', $where)
             . ' ORDER BY audit_timestamp DESC LIMIT :limit OFFSET :offset';
        $params[':limit']  = (int)$limit;
        $params[':offset'] = (int)$offset;
        return $this->db->fetchAll($sql, $params);
    }

    public function getLogCount($filters = []) {
        $where  = ['1=1'];
        $params = [];
        if (!empty($filters['action'])) { $where[] = 'audit_action = :action'; $params[':action'] = $filters['action']; }
        if (!empty($filters['table']))  { $where[] = 'audit_table = :table';   $params[':table']  = $filters['table'];  }
        if (!empty($filters['user_id'])){ $where[] = 'audit_user_id = :user_id'; $params[':user_id'] = $filters['user_id']; }
        $sql    = 'SELECT COUNT(*) as count FROM nu_audit_log WHERE ' . implode(' AND ', $where);
        $result = $this->db->fetchOne($sql, $params);
        return (int)($result['count'] ?? 0);
    }
}
