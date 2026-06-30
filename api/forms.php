<?php
/**
 * api/forms.php
 * CRUD for nu_forms builder records.
 * Actions: get, save, list, delete, get_by_code, patch_layout
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
    case 'get':          actionGet($db);         break;
    case 'save':         actionSave($db);         break;
    case 'list':         actionList($db);         break;
    case 'delete':       actionDelete($db);       break;
    case 'get_by_code':  actionGetByCode($db);    break;
    case 'patch_layout': actionPatchLayout($db);  break;
    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action: ' . $action]);
}

// ── Helpers ───────────────────────────────────────────────────────────────

function nu_is_structural_type(string $type): bool {
    return in_array($type, [
        'html','content','button','subform','fieldset',
        'divider','heading','section','group','row','tab'
    ], true);
}

function nu_col_def_for_type(string $type): string {
    switch ($type) {
        case 'number':   return 'DECIMAL(20,6) NULL DEFAULT NULL';
        case 'checkbox': return 'TINYINT(1) NOT NULL DEFAULT 0';
        case 'date':     return 'DATE NULL DEFAULT NULL';
        case 'time':     return 'TIME NULL DEFAULT NULL';
        case 'datetime': return 'DATETIME NULL DEFAULT NULL';
        case 'textarea': return 'TEXT NULL DEFAULT NULL';
        case 'uuid':     return 'VARCHAR(36) NULL DEFAULT NULL';
        default:         return 'VARCHAR(255) NULL DEFAULT NULL';
    }
}

function nu_flatten_fields(array $layout): array {
    $out = [];
    foreach ($layout as $node) {
        $t = $node['type'] ?? 'field';

        if ($t === 'tab') {
            // tab → tabs[] → rows[] → fields[]
            foreach ($node['tabs'] ?? [] as $tab) {
                foreach ($tab['rows'] ?? [] as $row) {
                    foreach (nu_flatten_fields($row['fields'] ?? []) as $f) $out[] = $f;
                }
            }
        } elseif ($t === 'group') {
            // group → rows[] → fields[]
            foreach ($node['rows'] ?? [] as $row) {
                foreach (nu_flatten_fields($row['fields'] ?? []) as $f) $out[] = $f;
            }
        } elseif ($t === 'section' || $t === 'row') {
            // legacy flat children[]
            foreach (nu_flatten_fields($node['children'] ?? []) as $f) $out[] = $f;
        } elseif ($t === 'subform') {
            // never a DB column — skip
            continue;
        } else {
            $out[] = $node;
        }
    }
    return $out;
}

function nu_resolve_col_name(array $field): string {
    $name = trim($field['name'] ?? $field['fieldname'] ?? '');
    $type = $field['type'] ?? $field['fieldtype'] ?? 'text';
    if ($type === 'lookup') {
        $lk  = $field['lookup'] ?? [];
        $col = trim($lk['store_field'] ?? $lk['storefield'] ?? $lk['store_col'] ?? $lk['storeCol'] ?? '');
        if ($col !== '') return preg_replace('/[^a-zA-Z0-9_]/', '', $col);
        if ($name !== '' && !preg_match('/^lookup_\d+$/', $name)) return preg_replace('/[^a-zA-Z0-9_]/', '', $name);
        return '';
    }
    return preg_replace('/[^a-zA-Z0-9_]/', '', $name);
}

/**
 * Execute a DDL statement via PDO::exec() — NOT via a prepared statement.
 * MySQL cannot run CREATE/ALTER/DROP TABLE through native prepared statements.
 * All DDL in this file must go through this function.
 */
function nu_ddl(NuDatabase $db, string $sql): void {
    error_log('[forms.php DDL] ' . $sql);
    try {
        $db->exec($sql);
        error_log('[forms.php DDL] OK');
    } catch (Throwable $e) {
        error_log('[forms.php DDL] FAILED: ' . $e->getMessage());
        throw $e;
    }
}

