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
        $msg = $error['message'] . ' in ' . basename($error['file']) . ':' . $error['line'];
        error_log('[NuForm FATAL] ' . $msg);
        echo json_encode(['success' => false, 'error' => $msg]);
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

function nu_log($message, $context = '') {
    $prefix = '[NuForm' . ($context !== '' ? ':' . $context : '') . '] ';
    error_log($prefix . $message);
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

function nu_get_table_columns($table) {
    static $cache = [];
    if (isset($GLOBALS['_nu_col_cache'][$table])) {
        $cache[$table] = $GLOBALS['_nu_col_cache'][$table];
        unset($GLOBALS['_nu_col_cache'][$table]);
    }
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

function nu_filter_save_to_columns($save, $table, $pk) {
    $cols = nu_get_table_columns($table);
    if (empty($cols)) return $save;
    $out = [];
    foreach ($save as $col => $val) {
        if ($col === $pk || isset($cols[$col])) $out[$col] = $val;
    }
    return $out;
}

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
            'pk_type'=>'form_pk_type','table_mode'=>'form_table_mode',
            'type'=>'form_type','display_mode'=>'browse_display_mode',
            'js_before_save'=>'formjsbeforesave','js_after_save'=>'formjsaftersave',
            'browse_php'=>'browsephp'];
    }
    return ['id'=>'form_id','name'=>'form_name','code'=>'form_code','table'=>'form_table',
        'layout'=>'form_layout','active'=>'form_active','custom_js'=>'form_custom_js',
        'custom_php'=>'form_custom_php','custom_css'=>'form_custom_css','browse_sql'=>'browse_sql',
        'browse_columns'=>'browse_columns','browse_search_enabled'=>'browse_search_enabled',
        'browse_search_placeholder'=>'browse_search_placeholder','browse_search_fields'=>'browse_search_fields',
        'browse_page_size'=>'browse_page_size','browse_default_sort'=>'browse_default_sort',
        'pk_type'=>'form_pk_type','table_mode'=>'form_table_mode',
        'type'=>'form_type','display_mode'=>'browse_display_mode',
        'js_before_save'=>'form_js_before_save','js_after_save'=>'form_js_after_save',
        'browse_php'=>'browse_php'];
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

// ── helpers for field flags ────────────────────────────────────────────────
function nu_field_readonly($field)               { return !empty($field['readonly']); }
function nu_field_hidden($field)                 { return !empty($field['hidden']); }
function nu_field_hidden_for_normal_users($field) { return !empty($field['hidden_for_normal_users']); }
function nu_field_no_duplicate($field)           { return !empty($field['no_duplicate']); }

/**
 * Returns true if the current user is an admin/globeadmin.
 * Tries NuAuth first, then falls back to $_SESSION directly.
 */
function nu_current_user_is_admin() {
    static $result = null;
    if ($result !== null) return $result;
    try {
        // 1. Try NuAuth
        if (class_exists('NuAuth')) {
            $auth = NuAuth::getInstance();
            if ($auth->checkAuth()) {
                $user = $auth->getCurrentUser();
                if (is_array($user)) {
                    $role = strtolower((string)($user['usr_role'] ?? ($user['role'] ?? ($user['access_level'] ?? ''))));
                    $result = in_array($role, ['admin', 'globeadmin'], true);
                    return $result;
                }
            }
        }
        // 2. Fallback: check $_SESSION directly
        $sessionRole = strtolower((string)(
            $_SESSION['usr_role'] ?? $_SESSION['role'] ?? $_SESSION['access_level'] ?? ''
        ));
        $result = in_array($sessionRole, ['admin', 'globeadmin'], true);
    } catch (Throwable $e) {
        $result = false;
    }
    return $result;
}

