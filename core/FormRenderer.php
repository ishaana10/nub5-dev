<?php
// nuBuilder Next - Runtime Form Renderer

class NuFormRenderer {
    private $db;
    private $auth;
    private $audit;

    public function __construct() {
        $this->db    = NuDatabase::getInstance();
        $this->auth  = new NuAuth();
        $this->audit = new NuAudit();
    }

    public function render($formCode, $recordId = null) {
        $form = $this->db->fetchOne(
            "SELECT * FROM nu_forms WHERE form_code = :code AND form_active = 1",
            [':code' => $formCode]
        );
        if (!$form) return '<div class="nu-toast error">Form not found</div>';

        // Permission check — view is the minimum needed to render
        if (!$this->auth->canForm($formCode, 'view')) {
            return '<div class="nu-alert nu-alert-danger">You do not have permission to view this form.</div>';
        }

        $perms  = $this->auth->formPerms($formCode);
        $layout = json_decode($form['form_layout'], true);
        if (!is_array($layout)) $layout = [];

        $record = null;
        if ($recordId && $form['form_table']) {
            $record = $this->db->fetchOne(
                "SELECT * FROM {$form['form_table']} WHERE id = :id",
                [':id' => $recordId]
            );
        }

        $isReadonly = $recordId ? !$perms['edit'] : !$perms['add'];

        $html  = '<form class="nu-generated-form nu-runtime-form" id="runtimeForm_' . $formCode . '" ';
        $html .= 'data-form-code="'   . htmlspecialchars($formCode) . '" ';
        $html .= 'data-table="'       . htmlspecialchars($form['form_table'] ?? '') . '" ';
        $html .= 'data-record-id="'   . ($recordId ?? '') . '" ';
        $html .= 'data-can-view="'    . ($perms['view']   ? '1' : '0') . '" ';
        $html .= 'data-can-add="'     . ($perms['add']    ? '1' : '0') . '" ';
        $html .= 'data-can-edit="'    . ($perms['edit']   ? '1' : '0') . '" ';
        $html .= 'data-can-delete="'  . ($perms['delete'] ? '1' : '0') . '" ';
        $html .= 'data-can-export="'  . ($perms['export'] ? '1' : '0') . '"';
        $html .= ($isReadonly ? ' data-readonly="1"' : '') . '>';

        $html .= '<div class="nu-form-grid">';
        foreach ($layout as $field) {
            $html .= $this->renderField($field, $record, $isReadonly);
        }
        $html .= '</div>';

        $html .= '<div class="nu-form-actions">';
        if (!$isReadonly) {
            $html .= '<button type="submit" class="nu-btn nu-btn-primary">Save</button>';
        }
        $html .= '<button type="button" class="nu-btn nu-btn-ghost" onclick="history.back()">Cancel</button>';
        if ($recordId && $perms['delete']) {
            $html .= '<button type="button" class="nu-btn nu-btn-danger" onclick="deleteRecord(this)">Delete</button>';
        }
        $html .= '</div>';
        $html .= '</form>';

        return $html;
    }

