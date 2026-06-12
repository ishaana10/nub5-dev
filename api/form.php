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

/**
 * Returns a set (keyed by column name => true) of real columns for $table.
 * Results are cached for the lifetime of the request.
 */
function nu_get_table_columns($table) {
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    try {
        $rows = nu_q("DESCRIBE `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
        $cols = [];
        foreach ($rows as $row) $cols[$row['Field']] = true;
        $cache[$table] = $cols;
        return $cols;
    } catch (Throwable $e) {
        $cache[$table] = [];
        return [];
    }
}

/**
 * Filter a $save array so only columns that actually exist in $table are kept.
 * The PK is always kept if present (it may not be in DESCRIBE when autoincrement).
 */
function nu_filter_save_to_columns($save, $table, $pk) {
    $cols = nu_get_table_columns($table);
    if (empty($cols)) return $save;
    $out = [];
    foreach ($save as $col => $val) {
        if ($col === $pk || isset($cols[$col])) $out[$col] = $val;
    }
    return $out;
}

/**
 * Coerce a value coming from the JSON body so it is safe to pass to PDO.
 *
 * - Arrays (checkbox_group, select[multiple]) are joined to a comma-separated string.
 * - null / scalar values pass through unchanged.
 */
function nu_coerce_save_value($value) {
    if (is_array($value)) {
        return implode(',', array_map('strval', $value));
    }
    return $value;
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
            'browse_page_size'=>'browsepagesize','browse_default_sort'=>'browsedefaultsort',
            'pk_type'=>'form_pk_type','table_mode'=>'form_table_mode'];
    }
    return ['id'=>'id','name'=>'form_name','code'=>'form_code','table'=>'form_table',
        'layout'=>'form_layout','active'=>'form_active','custom_js'=>'form_custom_js',
        'custom_php'=>'form_custom_php','custom_css'=>'form_custom_css','browse_sql'=>'browse_sql',
        'browse_columns'=>'browse_columns','browse_search_enabled'=>'browse_search_enabled',
        'browse_search_placeholder'=>'browse_search_placeholder','browse_search_fields'=>'browse_search_fields',
        'browse_page_size'=>'browse_page_size','browse_default_sort'=>'browse_default_sort',
        'pk_type'=>'form_pk_type','table_mode'=>'form_table_mode'];
}

function nu_get_form($code) {
    $table = nu_form_table_name();
    $c = nu_form_columns();

    $stmt = nu_q(
        "SELECT * FROM `{$table}` WHERE `{$c['code']}` = ? AND `{$c['active']}` = 1 LIMIT 1",
        [$code]
    );
    $form = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($form) return $form;

    $stmt = nu_q(
        "SELECT * FROM `{$table}` WHERE `{$c['code']}` = ? LIMIT 1",
        [$code]
    );
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
    $type = nu_field_type($field);
    if ($type === 'lookup') {
        $dbCol = nu_resolve_lookup_store_col($field);
        if ($dbCol !== '' && array_key_exists($dbCol, $record)) return $record[$dbCol];
    }
    if (array_key_exists($name, $record)) return $record[$name];
    if (isset($field['default_value'])) return $field['default_value'];
    if (isset($field['defaultvalue'])) return $field['defaultvalue'];
    return '';
}

