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

$action = $_GET['action'] ?? '';
$formCode = $_GET['code'] ?? '';
$db = NuDatabase::getInstance();

switch ($action) {
    case 'render':
        handleRender($db, $formCode, $_GET['id'] ?? null);
        break;
    case 'fields':
        handleFields($db, $formCode);
        break;
    case 'events':
        handleEvents($db, $formCode, $_GET['event'] ?? '');
        break;
    case 'save':
        handleSave($db, $formCode);
        break;
    case 'list':
        handleBrowse($db, $formCode);
        break;
    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
}

function handleRender($db, $formCode, $recordId = null) {
    $form = $db->fetchOne("SELECT * FROM nu_forms WHERE form_code = ?", [$formCode]);
    if (!$form) {
        echo json_encode(['success' => false, 'error' => 'Form not found']);
        exit;
    }
    
    $fields = $db->fetchAll("SELECT * FROM nu_form_fields WHERE form_id = ? AND field_active = 1 ORDER BY field_order", [$form['form_id']]);
    
    if (empty($fields) && !empty($form['form_layout'])) {
        $fields = json_decode($form['form_layout'], true) ?: [];
    }
    
    $events = $db->fetchAll("SELECT * FROM nu_form_events WHERE form_id = ? AND event_active = 1 ORDER BY event_order", [$form['form_id']]);
    $eventMap = [];
    foreach ($events as $e) {
        $eventMap[$e['event_type']] = $e['event_code'];
    }
    
    $record = [];
    $isNew = true;
    
    if ($recordId) {
        $record = $db->fetchOne("SELECT * FROM {$form['form_table']} WHERE id = ?", [$recordId]);
        $isNew = false;
    }
    
    $html = renderFormHTML($form, $fields, $eventMap, $record, $isNew, $db);
    echo json_encode(['success' => true, 'html' => $html, 'events' => $eventMap]);
}

function renderFormHTML($form, $fields, $events, $record, $isNew, $db) {
    ob_start();
    ?>
    <form class="nu-form" data-form-code="<?php echo htmlspecialchars($form['form_code']); ?>" 
          data-table="<?php echo htmlspecialchars($form['form_table']); ?>"
          data-record-id="<?php echo $record['id'] ?? ''; ?>"
          data-is-new="<?php echo $isNew ? '1' : '0'; ?>"
          onsubmit="return false;">
        
        <?php if (!empty($form['form_custom_css'])): ?>
        <style><?php echo $form['form_custom_css']; ?></style>
        <?php endif; ?>
        
        <div class="nu-form-fields" style="display:flex;flex-wrap:wrap;gap:12px;">
            <?php foreach ($fields as $field): ?>
                <?php renderField($field, $record, $isNew, $db); ?>
            <?php endforeach; ?>
        </div>
        
        <div class="nu-form-actions" style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px;">
            <button type="button" class="nu-btn nu-btn-ghost" onclick="var o=this.closest('.nu-modal-overlay'); if(o) o.remove(); else history.back();">Cancel</button>
            <button type="button" class="nu-btn nu-btn-primary" onclick="submitNuForm(this.closest('form'))"><?php echo $isNew ? 'Save' : 'Update'; ?></button>
        </div>
        
        <script>
        (function() {
            var nu = window.nuForm;
            nu.init('<?php echo $form['form_code']; ?>', <?php echo json_encode($record); ?>, <?php echo $isNew ? 'true' : 'false'; ?>);
            <?php if (!empty($events['js_onload'])): ?>
            try {
                <?php echo $events['js_onload']; ?>
            } catch(e) { console.error('onLoad error:', e); }
            <?php endif; ?>
        })();
        </script>
        
        <?php if (!empty($form['form_custom_js'])): ?>
        <script>
        try {
            <?php echo $form['form_custom_js']; ?>
        } catch(e) { console.error('Custom JS error:', e); }
        </script>
        <?php endif; ?>
    </form>
    <?php
    return ob_get_clean();
}

