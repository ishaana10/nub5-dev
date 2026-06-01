<?php
/**
 * api/forms.php
 * CRUD for nu_forms builder records.
 * Actions: get, save, list, delete
 */
header('Content-Type: application/json');
require_once '../config.php';
require_once '../core/Database.php';
require_once '../core/Auth.php';

$auth = new NuAuth();
if (!$auth->checkAuth()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$db     = NuDatabase::getInstance();
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'get':    actionGet($db);    break;
    case 'save':   actionSave($db);   break;
    case 'list':   actionList($db);   break;
    case 'delete': actionDelete($db); break;
    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action: ' . $action]);
}

// ── GET a single form by ID ───────────────────────────────────────────────
function actionGet($db) {
    $id = $_GET['id'] ?? '';
    if (!$id) {
        echo json_encode(['success' => false, 'error' => 'Missing id']);
        return;
    }
    try {
        $form = $db->fetchOne(
            'SELECT * FROM nu_forms WHERE form_id = ?',
            [$id]
        );
        if (!$form) {
            echo json_encode(['success' => false, 'error' => 'Form not found']);
            return;
        }
        echo json_encode(['success' => true, 'form' => $form]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

// ── LIST all forms ────────────────────────────────────────────────────────
function actionList($db) {
    try {
        $forms = $db->fetchAll(
            'SELECT form_id, form_name, form_code, form_table, form_type,
                    form_table_mode, form_pk_type, browse_display_mode,
                    created_at, updated_at
             FROM nu_forms
             ORDER BY updated_at DESC, form_name ASC'
        );
        echo json_encode(['success' => true, 'forms' => $forms]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

// ── SAVE (insert or update) ───────────────────────────────────────────────
function actionSave($db) {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        echo json_encode(['success' => false, 'error' => 'Invalid JSON payload']);
        return;
    }

    $formId   = $data['form_id']   ?? null;
    $formName = trim($data['form_name'] ?? '');
    $formCode = trim($data['form_code'] ?? '');

    if (!$formName) {
        echo json_encode(['success' => false, 'error' => 'form_name is required']);
        return;
    }
    if (!$formCode) {
        $formCode = preg_replace('/[^a-z0-9]+/', '_', strtolower($formName));
    }

    // Encode layout if it's an array
    $formLayout = $data['form_layout'] ?? '';
    if (is_array($formLayout)) {
        $formLayout = json_encode($formLayout);
    }

    $row = [
        'form_name'                => $formName,
        'form_code'                => $formCode,
        'form_table'               => $data['form_table']               ?? '',
        'form_type'                => $data['form_type']                ?? 'main',
        'form_table_mode'          => $data['form_table_mode']          ?? 'new',
        'form_pk_type'             => $data['form_pk_type']             ?? 'autoincrement',
        'form_layout'              => $formLayout,
        'browse_sql'               => $data['browse_sql']               ?? '',
        'browse_columns'           => $data['browse_columns']           ?? '',
        'browse_search_enabled'    => isset($data['browse_search_enabled'])    ? (int)$data['browse_search_enabled']    : 0,
        'browse_search_placeholder'=> $data['browse_search_placeholder'] ?? '',
        'browse_search_fields'     => $data['browse_search_fields']     ?? '',
        'browse_page_size'         => isset($data['browse_page_size'])  ? (int)$data['browse_page_size']  : 20,
        'browse_default_sort'      => $data['browse_default_sort']      ?? '',
        'browse_display_mode'      => $data['browse_display_mode']      ?? 'inline',
        'form_custom_js'           => $data['form_custom_js']           ?? '',
        'form_js_before_save'      => $data['form_js_before_save']      ?? '',
        'form_js_after_save'       => $data['form_js_after_save']       ?? '',
        'form_custom_php'          => $data['form_custom_php']          ?? '',
        'form_custom_css'          => $data['form_custom_css']          ?? '',
    ];

    try {
        if ($formId) {
            // UPDATE
            $row['updated_at'] = date('Y-m-d H:i:s');
            $db->update('nu_forms', $row, 'form_id = ?', [$formId]);
            echo json_encode(['success' => true, 'form_id' => $formId]);
        } else {
            // INSERT — check unique code
            $existing = $db->fetchOne(
                'SELECT form_id FROM nu_forms WHERE form_code = ?',
                [$formCode]
            );
            if ($existing) {
                echo json_encode(['success' => false, 'error' => 'Form code \'' . $formCode . '\' already exists']);
                return;
            }
            $row['created_at'] = date('Y-m-d H:i:s');
            $row['updated_at'] = date('Y-m-d H:i:s');
            $db->insert('nu_forms', $row);
            $newId = $db->lastInsertId();
            echo json_encode(['success' => true, 'form_id' => $newId]);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

// ── DELETE a form ─────────────────────────────────────────────────────────
function actionDelete($db) {
    $id = $_GET['id'] ?? '';
    if (!$id) {
        echo json_encode(['success' => false, 'error' => 'Missing id']);
        return;
    }
    try {
        $db->query('DELETE FROM nu_forms WHERE form_id = ?', [$id]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}
