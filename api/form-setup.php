<?php
header('Content-Type: application/json');
ini_set('display_errors', 0);
error_reporting(E_ALL);

try {
    $root = dirname(__DIR__);
    require_once $root . '/config.php';
    require_once $root . '/core/Database.php';
    require_once $root . '/core/Auth.php';

    session_start();
    if (!isset($_SESSION['nu_user_id'])) {
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }

    $input = file_get_contents('php://input');
    $data  = json_decode($input, true);
    if (!is_array($data)) {
        throw new Exception('Invalid JSON input');
    }

    $formId      = $data['form_id']      ?? null;
    $formTable   = $data['form_table']   ?? '';
    $fields      = $data['fields']       ?? [];
    $pkType      = $data['pk_type']      ?? 'autoincrement';
    $tableMode   = $data['table_mode']   ?? 'new';
    $dropEnabled = (bool)($data['drop_enabled'] ?? false);

    if (!$formTable || !is_array($fields)) {
        echo json_encode(['success' => false, 'error' => 'Table name and fields required']);
        exit;
    }

    $formTable = sanitizeIdentifier($formTable);
    if (!$formTable) {
        echo json_encode(['success' => false, 'error' => 'Invalid table name after sanitization']);
        exit;
    }

    $db  = NuDatabase::getInstance();
    $pdo = $db->getPdo();

    // ── If using an existing table, skip all DDL ─────────────────────────
    if ($tableMode === 'existing') {
        echo json_encode(['success' => true, 'message' => 'Using existing table — no DDL changes made']);
        exit;
    }

    // ── Build desired column list (excluding PK) ──────────────────────────
    $desiredCols = [];
    foreach ($fields as $f) {
        $name = sanitizeIdentifier($f['name'] ?? '');
        if ($name && $name !== 'id') {
            $desiredCols[] = $name;
        }
    }

    $existsStmt = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($formTable));
    $exists     = $existsStmt && $existsStmt->rowCount() > 0;

    // ── CREATE TABLE ──────────────────────────────────────────────────────
    if (!$exists) {
        $pkCol = $pkType === 'uuid'
            ? "`id` VARCHAR(36) NOT NULL DEFAULT '' PRIMARY KEY"
            : "`id` INT AUTO_INCREMENT PRIMARY KEY";

        $cols = [$pkCol];
        foreach ($fields as $f) {
            $name = sanitizeIdentifier($f['name'] ?? '');
            if (!$name || $name === 'id') continue;
            $cols[] = "`{$name}` " . mapFieldType($f);
        }
        $cols[] = "`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP";
        $cols[] = "`updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP";
        $cols[] = "`created_by` INT DEFAULT NULL";
        $cols[] = "`updated_by` INT DEFAULT NULL";
        $cols[] = "`deleted_at` DATETIME DEFAULT NULL";

        $sql = "CREATE TABLE `{$formTable}` (" . implode(', ', $cols) . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $pdo->exec($sql);

        echo json_encode(['success' => true, 'message' => 'Table created', 'pk_type' => $pkType]);
        exit;
    }

    // ── SYNC existing table ───────────────────────────────────────────────
    $existingCols    = [];
    $existingColMeta = [];
    $stmt = $pdo->query("SHOW COLUMNS FROM `{$formTable}`");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $existingCols[]                 = $row['Field'];
        $existingColMeta[$row['Field']] = $row;
    }

    $protected = ['id', 'created_at', 'updated_at', 'created_by', 'updated_by', 'deleted_at'];

    // FIX: Build rename map by walking the row-based layout JSON correctly.
    // Old code did positional matching on the top-level layout array which
    // contains row objects ({row, cols:[...]}) not field objects directly —
    // causing the rename logic to always read empty field names and producing
    // ghost renames or no renames at all.
    $renameMap = [];
    if ($formId) {
        $stmt = $pdo->prepare("SELECT form_layout FROM nu_forms WHERE form_id = ?");
        $stmt->execute([$formId]);
        $oldLayoutJson = $stmt->fetchColumn();
        $oldLayout     = json_decode($oldLayoutJson ?: '[]', true);

        // Flatten both old and new layouts into ordered field lists
        $oldFields = flatLayoutFields($oldLayout);
        $newFields = flatLayoutFields($fields); // $fields may already be flat OR row-based

        $max = min(count($oldFields), count($newFields));
        for ($i = 0; $i < $max; $i++) {
            $oldName = sanitizeIdentifier($oldFields[$i]['name'] ?? '');
            $newName = sanitizeIdentifier($newFields[$i]['name'] ?? '');
            if (
                $oldName && $newName &&
                $oldName !== $newName &&
                !in_array($oldName, $protected, true) &&
                !in_array($newName, $existingCols, true) &&
                in_array($oldName, $existingCols, true)
            ) {
                $renameMap[$oldName] = $newName;
            }
        }
    }

    foreach ($renameMap as $oldName => $newName) {
        $pdo->exec("ALTER TABLE `{$formTable}` RENAME COLUMN `{$oldName}` TO `{$newName}`");
    }
    if (!empty($renameMap)) {
        $existingCols = array_map(fn($col) => $renameMap[$col] ?? $col, $existingCols);
    }

    // Add missing columns
    foreach ($fields as $f) {
        // Support both flat field objects and row-based objects passed in
        if (isset($f['cols'])) continue; // skip row wrapper objects if any slipped through
        $name = sanitizeIdentifier($f['name'] ?? '');
        if (!$name || $name === 'id' || in_array($name, $existingCols, true)) continue;
        $type = mapFieldType($f);
        $pdo->exec("ALTER TABLE `{$formTable}` ADD COLUMN `{$name}` {$type}");
    }

    // Drop columns no longer in layout — only when $dropEnabled is true
    $dropped = [];
    if ($dropEnabled) {
        foreach ($existingCols as $colName) {
            if (in_array($colName, $protected, true)) continue;
            if (!in_array($colName, $desiredCols, true)) {
                $pdo->exec("ALTER TABLE `{$formTable}` DROP COLUMN `{$colName}`");
                $dropped[] = $colName;
            }
        }
    }

    echo json_encode([
        'success'      => true,
        'message'      => 'Table synced',
        'renamed'      => $renameMap,
        'dropped'      => $dropped,
        'drop_enabled' => $dropEnabled,
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Error $e) {
    echo json_encode(['success' => false, 'error' => 'Fatal: ' . $e->getMessage()]);
}

/**
 * Flatten a layout array that may be row-based ({row, cols:[...]}) or already flat.
 */
function flatLayoutFields(array $layout): array {
    $fields = [];
    foreach ($layout as $item) {
        if (isset($item['cols']) && is_array($item['cols'])) {
            // Row-based format
            foreach ($item['cols'] as $col) {
                if (!empty($col['name'])) $fields[] = $col;
            }
        } elseif (!empty($item['name'])) {
            // Already flat
            $fields[] = $item;
        }
    }
    return $fields;
}

function sanitizeIdentifier($name) {
    $name = trim((string)$name);
    if ($name === '') return '';
    return preg_replace('/[^a-zA-Z0-9_]/', '', $name);
}

function mapFieldType($field) {
    $type = $field['type'] ?? 'text';
    switch ($type) {
        case 'number':                          return 'DECIMAL(15,4)';
        case 'date':                            return 'DATE';
        case 'datetime':
        case 'datetime-local':                  return 'DATETIME';
        case 'time':                            return 'TIME';
        case 'textarea':
        case 'html':
        case 'subform':                         return 'TEXT';
        case 'checkbox':                        return 'TINYINT(1) DEFAULT 0';
        case 'checkbox_group':                  return 'TEXT';  // stored as JSON array string
        case 'file':
        case 'image':                           return 'VARCHAR(500)';
        case 'lookup':                          return 'INT DEFAULT NULL';
        case 'calculated':                      return 'VARCHAR(255)';
        case 'range':                           return 'INT DEFAULT 0';
        case 'color':                           return 'VARCHAR(7)';
        case 'select':
            return !empty($field['multiple']) ? 'TEXT' : 'VARCHAR(255)';
        default:                                return 'VARCHAR(255)';
    }
}