    private function renderField($field, $record = null, $readonly = false) {
        $type        = $field['type']        ?? 'text';
        $name        = $field['name']        ?? '';
        $label       = $field['label']       ?? $name;
        $value       = $record[$name]        ?? '';
        $required    = !empty($field['required']) ? 'required' : '';
        $placeholder = $field['placeholder'] ?? '';
        $options     = $field['options']     ?? [];
        $disabledAttr = $readonly ? 'disabled' : '';

        $html  = '<div class="nu-field nu-field-' . $type . '">';
        $html .= '<label>' . htmlspecialchars($label);
        if ($required && !$readonly) $html .= ' <span style="color:var(--danger)">*</span>';
        $html .= '</label>';

        switch ($type) {
            case 'textarea':
                $html .= '<textarea name="' . $name . '" class="nu-input" ' . $required . ' ' . $disabledAttr . ' placeholder="' . htmlspecialchars($placeholder) . '" rows="4">' . htmlspecialchars($value) . '</textarea>';
                break;

            // -------------------------------------------------------
            // Standard browser <select> — no Select2, ever.
            // Supports single and multi-select via the 'multiple' flag
            // stored in the field JSON by the form builder.
            // -------------------------------------------------------
            case 'select':
                $isMulti = !empty($field['multiple']);
                $html .= '<select name="' . $name . ($isMulti ? '[]' : '') . '"'
                       . ' class="nu-input"'
                       . ($isMulti ? ' multiple' : '')
                       . ' ' . $required
                       . ' ' . $disabledAttr . '>';
                if (!$isMulti) {
                    $html .= '<option value="">-- Select --</option>';
                }
                // Support scalar value for single, array for multi
                $selectedValues = $isMulti
                    ? (is_array($value) ? $value : (strlen($value) ? explode(',', $value) : []))
                    : [$value];
                foreach ($options as $opt) {
                    $selected = in_array($opt['value'], $selectedValues) ? 'selected' : '';
                    $html .= '<option value="' . htmlspecialchars($opt['value']) . '" ' . $selected . '>' . htmlspecialchars($opt['label']) . '</option>';
                }
                $html .= '</select>';
                break;

            // -------------------------------------------------------
            // Select2 — born as Select2, initialised once by
            // nu-select2-init.js via data-select-type="select2" and
            // the .nu-select2 class.
            //
            // Attributes emitted:
            //   data-select-type="select2"    → picked up by nuInitSelect2
            //   data-select-mode="single|multiple" → controls multiple flag
            //   data-placeholder="…"           → Select2 placeholder
            //   data-allow-clear="true|false"  → show/hide × button
            //   multiple                       → native HTML multi attr
            //
            // Field JSON keys consumed:
            //   placeholder  string
            //   allow_clear  bool   (default true)
            //   multiple     bool
            //   select_type  string ("select2" or "multiselect" — legacy)
            //   options      [{value, label}, …]
            // -------------------------------------------------------
            case 'select2':
                $s2Multiple   = !empty($field['multiple'])
                             || ($field['select_type'] ?? '') === 'multiselect';
                $s2Mode       = $s2Multiple ? 'multiple' : 'single';
                $s2Placeholder = htmlspecialchars($field['placeholder'] ?? 'Select\u2026');
                $s2AllowClear  = isset($field['allow_clear']) && $field['allow_clear'] === false
                               ? 'false' : 'true';

                $html .= '<select name="' . $name . ($s2Multiple ? '[]' : '') . '"'
                       . ' class="nu-input nu-select2"'
                       . ' data-select-type="select2"'
                       . ' data-select-mode="' . $s2Mode . '"'
                       . ' data-placeholder="' . $s2Placeholder . '"'
                       . ' data-allow-clear="'  . $s2AllowClear  . '"'
                       . ($s2Multiple ? ' multiple' : '')
                       . ' ' . $required
                       . ' ' . $disabledAttr . '>';

                // Empty first option is required by Select2 for placeholder
                if (!$s2Multiple) $html .= '<option value=""></option>';

                $selectedValues = $s2Multiple
                    ? (is_array($value) ? $value : (strlen($value) ? explode(',', $value) : []))
                    : [$value];
                foreach ($options as $opt) {
                    $selected = in_array($opt['value'], $selectedValues) ? 'selected' : '';
                    $html .= '<option value="' . htmlspecialchars($opt['value']) . '" ' . $selected . '>' . htmlspecialchars($opt['label']) . '</option>';
                }
                $html .= '</select>';
                break;

            case 'lookup':
                $html .= $this->renderLookup($field, $value, $readonly);
                break;

            case 'file':
                if (!$readonly) $html .= '<input type="file" name="' . $name . '" class="nu-input" ' . $required . '>';
                if ($value) {
                    $html .= '<div style="margin-top:6px;font-size:12px;"><a href="uploads/' . htmlspecialchars($value) . '" target="_blank">View current file</a></div>';
                }
                break;

            case 'date':
                $html .= '<input type="date" name="' . $name . '" class="nu-input" value="' . htmlspecialchars($value) . '" ' . $required . ' ' . $disabledAttr . '>';
                break;

            case 'datetime':
                $html .= '<input type="datetime-local" name="' . $name . '" class="nu-input" value="' . htmlspecialchars($value) . '" ' . $required . ' ' . $disabledAttr . '>';
                break;

            case 'number':
                $min  = isset($field['min'])  ? 'min="'  . $field['min']  . '"' : '';
                $max  = isset($field['max'])  ? 'max="'  . $field['max']  . '"' : '';
                $step = isset($field['step']) ? 'step="' . $field['step'] . '"' : '';
                $html .= '<input type="number" name="' . $name . '" class="nu-input" value="' . htmlspecialchars($value) . '" ' . $required . ' ' . $min . ' ' . $max . ' ' . $step . ' ' . $disabledAttr . ' placeholder="' . htmlspecialchars($placeholder) . '">';
                break;

            case 'checkbox':
                $checked = $value ? 'checked' : '';
                $html .= '<label class="nu-checkbox-label">';
                $html .= '<input type="checkbox" name="' . $name . '" value="1" ' . $checked . ' ' . $required . ' ' . $disabledAttr . '>';
                $html .= '<span>' . htmlspecialchars($label) . '</span>';
                $html .= '</label>';
                break;

            case 'subform':
                $html .= $this->renderSubform($field, $record);
                break;

            case 'repeater':
                $html .= $this->renderRepeater($field, $record);
                break;

            default:
                $inputType = in_array($type, ['email','password','url','tel']) ? $type : 'text';
                $html .= '<input type="' . $inputType . '" name="' . $name . '" class="nu-input" value="' . htmlspecialchars($value) . '" ' . $required . ' ' . $disabledAttr . ' placeholder="' . htmlspecialchars($placeholder) . '">';
        }

        if (!empty($field['help'])) {
            $html .= '<div class="nu-field-help">' . htmlspecialchars($field['help']) . '</div>';
        }
        $html .= '</div>';
        return $html;
    }

