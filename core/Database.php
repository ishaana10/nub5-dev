<?php
declare(strict_types=1);
/**
 * NuDatabase - PDO wrapper (singleton)
 * PHP 7.4 compatible
 * IMPORTANT: destructor must NOT touch session - it runs during PHP shutdown
 * after session_write_close() has already been called.
 */
class NuDatabase {
    private static $instance = null;
    private $pdo;
    private $config;

    private function __construct() {
        global $nuConfig;
        $this->config = $nuConfig ?? [];
        $this->connect();
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Static bridge so code that calls NuDatabase::getConnection() (or
     * Database::getConnection() via the class_alias below) doesn't trigger
     * "Non-static method … should not be called statically".
     */
    public static function getConnection() {
        return self::getInstance()->pdo;
    }

    private function connect() {
        $host    = $this->config['dbHost']     ?? 'localhost';
        $dbName  = $this->config['dbName']     ?? '';
        $user    = $this->config['dbUser']     ?? '';
        $pass    = $this->config['dbPassword'] ?? '';
        $charset = $this->config['dbCharset']  ?? 'utf8mb4';
        $port    = $this->config['dbPort']     ?? 3306;

        $dsn = "mysql:host={$host};port={$port};dbname={$dbName};charset={$charset}";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        $this->pdo = new PDO($dsn, $user, $pass, $options);
    }

    public function getPdo() {
        return $this->pdo;
    }

    public function query($sql, $params = []) {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function fetchOne($sql, $params = []) {
        return $this->query($sql, $params)->fetch() ?: null;
    }

    public function fetchAll($sql, $params = []) {
        return $this->query($sql, $params)->fetchAll();
    }

    public function insert($table, $data) {
        $cols         = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_map(fn($k) => ":$k", array_keys($data)));
        $params       = [];
        foreach ($data as $k => $v) {
            $params[":$k"] = $v;
        }
        $sql = "INSERT INTO {$table} ({$cols}) VALUES ({$placeholders})";
        $this->query($sql, $params);
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * UPDATE a table row.
     *
     * $where must use named placeholders that do NOT collide with :set_* names.
     * Example:  update('nu_forms', $row, 'form_id = :where_form_id', [':where_form_id' => 16])
     *
     * For convenience, if $whereParams is a plain positional array (e.g. [$id])
     * AND $where contains exactly one '?' we auto-convert it to a named placeholder
     * so PDO never sees mixed named + positional params.
     */
    public function update($table, $data, $where, $whereParams = []) {
        // Build named SET params (:set_colname = value)
        $sets   = implode(', ', array_map(fn($k) => "{$k} = :set_{$k}", array_keys($data)));
        $params = [];
        foreach ($data as $k => $v) {
            $params[":set_{$k}"] = $v;
        }

        // Auto-convert a single positional '?' in $where to a named placeholder
        // so we never mix named and positional params (PDO HY093).
        if (!empty($whereParams) && array_keys($whereParams) === range(0, count($whereParams) - 1)) {
            // Positional array detected — rewrite each '?' to :where_0, :where_1 …
            $i = 0;
            $where = preg_replace_callback('/\?/', function() use (&$i) {
                return ':where_' . $i++;
            }, $where);
            $namedWhere = [];
            foreach ($whereParams as $idx => $val) {
                $namedWhere[':where_' . $idx] = $val;
            }
            $whereParams = $namedWhere;
        }

        $sql  = "UPDATE {$table} SET {$sets} WHERE {$where}";
        $stmt = $this->query($sql, array_merge($params, $whereParams));
        return $stmt->rowCount();
    }

    public function delete($table, $where, $params = []) {
        return $this->query("DELETE FROM {$table} WHERE {$where}", $params)->rowCount();
    }

    public function lastInsertId() {
        return (int)$this->pdo->lastInsertId();
    }

    public function beginTransaction() { $this->pdo->beginTransaction(); }
    public function commit()           { $this->pdo->commit(); }
    public function rollback()         { $this->pdo->rollBack(); }

    // IMPORTANT: destructor must do nothing that affects sessions or output.
    // PHP calls __destruct during shutdown AFTER session_write_close().
    public function __destruct() {
        // Intentionally empty - do not call session_destroy() or any session function here
        $this->pdo = null;
    }

    private function __clone() {}
    public function __wakeup() { throw new RuntimeException('Cannot unserialize NuDatabase.'); }
}

if (!class_exists('Database')) {
    class_alias('NuDatabase', 'Database');
}