function nu_render_field($field, $value = '', $record = []) {
    $type  = nu_field_type($field);
    $name  = nu_field_name($field);
    $label = nu_field_label($field);

    if ($name === '' && !in_array($type, ['html','content','button','fieldset'], true)) return '';

    // ── hidden_for_normal_users — skip rendering for non-admins ────────────
    if (nu_field_hidden_for_normal_users($field) && !nu_current_user_is_admin()) {
        if ($name !== '') {
            return '<input type="hidden" data-field="' . nu_attr($name) . '" name="' . nu_attr($name) . '" value="' . nu_attr($value) . '">';
        }
        return '';
    }

    // ── hidden flag — render as hidden input, show nothing ─────────────────
    if (nu_field_hidden($field)) {
        if ($name !== '') {
            return '<input type="hidden" data-field="' . nu_attr($name) . '" name="' . nu_attr($name) . '" value="' . nu_attr($value) . '">';
        }
        return '';
    }

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

    // ── apply readonly flag to field rendering ──────────────────────────────
    $isReadonly   = nu_field_readonly($field);
    $readonlyAttr = $isReadonly ? ' readonly' : '';
    $readonlyStyle = $isReadonly ? 'background:var(--bg-offset,#f5f5f5);color:#888;cursor:default;' : '';

    $fullWidthTypes = ['subform', 'html', 'content', 'button', 'fieldset', 'checkbox'];

    if (in_array($type, $fullWidthTypes, true)) {

        if ($type === 'checkbox') {
            // readonly checkbox: disable interaction
            $checkedAttr  = !empty($value) ? ' checked' : '';
            $disabledAttr = $isReadonly ? ' disabled' : '';
            $control = '<div class="nu-field-wrapper" data-field="' . nu_attr($name) . '"'
                     . ' style="grid-column:span ' . $col . ';min-width:0;padding:6px 0;">'
                     . '<label style="display:inline-flex;align-items:center;gap:8px;cursor:' . ($isReadonly ? 'default' : 'pointer') . ';">'
                     . '<input type="hidden" name="' . nu_attr($name) . '" value="0">'
                     . '<input type="checkbox" data-field="' . nu_attr($name) . '" name="' . nu_attr($name) . '" value="1"' . $checkedAttr . $disabledAttr . ' style="width:15px;height:15px;">'
                     . '<span style="font-weight:600;font-size:13px;">' . nu_html($label) . '</span>'
                     . '</label>'
                     . $helpHtml
                     . '</div>';
            return $control;
        }

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

        if ($type === 'html' || $type === 'content') {
            $htmlContent = $field['html_content'] ?? ($field['default_value'] ?? '');
            return '<div class="nu-field-wrapper"'
                 . ' style="grid-column:span ' . $col . ';min-width:0;">'
                 . '<div>' . $htmlContent . '</div>'
                 . '</div>';
        }

        if ($type === 'button') {
            $action  = $field['button_action'] ?? '';
            $onclick = $action !== '' ? ' onclick="' . nu_attr($action) . '"' : '';
            return '<div class="nu-field-wrapper"'
                 . ' style="grid-column:span ' . $col . ';min-width:0;padding:4px 0;">'
                 . '<button type="button" class="nu-btn nu-btn-primary"' . $onclick . '>' . nu_html($label) . '</button>'
                 . '</div>';
        }

        if ($type === 'fieldset') {
            $legend = $field['legend'] ?? $label;
            return '<fieldset style="grid-column:span ' . $col . ';margin-bottom:16px;padding:12px;border:1px solid #ddd;border-radius:8px;">'
                 . '<legend>' . nu_html($legend) . '</legend>'
                 . '</fieldset>';
        }
    }

    if ($type === 'hidden') {
        return '<input type="hidden" data-field="' . nu_attr($name) . '" name="' . nu_attr($name) . '" value="' . nu_attr($value) . '">';
    }

    // ── uuid — always build $control first, then fall through to wrapper ────
    if ($type === 'uuid') {
        if ($value !== '' && $value !== null) {
            $control = '<input type="text" class="' . nu_attr($cssClass) . '" value="' . nu_attr($value) . '" readonly style="background:var(--bg-offset,#f5f5f5);color:#888;cursor:default;width:100%;">'
                     . '<input type="hidden" data-field="' . nu_attr($name) . '" name="' . nu_attr($name) . '" value="' . nu_attr($value) . '">';
        } else {
            // New record — emit hidden only; fall through to wrapper so label is shown
            $control = '<input type="hidden" data-field="' . nu_attr($name) . '" name="' . nu_attr($name) . '" value="">';
        }
    } else {
        switch ($type) {

            case 'textarea':
                $rows    = (int)($field['rows'] ?? 3);
                $control = '<textarea class="' . nu_attr($cssClass) . '" data-field="' . nu_attr($name) . '" name="' . nu_attr($name) . '"' . $placeholder . $required . $readonlyAttr . ' rows="' . $rows . '" style="width:100%;resize:vertical;' . $readonlyStyle . '">'
                         . nu_html($value) . '</textarea>';
                break;

            case 'select2':
                $isMultiple  = !empty($field['multiple']);
                $allowClear  = isset($field['allow_clear']) ? (bool)$field['allow_clear'] : true;
                $selectMode  = $isMultiple ? 'multiple' : 'single';
                $multipleAttr = $isMultiple ? ' multiple' : '';
                $disabledAttr = $isReadonly ? ' disabled' : '';
                $control  = '<select'
                          . ' class="' . nu_attr(trim($cssClass . ' nu-select2')) . '"'
                          . ' data-field="'       . nu_attr($name)         . '"'
                          . ' name="'             . nu_attr($name)         . '"'
                          . $required
                          . $multipleAttr
                          . $disabledAttr
                          . ' data-select-type="select2"'
                          . ' data-select-mode="' . $selectMode            . '"'
                          . ' data-allow-clear="' . ($allowClear ? 'true' : 'false') . '"'
                          . ' style="width:100%;">'.
                          '<option value="">Select...</option>'
                          . nu_render_options($field, $value)
                          . '</select>';
                break;

            case 'select':
                $isMultiple  = !empty($field['multiple']);
                $useSelect2  = !empty($field['select2']);
                $allowClear  = isset($field['allow_clear']) ? (bool)$field['allow_clear'] : true;
                $selectMode  = $isMultiple ? 'multiple' : 'single';
                $multipleAttr = $isMultiple ? ' multiple' : '';
                $disabledAttr = $isReadonly ? ' disabled' : '';
                $s2Class  = $useSelect2 ? ' nu-select2' : '';
                $s2Attrs  = $useSelect2
                    ? ' data-select-type="select2"'
                    . ' data-select-mode="' . $selectMode . '"'
                    . ' data-allow-clear="' . ($allowClear ? 'true' : 'false') . '"'
                    : '';
                $control  = '<select'
                          . ' class="' . nu_attr(trim($cssClass . $s2Class)) . '"'
                          . ' data-field="' . nu_attr($name) . '"'
                          . ' name="'       . nu_attr($name) . '"'
                          . $required
                          . $multipleAttr
                          . $disabledAttr
                          . $s2Attrs
                          . ' style="width:100%;">'
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
                $disabledAttr = $isReadonly ? ' disabled' : '';
                $control = '<div style="display:flex;flex-wrap:wrap;gap:8px 16px;padding-top:4px;">';
                foreach ($options as $opt) {
                    $optValue = (string)($opt['value'] ?? '');
                    $optLabel = (string)($opt['label'] ?? $optValue);
                    $checked  = in_array($optValue, $currentVals, true) ? ' checked' : '';
                    $iname    = ($type === 'radio') ? $name : $name . '[]';
                    $control .= '<label style="display:inline-flex;align-items:center;gap:5px;font-size:13px;cursor:' . ($isReadonly ? 'default' : 'pointer') . ';">'
                              . '<input type="' . $inputType . '" data-field="' . nu_attr($name) . '" name="' . nu_attr($iname) . '" value="' . nu_attr($optValue) . '"' . $checked . $disabledAttr . '>'
                              . nu_html($optLabel) . '</label>';
                }
                $control .= '</div>';
                break;

            case 'date':
                $control = '<input type="date" class="' . nu_attr($cssClass) . '" data-field="' . nu_attr($name) . '" name="' . nu_attr($name) . '" value="' . nu_attr($value) . '"' . $required . $readonlyAttr . ' style="width:100%;' . $readonlyStyle . '">';
                break;

            case 'time':
                $control = '<input type="time" class="' . nu_attr($cssClass) . '" data-field="' . nu_attr($name) . '" name="' . nu_attr($name) . '" value="' . nu_attr($value) . '"' . $required . $readonlyAttr . ' style="width:100%;' . $readonlyStyle . '">';
                break;

            case 'datetime':
                $v       = $value ? str_replace(' ', 'T', substr((string)$value, 0, 16)) : '';
                $control = '<input type="datetime-local" class="' . nu_attr($cssClass) . '" data-field="' . nu_attr($name) . '" name="' . nu_attr($name) . '" value="' . nu_attr($v) . '"' . $required . $readonlyAttr . ' style="width:100%;' . $readonlyStyle . '">';
                break;

            case 'number':
                $min  = isset($field['min'])  && $field['min']  !== '' ? ' min="'  . nu_attr($field['min'])  . '"' : '';
                $max  = isset($field['max'])  && $field['max']  !== '' ? ' max="'  . nu_attr($field['max'])  . '"' : '';
                $step = isset($field['step']) && $field['step'] !== '' ? ' step="' . nu_attr($field['step']) . '"' : '';
                $control = '<input type="number" class="' . nu_attr($cssClass) . '" data-field="' . nu_attr($name) . '" name="' . nu_attr($name) . '" value="' . nu_attr($value) . '"' . $placeholder . $required . $min . $max . $step . $readonlyAttr . ' style="width:100%;' . $readonlyStyle . '">';
                break;

            case 'email':
                $control = '<input type="email" class="' . nu_attr($cssClass) . '" data-field="' . nu_attr($name) . '" name="' . nu_attr($name) . '" value="' . nu_attr($value) . '"' . $placeholder . $required . $readonlyAttr . ' style="width:100%;' . $readonlyStyle . '">';
                break;

            case 'password':
                $control = '<input type="password" class="' . nu_attr($cssClass) . '" data-field="' . nu_attr($name) . '"' . $placeholder . $required . $readonlyAttr . ' style="width:100%;' . $readonlyStyle . '">';
                break;

            case 'file':
            case 'image':
                $accept   = !empty($field['accept'])          ? ' accept="' . nu_attr($field['accept']) . '"' : ($type === 'image' ? ' accept="image/*"' : '');
                $multiple = !empty($field['multiple_upload']) ? ' multiple' : '';
                $disabledAttr = $isReadonly ? ' disabled' : '';
                $control  = '<input type="file" class="' . nu_attr($cssClass) . '" data-field="' . nu_attr($name) . '" name="' . nu_attr($name) . '"' . $accept . $multiple . $disabledAttr . ' style="width:100%;">';
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
                if ($isReadonly) {
                    $control = '<div style="display:flex;gap:6px;align-items:center;">'
                        . '<input type="hidden" data-field="' . nu_attr($name) . '" name="' . nu_attr($name) . '" value="' . nu_attr($value) . '">'
                        . '<input type="text" class="' . nu_attr($cssClass) . '" value="' . nu_attr($displayVal) . '" readonly style="flex:1;background:var(--bg-offset,#f5f5f5);color:#888;cursor:default;">'
                        . '</div>';
                } else {
                    $control = '<div style="display:flex;gap:6px;align-items:center;">'
                        . '<input type="hidden" data-field="' . nu_attr($name) . '" name="' . nu_attr($name) . '" value="' . nu_attr($value) . '">'
                        . '<input type="text" class="' . nu_attr($cssClass) . '" data-field="' . nu_attr($name) . '_display" value="' . nu_attr($displayVal) . '" readonly style="flex:1;">'
                        . '<button type="button" class="nu-btn nu-btn-ghost" style="white-space:nowrap;" onclick="openLookupModal('
                        . '\'' . addslashes($name) . '\',\'' . addslashes($lTable) . '\',\'' . addslashes($lIdCol) . '\',\'' . addslashes($lDisplayCol) . '\',\'' . addslashes($lFilter) . '\',\'' . addslashes($lExtra) . '\''
                        . ')">&#x1F50D;</button>'
                        . '<button type="button" class="nu-btn nu-btn-ghost" onclick="clearLookup(\'' . addslashes($name) . '\')">&#x2715;</button>'
                        . '</div>';
                }
                break;

            case 'calculated':
                $expr    = $field['calculated'] ?? '';
                $control = '<input type="text" class="' . nu_attr($cssClass) . '" data-field="' . nu_attr($name) . '" data-calculated="true" data-expression="' . nu_attr($expr) . '" name="' . nu_attr($name) . '" value="' . nu_attr($value) . '" readonly style="width:100%;background:var(--bg-offset,#f5f5f5);color:#888;">';
                break;

            default:
                $control = '<input type="text" class="' . nu_attr($cssClass) . '" data-field="' . nu_attr($name) . '" name="' . nu_attr($name) . '" value="' . nu_attr($value) . '"' . $placeholder . $required . $readonlyAttr . ' style="width:100%;' . $readonlyStyle . '">';
                break;
        }
    }

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
    
    if ($type === 'tab') {
        return nu_render_tab_container($node, $record);
    }


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

function nu_render_tab_container(array $item, array $record) {
    $tabs = $item['tabs'] ?? [];
    if (empty($tabs)) return '';

    static $tcIdx = 0;
    $tcIdx++;
    $containerId = 'nu-tabs-tc' . $tcIdx;

    $html  = '<div class="nu-tabs" id="' . nu_attr($containerId) . '" style="margin-bottom:16px;">';
    $html .= '<div class="nu-tab-bar" role="tablist" style="display:flex;flex-wrap:wrap;gap:0;border-bottom:2px solid var(--color-primary,#4f6bed);padding:0 4px;">';

    foreach ($tabs as $i => $tab) {
        $tabLabel = $tab['name'] ?? ($tab['label'] ?? ('Tab ' . ($i + 1)));
        $tabId    = 'tab_' . $tcIdx . '_' . $i;
        $active   = $i === 0;
        $html .= '<button type="button"'
               . ' class="nu-tab-btn' . ($active ? ' nu-tab-active' : '') . '"'
               . ' role="tab"'
               . ' data-tab="' . nu_attr($tabId) . '"'
               . ' data-tabs-container="' . nu_attr($containerId) . '"'
               . ' aria-selected="' . ($active ? 'true' : 'false') . '"'
               . ' onclick="nuTabSwitch(this)"'
               . ' style="padding:6px 16px;font-size:13px;border:1px solid transparent;'
               . 'border-radius:6px 6px 0 0;margin-bottom:-2px;cursor:pointer;background:' . ($active ? '#fff' : 'none') . ';'
               . 'border-color:' . ($active ? 'var(--color-primary,#4f6bed) var(--color-primary,#4f6bed) #fff' : 'transparent') . ';'
               . 'font-weight:' . ($active ? '600' : '400') . ';'
               . 'color:' . ($active ? 'var(--color-primary,#4f6bed)' : '#555') . ';"'
               . '>' . nu_html($tabLabel) . '</button>';
    }
    $html .= '</div>';

    $ROW_STYLE = 'display:grid;grid-template-columns:repeat(12,1fr);gap:8px;margin-bottom:4px;align-items:start;';

    foreach ($tabs as $i => $tab) {
        $tabId  = 'tab_' . $tcIdx . '_' . $i;
        $hidden = $i !== 0;

        $html .= '<div'
               . ' id="' . nu_attr($containerId) . '-panel-' . nu_attr($tabId) . '"'
               . ' class="nu-tab-panel"'
               . ' role="tabpanel"'
               . ' data-tab="' . nu_attr($tabId) . '"'
               . ' style="padding:12px 4px;' . ($hidden ? 'display:none;' : '') . '"'
               . '>';

        $rows = $tab['rows'] ?? [];
        foreach ($rows as $rowIdx => $row) {
            // A row can be a plain row { fields: [...] } or a group { type: "group", rows: [...] }
            $rowType = $row['type'] ?? 'row';

            if ($rowType === 'group') {
                // Render as a group section
                $groupLabel = $row['label'] ?? 'Group';
                $html .= '<div style="border:1px solid #ddd;border-radius:8px;margin-bottom:12px;overflow:hidden;">';
                $html .= '<div style="padding:8px 14px;background:var(--bg-elevated,#f8f9fa);border-bottom:1px solid #ddd;font-weight:600;font-size:13px;">' . nu_html($groupLabel) . '</div>';
                $html .= '<div style="padding:10px 8px;">';
                foreach (($row['rows'] ?? []) as $innerRow) {
                    $innerFields = $innerRow['fields'] ?? [];
                    if (empty($innerFields)) continue;
                    $html .= '<div class="nu-form-row" style="' . $ROW_STYLE . '">';
                    foreach ($innerFields as $field) {
                        $html .= nu_render_field($field, nu_field_value($record, $field), $record);
                    }
                    $html .= '</div>';
                }
                $html .= '</div></div>';
            } else {
                // Plain row with fields
                $fields = $row['fields'] ?? [];
                if (empty($fields)) continue;
                $html .= '<div class="nu-form-row" style="' . $ROW_STYLE . '">';
                foreach ($fields as $field) {
                    $html .= nu_render_field($field, nu_field_value($record, $field), $record);
                }
                $html .= '</div>';
            }
        }

        $html .= '</div>';
    }

    $html .= '</div>';

    // Tab switch script (only once per page)
    static $tabScriptEmitted = false;
    if (!$tabScriptEmitted) {
        $tabScriptEmitted = true;
        $html .= '<script>if(!window.nuTabSwitch){window.nuTabSwitch=function(btn){'
               . 'var cid=btn.getAttribute("data-tabs-container");'
               . 'var tid=btn.getAttribute("data-tab");'
               . 'var wrap=document.getElementById(cid);'
               . 'if(!wrap)return;'
               . 'wrap.querySelectorAll(".nu-tab-btn").forEach(function(b){'
               . 'var on=b.getAttribute("data-tab")===tid;'
               . 'b.classList.toggle("nu-tab-active",on);'
               . 'b.setAttribute("aria-selected",on?"true":"false");'
               . 'b.style.background=on?"#fff":"none";'
               . 'b.style.borderColor=on?"var(--color-primary,#4f6bed) var(--color-primary,#4f6bed) #fff":"transparent";'
               . 'b.style.fontWeight=on?"600":"400";'
               . 'b.style.color=on?"var(--color-primary,#4f6bed)":"#555";'
               . '});'
               . 'wrap.querySelectorAll(".nu-tab-panel").forEach(function(p){'
               . 'var on=p.getAttribute("data-tab")===tid;'
               . 'p.style.display=on?"":"none";'
               . '});'
               . '}}</script>';
    }

    return $html;
}

function nu_render_form_html($form, $record = [], $recordId = null) {
    $c        = nu_form_columns();
    $layout   = nu_decode_layout($form);
    $formCode = $form[$c['code']]  ?? '';
    $formTable= $form[$c['table']] ?? '';

    $layout = nu_inject_parent_context($layout, $formTable, (string)($recordId ?? ''));

    $customPhp = trim((string)($form[$c['custom_php']] ?? ''));
    if ($customPhp !== '') {
        try {
            $data = $record;
            eval($customPhp);
            if (is_array($data)) $record = array_merge($record, $data);
        } catch (Throwable $e) {
            nu_log('custom_php error in form ' . $formCode . ': ' . $e->getMessage(), 'render');
            $layout[] = ['type' => 'html', 'html_content' => '<!-- custom_php error: ' . htmlspecialchars($e->getMessage()) . ' -->', 'col' => 12];
        }
    }

    $html  = '<form class="nu-generated-form"'
           . ' data-form-code="'  . nu_attr($formCode)   . '"'
           . ' data-table="'      . nu_attr($formTable)  . '"'
           . ' data-record-id="'  . nu_attr((string)$recordId) . '"'
           . ' data-is-new="'     . ($recordId ? '0' : '1') . '"'
           . ' onsubmit="event.preventDefault(); submitNuForm(this);"'
           . ' style="font-size:13px;">';

    $customCss = trim((string)($form[$c['custom_css']] ?? ''));
    if ($customCss !== '') {
        $scopeId = 'nu-form-' . preg_replace('/[^a-zA-Z0-9_-]/', '-', $formCode);
        $html .= '<style id="' . nu_attr($scopeId) . '-styles">'
              . '.nu-generated-form[data-form-code="' . addslashes($formCode) . '"] {'
              . '/* custom css wrapper */'
              . '}'
              . $customCss
              . '</style>';
    }

    $structuredNodes = [];
    $flatByRow       = [];
    $flatNoRow       = [];

    foreach ($layout as $node) {
        $type = $node['type'] ?? 'field';
        if (in_array($type, ['section', 'group', 'row', 'tab'], true)) {
            if ($flatNoRow || $flatByRow) {
                $structuredNodes[] = ['_flat_flush' => true, 'byRow' => $flatByRow, 'noRow' => $flatNoRow];
                $flatByRow = [];
                $flatNoRow = [];
            }
            $structuredNodes[] = ['_structured' => true, 'node' => $node];
        } else {
            $ri = $node['row_index'] ?? null;
            if ($ri !== null) {
                $flatByRow[(string)$ri][] = $node;
            } else {
                $flatNoRow[] = $node;
            }
        }
    }
    if ($flatNoRow || $flatByRow) {
        $structuredNodes[] = ['_flat_flush' => true, 'byRow' => $flatByRow, 'noRow' => $flatNoRow];
    }

    $ROW_STYLE = 'display:grid;grid-template-columns:repeat(12,1fr);gap:8px;margin-bottom:4px;align-items:start;';

    $si = 0;
    foreach ($structuredNodes as $entry) {
        if (!empty($entry['_structured'])) {
            $html .= nu_render_layout_node($entry['node'], $record, $si++);
        } elseif (!empty($entry['_flat_flush'])) {
            ksort($entry['byRow']);
            foreach ($entry['byRow'] as $rowFields) {
                $html .= '<div class="nu-form-row" style="' . $ROW_STYLE . '">';
                foreach ($rowFields as $node) {
                    $html .= nu_render_field($node, nu_field_value($record, $node), $node);
                }
                $html .= '</div>';
            }
            foreach ($entry['noRow'] as $node) {
                $html .= '<div class="nu-form-row" style="' . $ROW_STYLE . '">';
                $html .= nu_render_field($node, nu_field_value($record, $node), $node);
                $html .= '</div>';
            }
        }
    }

    $html .= '<div style="display:flex;gap:8px;justify-content:flex-end;margin-top:20px;padding-top:12px;border-top:1px solid #eee;">';
    $html .= '<button type="button" class="nu-btn nu-btn-ghost" onclick="closeNuForm(this)">Cancel</button>';
    $html .= '<button type="submit" class="nu-btn nu-btn-primary">Save</button>';
    $html .= '</div></form>';

    $customJs       = trim((string)($form[$c['custom_js']] ?? ''));
    $jsBeforeSave   = trim((string)($form[$c['js_before_save']] ?? ''));
    $jsAfterSave    = trim((string)($form[$c['js_after_save']] ?? ''));

    if ($customJs !== '' || $jsBeforeSave !== '' || $jsAfterSave !== '') {
        $escapedCode = htmlspecialchars($formCode, ENT_QUOTES, 'UTF-8');
        $html .= '<script data-nu-form-hooks="' . $escapedCode . '">';
        $html .= '(function(){';
        $html .= 'var _fc=' . json_encode($formCode) . ';';

        if ($customJs !== '') {
            $html .= 'window._nuFormOnLoad=window._nuFormOnLoad||{};';
            $html .= 'window._nuFormOnLoad[_fc]=function(formEl){';
            $html .= 'var nu=window.nu||{};';
            $html .= $customJs;
            $html .= '};';
        }

        if ($jsBeforeSave !== '') {
            $html .= 'window._nuFormBeforeSave=window._nuFormBeforeSave||{};';
            $html .= 'window._nuFormBeforeSave[_fc]=function(formEl,data){';
            $html .= 'var nu=window.nu||{};';
            $html .= $jsBeforeSave;
            $html .= '};';
        }

        if ($jsAfterSave !== '') {
            $html .= 'window._nuFormAfterSave=window._nuFormAfterSave||{};';
            $html .= 'window._nuFormAfterSave[_fc]=function(formEl,result){';
            $html .= 'var nu=window.nu||{};';
            $html .= $jsAfterSave;
            $html .= '};';
        }

        if ($customJs !== '') {
            $html .= 'var _el=document.querySelector(\'form.nu-generated-form[data-form-code="\'+_fc+\'"]\');';
            $html .= 'if(_el && typeof window._nuFormOnLoad[_fc]==="function") window._nuFormOnLoad[_fc](_el);';
        }

        $html .= '})();';
        $html .= '</script>';
    }

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

function nu_flatten_fields(array $layout): array {
    $out = [];
    foreach ($layout as $node) {
        $t = $node['type'] ?? 'field';

        if (in_array($t, ['section', 'group', 'row'], true)) {
            // top-level group/section uses children[]
            foreach (nu_flatten_fields($node['children'] ?? []) as $f) $out[] = $f;
            // group inside a tab uses rows[].fields[]
            foreach (($node['rows'] ?? []) as $row) {
                foreach (nu_flatten_fields($row['fields'] ?? []) as $f) $out[] = $f;
            }

        } elseif ($t === 'tab') {
            // tab container: iterate each tab panel's rows
            foreach (($node['tabs'] ?? []) as $tab) {
                foreach (($tab['rows'] ?? []) as $row) {
                    $rowType = $row['type'] ?? 'row';
                    if ($rowType === 'group') {
                        // group inside tab panel
                        foreach (($row['rows'] ?? []) as $innerRow) {
                            foreach (nu_flatten_fields($innerRow['fields'] ?? []) as $f) $out[] = $f;
                        }
                    } else {
                        // plain row inside tab panel
                        foreach (nu_flatten_fields($row['fields'] ?? []) as $f) $out[] = $f;
                    }
                }
            }

        } else {
            $out[] = $node;
        }
    }
    return $out;
}


/**
 * Flattens a nested form layout (groups, tabs, rows) into a single
 * flat array of field definitions, used when rendering/saving a form.
 *
 * @param  array $layout  The parsed form_layout JSON array
 * @return array          Flat list of field definition arrays
 */
function nu_flatten_layout(array $layout): array {
    $fields = [];

    foreach ($layout as $item) {
        $type = $item['type'] ?? 'text';

        if ($type === 'group') {
            // Group contains rows → rows contain fields
            foreach (($item['rows'] ?? []) as $row) {
                foreach (($row['fields'] ?? []) as $field) {
                    $fields[] = $field;
                }
            }

        } elseif ($type === 'tab') {
            // Tab contains tabs → each tab contains rows → rows contain fields
            foreach (($item['tabs'] ?? []) as $tab) {
                foreach (($tab['rows'] ?? []) as $row) {
                    if (($row['type'] ?? '') === 'group') {
                        // Nested group inside a tab
                        foreach (($row['rows'] ?? []) as $innerRow) {
                            foreach (($innerRow['fields'] ?? []) as $field) {
                                $fields[] = $field;
                            }
                        }
                    } else {
                        foreach (($row['fields'] ?? []) as $field) {
                            $fields[] = $field;
                        }
                    }
                }
            }

        } else {
            // Plain field (row_index-based flat item)
            $fields[] = $item;
        }
    }

    return $fields;
}

function nu_flatten_layout_for_grid($layout) {
    return array_values(array_filter(
        nu_flatten_layout($layout),
        function ($f) { return !nu_field_hide_in_grid($f); }
    ));
}

function nu_field_type_to_sql($fieldType) {
    switch ($fieldType) {
        case 'number':     return 'DOUBLE NULL DEFAULT NULL';
        case 'date':       return 'DATE NULL DEFAULT NULL';
        case 'time':       return 'TIME NULL DEFAULT NULL';
        case 'datetime':   return 'DATETIME NULL DEFAULT NULL';
        case 'checkbox':   return 'TINYINT(1) NOT NULL DEFAULT 0';
        case 'textarea':   return 'TEXT NULL DEFAULT NULL';
        case 'uuid':       return 'VARCHAR(36) NULL DEFAULT NULL';
        default:           return 'VARCHAR(255) NULL DEFAULT NULL';
    }
}

function nu_create_form_table($tableName, $pkType, array $fields) {
    $tableName = nu_safe_ident($tableName);
    if ($tableName === '') {
        nu_log('create_form_table called with empty table name', 'DDL');
        return ['created' => false, 'error' => 'Empty table name'];
    }

    if (nu_table_exists($tableName)) {
        nu_log('Table already exists, skipping CREATE: ' . $tableName, 'DDL');
        return ['created' => false, 'error' => null];
    }

    $pkTypeLower = strtolower(trim($pkType));
    if ($pkTypeLower === 'uuid') {
        $pkDef = '`id` VARCHAR(36) NOT NULL PRIMARY KEY';
    } else {
        $pkDef = '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
    }

    $colDefs = [$pkDef];
    $added   = ['id' => true];

    foreach ($fields as $field) {
        $name = nu_safe_ident(nu_field_name($field));
        $type = nu_field_type($field);
        if ($name === '' || isset($added[$name])) continue;
        if (in_array($type, ['html','content','button','fieldset','subform','heading','divider'], true)) continue;

        if ($type === 'lookup') {
            $dbCol = nu_safe_ident(nu_resolve_lookup_store_col($field));
            if ($dbCol === '' || isset($added[$dbCol])) continue;
            $name = $dbCol;
        }

        $colDefs[] = '`' . $name . '` ' . nu_field_type_to_sql($type);
        $added[$name] = true;
    }

    $sql = 'CREATE TABLE `' . $tableName . '` (' . implode(', ', $colDefs) . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    nu_log('Creating table: ' . $tableName . ' | SQL: ' . $sql, 'DDL');

    try {
        nu_db()->exec($sql);
        nu_log('Table created successfully: ' . $tableName, 'DDL');
        return ['created' => true, 'error' => null];
    } catch (Throwable $e) {
        nu_log('CREATE TABLE failed for ' . $tableName . ': ' . $e->getMessage(), 'DDL');
        return ['created' => false, 'error' => $e->getMessage()];
    }
}

function nu_sync_form_table_columns($tableName, array $fields) {
    $tableName = nu_safe_ident($tableName);
    if ($tableName === '' || !nu_table_exists($tableName)) {
        nu_log('sync_columns skipped — table missing or empty name: ' . $tableName, 'DDL');
        return ['added' => [], 'errors' => []];
    }

    $existing = nu_get_table_columns($tableName);
    $added    = [];
    $errors   = [];

    foreach ($fields as $field) {
        $name = nu_safe_ident(nu_field_name($field));
        $type = nu_field_type($field);
        if ($name === '') continue;
        if (in_array($type, ['html','content','button','fieldset','subform','heading','divider'], true)) continue;

        if ($type === 'lookup') {
            $dbCol = nu_safe_ident(nu_resolve_lookup_store_col($field));
            if ($dbCol === '') continue;
            $name = $dbCol;
        }

        if (isset($existing[$name])) continue;

        $colSql = nu_field_type_to_sql($type);
        $alterSql = "ALTER TABLE `{$tableName}` ADD COLUMN `{$name}` {$colSql}";
        nu_log('Adding column: ' . $tableName . '.' . $name . ' | SQL: ' . $alterSql, 'DDL');

        try {
            nu_db()->exec($alterSql);
            $added[]         = $name;
            $existing[$name] = true;
            nu_log('Column added: ' . $tableName . '.' . $name, 'DDL');
        } catch (Throwable $e) {
            $errMsg = 'Column ' . $name . ': ' . $e->getMessage();
            $errors[] = $errMsg;
            nu_log('ALTER TABLE failed — ' . $errMsg, 'DDL');
        }
    }

    return ['added' => $added, 'errors' => $errors];
}

/* ── nuHash equivalent — session context for browse_php scripts ─── */

/**
 * Returns an associative array of current-user context, mirroring the old
 * nuHash() function. Keys match the old constants for easy migration.
 *
 * Available keys:
 *   USER_ID          — numeric or string user PK
 *   USERNAME         — login name
 *   USER_NAME        — display name
 *   ACCESS_LEVEL_CODE — role string (e.g. 'admin', 'SC', 'BSO')
 *   station          — usr_station column if it exists
 *   usr_*            — every column from the user row
 */
function nu_get_hash() {
    static $cached = null;
    if ($cached !== null) return $cached;

    $hash = [
        'USER_ID'           => '',
        'USERNAME'          => '',
        'USER_NAME'         => '',
        'ACCESS_LEVEL_CODE' => '',
        'station'           => '',
    ];

    try {
        if (!class_exists('NuAuth')) { $cached = $hash; return $hash; }
        $auth = NuAuth::getInstance();
        if (!$auth->checkAuth()) { $cached = $hash; return $hash; }
        $user = $auth->getCurrentUser();
        if (!is_array($user)) { $cached = $hash; return $hash; }

        // Merge every column from the user row into the hash
        foreach ($user as $k => $v) {
            $hash[$k] = $v;
        }

        // Normalised shortcuts
        $hash['USER_ID']           = $user['usr_id']       ?? ($user['zzzzsys_user_id'] ?? ($user['id'] ?? ''));
        $hash['USERNAME']          = $user['usr_username'] ?? ($user['username'] ?? '');
        $hash['USER_NAME']         = $user['usr_name']     ?? ($user['name']     ?? '');
        $hash['ACCESS_LEVEL_CODE'] = $user['usr_role']     ?? ($user['role']     ?? ($user['access_level'] ?? ''));
        $hash['station']           = $user['usr_station']  ?? ($user['station']  ?? '');

    } catch (Throwable $e) {
        nu_log('nu_get_hash error: ' . $e->getMessage(), 'hash');
    }

    $cached = $hash;
    return $hash;
}

/* ── Subform handlers ─────────────────────────────────────────────── */

function nu_handle_subform_fields() {
    $code = $_GET['code'] ?? '';
    if ($code === '') nu_json(['success' => false, 'error' => 'Missing code'], 400);

    $form = nu_get_form($code);
    if (!$form) nu_json(['success' => false, 'error' => 'Child form not found'], 404);

    $c      = nu_form_columns();
    $table  = nu_safe_ident($form[$c['table']] ?? '');
    $layout = nu_decode_layout($form);
    $pk     = $table !== '' ? nu_get_pk($table) : 'id';

    $allFields  = nu_flatten_layout($layout);
    $gridFields = nu_flatten_layout_for_grid($layout);

    nu_json(['success' => true, 'data' => [
        'layout'     => $gridFields,
        'all_fields' => $allFields,
        'pk'         => $pk,
    ]]);
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

        // skip readonly fields on save (both server_readonly and readonly flags)
        if (nu_field_server_readonly($field) || nu_field_is_fk($field) || nu_field_readonly($field)) continue;

        // skip no_duplicate fields on INSERT
        if (!$id && nu_field_no_duplicate($field)) continue;

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

    $saveWithoutPk = array_filter($save, function ($v, $k) use ($pk) { return $k !== $pk; }, ARRAY_FILTER_USE_BOTH);
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

/* ── Standard handlers ────────────────────────────────────────────── */

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
            'fieldname'               => nu_field_name($field),
            'fieldlabel'              => nu_field_label($field),
            'fieldtype'               => nu_field_type($field),
            'is_fk'                   => nu_field_is_fk($field),
            'hide_in_grid'            => nu_field_hide_in_grid($field),
            'server_readonly'         => nu_field_server_readonly($field),
            'readonly'                => nu_field_readonly($field),
            'hidden'                  => nu_field_hidden($field),
            'hidden_for_normal_users' => nu_field_hidden_for_normal_users($field),
            'no_duplicate'            => nu_field_no_duplicate($field),
        ];
    }
    nu_json(['success' => true, 'data' => $out]);
}

