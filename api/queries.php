<?php
/**
 * api/queries.php
 * CRUD + run for nu_queries (Query Builder records).
 * Actions: list, get, save, delete, run, export, get_tables, get_columns
 */
header('Content-Type: application/json');
require_once '../config.php';
require_once '../core/Database.php';
require_once '../core/Auth.php';
require_once '../core/QueryExecutor.php';

$auth = new NuAuth();
if (!$auth->checkAuth()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$db     = NuDatabase::getInstance();
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'list':        actionList($db);           break;
    case 'get':         actionGet($db);            break;
    case 'save':        actionSave($db, $auth);    break;
    case 'delete':      actionDelete($db, $auth);  break;
    case 'run':         actionRun($db, $auth);     break;
    case 'export':      actionExport($db, $auth);  break;
    case 'get_tables':  actionGetTables($db);      break;
    case 'get_columns': actionGetColumns($db);     break;
    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action: ' . htmlspecialchars($action)]);
}

// ── LIST ──────────────────────────────────────────────────────────────────
function actionList($db) {
    try {
        $rows = $db->fetchAll(
            'SELECT query_id, query_code, query_name, query_description,
                    query_active, query_created_at, query_updated_at
             FROM nu_queries
             ORDER BY query_updated_at DESC'
        );
        echo json_encode(['success' => true, 'queries' => $rows]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

// ── GET single ────────────────────────────────────────────────────────────
function actionGet($db) {
    $id = $_GET['id'] ?? '';
    if (!$id) { echo json_encode(['success' => false, 'error' => 'Missing id']); return; }
    try {
        $row = $db->fetchOne('SELECT * FROM nu_queries WHERE query_id = ?', [(int)$id]);
        if (!$row) { echo json_encode(['success' => false, 'error' => 'Query not found']); return; }
        echo json_encode(['success' => true, 'query' => $row]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

// ── SAVE (insert or update) ───────────────────────────────────────────────
function actionSave($db, $auth) {
    if (!$auth->hasPermission('queries', 'build')) {
        echo json_encode(['success' => false, 'error' => 'Permission denied']); return;
    }
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) { echo json_encode(['success' => false, 'error' => 'Invalid JSON']); return; }

    $queryId     = !empty($data['query_id'])          ? (int)$data['query_id']          : null;
    $queryName   = trim($data['query_name']            ?? '');
    $queryCode   = trim($data['query_code']            ?? '');
    $querySql    = trim($data['query_sql']             ?? '');
    $queryDesc   = trim($data['query_description']     ?? '');
    $queryActive = isset($data['query_active'])        ? (int)$data['query_active']      : 1;

    if (!$queryName) { echo json_encode(['success' => false, 'error' => 'query_name is required']); return; }
    if (!$querySql)  { echo json_encode(['success' => false, 'error' => 'query_sql is required']);  return; }

    if (!$queryCode) {
        $queryCode = preg_replace('/[^a-z0-9]+/', '_', strtolower($queryName));
        $queryCode = trim($queryCode, '_');
    }
    $queryCode = preg_replace('/[^a-z0-9_]/', '', strtolower($queryCode));

    $upperSql = strtoupper(preg_replace('/\s+/', ' ', trim($querySql)));
    if (!preg_match('/^SELECT\s/i', $upperSql)) {
        echo json_encode(['success' => false, 'error' => 'Query must begin with SELECT']); return;
    }
    $forbidden = ['INSERT','UPDATE','DELETE','DROP','TRUNCATE','ALTER','GRANT','REVOKE','EXEC','EXECUTE','INTO OUTFILE','LOAD_FILE','SLEEP','BENCHMARK'];
    foreach ($forbidden as $kw) {
        if (preg_match('/\b' . preg_quote($kw, '/') . '\b/i', $querySql)) {
            echo json_encode(['success' => false, 'error' => 'Forbidden keyword: ' . $kw]); return;
        }
    }

    $paramsRaw = $data['query_parameters'] ?? [];
    if (is_array($paramsRaw)) {
        $paramsJson = json_encode($paramsRaw);
    } elseif (is_string($paramsRaw) && $paramsRaw !== '') {
        json_decode($paramsRaw);
        $paramsJson = json_last_error() === JSON_ERROR_NONE ? $paramsRaw : '{}';
    } else {
        $paramsJson = '{}';
    }

    $row = [
        'query_name'        => $queryName,
        'query_code'        => $queryCode,
        'query_description' => $queryDesc,
        'query_sql'         => $querySql,
        'query_parameters'  => $paramsJson,
        'query_active'      => $queryActive,
    ];

    try {
        if ($queryId) {
            $db->update('nu_queries', $row, 'query_id = ?', [$queryId]);
            echo json_encode(['success' => true, 'query_id' => $queryId]);
        } else {
            $existing = $db->fetchOne('SELECT query_id FROM nu_queries WHERE query_code = ?', [$queryCode]);
            if ($existing) {
                echo json_encode(['success' => false, 'error' => "Query code '{$queryCode}' already exists"]); return;
            }
            $db->insert('nu_queries', $row);
            $newId = $db->lastInsertId();
            echo json_encode(['success' => true, 'query_id' => $newId]);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

// ── DELETE ────────────────────────────────────────────────────────────────
function actionDelete($db, $auth) {
    if (!$auth->hasPermission('queries', 'build')) {
        echo json_encode(['success' => false, 'error' => 'Permission denied']); return;
    }
    $id = $_GET['id'] ?? '';
    if (!$id) { echo json_encode(['success' => false, 'error' => 'Missing id']); return; }
    try {
        $db->query('DELETE FROM nu_queries WHERE query_id = ?', [(int)$id]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

// ── RUN ───────────────────────────────────────────────────────────────────
function actionRun($db, $auth) {
    if (!$auth->hasPermission('queries', 'view')) {
        echo json_encode(['success' => false, 'error' => 'Permission denied']); return;
    }
    $code = $_GET['code'] ?? '';
    if (!$code) { echo json_encode(['success' => false, 'error' => 'Missing code']); return; }

    $params = [];
    foreach ($_GET as $k => $v) {
        if (!in_array($k, ['action', 'code'], true)) {
            $params[$k] = $v;
        }
    }

    $executor = new NuQueryExecutor();
    $result   = $executor->execute($code, $params);

    if (isset($result['error'])) {
        echo json_encode(['success' => false, 'error' => $result['error']]);
    } else {
        echo json_encode(['success' => true, 'data' => $result]);
    }
}

// ── EXPORT CSV ────────────────────────────────────────────────────────────
function actionExport($db, $auth) {
    if (!$auth->hasPermission('queries', 'view')) {
        // Can't send JSON here since we're about to stream CSV, so send a plain error
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Permission denied']);
        return;
    }

    $code = $_GET['code'] ?? '';
    if (!$code) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Missing code']);
        return;
    }

    // Collect any extra GET params as runtime parameters
    $params = [];
    foreach ($_GET as $k => $v) {
        if (!in_array($k, ['action', 'code'], true)) {
            $params[$k] = $v;
        }
    }

    $executor = new NuQueryExecutor();
    $result   = $executor->execute($code, $params);

    if (isset($result['error'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $result['error']]);
        return;
    }

    $records  = $result['records'];
    $columns  = $result['columns'];
    $filename = $code . '_' . date('Y-m-d') . '.csv';

    // Stream CSV directly to the browser
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    // UTF-8 BOM so Excel opens accented characters correctly
    fputs($out, "\xEF\xBB\xBF");
    fputcsv($out, $columns);
    foreach ($records as $row) {
        fputcsv($out, array_values($row));
    }
    fclose($out);
    exit;
}

// ── GET TABLES ────────────────────────────────────────────────────────────
function actionGetTables($db) {
    try {
        $rows   = $db->fetchAll('SHOW TABLES');
        $tables = array_map('current', $rows);
        echo json_encode(['success' => true, 'tables' => $tables]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

// ── GET COLUMNS ───────────────────────────────────────────────────────────
function actionGetColumns($db) {
    $table = $_GET['table'] ?? '';
    if (!$table) { echo json_encode(['success' => false, 'error' => 'Missing table']); return; }
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
        echo json_encode(['success' => false, 'error' => 'Invalid table name']); return;
    }
    try {
        $rows    = $db->fetchAll('SHOW COLUMNS FROM `' . $table . '`');
        $columns = array_map(fn($r) => $r['Field'], $rows);
        echo json_encode(['success' => true, 'columns' => $columns]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}