function nu_sync_table_from_layout(NuDatabase $db, string $table, string $layoutJson, string $pkType = 'autoincrement'): void {
    if ($table === '') return;
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    if ($table === '') return;

    $layout = json_decode($layoutJson, true);
    if (!is_array($layout)) {
        error_log('[forms.php] nu_sync_table_from_layout: invalid JSON for table=' . $table);
        return;
    }

    $fields  = nu_flatten_fields($layout);
    $desired = [];
    foreach ($fields as $field) {
        $type = $field['type'] ?? $field['fieldtype'] ?? 'text';
        if (nu_is_structural_type($type)) continue;
        $col = nu_resolve_col_name($field);
        if ($col === '') continue;
        $desired[$col] = nu_col_def_for_type($type);
    }

    $tableExists = (bool)$db->fetchOne("SHOW TABLES LIKE ?", [$table]);
    error_log('[forms.php] nu_sync_table_from_layout: table=' . $table . ' exists=' . ($tableExists ? 'yes' : 'no') . ' desired_cols=' . count($desired));

    if (!$tableExists) {
        // ── CREATE TABLE ──────────────────────────────────────────────────
        $pkDef = ($pkType === 'uuid')
            ? "`id` VARCHAR(36) NOT NULL PRIMARY KEY"
            : "`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY";

        $colsSql = '';
        foreach ($desired as $col => $def) {
            $colsSql .= ",\n  `{$col}` {$def}";
        }
        $colsSql .= ",\n  `created_at` DATETIME NULL DEFAULT NULL";
        $colsSql .= ",\n  `updated_at` DATETIME NULL DEFAULT NULL";

        $createSql = "CREATE TABLE `{$table}` (\n  {$pkDef}{$colsSql}\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        nu_ddl($db, $createSql);  // uses exec(), not query()
        return;
    }

    // ── ALTER TABLE: add any missing columns ──────────────────────────────
    $existing = [];
    foreach ($db->fetchAll("DESCRIBE `{$table}`") as $row) {
        $existing[$row['Field']] = true;
    }

    foreach ($desired as $col => $def) {
        if (isset($existing[$col])) continue;
        try {
            nu_ddl($db, "ALTER TABLE `{$table}` ADD COLUMN `{$col}` {$def}");  // uses exec()
        } catch (Throwable $e) {
            // logged inside nu_ddl — continue with remaining columns
        }
    }
}

/**
 * Ensure nu_forms has all expected columns (auto-migration).
 */
function nu_ensure_nu_forms_columns(NuDatabase $db): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    $existing = [];
    foreach ($db->fetchAll("DESCRIBE `nu_forms`") as $col) {
        $existing[$col['Field']] = true;
    }

    $needed = [
        'form_panel_mode'     => "VARCHAR(20) NOT NULL DEFAULT 'fixed'",
        'form_panel_width'    => "INT NOT NULL DEFAULT 0",
        'form_custom_js'      => "MEDIUMTEXT NULL DEFAULT NULL",
        'form_js_before_save' => "MEDIUMTEXT NULL DEFAULT NULL",
        'form_js_after_save'  => "MEDIUMTEXT NULL DEFAULT NULL",
        'form_custom_php'     => "MEDIUMTEXT NULL DEFAULT NULL",
        'form_custom_css'     => "MEDIUMTEXT NULL DEFAULT NULL",
    ];

    foreach ($needed as $col => $def) {
        if (isset($existing[$col])) continue;
        try {
            nu_ddl($db, "ALTER TABLE `nu_forms` ADD COLUMN `{$col}` {$def}");
        } catch (Throwable $e) {
            // logged inside nu_ddl
        }
    }
}

/**
 * Drop a data table — uses exec() (DDL path).
 */
function nu_drop_table(NuDatabase $db, string $table): array {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    if ($table === '') return [false, 'Empty table name after sanitisation'];

    $exists = $db->fetchOne("SHOW TABLES LIKE ?", [$table]);
    if (!$exists) return [true, ''];

    try {
        nu_ddl($db, "DROP TABLE `{$table}`");
        return [true, ''];
    } catch (Throwable $e) {
        return [false, $e->getMessage()];
    }
}