function renderField($field, $record, $isNew, $db) {
    $type = $field['field_type'] ?? $field['type'] ?? 'text';
    $name = htmlspecialchars($field['field_name'] ?? $field['name'] ?? '');
    $label = htmlspecialchars($field['field_label'] ?? $field['label'] ?? $name);
    $required = ($field['field_required'] ?? $field['required'] ?? false) ? 'required' : '';
    $width = htmlspecialchars($field['field_width'] ?? $field['width'] ?? '100%');
    $placeholder = htmlspecialchars($field['field_placeholder'] ?? $field['placeholder'] ?? '');
    $help = !empty($field['field_help_text']) ? '<small style="color:var(--text-secondary);display:block;margin-top:4px;font-size:12px;">' . htmlspecialchars($field['field_help_text']) . '</small>' : '';
    $calculated = !empty($field['field_calculated']) ? 'data-calculated="true" data-expression="' . htmlspecialchars($field['field_calculated']) . '"' : '';
    $css = !empty($field['field_css']) ? ' style="' . htmlspecialchars($field['field_css']) . '"' : '';
    $defaultValue = $isNew ? ($field['field_default_value'] ?? $field['default_value'] ?? '') : ($record[$name] ?? '');
    
    $events = '';
    if (!empty($field['field_js_onchange'])) $events .= ' onchange="' . htmlspecialchars($field['field_js_onchange']) . '"';
    
    echo '<div class="nu-field-wrapper" data-field="' . $name . '" style="margin-bottom:12px;width:' . $width . ';">';
    echo '<label style="display:block;font-size:13px;font-weight:500;margin-bottom:4px;">' . $label . ($required ? ' <span style="color:red;">*</span>' : '') . '</label>';
    
    switch ($type) {
        case 'text':
        case 'email':
        case 'number':
        case 'url':
        case 'password':
            echo '<input type="' . $type . '" class="nu-input" data-field="' . $name . '" name="' . $name . '" value="' . htmlspecialchars($defaultValue) . '" placeholder="' . $placeholder . '" ' . $required . ' ' . $calculated . $events . $css . '>';
            break;
            
        case 'date':
            echo '<input type="date" class="nu-input" data-field="' . $name . '" name="' . $name . '" value="' . htmlspecialchars($defaultValue) . '" ' . $required . $events . $css . '>';
            break;
            
        case 'color':
            echo '<input type="color" class="nu-input" data-field="' . $name . '" name="' . $name . '" value="' . htmlspecialchars($defaultValue ?: '#000000') . '" ' . $events . $css . '>';
            break;
            
        case 'range':
            echo '<input type="range" class="nu-input" data-field="' . $name . '" name="' . $name . '" value="' . htmlspecialchars($defaultValue ?: '50') . '" ' . $events . $css . '>';
            break;
            
        case 'textarea':
            echo '<textarea class="nu-input" data-field="' . $name . '" name="' . $name . '" placeholder="' . $placeholder . '" rows="4" ' . $required . $events . $css . '>' . htmlspecialchars($defaultValue) . '</textarea>';
            break;
            
        case 'select':
            $options = json_decode($field['field_options'] ?? $field['options'] ?? '[]', true);
            echo '<select class="nu-input" data-field="' . $name . '" name="' . $name . '" ' . $required . $events . $css . '>';
            echo '<option value="">-- Select --</option>';
            foreach ($options as $opt) {
                $selected = ($defaultValue == $opt['value']) ? 'selected' : '';
                echo '<option value="' . htmlspecialchars($opt['value']) . '" ' . $selected . '>' . htmlspecialchars($opt['label']) . '</option>';
            }
            echo '</select>';
            break;
            
        case 'radio':
            $options = json_decode($field['field_options'] ?? $field['options'] ?? '[]', true);
            echo '<div style="display:flex;gap:12px;flex-wrap:wrap;">';
            foreach ($options as $opt) {
                $checked = ($defaultValue == $opt['value']) ? 'checked' : '';
                echo '<label style="display:flex;align-items:center;gap:6px;cursor:pointer;">';
                echo '<input type="radio" name="' . $name . '" value="' . htmlspecialchars($opt['value']) . '" ' . $checked . $events . '>';
                echo htmlspecialchars($opt['label']);
                echo '</label>';
            }
            echo '</div>';
            break;
            
        case 'checkbox':
            $checked = $defaultValue ? 'checked' : '';
            echo '<label style="display:flex;align-items:center;gap:8px;cursor:pointer;">';
            echo '<input type="checkbox" data-field="' . $name . '" name="' . $name . '" value="1" ' . $checked . $events . $css . '>';
            echo '<span>' . $label . '</span>';
            echo '</label>';
            break;
            
        case 'lookup':
            $displayValue = '';
            $lookupTable = $field['field_lookup_table'] ?? '';
            $lookupId = $field['field_lookup_id'] ?? 'id';
            $lookupDisplay = $field['field_lookup_display'] ?? 'name';
            
            if ($defaultValue && $lookupTable) {
                try {
                    $lookup = $db->fetchOne("SELECT " . $lookupDisplay . " FROM " . $lookupTable . " WHERE " . $lookupId . " = ?", [$defaultValue]);
                    $displayValue = $lookup[$lookupDisplay] ?? '';
                } catch (Exception $e) {}
            }
            echo '<div style="display:flex;gap:8px;">';
            echo '<input type="hidden" data-field="' . $name . '" name="' . $name . '" value="' . htmlspecialchars($defaultValue) . '">';
            echo '<input type="text" class="nu-input" readonly placeholder="Click to select..." value="' . htmlspecialchars($displayValue) . '" onclick="openLookupModal(\'' . $name . '\', \'' . $lookupTable . '\', \'' . $lookupId . '\', \'' . $lookupDisplay . '\', \'' . htmlspecialchars($field['field_lookup_filter'] ?? '') . '\')" style="flex:1;cursor:pointer;">';
            echo '<button type="button" class="nu-btn nu-btn-ghost nu-btn-sm" onclick="clearLookup(\'' . $name . '\')">Clear</button>';
            echo '</div>';
            break;
            
        case 'subform':
            $subformId = $field['field_subform_id'] ?? 0;
            $subformFk = $field['field_subform_fk'] ?? '';
            $subformView = $field['field_subform_view'] ?? 'grid';
            echo '<div class="nu-subform" data-subform-id="' . $subformId . '" data-subform-fk="' . $subformFk . '" data-subform-view="' . $subformView . '" data-parent-id="' . ($record['id'] ?? '0') . '">';
            echo '<div style="padding:20px;text-align:center;color:var(--text-secondary);">Subform: loading...</div>';
            echo '</div>';
            break;
            
        case 'calculated':
            echo '<input type="text" class="nu-input nu-calculated" data-field="' . $name . '" readonly value="' . htmlspecialchars($defaultValue) . '" ' . $calculated . $css . '>';
            break;
            
        case 'html':
            echo '<div data-field="' . $name . '" ' . $css . '>' . ($field['field_default_value'] ?? '') . '</div>';
            break;
            
        case 'file':
            echo '<input type="file" class="nu-input" data-field="' . $name . '" name="' . $name . '" ' . $events . $css . '>';
            if ($defaultValue) echo '<p style="font-size:12px;margin-top:4px;">Current: ' . htmlspecialchars($defaultValue) . '</p>';
            break;
    }
    
    echo $help;
    echo '</div>';
}

