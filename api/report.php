<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/core/Database.php';
require_once dirname(__DIR__) . '/core/Auth.php';

header('Content-Type: application/json');

$auth = new NuAuth();
if (!$auth->checkAuth()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$db     = NuDatabase::getInstance();
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// ─── READ BODY ───────────────────────────────────────────────────────────────
$body = [];
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true) ?? [];
    if (empty($body)) $body = $_POST;
}

try {
    switch ($action) {

        // ── list all active reports ──────────────────────────────────────────
        case 'list':
            $rows = $db->fetchAll(
                "SELECT report_id, report_code, report_name, report_type,
                        report_view_mode, report_active, report_created_at
                 FROM nu_reports
                 ORDER BY report_name"
            );
            echo json_encode(['success' => true, 'data' => $rows]);
            break;

        // ── get single report definition ─────────────────────────────────────
        case 'get':
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) throw new Exception('Missing id');
            $row = $db->fetchOne(
                "SELECT * FROM nu_reports WHERE report_id = ?", [$id]
            );
            if (!$row) throw new Exception('Report not found');
            // decode JSON columns
            foreach (['report_columns','report_filters','report_settings'] as $col) {
                if (isset($row[$col]) && is_string($row[$col])) {
                    $row[$col] = json_decode($row[$col], true);
                }
            }
            echo json_encode(['success' => true, 'data' => $row]);
            break;

        // ── save (create or update) ──────────────────────────────────────────
        case 'save':
            $id         = (int)($body['report_id'] ?? 0);
            $name       = trim($body['report_name'] ?? '');
            $code       = trim($body['report_code'] ?? '');
            $type       = $body['report_type']      ?? 'table';
            $viewMode   = $body['report_view_mode'] ?? 'table'; // table|chart|webdatarocks
            $sql        = trim($body['report_sql']  ?? '');
            $columns    = $body['report_columns']   ?? [];
            $filters    = $body['report_filters']   ?? [];
            $settings   = $body['report_settings']  ?? [];

            if (!$name) throw new Exception('Report name is required');
            if (!$code) $code = strtolower(preg_replace('/[^a-z0-9]/i', '_', $name));
            if (!$sql)  throw new Exception('SQL query is required');

            // basic SQL guard — SELECT only
            $firstWord = strtoupper(strtok(trim($sql), " \t\n\r"));
            if ($firstWord !== 'SELECT') throw new Exception('Only SELECT queries allowed');

            $colsJson     = json_encode($columns);
            $filtersJson  = json_encode($filters);
            $settingsJson = json_encode($settings);
            $userId       = $_SESSION['user_id'] ?? null;

            if ($id) {
                $db->execute(
                    "UPDATE nu_reports SET
                        report_name=?, report_code=?, report_type=?, report_view_mode=?,
                        report_sql=?, report_columns=?, report_filters=?, report_settings=?,
                        report_updated_at=NOW()
                     WHERE report_id=?",
                    [$name, $code, $type, $viewMode, $sql, $colsJson, $filtersJson, $settingsJson, $id]
                );
                echo json_encode(['success' => true, 'id' => $id, 'message' => 'Report updated']);
            } else {
                $db->execute(
                    "INSERT INTO nu_reports
                        (report_name, report_code, report_type, report_view_mode,
                         report_sql, report_columns, report_filters, report_settings, report_created_by)
                     VALUES (?,?,?,?,?,?,?,?,?)",
                    [$name, $code, $type, $viewMode, $sql, $colsJson, $filtersJson, $settingsJson, $userId]
                );
                $newId = $db->lastInsertId();
                echo json_encode(['success' => true, 'id' => $newId, 'message' => 'Report created']);
            }
            break;

        // ── delete ───────────────────────────────────────────────────────────
        case 'delete':
            $id = (int)($body['id'] ?? $_GET['id'] ?? 0);
            if (!$id) throw new Exception('Missing id');
            $db->execute("DELETE FROM nu_reports WHERE report_id = ?", [$id]);
            echo json_encode(['success' => true, 'message' => 'Report deleted']);
            break;

        // ── run report — returns JSON data rows ──────────────────────────────
        case 'run':
            $id     = (int)($_GET['id'] ?? 0);
            $code   = trim($_GET['code'] ?? '');
            $params = [];

            // collect any extra GET params as filter values
            foreach ($_GET as $k => $v) {
                if (!in_array($k, ['action','id','code'])) {
                    $params[$k] = $v;
                }
            }

            // load report definition
            if ($id) {
                $report = $db->fetchOne("SELECT * FROM nu_reports WHERE report_id=?", [$id]);
            } elseif ($code) {
                $report = $db->fetchOne("SELECT * FROM nu_reports WHERE report_code=?", [$code]);
            } else {
                throw new Exception('id or code required');
            }
            if (!$report) throw new Exception('Report not found');

            $sql     = $report['report_sql'];
            $filters = json_decode($report['report_filters'] ?? '[]', true) ?: [];

            // ── apply filter params as safe WHERE clauses ─────────────────────
            // Only apply params that match declared filter field names
            $whereParts = [];
            $bindings   = [];
            foreach ($filters as $f) {
                $field = $f['field'] ?? '';
                if ($field && isset($params[$field]) && $params[$field] !== '') {
                    $op = $f['operator'] ?? '=';
                    if ($op === 'LIKE') {
                        $whereParts[] = "`$field` LIKE ?";
                        $bindings[] = '%' . $params[$field] . '%';
                    } else {
                        $whereParts[] = "`$field` = ?";
                        $bindings[] = $params[$field];
                    }
                }
            }

            // wrap sql as subquery if we're adding WHERE
            if ($whereParts) {
                $sql = "SELECT * FROM ($sql) AS _rpt WHERE " . implode(' AND ', $whereParts);
            }

            // ── execute ───────────────────────────────────────────────────────
            $rows    = $db->fetchAll($sql, $bindings);
            $columns = json_decode($report['report_columns'] ?? '[]', true) ?: [];

            // auto-detect columns from first row if not configured
            if (empty($columns) && !empty($rows)) {
                foreach (array_keys($rows[0]) as $col) {
                    $columns[] = ['field' => $col, 'label' => ucwords(str_replace('_', ' ', $col))];
                }
            }

            echo json_encode([
                'success'    => true,
                'data'       => $rows,
                'columns'    => $columns,
                'total'      => count($rows),
                'view_mode'  => $report['report_view_mode'] ?? 'table',
                'report_name' => $report['report_name'],
            ]);
            break;

        // ── list tables (for SQL helper) ─────────────────────────────────────
        case 'tables':
            $tables = $db->fetchAll("SHOW TABLES");
            $names  = array_map('array_values', $tables);
            $flat   = array_column($names, 0);
            echo json_encode(['success' => true, 'data' => $flat]);
            break;

        // ── get columns of a table (for SQL helper) ──────────────────────────
        case 'columns':
            $table = preg_replace('/[^a-z0-9_]/i', '', $_GET['table'] ?? '');
            if (!$table) throw new Exception('table required');
            $cols = $db->fetchAll("SHOW COLUMNS FROM `$table`");
            echo json_encode(['success' => true, 'data' => $cols]);
            break;

        default:
            throw new Exception('Unknown action: ' . $action);
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
