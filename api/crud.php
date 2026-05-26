<?php
ob_start();
ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        while (ob_get_level()) {
            ob_end_clean();
        }
        echo json_encode([
            'success' => false,
            'error' => $error['message'] . ' in ' . basename($error['file']) . ':' . $error['line']
        ]);
    }
});

function nu_json($arr, $status = 200) {
    http_response_code($status);
    while (ob_get_level()) {
        $buffer = ob_get_clean();
        if (trim($buffer) !== '') {
            $arr['_output'] = $buffer;
        }
    }
    echo json_encode($arr);
    exit;
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../core/Database.php';
if (file_exists(__DIR__ . '/../core/Auth.php')) {
    require_once __DIR__ . '/../core/Auth.php';
}

function nu_db() {
    global $pdo;

    if (isset($pdo) && $pdo instanceof PDO) return $pdo;
    if (isset($GLOBALS['db']) && $GLOBALS['db'] instanceof PDO) return $GLOBALS['db'];
    if (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof PDO) return $GLOBALS['conn'];

    if (class_exists('Database')) {
        if (method_exists('Database', 'getConnection')) {
            $conn = Database::getConnection();
            if ($conn instanceof PDO) return $conn;
        }

        if (method_exists('Database', 'getInstance')) {
            $instance = Database::getInstance();

            if ($instance instanceof PDO) return $instance;

            if (is_object($instance) && method_exists($instance, 'getPdo')) {
                $conn = $instance->getPdo();
                if ($conn instanceof PDO) return $conn;
            }

            if (is_object($instance) && method_exists($instance, 'getConnection')) {
                $conn = $instance->getConnection();
                if ($conn instanceof PDO) return $conn;
            }
        }

        if (method_exists('Database', 'connect')) {
            $conn = Database::connect();
            if ($conn instanceof PDO) return $conn;
        }
    }

    throw new Exception('Database connection not available');
}

function nu_q($sql, $params = []) {
    $stmt = nu_db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function nu_request_json() {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function nu_safe_table($table) {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$table);
    if ($table === '') {
        throw new Exception('Table required');
    }
    return $table;
}

function nu_safe_column($column) {
    $column = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$column);
    if ($column === '') {
        throw new Exception('Invalid column');
    }
    return $column;
}

function nu_parse_where($whereRaw) {
    $whereRaw = trim((string)$whereRaw);
    if ($whereRaw === '') {
        return [[], []];
    }

    $parts = explode('=', $whereRaw, 2);
    if (count($parts) !== 2) {
        throw new Exception('Invalid where format. Use field=value');
    }

    $field = nu_safe_column(trim($parts[0]));
    $value = trim($parts[1]);

    return [["`{$field}` = ?"], [$value]];
}

function nu_get_record($table, $id) {
    $stmt = nu_q("SELECT * FROM `{$table}` WHERE id = ? LIMIT 1", [$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function nu_list_records($table, $whereRaw, $extraFilters, $page, $perPage) {
    $where = [];
    $params = [];

    list($whereParts, $whereParams) = nu_parse_where($whereRaw);
    $where = array_merge($where, $whereParts);
    $params = array_merge($params, $whereParams);

    foreach ($extraFilters as $key => $value) {
        if ($value === '' || $value === null) continue;
        $col = nu_safe_column($key);
        $where[] = "`{$col}` = ?";
        $params[] = $value;
    }

    $whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

    $page = max(1, (int)$page);
    $perPage = max(1, min(200, (int)$perPage));
    $offset = ($page - 1) * $perPage;

    $countSql = "SELECT COUNT(*) FROM `{$table}`" . $whereSql;
    $total = (int)nu_q($countSql, $params)->fetchColumn();
    $pages = max(1, (int)ceil($total / $perPage));

    if ($page > $pages) {
        $page = $pages;
        $offset = ($page - 1) * $perPage;
    }

    $sql = "SELECT * FROM `{$table}`" . $whereSql . " ORDER BY id DESC LIMIT {$perPage} OFFSET {$offset}";
    $rows = nu_q($sql, $params)->fetchAll(PDO::FETCH_ASSOC);

    return [
        'records' => $rows,
        'page' => $page,
        'pages' => $pages,
        'total' => $total
    ];
}

function nu_create_record($table, $data) {
    if (!$data || !is_array($data)) {
        throw new Exception('No data provided');
    }

    $clean = [];
    foreach ($data as $key => $value) {
        $col = nu_safe_column($key);
        $clean[$col] = $value;
    }

    if (!$clean) {
        throw new Exception('No valid fields provided');
    }

    $cols = array_keys($clean);
    $placeholders = array_fill(0, count($cols), '?');
    $params = array_values($clean);

    $sql = "INSERT INTO `{$table}` (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', $placeholders) . ")";
    nu_q($sql, $params);

    return nu_db()->lastInsertId();
}

function nu_update_record($table, $id, $data) {
    if (!$id) {
        throw new Exception('ID required');
    }

    if (!$data || !is_array($data)) {
        throw new Exception('No data provided');
    }

    $clean = [];
    foreach ($data as $key => $value) {
        $col = nu_safe_column($key);
        $clean[$col] = $value;
    }

    if (!$clean) {
        throw new Exception('No valid fields provided');
    }

    $sets = [];
    $params = [];

    foreach ($clean as $col => $value) {
        $sets[] = "`{$col}` = ?";
        $params[] = $value;
    }

    $params[] = $id;

    $sql = "UPDATE `{$table}` SET " . implode(', ', $sets) . " WHERE id = ?";
    nu_q($sql, $params);

    return true;
}

function nu_delete_record($table, $id) {
    if (!$id) {
        throw new Exception('ID required');
    }

    nu_q("DELETE FROM `{$table}` WHERE id = ?", [$id]);
    return true;
}

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $table = nu_safe_table($_GET['table'] ?? '');
    $id = $_GET['id'] ?? null;

    switch ($method) {
        case 'GET':
            if ($id !== null && $id !== '') {
                $record = nu_get_record($table, $id);
                nu_json([
                    'success' => true,
                    'data' => $record,
                    'record' => $record
                ]);
            }

            $filters = $_GET;
            unset($filters['table'], $filters['id'], $filters['page'], $filters['per_page'], $filters['where'], $filters['_']);

            $result = nu_list_records(
                $table,
                $_GET['where'] ?? '',
                $filters,
                $_GET['page'] ?? 1,
                $_GET['per_page'] ?? 20
            );

            nu_json([
                'success' => true,
                'data' => $result['records'],
                'records' => $result['records'],
                'page' => $result['page'],
                'pages' => $result['pages'],
                'total' => $result['total']
            ]);
            break;

        case 'POST':
            $data = nu_request_json();
            $newId = nu_create_record($table, $data);
            nu_json([
                'success' => true,
                'id' => $newId,
                'message' => 'Created successfully'
            ]);
            break;

        case 'PUT':
            $data = nu_request_json();
            nu_update_record($table, $id, $data);
            nu_json([
                'success' => true,
                'id' => $id,
                'message' => 'Updated successfully'
            ]);
            break;

        case 'DELETE':
            nu_delete_record($table, $id);
            nu_json([
                'success' => true,
                'id' => $id,
                'message' => 'Deleted successfully'
            ]);
            break;

        default:
            nu_json([
                'success' => false,
                'error' => 'Method not allowed'
            ], 405);
    }
} catch (Throwable $e) {
    nu_json([
        'success' => false,
        'error' => $e->getMessage()
    ], 500);
}