function handleFields($db, $formCode) {
    $form = $db->fetchOne("SELECT form_id FROM nu_forms WHERE form_code = ?", [$formCode]);
    if (!$form) { echo json_encode(['success' => false]); exit; }
    $fields = $db->fetchAll("SELECT * FROM nu_form_fields WHERE form_id = ? AND field_active = 1 ORDER BY field_order", [$form['form_id']]);
    echo json_encode(['success' => true, 'data' => $fields]);
}

function handleEvents($db, $formCode, $eventType) {
    $form = $db->fetchOne("SELECT form_id FROM nu_forms WHERE form_code = ?", [$formCode]);
    if (!$form) { echo json_encode(['success' => false]); exit; }
    $code = $db->fetchOne("SELECT event_code FROM nu_form_events WHERE form_id = ? AND event_type = ? AND event_active = 1", [$form['form_id'], $eventType]);
    echo json_encode(['success' => !!$code, 'code' => $code['event_code'] ?? '']);
}

function handleSave($db, $formCode) {
    $form = $db->fetchOne("SELECT * FROM nu_forms WHERE form_code = ?", [$formCode]);
    if (!$form) { echo json_encode(['success' => false, 'error' => 'Form not found']); exit; }

    $input = json_decode(file_get_contents('php://input'), true);
    $recordId = $_GET['id'] ?? null;
    $isNew = !$recordId;

    $events = $db->fetchAll("SELECT * FROM nu_form_events WHERE form_id = ? AND event_active = 1", [$form['form_id']]);
    $eventMap = [];
    foreach ($events as $e) { $eventMap[$e['event_type']] = $e['event_code']; }

    if (!empty($eventMap['php_beforesave'])) {
        $data = $input;
        $hashCookies = $input['hashCookies'] ?? [];
        eval($eventMap['php_beforesave']);
        $input = $data;
    }

    // ✅ Get current logged-in user ID from session/auth
    $currentUserId = $_SESSION['user_id'] ?? null;

    try {
        $now = date('Y-m-d H:i:s');

        $tableColumns = [];
        foreach ($db->fetchAll("DESCRIBE `{$form['form_table']}`") as $row) {
            $tableColumns[$row['Field']] = true;
        }

        $safeInput = [];
        foreach ($input as $k => $v) {
            if (isset($tableColumns[$k])) {
                $safeInput[$k] = $v;
            }
        }

        if ($isNew) {
            $safeInput['created_at'] = $now;
            $safeInput['updated_at'] = $now;
            // ✅ Set created_by and updated_by on insert
            if ($currentUserId !== null) {
                if (isset($tableColumns['created_by'])) $safeInput['created_by'] = $currentUserId;
                if (isset($tableColumns['updated_by'])) $safeInput['updated_by'] = $currentUserId;
            }
            unset($safeInput['id']);
            $db->insert($form['form_table'], $safeInput);
            $recordId = $db->lastInsertId();
        } else {
            unset($safeInput['created_at']);
            unset($safeInput['created_by']); // ✅ Never overwrite original creator
            unset($safeInput['id']);
            $safeInput['updated_at'] = $now;
            // ✅ Set updated_by on update
            if ($currentUserId !== null && isset($tableColumns['updated_by'])) {
                $safeInput['updated_by'] = $currentUserId;
            }
            $db->update($form['form_table'], $safeInput, 'id = ?', [$recordId]);
        }

        // ✅ Missing: after-save event and response
        if (!empty($eventMap['php_aftersave'])) {
            $data = $safeInput;
            eval($eventMap['php_aftersave']);
        }

        echo json_encode(['success' => true, 'id' => $recordId]);

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
} // ✅ Missing closing brace restored

function handleBrowse($db, $formCode) {
    $form = $db->fetchOne("SELECT * FROM nu_forms WHERE form_code = ?", [$formCode]);
    if (!$form) { echo json_encode(['success' => false, 'error' => 'Form not found']); exit; }
    
    $page = intval($_GET['page'] ?? 1);
    $limit = 20;
    $offset = ($page - 1) * $limit;
    
    $fields = $db->fetchAll("SELECT field_name, field_label FROM nu_form_fields WHERE form_id = ? AND field_active = 1 ORDER BY field_order LIMIT 5", [$form['form_id']]);
    
    if (empty($fields)) {
        $layout = json_decode($form['form_layout'] ?? '[]', true);
        $fields = [];
        foreach ($layout as $f) {
            $fields[] = ['field_name' => $f['name'], 'field_label' => $f['label'] ?? $f['name']];
        }
    }
    
    $total = 0;
    $records = [];
    $pages = 0;
    
    try {
        $totalRow = $db->fetchOne("SELECT COUNT(*) as c FROM {$form['form_table']}");
        $total = $totalRow['c'] ?? 0;
        $records = $db->fetchAll("SELECT * FROM {$form['form_table']} ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}");
        $pages = ceil($total / $limit);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Table error: ' . $e->getMessage()]);
        exit;
    }
    
    echo json_encode(['success' => true, 'data' => ['records' => $records, 'layout' => $fields, 'page' => $page, 'pages' => $pages, 'total' => $total]]);
}