function nu_handle_events() {
    nu_json(['success' => true, 'code' => '']);
}

/**
 * nu_handle_list — browse query with optional role-based browse_php override.
 */
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
        nu_json(['success' => true, 'data' => [
            'layout'=>$layout,'records'=>[],'page'=>1,'pages'=>1,'total'=>0,
            'query'=>$q,'browsesearchenabled'=>0,'browsesearchplaceholder'=>'Search...'
        ]]);
    }

    $pageSize = (int)($form[$c['browse_page_size']] ?? 20);
    if ($pageSize < 1) $pageSize = 20;

    $pk         = nu_get_pk($table);
    $sortSql    = trim((string)($form[$c['browse_default_sort']] ?? ''));
    $defaultOrder = $sortSql !== '' ? $sortSql : "`{$pk}` DESC";

    $browsePhp = trim((string)($form[$c['browse_php']] ?? ''));
    $nuHash    = nu_get_hash();
    $nuSql     = null;
    $nuParams  = [];
    $nuWhere   = '';
    $nuOrder   = '';

    if ($browsePhp !== '') {
        try {
            eval($browsePhp);
        } catch (Throwable $e) {
            nu_log('browse_php error in form ' . $code . ': ' . $e->getMessage(), 'list');
        }
    }

    $searchEnabled = (int)($form[$c['browse_search_enabled']] ?? 0);
    $searchFields  = trim((string)($form[$c['browse_search_fields']] ?? ''));

    if ($nuSql !== null) {
        $baseSql    = $nuSql;
        $baseParams = $nuParams;

        if ($searchEnabled && $q !== '') {
            $fields = array_filter(array_map('trim', explode(',', $searchFields)));
            if (!$fields) {
                foreach (nu_flatten_layout($layout) as $field) {
                    $fname = nu_safe_ident(nu_field_name($field));
                    if ($fname !== '') $fields[] = $fname;
                }
            }
            $likes = [];
            foreach ($fields as $fn) {
                $fn = nu_safe_ident($fn);
                if ($fn === '') continue;
                $likes[] = "`{$fn}` LIKE ?";
                $baseParams[] = '%' . $q . '%';
            }
            if ($likes) {
                $baseSql = "SELECT * FROM ({$baseSql}) _nu_browse WHERE (" . implode(' OR ', $likes) . ")";
            }
        }

        $orderClause = $nuOrder !== '' ? $nuOrder : $defaultOrder;
        $total  = (int)nu_q("SELECT COUNT(*) FROM ({$baseSql}) _nu_cnt", $baseParams)->fetchColumn();
        $pages  = max(1, (int)ceil($total / $pageSize));
        if ($page > $pages) $page = $pages;
        $offset = ($page - 1) * $pageSize;
        $records = nu_q($baseSql . " ORDER BY {$orderClause} LIMIT {$pageSize} OFFSET {$offset}", $baseParams)
                       ->fetchAll(PDO::FETCH_ASSOC);

    } else {
        $where = []; $params = [];

        if ($nuWhere !== '') {
            $where[]  = '(' . $nuWhere . ')';
            $params   = array_merge($params, $nuParams);
        }

        if ($searchEnabled && $q !== '') {
            $fields = array_filter(array_map('trim', explode(',', $searchFields)));
            if (!$fields) {
                foreach (nu_flatten_layout($layout) as $field) {
                    $fname = nu_safe_ident(nu_field_name($field));
                    if ($fname !== '') $fields[] = $fname;
                }
            }
            $likes = [];
            foreach ($fields as $fn) {
                $fn = nu_safe_ident($fn);
                if ($fn === '') continue;
                $likes[] = "`{$fn}` LIKE ?";
                $params[] = '%' . $q . '%';
            }
            if ($likes) $where[] = '(' . implode(' OR ', $likes) . ')';
        }

        $whereSql    = $where ? (' WHERE ' . implode(' AND ', $where)) : '';
        $orderClause = $nuOrder !== '' ? $nuOrder : $defaultOrder;
        $total       = (int)nu_q("SELECT COUNT(*) FROM `{$table}`" . $whereSql, $params)->fetchColumn();
        $pages       = max(1, (int)ceil($total / $pageSize));
        if ($page > $pages) $page = $pages;
        $offset      = ($page - 1) * $pageSize;
        $records     = nu_q("SELECT * FROM `{$table}`" . $whereSql . " ORDER BY {$orderClause} LIMIT {$pageSize} OFFSET {$offset}", $params)
                           ->fetchAll(PDO::FETCH_ASSOC);
    }

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

        // skip readonly and hidden_for_normal_users (non-admin) on save
        if (nu_field_server_readonly($field)) continue;
        if (nu_field_readonly($field)) continue;
        if (nu_field_hidden_for_normal_users($field) && !nu_current_user_is_admin()) continue;

        // skip no_duplicate fields on INSERT
        if (!$id && nu_field_no_duplicate($field)) continue;

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

    $saveWithoutPk = array_filter($save, function ($v, $k) use ($pk) { return $k !== $pk; }, ARRAY_FILTER_USE_BOTH);
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

