<?php
ob_start();
ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        while (ob_get_level()) ob_end_clean();
        echo json_encode(['success' => false, 'error' => $error['message'] . ' in ' . basename($error['file']) . ':' . $error['line']]);
    }
});

function nu_json($arr, $status = 200) {
    http_response_code($status);
    while (ob_get_level()) {
        $buffer = ob_get_clean();
        if (trim($buffer) !== '') $arr['_output'] = $buffer;
    }
    echo json_encode($arr);
    exit;
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../core/Database.php';
if (file_exists(__DIR__ . '/../core/Auth.php')) require_once __DIR__ . '/../core/Auth.php';

function nu_db() {
    global $pdo;
    if (isset($pdo) && $pdo instanceof PDO) return $pdo;
    if (isset($GLOBALS['db']) && $GLOBALS['db'] instanceof PDO) return $GLOBALS['db'];
    if (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof PDO) return $GLOBALS['conn'];
    if (class_exists('NuDatabase')) { $c = NuDatabase::getConnection(); if ($c instanceof PDO) return $c; }
    if (class_exists('Database')) {
        foreach (['getConnection','getInstance','connect'] as $m) {
            if (!method_exists('Database', $m)) continue;
            $c = Database::$m();
            if ($c instanceof PDO) return $c;
            if (is_object($c) && method_exists($c,'getPdo') && ($p=$c->getPdo()) instanceof PDO) return $p;
            if (is_object($c) && method_exists($c,'getConnection') && ($p=$c->getConnection()) instanceof PDO) return $p;
        }
    }
    throw new Exception('Database connection not available');
}

function nu_q($sql, $params = []) {
    $stmt = nu_db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function nu_request_json() {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function nu_safe_ident($value) {
    return preg_replace('/[^a-zA-Z0-9_]/', '', (string)$value);
}

function nu_table_exists($table) {
    try { $stmt = nu_q("SHOW TABLES LIKE ?", [$table]); return (bool)$stmt->fetchColumn(); }
    catch (Throwable $e) { return false; }
}

function nu_form_table_name() {
    if (nu_table_exists('nu_forms')) return 'nu_forms';
    if (nu_table_exists('nuforms')) return 'nuforms';
    return 'nu_forms';
}

function nu_form_columns() {
    $table = nu_form_table_name();
    if ($table === 'nuforms') {
        return ['id'=>'id','name'=>'formname','code'=>'formcode','table'=>'formtable',
            'layout'=>'formlayout','active'=>'formactive','custom_js'=>'formcustomjs',
            'custom_php'=>'formcustomphp','custom_css'=>'formcustomcss','browse_sql'=>'browsesql',
            'browse_columns'=>'browsecolumns','browse_search_enabled'=>'browsesearchenabled',
            'browse_search_placeholder'=>'browsesearchplaceholder','browse_search_fields'=>'browsesearchfields',
            'browse_page_size'=>'browsepagesize','browse_default_sort'=>'browsedefaultsort'];
    }
    return ['id'=>'id','name'=>'form_name','code'=>'form_code','table'=>'form_table',
        'layout'=>'form_layout','active'=>'form_active','custom_js'=>'form_custom_js',
        'custom_php'=>'form_custom_php','custom_css'=>'form_custom_css','browse_sql'=>'browse_sql',
        'browse_columns'=>'browse_columns','browse_search_enabled'=>'browse_search_enabled',
        'browse_search_placeholder'=>'browse_search_placeholder','browse_search_fields'=>'browse_search_fields',
        'browse_page_size'=>'browse_page_size','browse_default_sort'=>'browse_default_sort'];
}

function nu_get_form($code) {
    $table = nu_form_table_name();
    $c = nu_form_columns();
    $stmt = nu_q("SELECT * FROM `{$table}` WHERE `{$c['code']}` = ? AND `{$c['active']}` = 1 LIMIT 1", [$code]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function nu_decode_layout($form) {
    $c = nu_form_columns();
    $layout = [];
    if (!empty($form[$c['layout']])) $layout = json_decode($form[$c['layout']], true);
    return is_array($layout) ? $layout : [];
}

function nu_get_pk($table) {
    try {
        $stmt = nu_q("SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return ($row && !empty($row['Column_name'])) ? $row['Column_name'] : 'id';
    } catch (Throwable $e) { return 'id'; }
}

function nu_get_record($table, $id) {
    $table = nu_safe_ident($table);
    if (!$table || !$id) return [];
    $pk = nu_get_pk($table);
    $stmt = nu_q("SELECT * FROM `{$table}` WHERE `{$pk}` = ? LIMIT 1", [$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

function nu_fetch_sql_options($sql) {
    $stmt = nu_q($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $row) {
        $vals = array_values($row);
        $out[] = ['value' => $vals[0] ?? '', 'label' => $vals[1] ?? ($vals[0] ?? '')];
    }
    return $out;
}

function nu_field_name($field)  { return $field['name']  ?? ($field['fieldname']  ?? ''); }
function nu_field_label($field) { return $field['label'] ?? ($field['fieldlabel'] ?? nu_field_name($field)); }
function nu_field_type($field)  { return $field['type']  ?? ($field['fieldtype']  ?? 'text'); }

function nu_field_value($record, $field) {
    $name = nu_field_name($field);
    if ($name === '') return '';
    if (array_key_exists($name, $record)) return $record[$name];
    if (isset($field['default_value'])) return $field['default_value'];
    if (isset($field['defaultvalue'])) return $field['defaultvalue'];
    return '';
}

function nu_attr($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function nu_html($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }

function nu_render_options($field, $selectedValue = null) {
    $options = [];
    $sourceType = $field['source_type'] ?? ($field['sourcetype'] ?? 'static');
    $sqlSource  = $field['sql_source']  ?? ($field['sqlsource']  ?? '');
    if ($sourceType === 'sql' && $sqlSource !== '') {
        try { $options = nu_fetch_sql_options($sqlSource); } catch (Throwable $e) { $options = []; }
    } elseif (!empty($field['options']) && is_array($field['options'])) {
        $options = $field['options'];
    }
    $html = '';
    foreach ($options as $opt) {
        $value    = (string)($opt['value'] ?? '');
        $label    = (string)($opt['label'] ?? $value);
        $selected = ((string)$selectedValue === $value) ? ' selected' : '';
        $html .= '<option value="' . nu_attr($value) . '"' . $selected . '>' . nu_html($label) . '</option>';
    }
    return $html;
}

function nu_resolve_lookup_id_col($lookup) {
    $idCol = nu_safe_ident($lookup['id_column'] ?? ($lookup['idcolumn'] ?? ''));
    if ($idCol !== '') return $idCol;
    $table = nu_safe_ident($lookup['table'] ?? '');
    if ($table !== '') return nu_get_pk($table);
    return 'id';
}

function nu_render_lookup_display($field, $value) {
    $lookup     = $field['lookup'] ?? [];
    $table      = nu_safe_ident($lookup['table'] ?? '');
    $idCol      = nu_resolve_lookup_id_col($lookup);
    $displayCol = nu_safe_ident($lookup['display_column'] ?? ($lookup['displaycolumn'] ?? 'name'));
    if ($table === '' || $idCol === '' || $displayCol === '' || $value === '' || $value === null) return '';
    try {
        $stmt = nu_q("SELECT `{$displayCol}` FROM `{$table}` WHERE `{$idCol}` = ? LIMIT 1", [$value]);
        return (string)($stmt->fetchColumn() ?: '');
    } catch (Throwable $e) { return ''; }
}

function nu_render_field($field, $value = '', $record = []) {
    $type  = nu_field_type($field);
    $name  = nu_field_name($field);
    $label = nu_field_label($field);

    if ($name === '' && !in_array($type, ['html','content','button','fieldset'], true)) return '';

    $required     = !empty($field['required']) ? ' required' : '';
    $phText       = $field['placeholder'] ?? '';
    $placeholder  = $phText !== '' ? ' placeholder="' . nu_attr($phText) . '"' : '';
    $helpText     = $field['help_text'] ?? ($field['helptext'] ?? '');
    $cssClass     = trim('nu-input ' . ($field['css_class'] ?? ($field['cssclass'] ?? '')));
    $styleWidth   = !empty($field['width']) ? 'width:' . nu_attr($field['width']) . ';' : '';
    $wrapperStyle = 'margin-bottom:16px;' . $styleWidth;

    $wrapStart = '<div class="nu-field-wrapper" data-field="' . nu_attr($name) . '" style="' . $wrapperStyle . '">';
    $labelHtml = '<label style="display:block;font-weight:600;margin-bottom:6px;">' . nu_html($label) . '</label>';
    $helpHtml  = $helpText !== '' ? '<div style="font-size:12px;color:#666;margin-top:4px;">' . nu_html($helpText) . '</div>' : '';

    switch ($type) {
        case 'textarea':
            $rows    = (int)($field['rows'] ?? 4);
            $control = '<textarea class="' . nu_attr($cssClass) . '" data-field="' . nu_attr($name) . '" name="' . nu_attr($name) . '"' . $placeholder . $required . ' rows="' . $rows . '">'
                     . nu_html($value) . '</textarea>';
            break;

        case 'select':
            $multiple = !empty($field['multiple']) ? ' multiple' : '';
            $control  = '<select class="' . nu_attr($cssClass) . '" data-field="' . nu_attr($name) . '" name="' . nu_attr($name) . '"' . $required . $multiple . '>'
                      . '<option value="">Select...</option>'
                      . nu_render_options($field, $value)
                      . '</select>';
            break;

        case 'radio':
        case 'checkbox_group':
            $options = [];
            $sourceType = $field['source_type'] ?? ($field['sourcetype'] ?? 'static');
            $sqlSource  = $field['sql_source']  ?? ($field['sqlsource']  ?? '');
            if ($sourceType === 'sql' && $sqlSource !== '') {
                try { $options = nu_fetch_sql_options($sqlSource); } catch (Throwable $e) { $options = []; }
            } elseif (!empty($field['options']) && is_array($field['options'])) {
                $options = $field['options'];
            }
            $inputType   = ($type === 'radio') ? 'radio' : 'checkbox';
            $currentVals = is_array($value) ? $value : explode(',', (string)$value);
            $control = '<div>';
            foreach ($options as $opt) {
                $optValue = (string)($opt['value'] ?? '');
                $optLabel = (string)($opt['label'] ?? $optValue);
                $checked  = in_array($optValue, $currentVals, true) ? ' checked' : '';
                $iname    = ($type === 'radio') ? $name : $name . '[]';
                $control .= '<label style="display:inline-flex;align-items:center;gap:6px;margin-right:12px;">'
                          . '<input type="' . $inputType . '" data-field="' . nu_attr($name) . '" name="' . nu_attr($iname) . '" value="' . nu_attr($optValue) . '"' . $checked . '>'
                          . nu_html($optLabel) . '</label>';
            }
            $control .= '</div>';
            break;

        case 'checkbox':
            $checked   = !empty($value) ? ' checked' : '';
            $labelHtml = '';
            $control   = '<label style="display:flex;align-items:center;gap:8px;">'
                       . '<input type="hidden" name="' . nu_attr($name) . '" value="0">'
                       . '<input type="checkbox" data-field="' . nu_attr($name) . '" name="' . nu_attr($name) . '" value="1"' . $checked . '>'
                       . nu_html($label) . '</label>';
            break;

        case 'date':
            $control = '<input type="date" class="' . nu_attr($cssClass) . '" data-field="' . nu_attr($name) . '" name="' . nu_attr($name) . '" value="' . nu_attr($value) . '"' . $required . '>';
            break;

        case 'time':
            $control = '<input type="time" class="' . nu_attr($cssClass) . '" data-field="' . nu_attr($name) . '" name="' . nu_attr($name) . '" value="' . nu_attr($value) . '"' . $required . '>';
            break;

        case 'datetime':
            $v       = $value ? str_replace(' ', 'T', substr((string)$value, 0, 16)) : '';
            $control = '<input type="datetime-local" class="' . nu_attr($cssClass) . '" data-field="' . nu_attr($name) . '" name="' . nu_attr($name) . '" value="' . nu_attr($v) . '"' . $required . '>';
            break;

        case 'number':
            $min  = isset($field['min'])  && $field['min']  !== '' ? ' min="'  . nu_attr($field['min'])  . '"' : '';
            $max  = isset($field['max'])  && $field['max']  !== '' ? ' max="'  . nu_attr($field['max'])  . '"' : '';
            $step = isset($field['step']) && $field['step'] !== '' ? ' step="' . nu_attr($field['step']) . '"' : '';
            $control = '<input type="number" class="' . nu_attr($cssClass) . '" data-field="' . nu_attr($name) . '" name="' . nu_attr($name) . '" value="' . nu_attr($value) . '"' . $placeholder . $required . $min . $max . $step . '>';
            break;

        case 'email':
            $control = '<input type="email" class="' . nu_attr($cssClass) . '" data-field="' . nu_attr($name) . '" name="' . nu_attr($name) . '" value="' . nu_attr($value) . '"' . $placeholder . $required . '>';
            break;

        case 'password':
            $control = '<input type="password" class="' . nu_attr($cssClass) . '" data-field="' . nu_attr($name) . '" name="' . nu_attr($name) . '"' . $placeholder . $required . '>';
            break;

        case 'file':
        case 'image':
            $accept   = !empty($field['accept'])          ? ' accept="' . nu_attr($field['accept']) . '"' : ($type === 'image' ? ' accept="image/*"' : '');
            $multiple = !empty($field['multiple_upload']) ? ' multiple' : '';
            $control  = '<input type="file" class="' . nu_attr($cssClass) . '" data-field="' . nu_attr($name) . '" name="' . nu_attr($name) . '"' . $accept . $multiple . '>';
            if (!empty($value)) $control .= '<div style="margin-top:6px;font-size:12px;color:#666;">Current: ' . nu_html($value) . '</div>';
            break;

        case 'lookup':
            $lookup      = $field['lookup'] ?? [];
            $lTable      = $lookup['table'] ?? '';
            $lIdCol      = nu_resolve_lookup_id_col($lookup);
            $lDisplayCol = nu_safe_ident($lookup['display_column'] ?? ($lookup['displaycolumn'] ?? 'name'));
            $lFilter     = $lookup['filter'] ?? '';
            $lExtra      = $lookup['extra']  ?? '';
            $displayVal  = nu_render_lookup_display($field, $value);
            $control = '<div style="display:flex;gap:8px;">'
                . '<input type="hidden" data-field="' . nu_attr($name) . '" name="' . nu_attr($name) . '" value="' . nu_attr($value) . '">'
                . '<input type="text" class="' . nu_attr($cssClass) . '" value="' . nu_attr($displayVal) . '" readonly>'
                . '<button type="button" class="nu-btn nu-btn-ghost" onclick="openLookupModal('
                . '\'' . addslashes($name) . '\',\'' . addslashes($lTable) . '\',\'' . addslashes($lIdCol) . '\',\'' . addslashes($lDisplayCol) . '\',\'' . addslashes($lFilter) . '\',\'' . addslashes($lExtra) . '\''
                . ')">Lookup</button>'
                . '<button type="button" class="nu-btn nu-btn-ghost" onclick="clearLookup(\'' . addslashes($name) . '\')">Clear</button>'
                . '</div>';
            break;

        case 'subform':
            $sf       = $field['subform'] ?? [];
            $sfCode   = nu_safe_ident($sf['form_code'] ?? ($sf['formcode'] ?? ($name)));
            $sfFk     = nu_safe_ident($sf['fk_field']  ?? ($sf['fkfield']  ?? ''));
            $sfView   = in_array($sf['view'] ?? 'grid', ['grid','form','inline'], true) ? ($sf['view'] ?? 'grid') : 'grid';
            $sfParent = nu_attr($record['id'] ?? '');
            $control  = '<div class="nu-subform-container"'
                      . ' data-subform-code="'   . nu_attr($sfCode)   . '"'
                      . ' data-subform-fk="'     . nu_attr($sfFk)     . '"'
                      . ' data-subform-view="'   . nu_attr($sfView)   . '"'
                      . ' data-parent-id="'      . $sfParent          . '"'
                      . ' style="border:1px solid #ddd;border-radius:8px;overflow:hidden;">'
                      . '<div class="nu-subform-toolbar" style="display:flex;justify-content:space-between;align-items:center;padding:8px 12px;background:var(--bg-elevated,#f8f9fa);border-bottom:1px solid #ddd;">'
                      . '<span style="font-weight:600;font-size:13px;">' . nu_html($label) . '</span>'
                      . '<button type="button" class="nu-btn nu-btn-primary nu-btn-sm" onclick="nuSubform.addRow(this)">+ Add Row</button>'
                      . '</div>'
                      . '<div class="nu-subform-body" style="padding:0;">'
                      . '<div class="nu-subform-loading" style="padding:20px;text-align:center;color:#666;font-size:13px;">Loading...</div>'
                      . '</div>'
                      . '</div>';
            break;

        case 'fieldset':
            $legend = $field['legend'] ?? $label;
            return '<fieldset style="margin-bottom:16px;padding:12px;border:1px solid #ddd;border-radius:8px;"><legend>' . nu_html($legend) . '</legend></fieldset>';

        case 'button':
            $action    = $field['button_action'] ?? '';
            $labelHtml = '';
            $onclick   = $action !== '' ? ' onclick="' . nu_attr($action) . '"' : '';
            $control   = '<button type="button" class="nu-btn nu-btn-primary"' . $onclick . '>' . nu_html($label) . '</button>';
            break;

        case 'html':
        case 'content':
            $labelHtml   = '';
            $htmlContent = $field['html_content'] ?? ($field['default_value'] ?? '');
            $control     = '<div>' . $htmlContent . '</div>';
            break;

        case 'calculated':
            $expr    = $field['calculated'] ?? '';
            $control = '<input type="text" class="' . nu_attr($cssClass) . '" data-field="' . nu_attr($name) . '" data-calculated="true" data-expression="' . nu_attr($expr) . '" name="' . nu_attr($name) . '" value="' . nu_attr($value) . '" readonly>';
            break;

        case 'hidden':
            return '<input type="hidden" data-field="' . nu_attr($name) . '" name="' . nu_attr($name) . '" value="' . nu_attr($value) . '">';

        default:
            $control = '<input type="text" class="' . nu_attr($cssClass) . '" data-field="' . nu_attr($name) . '" name="' . nu_attr($name) . '" value="' . nu_attr($value) . '"' . $placeholder . $required . '>';
            break;
    }

    return $wrapStart . $labelHtml . $control . $helpHtml . '</div>';
}

function nu_render_form_html($form, $record = [], $recordId = null) {
    $c        = nu_form_columns();
    $layout   = nu_decode_layout($form);
    $formCode = $form[$c['code']]  ?? '';
    $formTable= $form[$c['table']] ?? '';
    $formName = $form[$c['name']]  ?? $formCode;

    $html  = '<form class="nu-generated-form"'
           . ' data-form-code="'  . nu_attr($formCode)   . '"'
           . ' data-table="'      . nu_attr($formTable)  . '"'
           . ' data-record-id="'  . nu_attr((string)$recordId) . '"'
           . ' data-is-new="'     . ($recordId ? '0' : '1') . '"'
           . ' onsubmit="event.preventDefault(); submitNuForm(this);">';
    $html .= '<h3 style="margin-top:0;">' . nu_html($formName) . '</h3>';

    foreach ($layout as $field) {
        $html .= nu_render_field($field, nu_field_value($record, $field), $record);
    }

    $html .= '<div style="display:flex;gap:8px;justify-content:flex-end;margin-top:20px;">';
    $html .= '<button type="button" class="nu-btn nu-btn-ghost" onclick="closeNuForm(this)">Cancel</button>';
    $html .= '<button type="submit" class="nu-btn nu-btn-primary">Save</button>';
    $html .= '</div></form>';

    return $html;
}

/* ── Subform helpers ─────────────────────────────────────────────────── */

function nu_handle_subform_list() {
    $code     = $_GET['code']      ?? '';
    $fk       = nu_safe_ident($_GET['fk']  ?? '');
    $parentId = $_GET['parent_id'] ?? '';

    if ($code === '' || $fk === '' || $parentId === '') {
        nu_json(['success' => false, 'error' => 'Missing subform params'], 400);
    }

    $form = nu_get_form($code);
    if (!$form) nu_json(['success' => false, 'error' => 'Child form not found'], 404);

    $c      = nu_form_columns();
    $table  = nu_safe_ident($form[$c['table']] ?? '');
    $layout = nu_decode_layout($form);

    if ($table === '') nu_json(['success' => false, 'error' => 'No table for child form'], 400);

    $pk      = nu_get_pk($table);
    $records = nu_q("SELECT * FROM `{$table}` WHERE `{$fk}` = ? ORDER BY `{$pk}` ASC", [$parentId])
                    ->fetchAll(PDO::FETCH_ASSOC);

    // Resolve lookup display values
    foreach ($records as &$row) {
        foreach ($layout as $field) {
            if (nu_field_type($field) !== 'lookup') continue;
            $fname = nu_field_name($field);
            if ($fname === '' || !isset($row[$fname])) continue;
            $row[$fname . '_display'] = nu_render_lookup_display($field, $row[$fname]);
        }
    }
    unset($row);

    nu_json(['success' => true, 'data' => ['layout' => $layout, 'records' => $records, 'pk' => $pk]]);
}

function nu_handle_subform_save() {
    $code     = $_GET['code']      ?? '';
    $fk       = nu_safe_ident($_GET['fk']  ?? '');
    $parentId = $_GET['parent_id'] ?? '';
    $id       = $_GET['id']        ?? '';
    $data     = nu_request_json();

    if ($code === '' || $fk === '') nu_json(['success' => false, 'error' => 'Missing params'], 400);

    $form = nu_get_form($code);
    if (!$form) nu_json(['success' => false, 'error' => 'Child form not found'], 404);

    $c      = nu_form_columns();
    $table  = nu_safe_ident($form[$c['table']] ?? '');
    $layout = nu_decode_layout($form);

    if ($table === '') nu_json(['success' => false, 'error' => 'No table'], 400);

    $save = [];
    foreach ($layout as $field) {
        $name = nu_safe_ident(nu_field_name($field));
        if ($name === '') continue;
        $type = nu_field_type($field);
        if (in_array($type, ['html','heading','divider','fieldset','subform','button'], true)) continue;
        if ($type === 'checkbox') {
            $save[$name] = !empty($data[$name]) ? 1 : 0;
        } else {
            $save[$name] = $data[$name] ?? null;
        }
    }

    if ($parentId !== '' && $fk !== '') {
        $save[$fk] = $parentId;
    }

    $pk = nu_get_pk($table);

    if ($id) {
        $sets = []; $params = [];
        foreach ($save as $col => $val) { $sets[] = "`{$col}` = ?"; $params[] = $val; }
        $params[] = $id;
        nu_q("UPDATE `{$table}` SET " . implode(', ', $sets) . " WHERE `{$pk}` = ?", $params);
        nu_json(['success' => true, 'id' => $id]);
    } else {
        $cols = array_keys($save);
        $placeholders = array_fill(0, count($cols), '?');
        nu_q("INSERT INTO `{$table}` (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', $placeholders) . ")", array_values($save));
        nu_json(['success' => true, 'id' => nu_db()->lastInsertId()]);
    }
}

function nu_handle_subform_delete() {
    $code  = $_GET['code'] ?? '';
    $id    = $_GET['id']   ?? '';
    if ($code === '' || $id === '') nu_json(['success' => false, 'error' => 'Missing params'], 400);

    $form = nu_get_form($code);
    if (!$form) nu_json(['success' => false, 'error' => 'Child form not found'], 404);

    $c     = nu_form_columns();
    $table = nu_safe_ident($form[$c['table']] ?? '');
    if ($table === '') nu_json(['success' => false, 'error' => 'No table'], 400);

    $pk = nu_get_pk($table);
    nu_q("DELETE FROM `{$table}` WHERE `{$pk}` = ?", [$id]);
    nu_json(['success' => true]);
}

/* ── Standard handlers ───────────────────────────────────────────────── */

function nu_handle_render() {
    $code = $_GET['code'] ?? '';
    $id   = $_GET['id']   ?? '';
    if ($code === '') nu_json(['success' => false, 'error' => 'Missing code'], 400);
    $form = nu_get_form($code);
    if (!$form) nu_json(['success' => false, 'error' => 'Form not found'], 404);
    $c      = nu_form_columns();
    $table  = $form[$c['table']] ?? '';
    $record = $id ? nu_get_record($table, $id) : [];
    nu_json(['success' => true, 'html' => nu_render_form_html($form, $record, $id ?: null)]);
}

function nu_handle_fields() {
    $code = $_GET['code'] ?? '';
    if ($code === '') nu_json(['success' => false, 'error' => 'Missing code'], 400);
    $form   = nu_get_form($code);
    if (!$form) nu_json(['success' => false, 'error' => 'Form not found'], 404);
    $layout = nu_decode_layout($form);
    $out    = [];
    foreach ($layout as $field) {
        $out[] = ['fieldname' => nu_field_name($field), 'fieldlabel' => nu_field_label($field), 'fieldtype' => nu_field_type($field)];
    }
    nu_json(['success' => true, 'data' => $out]);
}

function nu_handle_events() {
    nu_json(['success' => true, 'code' => '']);
}

function nu_handle_list() {
    $code = $_GET['code'] ?? '';
    $page = max(1, (int)($_GET['page'] ?? 1));
    $q    = trim($_GET['q'] ?? '');
    if ($code === '') nu_json(['success' => false, 'error' => 'Missing code'], 400);
    $form   = nu_get_form($code);
    if (!$form) nu_json(['success' => false, 'error' => 'Form not found'], 404);
    $c      = nu_form_columns();
    $table  = nu_safe_ident($form[$c['table']] ?? '');
    $layout = nu_decode_layout($form);
    if ($table === '') {
        nu_json(['success' => true, 'data' => ['layout'=>$layout,'records'=>[],'page'=>1,'pages'=>1,'total'=>0,'query'=>$q,'browsesearchenabled'=>0,'browsesearchplaceholder'=>'Search...']]);
    }
    $pageSize = (int)($form[$c['browse_page_size']] ?? 20);
    if ($pageSize < 1) $pageSize = 20;
    $where = []; $params = [];
    $searchEnabled = (int)($form[$c['browse_search_enabled']] ?? 0);
    $searchFields  = trim((string)($form[$c['browse_search_fields']] ?? ''));
    if ($searchEnabled && $q !== '') {
        $fields = array_filter(array_map('trim', explode(',', $searchFields)));
        if (!$fields) { foreach ($layout as $field) { $fname = nu_safe_ident(nu_field_name($field)); if ($fname !== '') $fields[] = $fname; } }
        $likes = [];
        foreach ($fields as $fieldName) { $fieldName = nu_safe_ident($fieldName); if ($fieldName === '') continue; $likes[] = "`{$fieldName}` LIKE ?"; $params[] = '%' . $q . '%'; }
        if ($likes) $where[] = '(' . implode(' OR ', $likes) . ')';
    }
    $whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';
    $total    = (int)nu_q("SELECT COUNT(*) FROM `{$table}`" . $whereSql, $params)->fetchColumn();
    $pages    = max(1, (int)ceil($total / $pageSize));
    if ($page > $pages) $page = $pages;
    $offset   = ($page - 1) * $pageSize;
    $pk       = nu_get_pk($table);
    $sortSql  = trim((string)($form[$c['browse_default_sort']] ?? ''));
    $orderSql = $sortSql !== '' ? " ORDER BY {$sortSql}" : " ORDER BY `{$pk}` DESC";
    $records  = nu_q("SELECT * FROM `{$table}`" . $whereSql . $orderSql . " LIMIT {$pageSize} OFFSET {$offset}", $params)->fetchAll(PDO::FETCH_ASSOC);
    nu_json(['success' => true, 'data' => [
        'layout'  => $layout, 'records' => $records,
        'page'    => $page,   'pages'   => $pages,   'total' => $total,
        'query'   => $q,      'browsesearchenabled'   => $searchEnabled,
        'browsesearchplaceholder' => $form[$c['browse_search_placeholder']] ?? 'Search...'
    ]]);
}

function nu_handle_save() {
    $code = $_GET['code'] ?? '';
    $id   = $_GET['id']   ?? '';
    $data = nu_request_json();
    if ($code === '') nu_json(['success' => false, 'error' => 'Missing code'], 400);
    $form   = nu_get_form($code);
    if (!$form) nu_json(['success' => false, 'error' => 'Form not found'], 404);
    $c      = nu_form_columns();
    $table  = nu_safe_ident($form[$c['table']] ?? '');
    if ($table === '') nu_json(['success' => false, 'error' => 'No table configured'], 400);
    $layout = nu_decode_layout($form);
    $save   = [];
    foreach ($layout as $field) {
        $name = nu_safe_ident(nu_field_name($field));
        if ($name === '') continue;
        $type = nu_field_type($field);
        if (in_array($type, ['html','heading','divider','fieldset','subform','button'], true)) continue;
        $save[$name] = ($type === 'checkbox') ? (!empty($data[$name]) ? 1 : 0) : ($data[$name] ?? null);
    }
    if (!$save) nu_json(['success' => false, 'error' => 'No fields to save'], 400);
    if ($id) {
        $sets = []; $params = [];
        foreach ($save as $col => $val) { $sets[] = "`{$col}` = ?"; $params[] = $val; }
        $pk = nu_get_pk($table); $params[] = $id;
        nu_q("UPDATE `{$table}` SET " . implode(', ', $sets) . " WHERE `{$pk}` = ?", $params);
        nu_json(['success' => true, 'id' => $id]);
    } else {
        $cols = array_keys($save); $placeholders = array_fill(0, count($cols), '?');
        nu_q("INSERT INTO `{$table}` (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', $placeholders) . ")", array_values($save));
        nu_json(['success' => true, 'id' => nu_db()->lastInsertId()]);
    }
}

/* ── Router ──────────────────────────────────────────────────────────── */
try {
    $action = $_GET['action'] ?? '';
    switch ($action) {
        case 'render':          nu_handle_render();         break;
        case 'fields':          nu_handle_fields();         break;
        case 'events':          nu_handle_events();         break;
        case 'list':            nu_handle_list();           break;
        case 'save':            nu_handle_save();           break;
        case 'subform_list':    nu_handle_subform_list();   break;
        case 'subform_save':    nu_handle_subform_save();   break;
        case 'subform_delete':  nu_handle_subform_delete(); break;
        default: nu_json(['success' => false, 'error' => 'Invalid action'], 400);
    }
} catch (Throwable $e) {
    nu_json(['success' => false, 'error' => $e->getMessage()], 500);
}
