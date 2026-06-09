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

/**
 * Field types that should NOT produce a DB column.
 */
function nu_is_structural_type(string $type): bool {
    return in_array($type, ['html','content','button','subform','fieldset','divider','heading','section','group','row'], true);
}

/**
 * Map a form field type to a MySQL column definition.
 */
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

/**
 * Flatten a layout array (which may contain sections/groups/rows) into
 * a plain list of leaf field nodes.
 */
function nu_flatten_fields(array $layout): array {
    $out = [];
    foreach ($layout as $node) {
        $t = $node['type'] ?? 'field';
        if (in_array($t, ['section','group','row'], true)) {
            foreach (nu_flatten_fields($node['children'] ?? []) as $f) $out[] = $f;
        } else {
            $out[] = $node;
        }
    }
    return $out;
}

/**
 * Resolve the DB column name for a field.
 * - lookup fields: use store_field / storefield / store_col
 * - others: use the field name directly
 * Returns '' if no column should be written (e.g. unnamed lookup).
 */
function nu_resolve_col_name(array $field): string {
    $name = trim($field['name'] ?? $field['fieldname'] ?? '');
    $type = $field['type'] ?? $field['fieldtype'] ?? 'text';
    if ($type === 'lookup') {
        $lk  = $field['lookup'] ?? [];
        $col = trim(
            $lk['store_field'] ?? $lk['storefield'] ?? $lk['store_col'] ?? $lk['storeCol'] ?? ''
        );
        if ($col !== '') return preg_replace('/[^a-zA-Z0-9_]/', '', $col);
        // fall back to field name if it looks like a real column
        if ($name !== '' && !preg_match('/^lookup_\d+$/', $name)) return preg_replace('/[^a-zA-Z0-9_]/', '', $name);
        return '';
    }
    return preg_replace('/[^a-zA-Z0-9_]/', '', $name);
}

/**
 * Given a PDO connection, the target table name, a form layout JSON string,
 * and the pk_type, ensure the table and all saveable columns exist.
 *
 * - Creates the table if it doesn't exist.
 * - Adds any missing columns (never drops or modifies existing ones).
 */
