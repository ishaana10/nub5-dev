<?php
declare(strict_types=1);
/**
 * api/inspector.php  — Admin-only DB & Server Inspector
 * Actions: tables, columns, data, sql, files, serverinfo
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Auth.php';

header('Content-Type: application/json');

// ── Auth + globeadmin gate ────────────────────────────────────────────────────
$auth = NuAuth::getInstance();
if (!$auth->checkAuth()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
$user = $auth->getCurrentUser();
$role = strtolower((string)($user['usr_role'] ?? ''));
// Allow both 'globeadmin' (super-admin) and 'admin'
if ($role !== 'globeadmin' && $role !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Admin access required']);
    exit;
}

$action = (string)($_GET['action'] ?? '');

try {
    $db = NuDatabase::getInstance();
    $pdo = $db->getConnection();
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => 'DB connection failed: ' . $e->getMessage()]);
    exit;
}

switch ($action) {

    // ── List all tables ────────────────────────────────────────────────────────
    case 'tables':
        $rows = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        echo json_encode(['success' => true, 'tables' => $rows]);
        break;

    // ── Show columns for a table ───────────────────────────────────────────────
    case 'columns':
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string)($_GET['table'] ?? ''));
        if (!$table) { echo json_encode(['success' => false, 'error' => 'No table']); break; }
        $stmt = $pdo->query('DESCRIBE `' . $table . '`');
        $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $cnt  = $pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
        echo json_encode(['success' => true, 'columns' => $cols, 'row_count' => $cnt]);
        break;

    // ── Preview data (first 100 rows) ──────────────────────────────────────────
    case 'data':
        $table  = preg_replace('/[^a-zA-Z0-9_]/', '', (string)($_GET['table'] ?? ''));
        $limit  = min(500, max(1, (int)($_GET['limit']  ?? 100)));
        $offset = max(0,            (int)($_GET['offset'] ?? 0));
        if (!$table) { echo json_encode(['success' => false, 'error' => 'No table']); break; }
        $cols = $pdo->query('DESCRIBE `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC);
        $rows = $pdo->query('SELECT * FROM `' . $table . '` LIMIT ' . $limit . ' OFFSET ' . $offset)->fetchAll(PDO::FETCH_ASSOC);
        $cnt  = $pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
        echo json_encode(['success' => true, 'columns' => array_column($cols, 'Field'), 'rows' => $rows, 'total' => $cnt]);
        break;

    // ── Run arbitrary SQL ──────────────────────────────────────────────────────
    case 'sql':
        $raw  = file_get_contents('php://input');
        $body = json_decode($raw ?: '{}', true);
        $sql  = trim((string)($body['sql'] ?? $_POST['sql'] ?? ''));
        if ($sql === '') { echo json_encode(['success' => false, 'error' => 'No SQL provided']); break; }
        try {
            $stmt   = $pdo->query($sql);
            $upper  = strtoupper(ltrim($sql));
            $isRead = preg_match('/^(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN)/i', $upper);
            if ($isRead) {
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['success' => true, 'type' => 'select', 'rows' => $rows, 'count' => count($rows)]);
            } else {
                echo json_encode(['success' => true, 'type' => 'write', 'affected' => $stmt->rowCount()]);
            }
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    // ── File / Directory browser ───────────────────────────────────────────────
    case 'files':
        $base    = realpath(NU_ROOT);
        $reqPath = (string)($_GET['path'] ?? '');
        $target  = realpath($base . '/' . ltrim($reqPath, '/'));
        if ($target === false || strpos($target, $base) !== 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid path']); break;
        }
        if (is_file($target)) {
            $size = filesize($target);
            if ($size > 204800) {
                echo json_encode(['success' => true, 'type' => 'file', 'name' => basename($target),
                    'size' => $size, 'content' => '[File too large — ' . round($size/1024) . ' KB]']); break;
            }
            $ext    = strtolower(pathinfo($target, PATHINFO_EXTENSION));
            $binary = in_array($ext, ['jpg','jpeg','png','gif','bmp','ico','pdf','zip','gz','tar','bin','exe']);
            if ($binary) {
                echo json_encode(['success' => true, 'type' => 'file', 'name' => basename($target),
                    'size' => $size, 'content' => '[Binary file — not displayable]']); break;
            }
            echo json_encode(['success' => true, 'type' => 'file', 'name' => basename($target),
                'size' => $size, 'content' => file_get_contents($target)]);
            break;
        }
        $entries = [];
        foreach (scandir($target) as $item) {
            if ($item === '.') continue;
            $full = $target . '/' . $item;
            $rel  = str_replace($base, '', $full);
            $entries[] = [
                'name'      => $item,
                'path'      => $rel,
                'type'      => is_dir($full) ? 'dir' : 'file',
                'size'      => is_file($full) ? filesize($full) : null,
                'modified'  => date('Y-m-d H:i:s', filemtime($full)),
                'is_parent' => $item === '..'
            ];
        }
        echo json_encode(['success' => true, 'type' => 'dir',
            'path' => str_replace($base, '', $target) ?: '/', 'entries' => $entries]);
        break;

    // ── Server info ────────────────────────────────────────────────────────────
    case 'serverinfo':
        $dbVersion = $pdo->query('SELECT VERSION()')->fetchColumn();
        echo json_encode([
            'success'      => true,
            'php_version'  => PHP_VERSION,
            'db_version'   => $dbVersion,
            'server_os'    => PHP_OS,
            'app_root'     => NU_ROOT,
            'disk_free'    => function_exists('disk_free_space')  ? disk_free_space(NU_ROOT)  : null,
            'disk_total'   => function_exists('disk_total_space') ? disk_total_space(NU_ROOT) : null,
            'memory_limit' => ini_get('memory_limit'),
            'upload_max'   => ini_get('upload_max_filesize'),
            'extensions'   => get_loaded_extensions(),
        ]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
}