    /**
     * Render a lookup (remote-populated) <select>.
     * Add "use_select2": true in the field JSON to get a Select2-enhanced
     * lookup — it receives data-select-type="select2", .nu-select2, and
     * is initialised by nu-select2-init.js on form open.
     */
    private function renderLookup($field, $value, $readonly = false) {
        $name         = $field['name'];
        $lookup       = $field['lookup'];
        $table        = $lookup['table']          ?? '';
        $idCol        = $lookup['id_column']      ?? 'id';
        $displayCol   = $lookup['display_column'] ?? 'name';
        $disabledAttr = $readonly ? 'disabled' : '';
        $useSelect2   = !empty($field['use_select2']);
        $placeholder  = htmlspecialchars($field['placeholder'] ?? '-- Select --');

        $options = [];
        if ($table) {
            $options = $this->db->fetchAll(
                "SELECT {$idCol} as id, {$displayCol} as label FROM {$table} LIMIT 500"
            );
        }

        $cssClass = $useSelect2 ? 'nu-input nu-select2 nu-lookup' : 'nu-input nu-lookup';

        $html = '<select name="' . $name . '" class="' . $cssClass . '"';
        if ($useSelect2) {
            $html .= ' data-select-type="select2"';
            $html .= ' data-select-mode="single"';
            $html .= ' data-placeholder="' . $placeholder . '"';
            $html .= ' data-allow-clear="true"';
        }
        $html .= ' ' . $disabledAttr . '>';
        // Empty first option required for both plain select and Select2 placeholder
        $html .= '<option value="">' . ($useSelect2 ? '' : '-- Select --') . '</option>';
        foreach ($options as $opt) {
            $selected = $value == $opt['id'] ? 'selected' : '';
            $html .= '<option value="' . htmlspecialchars($opt['id']) . '" ' . $selected . '>' . htmlspecialchars($opt['label']) . '</option>';
        }
        $html .= '</select>';
        return $html;
    }

    private function renderSubform($field, $record) {
        $subformCode = $field['subform_code'] ?? '';
        $parentField = $field['parent_field'] ?? 'parent_id';
        $parentId    = $record['id'] ?? 0;
        $html  = '<div class="nu-subform" data-subform="' . $subformCode . '" data-parent-field="' . $parentField . '" data-parent-id="' . $parentId . '">';
        $html .= '<div class="nu-subform-header">';
        $html .= '<span>Subform: ' . htmlspecialchars($subformCode) . '</span>';
        $html .= '<button type="button" class="nu-btn nu-btn-ghost nu-btn-sm" onclick="loadSubform(this)">Load</button>';
        $html .= '</div><div class="nu-subform-content"></div></div>';
        return $html;
    }