function nu_sync_table_from_layout(PDO $pdo, string $table, string $layoutJson, string $pkType = 'autoincrement'): void {
    if ($table === '') return;
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    if ($table === '') return;

    $layout = json_decode($layoutJson, true);
    if (!is_array($layout)) return;

    $fields = nu_flatten_fields($layout);

    // Build desired columns: colName => colDef
    $desired = [];
    foreach ($fields as $field) {
        $type = $field['type'] ?? $field['fieldtype'] ?? 'text';
        if (nu_is_structural_type($type)) continue;
        $col = nu_resolve_col_name($field);
        if ($col === '') continue;
        $desired[$col] = nu_col_def_for_type($type);
    }

    // Check if table exists
    $stmt = $pdo->query("SHOW TABLES LIKE '" . addslashes($table) . "'");
    $tableExists = (bool)$stmt->fetchColumn();

    if (!$tableExists) {
        // Create table from scratch
        $pkDef = ($pkType === 'uuid')
            ? "`id` VARCHAR(36) NOT NULL PRIMARY KEY"
            : "`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY";
        $colsSql = '';
        foreach ($desired as $col => $def) {
            $colsSql .= ",\n  `{$col}` {$def}";
        }
        $colsSql .= ",\n  `created_at` DATETIME NULL DEFAULT NULL";
        $colsSql .= ",\n  `updated_at` DATETIME NULL DEFAULT NULL";
        $pdo->exec("CREATE TABLE `{$table}` (\n  {$pkDef}{$colsSql}\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        return;
    }

    // Table exists — find current columns
    $existing = [];
    foreach ($pdo->query("DESCRIBE `{$table}`")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $existing[$row['Field']] = true;
    }

    // ALTER TABLE for each missing column
    foreach ($desired as $col => $def) {
        if (isset($existing[$col])) continue;
        try {
            $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$col}` {$def}");
        } catch (Throwable $e) {
            error_log("[forms.php] ALTER TABLE {$table} ADD COLUMN {$col}: " . $e->getMessage());
        }
    }
}

/**
 * Get a raw PDO connection from the NuDatabase instance.
 */
function nu_pdo($db): PDO {
    // NuDatabase usually exposes getConnection() or getPdo()
    foreach (['getConnection','getPdo','getDb'] as $m) {
        if (method_exists($db, $m)) {
            $c = $db->$m();
            if ($c instanceof PDO) return $c;
        }
    }
    // Last resort: reflect on the object for a PDO property
    foreach ((array)$db as $v) {
        if ($v instanceof PDO) return $v;
    }
    throw new RuntimeException('Cannot obtain PDO from NuDatabase instance');
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

// ── GET a single form by form_code ────────────────────────────────────────
function actionGetByCode($db) {
    $code = $_GET['code'] ?? '';
    if (!$code) {
        echo json_encode(['success' => false, 'error' => 'Missing code']);
        return;
    }
    try {
        $form = $db->fetchOne(
            'SELECT * FROM nu_forms WHERE form_code = ? LIMIT 1',
            [$code]
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

// ── PATCH only the form_layout column of a form ───────────────────────────
function actionPatchLayout($db) {
    $id = $_GET['id'] ?? '';
    if (!$id) {
        echo json_encode(['success' => false, 'error' => 'Missing id']);
        return;
    }
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['form_layout'])) {
        echo json_encode(['success' => false, 'error' => 'Missing form_layout in payload']);
        return;
    }
    $layout = $data['form_layout'];
    if (is_array($layout)) {
        $layout = json_encode($layout);
    }
    try {
        $db->update(
            'nu_forms',
            ['form_layout' => $layout, 'updated_at' => date('Y-m-d H:i:s')],
            'form_id = ?',
            [$id]
        );

        // Sync the target table schema after layout change
        $form = $db->fetchOne('SELECT form_table, form_pk_type FROM nu_forms WHERE form_id = ?', [$id]);
        if ($form && !empty($form['form_table'])) {
            try {
                nu_sync_table_from_layout(nu_pdo($db), $form['form_table'], $layout, $form['form_pk_type'] ?? 'autoincrement');
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

    $formLayout = $data['form_layout'] ?? '';
    if (is_array($formLayout)) {
        $formLayout = json_encode($formLayout);
    }

    $formTable = $data['form_table'] ?? '';
    $pkType    = $data['form_pk_type'] ?? 'autoincrement';

    $row = [
        'form_name'                => $formName,
        'form_code'                => $formCode,
        'form_table'               => $formTable,
        'form_type'                => $data['form_type']                ?? 'main',
        'form_table_mode'          => $data['form_table_mode']          ?? 'new',
        'form_pk_type'             => $pkType,
        'form_layout'              => $formLayout,
        'form_active'              => 1,
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
            $row['updated_at'] = date('Y-m-d H:i:s');
            $db->update('nu_forms', $row, 'form_id = ?', [$formId]);
            $savedId = $formId;
        } else {
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
            $savedId = $db->lastInsertId();
        }

        // Sync the target table schema
        if ($formTable !== '' && $formLayout !== '') {
            try {
                nu_sync_table_from_layout(nu_pdo($db), $formTable, $formLayout, $pkType);
            } catch (Throwable $e) {
                error_log('[forms.php] sync after save: ' . $e->getMessage());
            }
        }

        echo json_encode(['success' => true, 'form_id' => $savedId]);
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
        // Grab form_table before deleting the metadata row
        $form = $db->fetchOne('SELECT form_table FROM nu_forms WHERE form_id = ?', [$id]);
        $formTable = preg_replace('/[^a-zA-Z0-9_]/', '', $form['form_table'] ?? '');

        // Delete from nu_forms
        $db->query('DELETE FROM nu_forms WHERE form_id = ?', [$id]);

        // Drop the data table — in its own try/catch so DDL failures are non-fatal
        if ($formTable !== '') {
            try {
                nu_pdo($db)->exec("DROP TABLE IF EXISTS `{$formTable}`");
            } catch (Throwable $e) {
                error_log('[forms.php] DROP TABLE ' . $formTable . ': ' . $e->getMessage());
            }
        }

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}