function nu_attr($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function nu_html($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }

function nu_generate_uuid() {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function nu_form_pk_type($form) {
    $c = nu_form_columns();
    return strtolower(trim((string)($form[$c['pk_type']] ?? 'auto')));
}

/**
 * Render <option> tags for select / radio / checkbox_group fields.
 */
function nu_render_options($field, $selectedValue = null) {
    $options   = [];
    $optSource = $field['options_source'] ?? ($field['source_type'] ?? 'manual');

    if ($optSource === 'table') {
        $tbl      = nu_safe_ident($field['options_table']     ?? '');
        $valCol   = nu_safe_ident($field['options_value_col'] ?? '');
        $labelCol = nu_safe_ident($field['options_label_col'] ?? $valCol);
        $filter   = trim((string)($field['options_filter']   ?? ''));
        if ($tbl !== '' && $valCol !== '') {
            $sql = "SELECT `{$valCol}`, `{$labelCol}` FROM `{$tbl}`";
            if ($filter !== '') $sql .= ' WHERE ' . $filter;
            try { $options = nu_fetch_sql_options($sql); } catch (Throwable $e) { $options = []; }
        }
    } elseif ($optSource === 'sql' && !empty($field['sql_source'])) {
        try { $options = nu_fetch_sql_options($field['sql_source']); } catch (Throwable $e) { $options = []; }
    } else {
        $options = is_array($field['options'] ?? null) ? $field['options'] : [];
    }

    $selectedArr = is_array($selectedValue)
        ? array_map('strval', $selectedValue)
        : array_map('trim', explode(',', (string)$selectedValue));

    $html = '';
    foreach ($options as $opt) {
        $value    = (string)($opt['value'] ?? '');
        $label    = (string)($opt['label'] ?? $value);
        $selected = in_array($value, $selectedArr, true) ? ' selected' : '';
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

function nu_resolve_lookup_store_col($field) {
    $lookup = $field['lookup'] ?? [];
    $col = $lookup['store_field']
        ?? $lookup['storefield']
        ?? $lookup['store_col']
        ?? $lookup['storeCol']
        ?? '';
    $col = nu_safe_ident($col);
    if ($col !== '') return $col;
    $name = nu_safe_ident(nu_field_name($field));
    if ($name !== '' && !preg_match('/^lookup_\d+$/', $name)) return $name;
    return '';
}

function nu_resolve_lookup_display_col($lookup) {
    $col = $lookup['display_column']
        ?? $lookup['displaycolumn']
        ?? $lookup['display_col']
        ?? $lookup['displayCol']
        ?? '';
    return nu_safe_ident($col);
}

function nu_render_lookup_display($field, $value) {
    $lookup     = $field['lookup'] ?? [];
    $table      = nu_safe_ident($lookup['table'] ?? '');
    $idCol      = nu_resolve_lookup_id_col($lookup);
    $displayCol = nu_resolve_lookup_display_col($lookup);
    if ($table === '' || $idCol === '' || $displayCol === '' || $value === '' || $value === null) return (string)($value ?? '');
    try {
        $stmt = nu_q("SELECT `{$displayCol}` FROM `{$table}` WHERE `{$idCol}` = ? LIMIT 1", [$value]);
        return (string)($stmt->fetchColumn() ?: '');
    } catch (Throwable $e) { return (string)$value; }
}

function nu_field_is_fk($field)           { return !empty($field['is_fk']); }
function nu_field_hide_in_grid($field)     { return !empty($field['hide_in_grid']); }
function nu_field_server_readonly($field)  { return !empty($field['server_readonly']); }

/**
 * Render a single field using a label-left inline layout that mirrors the form editor.
 *
 * Layout:
 *   +------------------+------------------------------------+
 *   |  Label (right)   |  Input / control (left-aligned)    |
 *   +------------------+------------------------------------+
 *
 * - Label column: fixed 160px, right-aligned, bold, text-muted colour
 * - Control column: flex 1, full width
 * - Full-width exceptions (subform, checkbox, html, button, fieldset): no label column
 */
function nu_render_field($field, $value = '', $record = []) {
    $type  = nu_field_type($field);
    $name  = nu_field_name($field);
    $label = nu_field_label($field);

    if ($name === '' && !in_array($type, ['html','content','button','fieldset'], true)) return '';

    if (nu_field_is_fk($field)) {
        return '<input type="hidden"'
             . ' data-field="' . nu_attr($name) . '"'
             . ' name="'       . nu_attr($name) . '"'
             . ' data-is-fk="1"'
             . ' value="'      . nu_attr($value) . '">';
    }

    $required    = !empty($field['required']) ? ' required' : '';
    $phText      = $field['placeholder'] ?? '';
    $placeholder = $phText !== '' ? ' placeholder="' . nu_attr($phText) . '"' : '';
    $helpText    = $field['help_text'] ?? ($field['helptext'] ?? '');
    $cssClass    = trim('nu-input ' . ($field['css_class'] ?? ($field['cssclass'] ?? '')));

    $col = (int)($field['col'] ?? 12);
    if ($col < 1 || $col > 12) $col = 12;

    $helpHtml = $helpText !== ''
        ? '<div style="font-size:11px;color:#999;margin-top:3px;">' . nu_html($helpText) . '</div>'
        : '';

    // ── Full-width types — no label column ──────────────────────────────
    $fullWidthTypes = ['subform', 'html', 'content', 'button', 'fieldset', 'checkbox'];

    if (in_array($type, $fullWidthTypes, true)) {

        // Checkbox: inline label next to tick box
        if ($type === 'checkbox') {
            $checked = !empty($value) ? ' checked' : '';
            $control = '<div class="nu-field-wrapper" data-field="' . nu_attr($name) . '"'
                     . ' style="grid-column:span ' . $col . ';min-width:0;padding:6px 0;">'
                     . '<label style="display:inline-flex;align-items:center;gap:8px;cursor:pointer;">'
                     . '<input type="hidden" name="' . nu_attr($name) . '" value="0">'
                     . '<input type="checkbox" data-field="' . nu_attr($name) . '" name="' . nu_attr($name) . '" value="1"' . $checked . ' style="width:15px;height:15px;">'
                     . '<span style="font-weight:600;font-size:13px;">' . nu_html($label) . '</span>'
                     . '</label>'
                     . $helpHtml
                     . '</div>';
            return $control;
        }

        // Subform: full-width block with label as section header
        if ($type === 'subform') {
            $sf       = $field['subform'] ?? [];
            $sfCode   = nu_safe_ident($sf['form_code'] ?? ($sf['formcode'] ?? $name));
            $sfFk     = nu_safe_ident($sf['fk_field']  ?? ($sf['fkfield']  ?? ''));
            $sfView   = in_array($sf['view'] ?? 'grid', ['grid','form','inline'], true) ? ($sf['view'] ?? 'grid') : 'grid';
            $sfParent = (string)($field['_parent_id'] ?? '');
            return '<div class="nu-field-wrapper" data-field="' . nu_attr($name) . '"'
                 . ' style="grid-column:span ' . $col . ';min-width:0;margin-bottom:8px;">'
                 . '<div class="nu-subform-container"'
                 . ' data-subform-code="'  . nu_attr($sfCode)   . '"'
                 . ' data-subform-fk="'    . nu_attr($sfFk)     . '"'
                 . ' data-subform-view="'  . nu_attr($sfView)   . '"'
                 . ' data-parent-id="'     . nu_attr($sfParent) . '"'
                 . ' style="border:1px solid #ddd;border-radius:8px;overflow:hidden;">'
                 . '<div class="nu-subform-toolbar" style="display:flex;justify-content:space-between;align-items:center;padding:8px 12px;background:var(--bg-elevated,#f8f9fa);border-bottom:1px solid #ddd;">'
                 . '<span style="font-weight:600;font-size:13px;">' . nu_html($label) . '</span>'
                 . '<button type="button" class="nu-btn nu-btn-primary nu-btn-sm" onclick="nuSubform.addRow(this)">+ Add Row</button>'
                 . '</div>'
                 . '<div class="nu-subform-body" style="padding:0;">'
                 . '<div class="nu-subform-loading" style="padding:20px;text-align:center;color:#888;font-size:13px;">Loading...</div>'
                 . '</div>'
                 . '</div>'
                 . '</div>';
        }

        // HTML / content
        if ($type === 'html' || $type === 'content') {
            $htmlContent = $field['html_content'] ?? ($field['default_value'] ?? '');
            return '<div class="nu-field-wrapper"'
                 . ' style="grid-column:span ' . $col . ';min-width:0;">'
                 . '<div>' . $htmlContent . '</div>'
                 . '</div>';
        }

        // Button
        if ($type === 'button') {
            $action  = $field['button_action'] ?? '';
            $onclick = $action !== '' ? ' onclick="' . nu_attr($action) . '"' : '';
            return '<div class="nu-field-wrapper"'
                 . ' style="grid-column:span ' . $col . ';min-width:0;padding:4px 0;">'
                 . '<button type="button" class="nu-btn nu-btn-primary"' . $onclick . '>' . nu_html($label) . '</button>'
                 . '</div>';
        }

        // Fieldset
        if ($type === 'fieldset') {
            $legend = $field['legend'] ?? $label;
            return '<fieldset style="grid-column:span ' . $col . ';margin-bottom:16px;padding:12px;border:1px solid #ddd;border-radius:8px;">'
                 . '<legend>' . nu_html($legend) . '</legend>'
                 . '</fieldset>';
        }
    }

    // ── Hidden / UUID with no value ──────────────────────────────────────
    if ($type === 'hidden') {
        return '<input type="hidden" data-field="' . nu_attr($name) . '" name="' . nu_attr($name) . '" value="' . nu_attr($value) . '">';
    }

    if ($type === 'uuid') {
        if ($value !== '' && $value !== null) {
            $control = '<input type="text" class="' . nu_attr($cssClass) . '" value="' . nu_attr($value) . '" readonly style="background:var(--bg-offset,#f5f5f5);color:#888;cursor:default;width:100%;">'
                     . '<input type="hidden" data-field="' . nu_attr($name) . '" name="' . nu_attr($name) . '" value="' . nu_attr($value) . '">';
        } else {
            return '<input type="hidden" data-field="' . nu_attr($name) . '" name="' . nu_attr($name) . '" value="">';
        }
        // Falls through to label-left wrapper below
    } else {
        // ── Build the input control ─────────────────────────────────────
        switch ($type) {

            case 'textarea':
                $rows    = (int)($field['rows'] ?? 3);
                $control = '<textarea class="' . nu_attr($cssClass) . '" data-field="' . nu_attr($name) . '" name="' . nu_attr($name) . '"' . $placeholder . $required . ' rows="' . $rows . '" style="width:100%;resize:vertical;">'
                         . nu_html($value) . '</textarea>';
                break;

            case 'select':
                $multiple   = !empty($field['multiple']) ? ' multiple' : '';
                $useSelect2 = !empty($field['select2']);
                $s2Class    = $useSelect2 ? ' nu-select2' : '';
                $s2Attr     = $useSelect2 ? ' data-select2="1"' : '';
                $control    = '<select class="' . nu_attr(trim($cssClass . $s2Class)) . '" data-field="' . nu_attr($name) . '" name="' . nu_attr($name) . '"' . $required . $multiple . $s2Attr . ' style="width:100%;">'
                            . '<option value="">Select...</option>'
                            . nu_render_options($field, $value)
                            . '</select>';
                break;

            case 'radio':
            case 'checkbox_group':
                $options = [];
                $optSource  = $field['options_source'] ?? ($field['source_type'] ?? 'manual');
                if ($optSource === 'table') {
                    $tbl      = nu_safe_ident($field['options_table']     ?? '');
                    $valCol   = nu_safe_ident($field['options_value_col'] ?? '');
                    $labelCol = nu_safe_ident($field['options_label_col'] ?? $valCol);
                    $filter   = trim((string)($field['options_filter']   ?? ''));
                    if ($tbl !== '' && $valCol !== '') {
                        $sql = "SELECT `{$valCol}`, `{$labelCol}` FROM `{$tbl}`";
                        if ($filter !== '') $sql .= ' WHERE ' . $filter;
                        try { $options = nu_fetch_sql_options($sql); } catch (Throwable $e) { $options = []; }
                    }
                } elseif ($optSource === 'sql' && !empty($field['sql_source'])) {
                    try { $options = nu_fetch_sql_options($field['sql_source']); } catch (Throwable $e) { $options = []; }
                } else {
                    $options = is_array($field['options'] ?? null) ? $field['options'] : [];
                }
                $inputType   = ($type === 'radio') ? 'radio' : 'checkbox';
                $currentVals = is_array($value) ? array_map('strval', $value) : array_map('trim', explode(',', (string)$value));
                $control = '<div style="display:flex;flex-wrap:wrap;gap:8px 16px;padding-top:4px;">';
                foreach ($options as $opt) {
                    $optValue = (string)($opt['value'] ?? '');
                    $optLabel = (string)($opt['label'] ?? $optValue);
                    $checked  = in_array($optValue, $currentVals, true) ? ' checked' : '';
                    $iname    = ($type === 'radio') ? $name : $name . '[]';
                    $control .= '<label style="display:inline-flex;align-items:center;gap:5px;font-size:13px;cursor:pointer;">'
                              . '<input type="' . $inputType . '" data-field="' . nu_attr($name) . '" name="' . nu_attr($iname) . '" value="' . nu_attr($optValue) . '"' . $checked . '>'
                              . nu_html($optLabel) . '</label>';
                }
                $control .= '</div>';
                break;

            case 'date':
                $control = '<input type="date" class="' . nu_attr($cssClass) . '" data-field="' . nu_attr($name) . '" name="' . nu_attr($name) . '" value="' . nu_attr($value) . '"' . $required . ' style="width:100%;">';
                break;

            case 'time':
                $control = '<input type="time" class="' . nu_attr($cssClass) . '" data-field="' . nu_attr($name) . '" name="' . nu_attr($name) . '" value="' . nu_attr($value) . '"' . $required . ' style="width:100%;">';
                break;

            case 'datetime':
                $v       = $value ? str_replace(' ', 'T', substr((string)$value, 0, 16)) : '';
                $control = '<input type="datetime-local" class="' . nu_attr($cssClass) . '" data-field="' . nu_attr($name) . '" name="' . nu_attr($name) . '" value="' . nu_attr($v) . '"' . $required . ' style="width:100%;">';
                break;

            case 'number':
                $min  = isset($field['min'])  && $field['min']  !== '' ? ' min="'  . nu_attr($field['min'])  . '"' : '';
                $max  = isset($field['max'])  && $field['max']  !== '' ? ' max="'  . nu_attr($field['max'])  . '"' : '';
                $step = isset($field['step']) && $field['step'] !== '' ? ' step="' . nu_attr($field['step']) . '"' : '';
                $control = '<input type="number" class="' . nu_attr($cssClass) . '" data-field="' . nu_attr($name) . '" name="' . nu_attr($name) . '" value="' . nu_attr($value) . '"' . $placeholder . $required . $min . $max . $step . ' style="width:100%;">';
                break;

            case 'email':
                $control = '<input type="email" class="' . nu_attr($cssClass) . '" data-field="' . nu_attr($name) . '" name="' . nu_attr($name) . '" value="' . nu_attr($value) . '"' . $placeholder . $required . ' style="width:100%;">';
                break;

            case 'password':
                $control = '<input type="password" class="' . nu_attr($cssClass) . '" data-field="' . nu_attr($name) . '" name="' . nu_attr($name) . '"' . $placeholder . $required . ' style="width:100%;">';
                break;

            case 'file':
            case 'image':
                $accept   = !empty($field['accept'])          ? ' accept="' . nu_attr($field['accept']) . '"' : ($type === 'image' ? ' accept="image/*"' : '');
                $multiple = !empty($field['multiple_upload']) ? ' multiple' : '';
                $control  = '<input type="file" class="' . nu_attr($cssClass) . '" data-field="' . nu_attr($name) . '" name="' . nu_attr($name) . '"' . $accept . $multiple . ' style="width:100%;">';
                if (!empty($value)) $control .= '<div style="margin-top:4px;font-size:11px;color:#888;">Current: ' . nu_html($value) . '</div>';
                break;

            case 'lookup':
                $lookup      = $field['lookup'] ?? [];
                $lTable      = $lookup['table'] ?? '';
                $lIdCol      = nu_resolve_lookup_id_col($lookup);
                $lDisplayCol = nu_resolve_lookup_display_col($lookup);
                $lFilter     = $lookup['filter'] ?? '';
                $lExtra      = $lookup['extra']  ?? '';
                $displayVal  = nu_render_lookup_display($field, $value);
                $control = '<div style="display:flex;gap:6px;align-items:center;">'
                    . '<input type="hidden" data-field="' . nu_attr($name) . '" name="' . nu_attr($name) . '" value="' . nu_attr($value) . '">'
                    . '<input type="text" class="' . nu_attr($cssClass) . '" data-field="' . nu_attr($name) . '_display" value="' . nu_attr($displayVal) . '" readonly style="flex:1;">'
                    . '<button type="button" class="nu-btn nu-btn-ghost" style="white-space:nowrap;" onclick="openLookupModal('
                    . '\'' . addslashes($name) . '\',\'' . addslashes($lTable) . '\',\'' . addslashes($lIdCol) . '\',\'' . addslashes($lDisplayCol) . '\',\'' . addslashes($lFilter) . '\',\'' . addslashes($lExtra) . '\''
                    . ')">&#x1F50D;</button>'
                    . '<button type="button" class="nu-btn nu-btn-ghost" onclick="clearLookup(\'' . addslashes($name) . '\')">&#x2715;</button>'
                    . '</div>';
                break;

            case 'calculated':
                $expr    = $field['calculated'] ?? '';
                $control = '<input type="text" class="' . nu_attr($cssClass) . '" data-field="' . nu_attr($name) . '" data-calculated="true" data-expression="' . nu_attr($expr) . '" name="' . nu_attr($name) . '" value="' . nu_attr($value) . '" readonly style="width:100%;background:var(--bg-offset,#f5f5f5);color:#888;">';
                break;

            default: // text and everything else
                $control = '<input type="text" class="' . nu_attr($cssClass) . '" data-field="' . nu_attr($name) . '" name="' . nu_attr($name) . '" value="' . nu_attr($value) . '"' . $placeholder . $required . ' style="width:100%;">';
                break;
        }
    }

    // ── Label-left inline wrapper ────────────────────────────────────────
    // Mirrors the form editor: bold label right-aligned in a 160px column,
    // input stretches to fill the remaining space.
    $labelHtml = '<label for="nuf_' . nu_attr($name) . '"'
               . ' style="display:block;font-weight:600;font-size:13px;color:#555;'
               . 'text-align:right;padding-right:10px;padding-top:7px;'
               . 'min-width:160px;max-width:160px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">'
               . nu_html($label)
               . '</label>';

    $wrapInner = '<div style="display:flex;align-items:flex-start;gap:0;width:100%;">'
               . $labelHtml
               . '<div style="flex:1;min-width:0;">'
               . $control
               . $helpHtml
               . '</div>'
               . '</div>';

    return '<div class="nu-field-wrapper" data-field="' . nu_attr($name) . '"'
         . ' style="grid-column:span ' . $col . ';min-width:0;margin-bottom:6px;">'
         . $wrapInner
         . '</div>';
}

function nu_section_color($index) {
    $palette = [
        ['border'=>'#01696f','bg'=>'rgba(1,105,111,0.04)','head'=>'rgba(1,105,111,0.08)','text'=>'#01696f'],
        ['border'=>'#7a39bb','bg'=>'rgba(122,57,187,0.04)','head'=>'rgba(122,57,187,0.08)','text'=>'#7a39bb'],
        ['border'=>'#d19900','bg'=>'rgba(209,153,0,0.04)', 'head'=>'rgba(209,153,0,0.08)', 'text'=>'#9a7000'],
        ['border'=>'#437a22','bg'=>'rgba(67,122,34,0.04)', 'head'=>'rgba(67,122,34,0.08)', 'text'=>'#437a22'],
        ['border'=>'#a13544','bg'=>'rgba(161,53,68,0.04)', 'head'=>'rgba(161,53,68,0.08)', 'text'=>'#a13544'],
    ];
    return $palette[$index % count($palette)];
}

function nu_toggle_script() {
    return '<script id="nuToggleInit">'
         . 'if(!window.nuToggleContainer){'
         . 'window.nuToggleContainer=function(btn){'
         . 'if(!btn)return;'
         . 'var tid=btn.getAttribute("data-target");'
         . 'if(!tid)return;'
         . 'var body=document.getElementById(tid);'
         . 'if(!body)return;'
         . 'var hidden=body.style.display==="none"||body.style.display==="";'
         . 'body.style.display=hidden?"block":"none";'
         . 'btn.innerHTML=hidden?"&#9660;":"&#9654;";'
         . '}}'
         . '</s' . 'cript>';
}

function nu_render_layout_node($node, $record, $sectionIndex = 0) {
    $type = $node['type'] ?? 'field';

    if ($type === 'section') {
        $id         = 'sec_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $node['id'] ?? ('s' . $sectionIndex));
        $label      = nu_html($node['label'] ?? 'Section');
        $collapsible= !empty($node['collapsible']);
        $collapsed  = !empty($node['collapsed']);
        $col        = nu_section_color($sectionIndex);
        $bodyStyle  = $collapsed ? 'display:none;' : 'display:block;';
        $icon       = $collapsed ? '&#9654;' : '&#9660;';

        $html  = '<div class="nu-section" id="' . nu_attr($id) . '" style="'
               . 'border:1.5px solid ' . $col['border'] . ';'
               . 'border-radius:10px;margin-bottom:20px;'
               . 'background:' . $col['bg'] . ';overflow:hidden;">';
        $html .= '<div class="nu-section-header" style="'
               . 'display:flex;align-items:center;gap:6px;'
               . 'padding:10px 16px;'
               . 'background:' . $col['head'] . ';'
               . 'border-bottom:1px solid ' . $col['border'] . ';'
               . 'user-select:none;'
               . ($collapsible ? 'cursor:pointer;' : '') . '"'
               . ($collapsible ? ' onclick="nuToggleContainer(this.querySelector(\'.nu-section-toggle\'))"' : '') . '>';
        if ($collapsible) {
            $html .= '<button type="button" class="nu-section-toggle"'
                   . ' data-target="' . nu_attr($id) . '-body"'
                   . ' onclick="event.stopPropagation();nuToggleContainer(this)"'
                   . ' style="background:none;border:none;cursor:pointer;font-size:14px;'
                   . 'color:' . $col['text'] . ';padding:0 6px 0 0;line-height:1;flex-shrink:0;">'
                   . $icon . '</button>';
        }
        $html .= '<span style="font-weight:700;font-size:14px;color:' . $col['text'] . ';">' . $label . '</span>';
        $html .= '</div>';
        $html .= '<div id="' . nu_attr($id) . '-body" class="nu-section-body" style="padding:16px 12px;' . $bodyStyle . '">';
        $si = 0;
        foreach (($node['children'] ?? []) as $child) {
            $html .= nu_render_layout_node($child, $record, $si++);
        }
        $html .= '</div></div>';
        return $html;
    }

    if ($type === 'group') {
        $id         = 'grp_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $node['id'] ?? ('g' . $sectionIndex));
        $label      = nu_html($node['label'] ?? 'Group');
        $collapsible= !empty($node['collapsible']);
        $collapsed  = !empty($node['collapsed']);
        $bodyStyle  = $collapsed ? 'display:none;' : 'display:block;';
        $icon       = $collapsed ? '&#9654;' : '&#9660;';

        $html  = '<div class="nu-group" id="' . nu_attr($id) . '" style="border:1px solid #ddd;border-radius:8px;margin-bottom:16px;overflow:hidden;">';
        $html .= '<div class="nu-group-header" style="'
               . 'display:flex;align-items:center;gap:6px;'
               . 'padding:8px 14px;'
               . 'background:var(--bg-elevated,#f8f9fa);'
               . 'border-bottom:1px solid #ddd;'
               . 'user-select:none;'
               . ($collapsible ? 'cursor:pointer;' : '') . '"'
               . ($collapsible ? ' onclick="nuToggleContainer(this.querySelector(\'.nu-group-toggle\'))"' : '') . '>';
        if ($collapsible) {
            $html .= '<button type="button" class="nu-group-toggle"'
                   . ' data-target="' . nu_attr($id) . '-body"'
                   . ' onclick="event.stopPropagation();nuToggleContainer(this)"'
                   . ' style="background:none;border:none;cursor:pointer;font-size:13px;'
                   . 'color:#666;padding:0 6px 0 0;line-height:1;flex-shrink:0;">'
                   . $icon . '</button>';
        }
        $html .= '<span style="font-weight:600;font-size:13px;color:var(--text,#333);">' . $label . '</span>';
        $html .= '</div>';
        $html .= '<div id="' . nu_attr($id) . '-body" class="nu-group-body" style="padding:12px 10px;' . $bodyStyle . '">';
        $gi = 0;
        foreach (($node['children'] ?? []) as $child) {
            $html .= nu_render_layout_node($child, $record, $gi++);
        }
        $html .= '</div></div>';
        return $html;
    }

    if ($type === 'row') {
        $html = '<div class="nu-form-row" style="display:grid;grid-template-columns:repeat(12,1fr);gap:8px;margin-bottom:4px;align-items:start;">';
        foreach (($node['children'] ?? []) as $field) {
            $html .= nu_render_field($field, nu_field_value($record, $field), $record);
        }
        $html .= '</div>';
        return $html;
    }

    $col = (int)($node['col'] ?? 12);
    $node['col'] = $col;
    return '<div class="nu-form-row" style="display:grid;grid-template-columns:repeat(12,1fr);gap:8px;margin-bottom:4px;align-items:start;">'
         . nu_render_field($node, nu_field_value($record, $node), $node)
         . '</div>';
}

function nu_render_form_html($form, $record = [], $recordId = null) {
    $c        = nu_form_columns();
    $layout   = nu_decode_layout($form);
    $formCode = $form[$c['code']]  ?? '';
    $formTable= $form[$c['table']] ?? '';
    $formName = $form[$c['name']]  ?? $formCode;

    $layout = nu_inject_parent_context($layout, $formTable, (string)($recordId ?? ''));

    $html  = '<form class="nu-generated-form"'
           . ' data-form-code="'  . nu_attr($formCode)   . '"'
           . ' data-table="'      . nu_attr($formTable)  . '"'
           . ' data-record-id="'  . nu_attr((string)$recordId) . '"'
           . ' data-is-new="'     . ($recordId ? '0' : '1') . '"'
           . ' onsubmit="event.preventDefault(); submitNuForm(this);"'
           . ' style="font-size:13px;">';

    $si = 0;
    foreach ($layout as $node) {
        $html .= nu_render_layout_node($node, $record, $si++);
    }

    $html .= '<div style="display:flex;gap:8px;justify-content:flex-end;margin-top:20px;padding-top:12px;border-top:1px solid #eee;">';
    $html .= '<button type="button" class="nu-btn nu-btn-ghost" onclick="closeNuForm(this)">Cancel</button>';
    $html .= '<button type="submit" class="nu-btn nu-btn-primary">Save</button>';
    $html .= '</div></form>';

    return $html;
}

function nu_inject_parent_context(array $layout, string $parentTable, string $parentId): array {
    foreach ($layout as &$node) {
        $t = $node['type'] ?? 'field';
        if ($t === 'subform') {
            $node['_parent_table'] = $parentTable;
            $node['_parent_id']    = $parentId;
        } elseif (in_array($t, ['section', 'group', 'row'], true) && isset($node['children'])) {
            $node['children'] = nu_inject_parent_context($node['children'], $parentTable, $parentId);
        }
    }
    unset($node);
    return $layout;
}

function nu_flatten_layout($layout) {
    $fields = [];
    foreach ($layout as $node) {
        $t = $node['type'] ?? 'field';
        if ($t === 'section' || $t === 'group') {
            foreach (nu_flatten_layout($node['children'] ?? []) as $f) $fields[] = $f;
        } elseif ($t === 'row') {
            foreach (($node['children'] ?? []) as $f) $fields[] = $f;
        } else {
            $fields[] = $node;
        }
    }
    return $fields;
}

function nu_flatten_layout_for_grid($layout) {
    return array_values(array_filter(
        nu_flatten_layout($layout),
        fn($f) => !nu_field_hide_in_grid($f)
    ));
}

/* ── Subform handlers ───────────────────────────────────────────── */

function nu_handle_subform_fields() {
    $code = $_GET['code'] ?? '';
    if ($code === '') nu_json(['success' => false, 'error' => 'Missing code'], 400);

    $form = nu_get_form($code);
    if (!$form) nu_json(['success' => false, 'error' => 'Child form not found'], 404);

    $c      = nu_form_columns();
    $table  = nu_safe_ident($form[$c['table']] ?? '');
    $fields = nu_flatten_layout(nu_decode_layout($form));
    $pk     = $table !== '' ? nu_get_pk($table) : 'id';

    nu_json(['success' => true, 'data' => ['layout' => $fields, 'pk' => $pk]]);
}

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

    $allFields  = nu_flatten_layout($layout);
    $gridFields = nu_flatten_layout_for_grid($layout);

    if ($table === '') nu_json(['success' => false, 'error' => 'No table for child form'], 400);

    $pk      = nu_get_pk($table);
    $records = nu_q("SELECT * FROM `{$table}` WHERE `{$fk}` = ? ORDER BY `{$pk}` ASC", [$parentId])
                    ->fetchAll(PDO::FETCH_ASSOC);

    foreach ($records as &$row) {
        foreach ($allFields as $field) {
            if (nu_field_type($field) !== 'lookup') continue;
            $fname = nu_field_name($field);
            if ($fname === '' || !isset($row[$fname])) continue;
            $row[$fname . '_display'] = nu_render_lookup_display($field, $row[$fname]);
        }
    }
    unset($row);

    nu_json(['success' => true, 'data' => [
        'layout'     => $gridFields,
        'all_fields' => $allFields,
        'records'    => $records,
        'pk'         => $pk,
    ]]);
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
    $fields = nu_flatten_layout(nu_decode_layout($form));

    if ($table === '') nu_json(['success' => false, 'error' => 'No table'], 400);

    $pk     = nu_get_pk($table);
    $pkType = nu_form_pk_type($form);

    $save = [];

    if (!$id && $pkType === 'uuid') {
        $save[$pk] = nu_generate_uuid();
    }

    foreach ($fields as $field) {
        $name = nu_safe_ident(nu_field_name($field));
        if ($name === '') continue;
        $type = nu_field_type($field);

        if (in_array($type, ['html','heading','divider','fieldset','subform','button'], true)) continue;

        if ($type === 'uuid') {
            if (!$id) continue;
            if (!empty($data[$name])) $save[$name] = $data[$name];
            continue;
        }

        if (nu_field_server_readonly($field) || nu_field_is_fk($field)) continue;

        if ($type === 'lookup') {
            $dbCol = nu_resolve_lookup_store_col($field);
            if ($dbCol === '') continue;
            $save[$dbCol] = nu_coerce_save_value($data[$name] ?? null);
            continue;
        }

        if ($type === 'checkbox') {
            $save[$name] = !empty($data[$name]) ? 1 : 0;
        } else {
            $save[$name] = nu_coerce_save_value($data[$name] ?? null);
        }
    }

    if ($parentId !== '' && $fk !== '') {
        $save[$fk] = $parentId;
    }

    $save = nu_filter_save_to_columns($save, $table, $pk);

    $saveWithoutPk = array_filter($save, fn($k) => $k !== $pk, ARRAY_FILTER_USE_KEY);
    if (!$id && !$saveWithoutPk && !($pkType === 'uuid')) {
        nu_json(['success' => false, 'error' => 'No fields to save'], 400);
    }

    if ($id) {
        $sets = []; $params = [];
        foreach ($save as $col => $val) {
            if ($col === $pk) continue;
            $sets[] = "`{$col}` = ?"; $params[] = $val;
        }
        if (!$sets) nu_json(['success' => false, 'error' => 'No fields to update'], 400);
        $params[] = $id;
        nu_q("UPDATE `{$table}` SET " . implode(', ', $sets) . " WHERE `{$pk}` = ?", $params);
        nu_json(['success' => true, 'id' => $id]);
    } else {
        $cols = array_keys($save);
        $placeholders = array_fill(0, count($cols), '?');
        nu_q("INSERT INTO `{$table}` (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', $placeholders) . ")", array_values($save));
        $newId = ($pkType === 'uuid') ? ($save[$pk] ?? nu_db()->lastInsertId()) : nu_db()->lastInsertId();
        nu_json(['success' => true, 'id' => $newId]);
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

/* ── Standard handlers ───────────────────────────────────────────── */

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
    $fields = nu_flatten_layout(nu_decode_layout($form));
    $out    = [];
    foreach ($fields as $field) {
        $out[] = [
            'fieldname'       => nu_field_name($field),
            'fieldlabel'      => nu_field_label($field),
            'fieldtype'       => nu_field_type($field),
            'is_fk'           => nu_field_is_fk($field),
            'hide_in_grid'    => nu_field_hide_in_grid($field),
            'server_readonly' => nu_field_server_readonly($field),
        ];
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
        if (!$fields) { foreach (nu_flatten_layout($layout) as $field) { $fname = nu_safe_ident(nu_field_name($field)); if ($fname !== '') $fields[] = $fname; } }
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

    $pk     = nu_get_pk($table);
    $pkType = nu_form_pk_type($form);
    $fields = nu_flatten_layout(nu_decode_layout($form));

    $save = [];

    if (!$id && $pkType === 'uuid') {
        $save[$pk] = nu_generate_uuid();
    }

    foreach ($fields as $field) {
        $name = nu_safe_ident(nu_field_name($field));
        if ($name === '') continue;
        $type = nu_field_type($field);
        if (in_array($type, ['html','heading','divider','fieldset','subform','button'], true)) continue;

        if ($type === 'uuid') {
            if (!$id) continue;
            if (!empty($data[$name])) $save[$name] = $data[$name];
            continue;
        }

        if (nu_field_server_readonly($field)) continue;

        if ($type === 'lookup') {
            $dbCol = nu_resolve_lookup_store_col($field);
            if ($dbCol === '') continue;
            $save[$dbCol] = nu_coerce_save_value($data[$name] ?? null);
            continue;
        }

        if ($type === 'checkbox') {
            $save[$name] = !empty($data[$name]) ? 1 : 0;
        } else {
            $save[$name] = nu_coerce_save_value($data[$name] ?? null);
        }
    }

    $save = nu_filter_save_to_columns($save, $table, $pk);

    $saveWithoutPk = array_filter($save, fn($k) => $k !== $pk, ARRAY_FILTER_USE_KEY);
    if (!$id && empty($saveWithoutPk) && $pkType !== 'uuid') {
        nu_json(['success' => false, 'error' => 'No fields to save'], 400);
    }

    if ($id) {
        $sets = []; $params = [];
        foreach ($save as $col => $val) {
            if ($col === $pk) continue;
            $sets[] = "`{$col}` = ?"; $params[] = $val;
        }
        if (!$sets) nu_json(['success' => false, 'error' => 'No fields to update'], 400);
        $params[] = $id;
        nu_q("UPDATE `{$table}` SET " . implode(', ', $sets) . " WHERE `{$pk}` = ?", $params);
        nu_json(['success' => true, 'id' => $id]);
    } else {
        $cols = array_keys($save);
        $placeholders = array_fill(0, count($cols), '?');
        nu_q("INSERT INTO `{$table}` (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', $placeholders) . ")", array_values($save));
        $newId = ($pkType === 'uuid') ? ($save[$pk] ?? nu_db()->lastInsertId()) : nu_db()->lastInsertId();
        nu_json(['success' => true, 'id' => $newId]);
    }
}

/* ── Router ────────────────────────────────────────── */
try {
    $action = $_GET['action'] ?? '';
    switch ($action) {
        case 'render':           nu_handle_render();          break;
        case 'fields':           nu_handle_fields();          break;
        case 'events':           nu_handle_events();          break;
        case 'list':             nu_handle_list();            break;
        case 'save':             nu_handle_save();            break;
        case 'subform_fields':   nu_handle_subform_fields();  break;
        case 'subform_list':     nu_handle_subform_list();    break;
        case 'subform_save':     nu_handle_subform_save();    break;
        case 'subform_delete':   nu_handle_subform_delete();  break;
        default: nu_json(['success' => false, 'error' => 'Invalid action'], 400);
    }
} catch (Throwable $e) {
    nu_json(['success' => false, 'error' => $e->getMessage()], 500);
}
