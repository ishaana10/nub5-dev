<?php
/**
 * API endpoint for App Cloner
 * Routes:
 *   POST action=start   → kicks off clone in background, returns jobId
 *   GET  action=progress&jobId=X  → returns JSON progress log
 *   POST action=export_sql → returns SQL export as download
 *   GET  action=list_databases → returns available databases for target picker
 */
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/AppCloner.php';

header('Content-Type: application/json');

// Only superadmin can use cloner
$auth = new Auth();
if (!$auth->isSuperAdmin()) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$action = $_REQUEST['action'] ?? '';

try {
    switch ($action) {

        // ── List source DB tables (for selective clone UI)
        case 'list_tables': {
            $db   = NuDatabase::getInstance()->getPdo();
            $rows = $db->query("SELECT TABLE_NAME, TABLE_TYPE, TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_TYPE, TABLE_NAME")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['tables' => $rows]);
            break;
        }

        // ── List all databases on the target host for autocomplete
        case 'list_databases': {
            $host    = $_POST['host']    ?? 'localhost';
            $user    = $_POST['user']    ?? '';
            $pass    = $_POST['pass']    ?? '';
            $port    = (int)($_POST['port'] ?? 3306);
            $pdo     = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $dbs     = $pdo->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN);
            echo json_encode(['databases' => $dbs]);
            break;
        }

        // ── Start a clone job
        case 'start': {
            $jobId   = uniqid('cloner_', true);
            $payload = json_decode(file_get_contents('php://input'), true) ?? [];

            // Validate required fields
            $required = ['targetDB','targetHost','targetUser','targetPass','opts'];
            foreach ($required as $f) {
                if (empty($payload[$f])) { http_response_code(400); echo json_encode(['error' => "Missing: $f"]); exit; }
            }

            // Persist job config for background process
            $jobFile = __DIR__ . '/../temp/nu_cloner_job_' . $jobId . '.json';
            file_put_contents($jobFile, json_encode(array_merge($payload, ['jobId' => $jobId])));

            // Launch background PHP process
            $script = escapeshellarg(__DIR__ . '/appcloner_worker.php');
            $arg    = escapeshellarg($jobId);
            if (PHP_OS_FAMILY === 'Windows') {
                pclose(popen("start /B php $script $arg", 'r'));
            } else {
                exec("php $script $arg > /dev/null 2>&1 &");
            }

            echo json_encode(['jobId' => $jobId, 'status' => 'started']);
            break;
        }

        // ── Poll progress
        case 'progress': {
            $jobId = preg_replace('/[^a-z0-9_.]/i', '', $_GET['jobId'] ?? '');
            $file  = __DIR__ . '/../temp/nu_cloner_progress_' . $jobId . '.json';
            if (!file_exists($file)) { echo json_encode(['steps' => [], 'done' => false]); break; }
            $steps = json_decode(file_get_contents($file), true) ?? [];
            $done  = !empty($steps) && in_array($steps[count($steps)-1]['status'] ?? '', ['success','error']);
            echo json_encode(['steps' => $steps, 'done' => $done]);
            break;
        }

        // ── SQL Export download
        case 'export_sql': {
            $payload    = json_decode(file_get_contents('php://input'), true) ?? [];
            $opts       = array_map('intval', $payload['opts'] ?? [1,2,3,4]);
            $format     = in_array($payload['format'] ?? 'mysql', ['mysql','mssql']) ? $payload['format'] : 'mysql';
            $insertType = in_array($payload['insertType'] ?? 'INSERT', ['INSERT','INSERT IGNORE','REPLACE']) ? $payload['insertType'] : 'INSERT';
            $zip        = !empty($payload['zip']);
            $targetDB   = $payload['targetDB'] ?? 'cloned_db';
            $schemaOnly = !empty($payload['schemaOnly']);

            $cloner = new AppCloner([
                'schemaOnly' => $schemaOnly,
                'zipExport'  => $zip,
                'sqlExport'  => [
                    'enabled'               => true,
                    'format'                => $format,
                    'includeDropStatements' => (bool)($payload['includeDrops'] ?? true),
                    'includeCreateDatabase' => true,
                    'includeUseDatabase'    => true,
                    'maxRowsPerInsert'      => (int)($payload['batchSize'] ?? 500),
                    'addComments'           => true,
                    'disableConstraints'    => true,
                ],
                'rowFilters' => $payload['rowFilters'] ?? [],
            ]);

            $sql = $cloner->exportSQL($targetDB, $opts, $insertType, $format);

            if ($zip) {
                header('Content-Type: application/gzip');
                header('Content-Disposition: attachment; filename="' . $targetDB . '_export.sql.gz"');
            } else {
                header('Content-Type: text/plain; charset=utf-8');
                header('Content-Disposition: attachment; filename="' . $targetDB . '_export.sql"');
            }
            echo $sql;
            break;
        }

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown action']);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
