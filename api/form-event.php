<?php
require_once '../config.php';
require_once '../core/Database.php';
require_once '../core/Auth.php';

$auth = new NuAuth();
if (!$auth->checkAuth()) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$db = NuDatabase::getInstance();
$input = json_decode(file_get_contents('php://input'), true);

$formId = intval($input['form_id'] ?? 0);
$eventType = $input['event_type'] ?? '';
$eventCode = $input['event_code'] ?? '';

if (!$formId || !$eventType) {
    echo json_encode(['success' => false, 'error' => 'Missing form_id or event_type']);
    exit;
}

// Delete existing event of this type
$db->query("DELETE FROM nu_form_events WHERE form_id = ? AND event_type = ?", [$formId, $eventType]);

// Insert new event
$db->insert('nu_form_events', [
    'form_id' => $formId,
    'event_type' => $eventType,
    'event_code' => $eventCode,
    'event_order' => 0,
    'event_active' => 1
]);

echo json_encode(['success' => true]);