    private function renderRepeater($field, $record) {
        $name = $field['name'];
        $html  = '<div class="nu-repeater" data-field="' . $name . '">';
        $html .= '<div class="nu-repeater-rows"></div>';
        $html .= '<button type="button" class="nu-btn nu-btn-ghost nu-btn-sm" onclick="addRepeaterRow(this)">+ Add Row</button>';
        $html .= '</div>';
        return $html;
    }

    public function save($formCode, $data, $recordId = null) {
        // API-level permission enforcement
        $action = $recordId ? 'edit' : 'add';
        if (!$this->auth->canForm($formCode, $action)) {
            throw new Exception('Permission denied: cannot ' . $action . ' records on ' . $formCode);
        }

        $form = $this->db->fetchOne(
            "SELECT * FROM nu_forms WHERE form_code = :code",
            [':code' => $formCode]
        );
        if (!$form || !$form['form_table']) throw new Exception('Form has no target table');

        $table  = $form['form_table'];
        $layout = json_decode($form['form_layout'], true);

        $safeData = [];
        foreach ($layout as $field) {
            $name = $field['name'] ?? '';
            if (isset($data[$name])) $safeData[$name] = $data[$name];
        }

        if ($recordId) {
            $old = $this->db->fetchOne("SELECT * FROM {$table} WHERE id = :id", [':id' => $recordId]);
            $this->db->update($table, $safeData, "id = :id", [':id' => $recordId]);
            $this->audit->log('update', $table, $recordId, $old, $safeData);
            return $recordId;
        } else {
            $id = $this->db->insert($table, $safeData);
            $this->audit->log('create', $table, $id, null, $safeData);
            return $id;
        }
    }

    public function delete($formCode, $recordId) {
        if (!$this->auth->canForm($formCode, 'delete')) {
            throw new Exception('Permission denied: cannot delete records on ' . $formCode);
        }

        $form = $this->db->fetchOne(
            "SELECT * FROM nu_forms WHERE form_code = :code",
            [':code' => $formCode]
        );
        if (!$form || !$form['form_table']) throw new Exception('Form has no target table');

        $old = $this->db->fetchOne(
            "SELECT * FROM {$form['form_table']} WHERE id = :id",
            [':id' => $recordId]
        );
        $this->db->delete($form['form_table'], "id = :id", [':id' => $recordId]);
        $this->audit->log('delete', $form['form_table'], $recordId, $old, null);
        return true;
    }

    public function browse($formCode, $page = 1, $perPage = 20, $filters = []) {
        if (!$this->auth->canForm($formCode, 'view')) {
            throw new Exception('Permission denied: cannot view ' . $formCode);
        }

        $form = $this->db->fetchOne(
            "SELECT * FROM nu_forms WHERE form_code = :code",
            [':code' => $formCode]
        );
        if (!$form || !$form['form_table']) throw new Exception('Form has no target table');

        $table   = $form['form_table'];
        $layout  = json_decode($form['form_layout'], true);
        $offset  = ($page - 1) * $perPage;

        $where  = ['1=1'];
        $params = [];
        foreach ($filters as $key => $val) {
            if ($val !== '') {
                $where[]        = "{$key} LIKE :{$key}";
                $params[":{$key}"] = '%' . $val . '%';
            }
        }

        $sql = "SELECT * FROM {$table} WHERE " . implode(' AND ', $where) . " ORDER BY id DESC LIMIT :limit OFFSET :offset";
        $params[':limit']  = (int)$perPage;
        $params[':offset'] = (int)$offset;
        $records = $this->db->fetchAll($sql, $params);

        $countSql = "SELECT COUNT(*) as total FROM {$table} WHERE " . implode(' AND ', $where);
        unset($params[':limit'], $params[':offset']);
        $total = $this->db->fetchOne($countSql, $params)['total'];

        return [
            'records' => $records,
            'total'   => $total,
            'page'    => $page,
            'per_page'=> $perPage,
            'pages'   => ceil($total / $perPage),
            'layout'  => $layout,
            'perms'   => $this->auth->formPerms($formCode),
        ];
    }
}
?>
