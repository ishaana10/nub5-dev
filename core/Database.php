<?php
declare(strict_types=1);
/**
 * NuDatabase - PDO Singleton with query helpers
 * Class name: NuDatabase (used by Auth.php, all API files)
 * Also aliased as Database for backward compatibility.
 */
class NuDatabase {
    private static $instance = null;
    private $pdo;

    private function __construct() {
        global $nuConfig;
        if (!is_array($nuConfig)) {
            throw new RuntimeException('nuConfig not loaded before NuDatabase instantiation.');
        }
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $nuConfig['dbHost'],
            $nuConfig['dbPort'] ?? 3306,
            $nuConfig['dbName'],
            $nuConfig['dbCharset']
        );
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_PERSISTENT         => false,
            PDO::MYSQL_ATTR_FOUND_ROWS   => true,
        ];
        try {
            $this->pdo = new PDO($dsn, $nuConfig['dbUser'], $nuConfig['dbPassword'], $options);
        } catch (PDOException $e) {
            error_log('[NuDatabase] Connection failed: ' . $e->getMessage());
            throw new RuntimeException('Database connection failed. Check server logs.');
        }
    }

    /**
     * @return NuDatabase
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getPdo() {
        return $this->pdo;
    }

    /** Execute a prepared statement and return the PDOStatement */
    public function query($sql, $params = []) {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function fetchAll($sql, $params = []) {
        return $this->query($sql, $params)->fetchAll();
    }

    /**
     * @return array|false
     */
    public function fetchOne($sql, $params = []) {
        return $this->query($sql, $params)->fetch();
    }

    /** Safe insert - returns last insert ID */
    public function insert($table, $data) {
        if (empty($data)) throw new InvalidArgumentException('Insert data cannot be empty.');
        $cols = implode(', ', array_map(function($k) { return "`$k`"; }, array_keys($data)));
        $placeholders = ':' . implode(', :', array_keys($data));
        $this->query("INSERT INTO `{$table}` ({$cols}) VALUES ({$placeholders})", $data);
        return $this->pdo->lastInsertId();
    }

    /** Safe update */
    public function update($table, $data, $where, $whereParams = []) {
        if (empty($data)) throw new InvalidArgumentException('Update data cannot be empty.');
        $set = implode(', ', array_map(function($k) { return "`{$k}` = :{$k}"; }, array_keys($data)));
        $this->query("UPDATE `{$table}` SET {$set} WHERE {$where}", array_merge($data, $whereParams));
        return true;
    }

    /** Safe delete */
    public function delete($table, $where, $whereParams = []) {
        $this->query("DELETE FROM `{$table}` WHERE {$where}", $whereParams);
        return true;
    }

    public function beginTransaction() { return $this->pdo->beginTransaction(); }
    public function commit()           { return $this->pdo->commit(); }
    public function rollback()         { return $this->pdo->rollBack(); }
    public function lastInsertId()     { return $this->pdo->lastInsertId(); }

    /** Prevent cloning/unserialization */
    private function __clone() {}
    public function __wakeup() { throw new RuntimeException('Cannot unserialize singleton.'); }
}

// Backward-compatible alias so any file using `new Database()` or `Database::getInstance()` still works
if (!class_exists('Database')) {
    class_alias('NuDatabase', 'Database');
}
