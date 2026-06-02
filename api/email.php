<?php
/**
 * api/email.php
 * REST API endpoint for email operations:
 *   POST  action=send            - Send email (direct or via template)
 *   POST  action=test            - Send test email to verify SMTP config
 *   GET   action=templates       - List all email templates
 *   POST  action=save_template   - Create or update a template
 *   POST  action=delete_template - Delete a template
 *   GET   action=logs            - Paginated email send log
 *   GET   action=get_settings    - Retrieve DB email settings
 *   POST  action=save_settings   - Persist email settings to DB
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../core/EmailService.php';

header('Content-Type: application/json');

// Auth guard
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorised']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {

        // ------------------------------------------------------------------
        case 'send':
            $to      = $input['to']      ?? '';
            $subject = $input['subject'] ?? '';
            $body    = $input['body']    ?? '';
            $tplSlug = $input['template_slug'] ?? '';
            $vars    = $input['variables'] ?? [];
            $options = [
                'cc'        => $input['cc']  ?? [],
                'bcc'       => $input['bcc'] ?? [],
                'reply_to'  => $input['reply_to'] ?? '',
            ];

            if (!$to) throw new \InvalidArgumentException('Recipient (to) is required.');

            if ($tplSlug) {
                $rendered = EmailService::renderTemplate($tplSlug, $vars);
                if (!$rendered) throw new \RuntimeException("Template '{$tplSlug}' not found or inactive.");
                $subject = $rendered['subject'];
                $body    = $rendered['body'];
            }

            if (!$subject || !$body) throw new \InvalidArgumentException('Subject and body are required.');

            $svc    = new EmailService();
            $result = $svc->send($to, $subject, $body, $options);
            echo json_encode($result);
            break;

        // ------------------------------------------------------------------
        case 'test':
            $to  = $input['to'] ?? ($_SESSION['user_email'] ?? '');
            if (!$to) throw new \InvalidArgumentException('Recipient email required.');
            $svc    = new EmailService();
            $result = $svc->send($to, 'nub5-dev Email Test', '<h2 style="font-family:sans-serif">Email system working ✓</h2><p style="font-family:sans-serif">Your nub5-dev email configuration is correct.</p>');
            echo json_encode($result);
            break;

        // ------------------------------------------------------------------
        case 'templates':
            $rows = $db->query("SELECT * FROM nu_email_templates ORDER BY name ASC");
            $templates = [];
            while ($row = $rows->fetch_assoc()) $templates[] = $row;
            echo json_encode(['success' => true, 'data' => $templates]);
            break;

        // ------------------------------------------------------------------
        case 'save_template':
            $id       = (int)($input['id'] ?? 0);
            $name     = $db->real_escape_string($input['name']    ?? '');
            $slug     = $db->real_escape_string($input['slug']    ?? '');
            $subject  = $db->real_escape_string($input['subject'] ?? '');
            $body     = $db->real_escape_string($input['body']    ?? '');
            $desc     = $db->real_escape_string($input['description'] ?? '');
            $active   = (int)($input['is_active'] ?? 1);

            if (!$name || !$slug || !$subject || !$body)
                throw new \InvalidArgumentException('name, slug, subject, and body are required.');

            if ($id > 0) {
                $db->query("UPDATE nu_email_templates SET name='{$name}', slug='{$slug}', subject='{$subject}',
                            body='{$body}', description='{$desc}', is_active={$active}, updated_at=NOW()
                            WHERE id={$id}");
                echo json_encode(['success' => true, 'message' => 'Template updated.']);
            } else {
                $db->query("INSERT INTO nu_email_templates (name, slug, subject, body, description, is_active, created_at, updated_at)
                            VALUES ('{$name}', '{$slug}', '{$subject}', '{$body}', '{$desc}', {$active}, NOW(), NOW())");
                echo json_encode(['success' => true, 'id' => $db->insert_id, 'message' => 'Template created.']);
            }
            break;

        // ------------------------------------------------------------------
        case 'delete_template':
            $id = (int)($input['id'] ?? 0);
            if (!$id) throw new \InvalidArgumentException('Template id required.');
            $db->query("DELETE FROM nu_email_templates WHERE id={$id}");
            echo json_encode(['success' => true, 'message' => 'Template deleted.']);
            break;

        // ------------------------------------------------------------------
        case 'logs':
            $limit  = min((int)($_GET['limit']  ?? 50), 200);
            $offset = (int)($_GET['offset'] ?? 0);
            $rows   = $db->query("SELECT * FROM nu_email_log ORDER BY sent_at DESC LIMIT {$limit} OFFSET {$offset}");
            $logs   = [];
            while ($row = $rows->fetch_assoc()) $logs[] = $row;
            $total  = $db->query("SELECT COUNT(*) AS c FROM nu_email_log")->fetch_assoc()['c'];
            echo json_encode(['success' => true, 'data' => $logs, 'total' => $total]);
            break;

        // ------------------------------------------------------------------
        case 'get_settings':
            $rows   = $db->query("SELECT setting_key, setting_value FROM nu_email_settings");
            $config = [];
            while ($row = $rows->fetch_assoc()) {
                $config[$row['setting_key']] = $row['setting_value'];
            }
            echo json_encode(['success' => true, 'data' => $config]);
            break;

        // ------------------------------------------------------------------
        case 'save_settings':
            $settings = $input['settings'] ?? [];
            if (empty($settings)) throw new \InvalidArgumentException('No settings provided.');

            foreach ($settings as $setting) {
                $key   = $db->real_escape_string($setting['key']   ?? '');
                $value = $db->real_escape_string($setting['value'] ?? '');
                if (!$key) continue;
                // UPSERT
                $db->query("INSERT INTO nu_email_settings (setting_key, setting_value)
                            VALUES ('{$key}', '{$value}')
                            ON DUPLICATE KEY UPDATE setting_value='{$value}', updated_at=NOW()");
            }
            echo json_encode(['success' => true, 'message' => 'Settings saved.']);
            break;

        // ------------------------------------------------------------------
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    }
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
