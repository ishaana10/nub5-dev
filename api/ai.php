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
$data = json_decode(file_get_contents('php://input'), true);
$prompt = $data['prompt'] ?? '';

// AI Provider configuration
$aiProvider = $GLOBALS['nuConfig']['aiProvider'] ?? 'openai';
$aiApiKey = $GLOBALS['nuConfig']['aiApiKey'] ?? '';
$aiModel = $GLOBALS['nuConfig']['aiModel'] ?? 'gpt-3.5-turbo';

if (!$aiApiKey) {
    // Fallback to local mock responses for demo
    echo json_encode(handleMockAi($action, $prompt));
    exit;
}

function handleMockAi($action, $prompt) {
    switch ($action) {
        case 'chat':
            $responses = [
                'I can help you with that. To build a customer form, you would need fields for name, contact info, and order details.',
                'For that SQL query, you could use: SELECT * FROM orders WHERE status = 'active' ORDER BY created_at DESC.',
                'I recommend creating a workflow with manager approval for documents over $1000.',
                'You can set up a dashboard widget to track daily sales using the orders table.',
            ];
            return ['success' => true, 'response' => $responses[array_rand($responses)]];

        case 'generate_form':
            return ['success' => true, 'data' => [
                'name' => 'AI Generated: ' . substr($prompt, 0, 30),
                'table' => 'ai_generated_' . time(),
                'fields' => [
                    ['type' => 'text', 'name' => 'name', 'label' => 'Name', 'required' => true],
                    ['type' => 'email', 'name' => 'email', 'label' => 'Email', 'required' => true],
                    ['type' => 'select', 'name' => 'category', 'label' => 'Category', 'options' => [['value' => 'a', 'label' => 'Option A'], ['value' => 'b', 'label' => 'Option B']]],
                    ['type' => 'date', 'name' => 'date', 'label' => 'Date'],
                    ['type' => 'textarea', 'name' => 'notes', 'label' => 'Notes']
                ]
            ]];

        case 'generate_report':
            return ['success' => true, 'data' => [
                'name' => 'AI Report: ' . substr($prompt, 0, 30),
                'sql' => 'SELECT DATE(created_at) as date, COUNT(*) as total, SUM(amount) as revenue FROM orders GROUP BY DATE(created_at) ORDER BY date DESC LIMIT 30',
                'columns' => [
                    ['field' => 'date', 'label' => 'Date'],
                    ['field' => 'total', 'label' => 'Orders'],
                    ['field' => 'revenue', 'label' => 'Revenue']
                ]
            ]];

        default:
            return ['success' => false, 'error' => 'Unknown action'];
    }
}

// Real AI API integration (when key is configured)
function callOpenAI($prompt, $apiKey, $model) {
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'model' => $model,
        'messages' => [['role' => 'user', 'content' => $prompt]],
        'max_tokens' => 1000
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

// For now, return mock responses
echo json_encode(handleMockAi($action, $prompt));
?>