// ── GET a single form by ID ───────────────────────────────────────────────
function actionGet($db) {
    $id = $_GET['id'] ?? '';
    if (!$id) { echo json_encode(['success' => false, 'error' => 'Missing id']); return; }
    try {
        $form = $db->fetchOne('SELECT * FROM nu_forms WHERE form_id = ?', [$id]);
        if (!$form) { echo json_encode(['success' => false, 'error' => 'Form not found']); return; }
        echo json_encode(['success' => true, 'form' => $form]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

// ── GET a single form by form_code ────────────────────────────────────────
function actionGetByCode($db) {
    $code = $_GET['code'] ?? '';
    if (!$code) { echo json_encode(['success' => false, 'error' => 'Missing code']); return; }
    try {
        $form = $db->fetchOne('SELECT * FROM nu_forms WHERE form_code = ? LIMIT 1', [$code]);
        if (!$form) { echo json_encode(['success' => false, 'error' => 'Form not found']); return; }
        echo json_encode(['success' => true, 'form' => $form]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

// ── PATCH only the form_layout column ────────────────────────────────────
function actionPatchLayout($db) {
    $id = $_GET['id'] ?? '';
    if (!$id) { echo json_encode(['success' => false, 'error' => 'Missing id']); return; }
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['form_layout'])) {
        echo json_encode(['success' => false, 'error' => 'Missing form_layout in payload']);
        return;
    }
    $layout = $data['form_layout'];
    if (is_array($layout)) $layout = json_encode($layout);
    try {
        $db->update('nu_forms', ['form_layout' => $layout, 'updated_at' => date('Y-m-d H:i:s')], 'form_id = ?', [$id]);
        $form = $db->fetchOne('SELECT form_table, form_pk_type FROM nu_forms WHERE form_id = ?', [$id]);
        if ($form && !empty($form['form_table'])) {
            try {
                nu_sync_table_from_layout($db, $form['form_table'], $layout, $form['form_pk_type'] ?? 'autoincrement');
            } catch (Throwable $e) {
                error_log('[forms.php] sync after patch_layout: ' . $e->getMessage());
            }
        }
        echo json_encode(['success' => true]);
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
             FROM nu_forms ORDER BY updated_at DESC, form_name ASC'
        );
        echo json_encode(['success' => true, 'forms' => $forms]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

// ── SAVE (insert or update) ───────────────────────────────────────────────
function actionSave($db) {
    nu_ensure_nu_forms_columns($db);

    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) { echo json_encode(['success' => false, 'error' => 'Invalid JSON payload']); return; }

    $formId   = $data['form_id']   ?? null;
    $formName = trim($data['form_name'] ?? '');
    $formCode = trim($data['form_code'] ?? '');

    if (!$formName) { echo json_encode(['success' => false, 'error' => 'form_name is required']); return; }
    if (!$formCode) $formCode = preg_replace('/[^a-z0-9]+/', '_', strtolower($formName));

    $formLayout = $data['form_layout'] ?? '';
    if (is_array($formLayout)) $formLayout = json_encode($formLayout);

    // Read these BEFORE array processing so DDL always has them
    $formTable = trim($data['form_table'] ?? '');
    $pkType    = trim($data['form_pk_type'] ?? 'autoincrement');
    $tableMode = trim($data['form_table_mode'] ?? 'new');

    error_log('[forms.php] actionSave: name=' . $formName . ' table=' . $formTable . ' pk=' . $pkType . ' mode=' . $tableMode);

    $row = [
        'form_name'                 => $formName,
        'form_code'                 => $formCode,
        'form_table'                => $formTable,
        'form_type'                 => $data['form_type']                ?? 'main',
        'form_table_mode'           => $tableMode,
        'form_pk_type'              => $pkType,
        'form_layout'               => $formLayout,
        'form_active'               => 1,
        'browse_sql'                => $data['browse_sql']               ?? '',
        'browse_columns'            => $data['browse_columns']           ?? '',
        'browse_search_enabled'     => isset($data['browse_search_enabled'])   ? (int)$data['browse_search_enabled']  : 0,
        'browse_search_placeholder' => $data['browse_search_placeholder'] ?? '',
        'browse_search_fields'      => $data['browse_search_fields']     ?? '',
        'browse_page_size'          => isset($data['browse_page_size'])  ? (int)$data['browse_page_size'] : 20,
        'browse_default_sort'       => $data['browse_default_sort']      ?? '',
        'browse_display_mode'       => $data['browse_display_mode']      ?? 'inline',
        'form_custom_js'            => $data['form_custom_js']           ?? '',
        'form_js_before_save'       => $data['form_js_before_save']      ?? '',
        'form_js_after_save'        => $data['form_js_after_save']       ?? '',
        'form_custom_php'           => $data['form_custom_php']          ?? '',
        'form_custom_css'           => $data['form_custom_css']          ?? '',
        'form_panel_mode'           => $data['form_panel_mode']          ?? 'fixed',
        'form_panel_width'          => isset($data['form_panel_width'])  ? (int)$data['form_panel_width'] : 0,
    ];

    try {
        if ($formId) {
            $row['updated_at'] = date('Y-m-d H:i:s');
            $db->update('nu_forms', $row, 'form_id = ?', [$formId]);
            $savedId = $formId;
            error_log('[forms.php] actionSave: updated form_id=' . $savedId);
        } else {
            $existing = $db->fetchOne('SELECT form_id FROM nu_forms WHERE form_code = ?', [$formCode]);
            if ($existing) { echo json_encode(['success' => false, 'error' => "Form code '{$formCode}' already exists"]); return; }
            $row['created_at'] = date('Y-m-d H:i:s');
            $row['updated_at'] = date('Y-m-d H:i:s');
            $db->insert('nu_forms', $row);
            $savedId = $db->lastInsertId();
            error_log('[forms.php] actionSave: inserted form_id=' . $savedId);
        }

        // ── DDL: create or sync the data table ───────────────────────────
        if ($formTable !== '' && $tableMode !== 'existing_no_sync') {
            if ($formLayout !== '' && $formLayout !== '[]') {
                try {
                    nu_sync_table_from_layout($db, $formTable, $formLayout, $pkType);
                } catch (Throwable $e) {
                    error_log('[forms.php] DDL sync FAILED: ' . $e->getMessage());
                    // form row already saved — DDL failure is logged, not fatal
                }
            } else {
                error_log('[forms.php] actionSave: skipping DDL — form_layout empty for table=' . $formTable);
            }
        } elseif ($formTable === '') {
            error_log('[forms.php] actionSave: no form_table — skipping DDL');
        } else {
            error_log('[forms.php] actionSave: table_mode=existing_no_sync — skipping DDL for ' . $formTable);
        }

        echo json_encode(['success' => true, 'form_id' => $savedId]);
    } catch (Exception $e) {
        error_log('[forms.php] actionSave EXCEPTION: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

// ── DELETE a form ─────────────────────────────────────────────────────────
function actionDelete($db) {
    $id = $_GET['id'] ?? '';
    if (!$id) { echo json_encode(['success' => false, 'error' => 'Missing id']); return; }

    try {
        $form = $db->fetchOne('SELECT form_table FROM nu_forms WHERE form_id = ?', [$id]);
        if (!$form) { echo json_encode(['success' => false, 'error' => 'Form not found']); return; }

        $formTable = preg_replace('/[^a-zA-Z0-9_]/', '', trim($form['form_table'] ?? ''));

        $db->query('DELETE FROM nu_forms WHERE form_id = ?', [$id]);

        $dropWarning = null;
        if ($formTable !== '') {
            [$dropped, $dropErr] = nu_drop_table($db, $formTable);
            if (!$dropped) {
                $dropWarning = $dropErr;
                error_log('[forms.php] DROP TABLE ' . $formTable . ': ' . $dropErr);
            }
        }

        $resp = ['success' => true];
        if ($dropWarning !== null) $resp['drop_warning'] = $dropWarning;
        echo json_encode($resp);

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}