function nu_handle_save_form() {
    $data = nu_request_json();

    $formId   = (int)($data['form_id'] ?? 0);
    $c        = nu_form_columns();
    $ftable   = nu_form_table_name();

    $name      = trim((string)($data['form_name']   ?? ''));
    $code      = trim((string)($data['form_code']   ?? ''));
    $table     = trim((string)($data['form_table']  ?? ''));
    $layout    = $data['form_layout'] ?? [];
    $tableMode = trim((string)($data['form_table_mode'] ?? 'new'));
    $pkType    = trim((string)($data['form_pk_type'] ?? 'autoincrement'));

    nu_log('save_form called — name="' . $name . '" code="' . $code . '" table="' . $table . '" table_mode="' . $tableMode . '" pk_type="' . $pkType . '"', 'save_form');

    if ($name === '') nu_json(['success' => false, 'error' => 'form_name is required'], 400);

    if ($code === '') {
        $code = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $name));
        $code = trim($code, '_');
    }

    $save = [
        $c['name']                     => $name,
        $c['code']                     => $code,
        $c['table']                    => $table,
        $c['layout']                   => json_encode(is_array($layout) ? $layout : []),
        $c['active']                   => 1,
        $c['type']                     => $data['form_type']           ?? 'main',
        $c['display_mode']             => $data['browse_display_mode'] ?? 'inline',
        $c['pk_type']                  => $pkType,
        $c['table_mode']               => $tableMode,
        $c['custom_js']                => $data['form_custom_js']      ?? '',
        $c['js_before_save']           => $data['form_js_before_save'] ?? '',
        $c['js_after_save']            => $data['form_js_after_save']  ?? '',
        $c['custom_php']               => $data['form_custom_php']     ?? '',
        $c['custom_css']               => $data['form_custom_css']     ?? '',
        $c['browse_sql']               => $data['browse_sql']          ?? '',
        $c['browse_columns']           => $data['browse_columns']      ?? '',
        $c['browse_search_enabled']    => !empty($data['browse_search_enabled']) ? 1 : 0,
        $c['browse_search_placeholder']=> $data['browse_search_placeholder'] ?? 'Search...',
        $c['browse_search_fields']     => $data['browse_search_fields']     ?? '',
        $c['browse_page_size']         => (int)($data['browse_page_size']   ?? 20),
        $c['browse_default_sort']      => $data['browse_default_sort']      ?? '',
        $c['browse_php']               => $data['browse_php']               ?? '',
    ];

    $formTableCols = nu_get_table_columns($ftable);
    if (!empty($formTableCols)) {
        $save = array_intersect_key($save, $formTableCols);
    }

    if ($formId > 0) {
        $sets = []; $params = [];
        foreach ($save as $col => $val) {
            $sets[]   = "`{$col}` = ?";
            $params[] = $val;
        }
        $params[] = $formId;
        $pkCol = $c['id'];
        nu_q("UPDATE `{$ftable}` SET " . implode(', ', $sets) . " WHERE `{$pkCol}` = ?", $params);
        nu_log('Updated form row id=' . $formId, 'save_form');
    } else {
        $cols         = array_keys($save);
        $placeholders = array_fill(0, count($cols), '?');
        nu_q(
            "INSERT INTO `{$ftable}` (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', $placeholders) . ")",
            array_values($save)
        );
        $formId = (int)nu_db()->lastInsertId();
        nu_log('Inserted form row id=' . $formId, 'save_form');
    }

    $ddlResult  = ['created' => false, 'added' => [], 'errors' => []];
    $safeTable  = nu_safe_ident($table);
    $flatFields = nu_flatten_layout(is_array($layout) ? $layout : []);

    if ($safeTable === '') {
        nu_log('No form_table specified — skipping DDL for form_id=' . $formId, 'save_form');
    } elseif ($tableMode === 'existing_no_sync') {
        nu_log('table_mode=existing_no_sync — skipping DDL for table ' . $safeTable, 'save_form');
    } elseif (!nu_table_exists($safeTable)) {
        nu_log('Table does not exist, will CREATE: ' . $safeTable, 'save_form');
        $createResult = nu_create_form_table($safeTable, $pkType, $flatFields);
        $ddlResult['created'] = $createResult['created'];
        if ($createResult['error']) {
            $ddlResult['errors'][] = $createResult['error'];
            nu_log('Table creation error for ' . $safeTable . ': ' . $createResult['error'], 'save_form');
        }
    } else {
        nu_log('Table exists, syncing columns for: ' . $safeTable, 'save_form');
        $syncResult = nu_sync_form_table_columns($safeTable, $flatFields);
        $ddlResult['added']  = $syncResult['added'];
        $ddlResult['errors'] = $syncResult['errors'];
        if ($syncResult['errors']) {
            nu_log('Column sync errors for ' . $safeTable . ': ' . implode('; ', $syncResult['errors']), 'save_form');
        }
    }

    nu_json([
        'success'        => true,
        'form_id'        => $formId,
        'form_code'      => $code,
        'table_created'  => $ddlResult['created'],
        'columns_added'  => $ddlResult['added'],
        'ddl_errors'     => $ddlResult['errors'],
    ]);
}

