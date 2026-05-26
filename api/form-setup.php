<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json');

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
    $data = json_decode($input, true);
    if (!$data) {
        throw new Exception('Invalid JSON input');
    }

    $formId = $data['form_id'] ?? null;
    $formTable = $data['form_table'] ?? '';
    $fields = $data['fields'] ?? [];

    if (!$formTable || !is_array($fields)) {
        echo json_encode(['success' => false, 'error' => 'Table name and fields required']);
        exit;
    }

    $formTable = sanitizeIdentifier($formTable);

    $db = NuDatabase::getInstance();
    $pdo = $db->getPdo();

    $desiredCols = [];
    foreach ($fields as $f) {
        $name = sanitizeIdentifier($f['name'] ?? '');
        if ($name && $name !== 'id') {
            $desiredCols[] = $name;
        }
    }

    $existsStmt = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($formTable));
    $exists = $existsStmt && $existsStmt->rowCount() > 0;

    if (!$exists) {
        $cols = ["`id` INT AUTO_INCREMENT PRIMARY KEY"];

        foreach ($fields as $f) {
            $name = sanitizeIdentifier($f['name'] ?? '');
            if (!$name || $name === 'id') {
                continue;
            }

            $type = mapFieldType($f);
            $cols[] = "`{$name}` {$type}";
        }

        $cols[] = "`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP";
        $cols[] = "`updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP";
        $cols[] = "`created_by` INT DEFAULT NULL";
        $cols[] = "`updated_by` INT DEFAULT NULL";
        $cols[] = "`deleted_at` DATETIME DEFAULT NULL";

        $sql = "CREATE TABLE `{$formTable}` (" . implode(', ', $cols) . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        $pdo->exec($sql);

        echo json_encode(['success' => true, 'message' => 'Table created']);
        exit;
    }

    $existingCols = [];
    $existingColMeta = [];
    $stmt = $pdo->query("SHOW COLUMNS FROM `{$formTable}`");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $existingCols[] = $row['Field'];
        $existingColMeta[$row['Field']] = $row;
    }

    $protected = ['id', 'created_at', 'updated_at', 'created_by', 'updated_by', 'deleted_at'];

    $oldFields = [];
    if ($formId) {
        $stmt = $pdo->prepare("SELECT form_layout FROM nu_forms WHERE form_id = ?");
        $stmt->execute([$formId]);
        $oldLayoutJson = $stmt->fetchColumn();
        $oldLayout = json_decode($oldLayoutJson ?: '[]', true);

        if (is_array($oldLayout)) {
            $oldFields = $oldLayout;
        }
    }

    $renameMap = [];

    if (!empty($oldFields)) {
        $max = min(count($oldFields), count($fields));

        for ($i = 0; $i < $max; $i++) {
            $oldName = sanitizeIdentifier($oldFields[$i]['name'] ?? '');
            $newName = sanitizeIdentifier($fields[$i]['name'] ?? '');

            if (
                $oldName &&
                $newName &&
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
        $existingCols = array_map(function ($col) use ($renameMap) {
            return $renameMap[$col] ?? $col;
        }, $existingCols);
    }

    foreach ($fields as $f) {
        $name = sanitizeIdentifier($f['name'] ?? '');
        if (!$name || $name === 'id' || in_array($name, $existingCols, true)) {
            continue;
        }

        $type = mapFieldType($f);
        $pdo->exec("ALTER TABLE `{$formTable}` ADD COLUMN `{$name}` {$type}");
    }

    foreach ($existingCols as $colName) {
        if (in_array($colName, $protected, true)) {
            continue;
        }

        if (!in_array($colName, $desiredCols, true)) {
            $pdo->exec("ALTER TABLE `{$formTable}` DROP COLUMN `{$colName}`");
        }
    }

    echo json_encode([
        'success' => true,
        'message' => 'Table synced',
        'renamed' => $renameMap
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Error $e) {
    echo json_encode(['success' => false, 'error' => 'Fatal: ' . $e->getMessage()]);
}

function sanitizeIdentifier($name) {
    $name = trim((string)$name);
    if ($name === '') {
        return '';
    }

    return preg_replace('/[^a-zA-Z0-9_]/', '', $name);
}

function mapFieldType($field) {
    $type = $field['type'] ?? 'text';

    switch ($type) {
        case 'number':
            return 'DECIMAL(15,2)';
        case 'date':
            return 'DATE';
        case 'datetime':
            return 'DATETIME';
        case 'textarea':
        case 'html':
        case 'subform':
            return 'TEXT';
        case 'checkbox':
            return 'TINYINT(1) DEFAULT 0';
        case 'file':
        case 'image':
            return 'VARCHAR(500)';
        case 'lookup':
            return 'INT';
        case 'calculated':
            return 'VARCHAR(255)';
        case 'select':
            return !empty($field['multiple']) ? 'TEXT' : 'VARCHAR(255)';
        default:
            return 'VARCHAR(255)';
    }
}
?>