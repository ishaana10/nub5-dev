<?php
/**
 * api/errorlog.php — Error Log REST endpoint
 *
 * Actions (admin only except log_js which is session-gated):
 *   GET  ?action=list   [&type=PHP|SQL|JS|APP] [&severity=error] [&search=x] [&page=1] [&per_page=50]
 *   GET  ?action=get&id=123
 *   POST ?action=log_js          — JS error payload from window.onerror / unhandledrejection
 *   POST ?action=clear           — truncate all logs (admin only)
 *   POST ?action=delete&id=123   — delete one entry (admin only)
 *
 * PHP 7.4 compatible.
 */
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/ErrorLogger.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$db     = NuDatabase::getInstance();

// ── JS error logging: only requires a valid session (not admin) ──────────────
if ($action === 'log_js') {
    if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['nu_user_id'])) {
        echo json_encode(['success' => false, 'error' => 'Not authenticated']);
        exit;
    }
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw, true) ?: [];
    if (empty($payload['message'])) {
        echo json_encode(['success' => false, 'error' => 'Missing message']);
        exit;
    }
    $payload['userAgent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
    NuErrorLogger::logJs($payload);
    echo json_encode(['success' => true]);
    exit;
}

// ── All other actions require admin ─────────────────────────────────────────
if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['nu_user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
$role = $_SESSION['nu_role'] ?? $_SESSION['nu_user_role'] ?? '';
if (!in_array(strtolower($role), ['admin', 'superadmin', 'globeadmin', 'administrator'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Admin access required']);
    exit;
}

try {
    switch ($action) {

        // ── List ────────────────────────────────────────────────────────────
        case 'list':
        default: {
            $type     = $_GET['type']     ?? '';
            $severity = $_GET['severity'] ?? '';
            $search   = $_GET['search']   ?? '';
            $page     = max(1, (int)($_GET['page']     ?? 1));
            $perPage  = min(200, max(10, (int)($_GET['per_page'] ?? 50)));
            $offset   = ($page - 1) * $perPage;

            $where  = ['1=1'];
            $params = [];

            if ($type !== '') {
                $where[]         = 'errlog_type = :type';
                $params[':type'] = strtoupper($type);
            }
            if ($severity !== '') {
                $where[]             = 'errlog_severity = :sev';
                $params[':sev']      = strtolower($severity);
            }
            if ($search !== '') {
                $where[]             = '(errlog_message LIKE :s1 OR errlog_file LIKE :s2 OR errlog_context LIKE :s3)';
                $like                = '%' . $search . '%';
                $params[':s1']       = $like;
                $params[':s2']       = $like;
                $params[':s3']       = $like;
            }

            $whereStr = implode(' AND ', $where);

            $total = (int)$db->fetchOne(
                "SELECT COUNT(*) AS cnt FROM nu_error_log WHERE {$whereStr}",
                $params
            )['cnt'];

            $rows = $db->fetchAll(
                "SELECT errlog_id, errlog_type, errlog_severity, errlog_message,
                        errlog_file, errlog_line, errlog_request_uri,
                        errlog_user_name, errlog_created_at
                 FROM nu_error_log
                 WHERE {$whereStr}
                 ORDER BY errlog_id DESC
                 LIMIT :lim OFFSET :off",
                array_merge($params, [':lim' => $perPage, ':off' => $offset])
            );

            echo json_encode([
                'success'  => true,
                'total'    => $total,
                'page'     => $page,
                'per_page' => $perPage,
                'pages'    => (int)ceil($total / $perPage),
                'rows'     => $rows,
            ]);
            break;
        }

        // ── Get single entry with full context + trace ───────────────────────
        case 'get': {
            $id  = (int)($_GET['id'] ?? 0);
            $row = $db->fetchOne(
                'SELECT * FROM nu_error_log WHERE errlog_id = :id',
                [':id' => $id]
            );
            if (!$row) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Not found']);
                break;
            }
            // Decode context JSON for nicer output
            if (!empty($row['errlog_context'])) {
                $decoded = json_decode($row['errlog_context'], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $row['errlog_context'] = $decoded;
                }
            }
            echo json_encode(['success' => true, 'row' => $row]);
            break;
        }

        // ── Clear all ────────────────────────────────────────────────────────
        case 'clear': {
            $db->query('TRUNCATE TABLE nu_error_log');
            echo json_encode(['success' => true, 'message' => 'All error logs cleared']);
            break;
        }

        // ── Delete one ──────────────────────────────────────────────────────
        case 'delete': {
            $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
            $db->delete('nu_error_log', 'errlog_id = :id', [':id' => $id]);
            echo json_encode(['success' => true]);
            break;
        }

        // ── Stats summary ───────────────────────────────────────────────────
        case 'stats': {
            $counts = $db->fetchAll(
                "SELECT errlog_type, errlog_severity, COUNT(*) AS cnt
                 FROM nu_error_log
                 GROUP BY errlog_type, errlog_severity
                 ORDER BY errlog_type, cnt DESC"
            );
            $recent = $db->fetchOne(
                "SELECT errlog_created_at FROM nu_error_log ORDER BY errlog_id DESC LIMIT 1"
            );
            echo json_encode([
                'success' => true,
                'counts'  => $counts,
                'last_error_at' => $recent['errlog_created_at'] ?? null,
            ]);
            break;
        }
    }
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