function nu_handle_load_form() {
    $formId = (int)($_GET['form_id'] ?? 0);
    if ($formId <= 0) nu_json(['success' => false, 'error' => 'Missing form_id'], 400);

    $ftable = nu_form_table_name();
    $c      = nu_form_columns();
    $pk     = $c['id'];

    $form = nu_q("SELECT * FROM `{$ftable}` WHERE `{$pk}` = ? LIMIT 1", [$formId])
                ->fetch(PDO::FETCH_ASSOC);

    if (!$form) nu_json(['success' => false, 'error' => 'Form not found'], 404);

    nu_json(['success' => true, 'data' => [
        'form_id'                  => (int)$form[$c['id']],
        'form_name'                => $form[$c['name']]                      ?? '',
        'form_code'                => $form[$c['code']]                      ?? '',
        'form_table'               => $form[$c['table']]                     ?? '',
        'form_layout'              => json_decode($form[$c['layout']] ?? '[]', true) ?: [],
        'form_type'                => $form[$c['type']]                      ?? 'main',
        'browse_display_mode'      => $form[$c['display_mode']]              ?? 'inline',
        'form_pk_type'             => $form[$c['pk_type']]                   ?? 'autoincrement',
        'form_table_mode'          => $form[$c['table_mode']]                ?? 'new',
        'form_custom_js'           => $form[$c['custom_js']]                 ?? '',
        'form_js_before_save'      => $form[$c['js_before_save']]            ?? '',
        'form_js_after_save'       => $form[$c['js_after_save']]             ?? '',
        'form_custom_php'          => $form[$c['custom_php']]                ?? '',
        'form_custom_css'          => $form[$c['custom_css']]                ?? '',
        'browse_sql'               => $form[$c['browse_sql']]                ?? '',
        'browse_columns'           => $form[$c['browse_columns']]            ?? '',
        'browse_search_enabled'    => (int)($form[$c['browse_search_enabled']]  ?? 0),
        'browse_search_placeholder'=> $form[$c['browse_search_placeholder']]  ?? 'Search...',
        'browse_search_fields'     => $form[$c['browse_search_fields']]      ?? '',
        'browse_page_size'         => (int)($form[$c['browse_page_size']]    ?? 20),
        'browse_default_sort'      => $form[$c['browse_default_sort']]       ?? '',
        'browse_php'               => $form[$c['browse_php']]                ?? '',
    ]]);
}

/* ── Router ─────────────────────────────────────────── */
try {
    $action = $_GET['action'] ?? '';
    nu_log('action=' . $action, 'router');
    switch ($action) {
        case 'render':           nu_handle_render();          break;
        case 'fields':           nu_handle_fields();          break;
        case 'events':           nu_handle_events();          break;
        case 'list':             nu_handle_list();            break;
        case 'save':             nu_handle_save();            break;
        case 'save_form':        nu_handle_save_form();       break;
        case 'load_form':        nu_handle_load_form();       break;
        case 'subform_fields':   nu_handle_subform_fields();  break;
        case 'subform_list':     nu_handle_subform_list();    break;
        case 'subform_save':     nu_handle_subform_save();    break;
        case 'subform_delete':   nu_handle_subform_delete();  break;
        default:
            nu_log('Unknown action: ' . $action, 'router');
            nu_json(['success' => false, 'error' => 'Invalid action'], 400);
    }
} catch (Throwable $e) {
    nu_log('Unhandled exception in action=' . ($_GET['action'] ?? '') . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(), 'router');
    nu_json(['success' => false, 'error' => $e->getMessage()], 500);
}
