<?php
require_once '../config.php';
require_once '../core/Database.php';
require_once '../core/Auth.php';

header('Content-Type: application/json');

$auth = new NuAuth();
if (!$auth->checkAuth()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? '';
$db = NuDatabase::getInstance();

switch ($action) {
    case 'test':
        $id = $_GET['id'] ?? 0;
        $webhook = $db->fetchOne("SELECT * FROM nu_webhooks WHERE webhook_id = :id", [':id' => $id]);
        if (!$webhook) {
            echo json_encode(['success' => false, 'error' => 'Webhook not found']);
            exit;
        }

        $payload = [
            'event' => 'test',
            'timestamp' => date('c'),
            'data' => ['message' => 'This is a test webhook from nuBuilder Next']
        ];

        if ($webhook['webhook_secret']) {
            $payload['signature'] = hash_hmac('sha256', json_encode($payload), $webhook['webhook_secret']);
        }

        $ch = curl_init($webhook['webhook_url']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $db->update('nu_webhooks', ['webhook_last_triggered' => date('Y-m-d H:i:s')], 'webhook_id = :id', [':id' => $id]);

        echo json_encode(['success' => $httpCode >= 200 && $httpCode < 300, 'http_code' => $httpCode, 'response' => $response]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
}
?>
