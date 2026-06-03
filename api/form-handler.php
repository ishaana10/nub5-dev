<?php
header('Content-Type: application/json');
require_once '../config.php';
require_once '../core/Database.php';
require_once '../core/Auth.php';
require_once '../core/EmailService.php';

$auth = new NuAuth();
if (!$auth->checkAuth()) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$action   = $_GET['action']   ?? '';
$formCode = $_GET['code']     ?? '';
$db       = NuDatabase::getInstance();

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

/**
 * Validate that a string is a safe DB table/column identifier.
 */
function isSafeIdentifier(string $name): bool {
    return (bool) preg_match('/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/', $name);
}

/**
 * Extract a flat list of field definitions from a row-based form_layout JSON.
 * Each entry has at minimum: name, type.
 */
function flatFieldsFromLayout(array $layout): array {
    $fields = [];
    foreach ($layout as $row) {
        // Support both row-based {row, cols:[...]} and flat array formats
        if (isset($row['cols']) && is_array($row['cols'])) {
            foreach ($row['cols'] as $col) {
                if (!empty($col['name'])) $fields[] = $col;
            }
        } elseif (!empty($row['name'])) {
            $fields[] = $row;
        }
    }
    return $fields;
}

function handleRender($db, $formCode, $recordId = null) {
    $form = $db->fetchOne("SELECT * FROM nu_forms WHERE form_code = ? AND form_active = 1", [$formCode]);
    if (!$form) {
        echo json_encode(['success' => false, 'error' => 'Form not found']);
        exit;
    }

    // FIX: Validate table name before interpolating into SQL
    if (!isSafeIdentifier($form['form_table'] ?? '')) {
        echo json_encode(['success' => false, 'error' => 'Invalid form table name']);
        exit;
    }

    $fields = $db->fetchAll(
        "SELECT * FROM nu_form_fields WHERE form_id = ? AND field_active = 1 ORDER BY field_order",
        [$form['form_id']]
    );

    if (empty($fields) && !empty($form['form_layout'])) {
        $layout = json_decode($form['form_layout'], true) ?: [];
        $fields = flatFieldsFromLayout($layout);
    }

    $events   = $db->fetchAll(
        "SELECT * FROM nu_form_events WHERE form_id = ? AND event_active = 1 ORDER BY event_order",
        [$form['form_id']]
    );
    $eventMap = [];
    foreach ($events as $e) {
        $eventMap[$e['event_type']] = $e['event_code'];
    }

    $record = [];
    $isNew  = true;

    if ($recordId) {
        $record = $db->fetchOne(
            "SELECT * FROM `{$form['form_table']}` WHERE id = ? AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')",
            [$recordId]
        );
        if (!$record) {
            echo json_encode(['success' => false, 'error' => 'Record not found or deleted']);
            exit;
        }
        $isNew = false;
    }

    $html = renderFormHTML($form, $fields, $eventMap, $record, $isNew, $db);
    echo json_encode(['success' => true, 'html' => $html, 'events' => $eventMap]);
}

function renderFormHTML($form, $fields, $events, $record, $isNew, $db) {
    ob_start();
    $formCode  = htmlspecialchars($form['form_code'],  ENT_QUOTES);
    $formTable = htmlspecialchars($form['form_table'], ENT_QUOTES);
    $recordId  = htmlspecialchars($record['id'] ?? '', ENT_QUOTES);
    ?>
    <form class="nu-form"
          data-form-code="<?= $formCode ?>"
          data-table="<?= $formTable ?>"
          data-record-id="<?= $recordId ?>"
          data-is-new="<?= $isNew ? '1' : '0' ?>"
          onsubmit="return false;">

        <?php
        // FIX: Custom CSS is intentionally embedded as authored styles.
        // It is only saved by admins, not end-users, so XSS risk is admin-scoped.
        // Strip closing style tags to prevent breakout.
        if (!empty($form['form_custom_css'])):
            $safeCss = str_replace(['</style>', '</STYLE>'], '', $form['form_custom_css']);
        ?>
        <style><?= $safeCss ?></style>
        <?php endif; ?>

        <div class="nu-form-fields" style="display:flex;flex-wrap:wrap;gap:12px;">
            <?php foreach ($fields as $field): ?>
                <?php renderField($field, $record, $isNew, $db); ?>
            <?php endforeach; ?>
        </div>

        <div class="nu-form-actions" style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px;">
            <button type="button" class="nu-btn nu-btn-ghost"
                    onclick="var o=this.closest('.nu-modal-overlay'); if(o) o.remove(); else history.back();">Cancel</button>
            <button type="button" class="nu-btn nu-btn-primary"
                    onclick="submitNuForm(this.closest('form'))"><?= $isNew ? 'Save' : 'Update' ?></button>
        </div>

        <script>
        (function() {
            var nu = window.nuForm;
            if (nu && typeof nu.init === 'function') {
                nu.init(
                    '<?= $formCode ?>',
                    <?= json_encode($record, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
                    <?= $isNew ? 'true' : 'false' ?>
                );
            }
            <?php if (!empty($events['js_onload'])): ?>
            try {
                <?= $events['js_onload'] ?>
            } catch(e) { console.error('[nub5] onLoad error:', e); }
            <?php endif; ?>
        })();
        </script>

        <?php if (!empty($form['form_custom_js'])): ?>
        <script>
        // Custom JS — authored by form admin
        try {
            <?= $form['form_custom_js'] ?>
        } catch(e) { console.error('[nub5] Custom JS error:', e); }
        </script>
        <?php endif; ?>
    </form>
    <?php
    return ob_get_clean();
}

function renderField($field, $record, $isNew, $db) {
    $type        = $field['field_type']          ?? $field['type']          ?? 'text';
    $name        = $field['field_name']          ?? $field['name']          ?? '';
    $label       = $field['field_label']         ?? $field['label']         ?? $name;
    $required    = ($field['field_required']     ?? $field['required']      ?? false) ? 'required' : '';
    $width       = $field['field_width']         ?? $field['width']         ?? '100%';
    $placeholder = $field['field_placeholder']   ?? $field['placeholder']   ?? '';
    $helpText    = $field['field_help_text']     ?? '';
    $calcExpr    = $field['field_calculated']    ?? '';
    $cssStyle    = $field['field_css']           ?? '';
    $defaultValue = $isNew
        ? ($field['field_default_value'] ?? $field['default_value'] ?? '')
        : ($record[htmlspecialchars($name, ENT_QUOTES)] ?? '');

    // Escape for HTML attributes
    $eName        = htmlspecialchars($name,         ENT_QUOTES);
    $eLabel       = htmlspecialchars($label,        ENT_QUOTES);
    $eWidth       = htmlspecialchars($width,        ENT_QUOTES);
    $ePlaceholder = htmlspecialchars($placeholder,  ENT_QUOTES);
    $eDefault     = htmlspecialchars((string)$defaultValue, ENT_QUOTES);
    $eCss         = $cssStyle ? ' style="' . htmlspecialchars($cssStyle, ENT_QUOTES) . '"' : '';
    $eCalc        = $calcExpr ? ' data-calculated="true" data-expression="' . htmlspecialchars($calcExpr, ENT_QUOTES) . '"' : '';

    $events = '';
    if (!empty($field['field_js_onchange'])) {
        $events .= ' onchange="' . htmlspecialchars($field['field_js_onchange'], ENT_QUOTES) . '"';
    }
    if (!empty($field['field_js_onclick'])) {
        $events .= ' onclick="' . htmlspecialchars($field['field_js_onclick'], ENT_QUOTES) . '"';
    }

    $help = $helpText
        ? '<small style="color:var(--text-secondary);display:block;margin-top:4px;font-size:12px;">' . htmlspecialchars($helpText) . '</small>'
        : '';

    echo '<div class="nu-field-wrapper" data-field="' . $eName . '" style="margin-bottom:12px;width:' . $eWidth . ';">';

    // Checkbox renders its own label inline — skip outer label for checkbox
    if ($type !== 'checkbox') {
        echo '<label style="display:block;font-size:13px;font-weight:500;margin-bottom:4px;">';
        echo htmlspecialchars($label);
        if ($required) echo ' <span style="color:var(--color-error,red);">*</span>';
        echo '</label>';
    }

    switch ($type) {
        case 'text':
        case 'email':
        case 'number':
        case 'url':
        case 'password':
            echo '<input type="' . $type . '" class="nu-input" data-field="' . $eName . '" name="' . $eName . '" value="' . $eDefault . '" placeholder="' . $ePlaceholder . '" ' . $required . $eCalc . $events . $eCss . '>';
            break;

        // FIX: Added missing datetime and time field cases
        case 'datetime':
        case 'datetime-local':
            // Normalise DB datetime (YYYY-MM-DD HH:MM:SS) to datetime-local input format
            $dtVal = $eDefault ? str_replace(' ', 'T', substr($eDefault, 0, 16)) : '';
            echo '<input type="datetime-local" class="nu-input" data-field="' . $eName . '" name="' . $eName . '" value="' . htmlspecialchars($dtVal, ENT_QUOTES) . '" ' . $required . $events . $eCss . '>';
            break;

        case 'time':
            echo '<input type="time" class="nu-input" data-field="' . $eName . '" name="' . $eName . '" value="' . $eDefault . '" ' . $required . $events . $eCss . '>';
            break;

        case 'date':
            echo '<input type="date" class="nu-input" data-field="' . $eName . '" name="' . $eName . '" value="' . $eDefault . '" ' . $required . $events . $eCss . '>';
            break;

        case 'color':
            $colorVal = $eDefault ?: '#000000';
            echo '<input type="color" class="nu-input" data-field="' . $eName . '" name="' . $eName . '" value="' . htmlspecialchars($colorVal, ENT_QUOTES) . '" ' . $events . $eCss . '>';
            break;

        case 'range':
            $rangeVal = $eDefault !== '' ? $eDefault : '50';
            $min  = htmlspecialchars($field['field_min'] ?? '0',   ENT_QUOTES);
            $max  = htmlspecialchars($field['field_max'] ?? '100', ENT_QUOTES);
            $step = htmlspecialchars($field['field_step'] ?? '1',  ENT_QUOTES);
            echo '<input type="range" class="nu-input" data-field="' . $eName . '" name="' . $eName . '" value="' . $rangeVal . '" min="' . $min . '" max="' . $max . '" step="' . $step . '" ' . $events . $eCss . '>';
            break;

        case 'textarea':
            $rows = max(2, (int)($field['field_rows'] ?? 4));
            echo '<textarea class="nu-input" data-field="' . $eName . '" name="' . $eName . '" placeholder="' . $ePlaceholder . '" rows="' . $rows . '" ' . $required . $events . $eCss . '>' . htmlspecialchars((string)$defaultValue) . '</textarea>';
            break;

        case 'select':
            $opts     = json_decode($field['field_options'] ?? $field['options'] ?? '[]', true) ?: [];
            $multiple = !empty($field['field_multiple']) ? ' multiple' : '';
            echo '<select class="nu-input" data-field="' . $eName . '" name="' . $eName . ($multiple ? '[]' : '') . '"' . $multiple . ' ' . $required . $events . $eCss . '>';
            if (!$multiple) echo '<option value="">-- Select --</option>';
            foreach ($opts as $opt) {
                $optVal = $opt['value'] ?? $opt;
                $optLbl = $opt['label'] ?? $opt;
                $sel    = ((string)$defaultValue === (string)$optVal) ? ' selected' : '';
                echo '<option value="' . htmlspecialchars((string)$optVal, ENT_QUOTES) . '"' . $sel . '>' . htmlspecialchars((string)$optLbl) . '</option>';
            }
            echo '</select>';
            break;

        case 'radio':
            $opts = json_decode($field['field_options'] ?? $field['options'] ?? '[]', true) ?: [];
            echo '<div style="display:flex;gap:12px;flex-wrap:wrap;">';
            foreach ($opts as $opt) {
                $optVal = htmlspecialchars((string)($opt['value'] ?? $opt), ENT_QUOTES);
                $optLbl = htmlspecialchars((string)($opt['label'] ?? $opt));
                $chk    = ((string)$defaultValue === (string)($opt['value'] ?? $opt)) ? ' checked' : '';
                echo '<label style="display:flex;align-items:center;gap:6px;cursor:pointer;">';
                echo '<input type="radio" name="' . $eName . '" value="' . $optVal . '"' . $chk . $events . '>';
                echo $optLbl;
                echo '</label>';
            }
            echo '</div>';
            break;

        case 'checkbox_group':
            $opts     = json_decode($field['field_options'] ?? $field['options'] ?? '[]', true) ?: [];
            $selected = is_array($defaultValue) ? $defaultValue : (array) json_decode((string)$defaultValue, true);
            echo '<div style="display:flex;gap:12px;flex-wrap:wrap;">';
            foreach ($opts as $opt) {
                $optVal = (string)($opt['value'] ?? $opt);
                $optLbl = htmlspecialchars((string)($opt['label'] ?? $opt));
                $chk    = in_array($optVal, $selected, true) ? ' checked' : '';
                echo '<label style="display:flex;align-items:center;gap:6px;cursor:pointer;">';
                echo '<input type="checkbox" name="' . $eName . '[]" value="' . htmlspecialchars($optVal, ENT_QUOTES) . '"' . $chk . $events . '>';
                echo $optLbl;
                echo '</label>';
            }
            echo '</div>';
            break;

        case 'checkbox':
            $chk = $defaultValue ? ' checked' : '';
            echo '<label style="display:flex;align-items:center;gap:8px;cursor:pointer;">';
            echo '<input type="checkbox" data-field="' . $eName . '" name="' . $eName . '" value="1"' . $chk . $events . $eCss . '>';
            echo '<span>' . htmlspecialchars($label) . '</span>';
            echo '</label>';
            break;

        case 'lookup':
            $displayValue  = '';
            $lookupTable   = $field['field_lookup_table']   ?? $field['lookup_table']   ?? '';
            $lookupId      = $field['field_lookup_id']      ?? $field['lookup_id']      ?? 'id';
            $lookupDisplay = $field['field_lookup_display'] ?? $field['lookup_display'] ?? 'name';
            $lookupFilter  = $field['field_lookup_filter']  ?? $field['lookup_filter']  ?? '';

            if ($defaultValue && $lookupTable && isSafeIdentifier($lookupTable) && isSafeIdentifier($lookupId) && isSafeIdentifier($lookupDisplay)) {
                try {
                    $lkRow = $db->fetchOne(
                        "SELECT `{$lookupDisplay}` FROM `{$lookupTable}` WHERE `{$lookupId}` = ?",
                        [$defaultValue]
                    );
                    $displayValue = $lkRow[$lookupDisplay] ?? '';
                } catch (Exception $e) {
                    // Non-fatal — display stays empty
                }
            }

            echo '<div style="display:flex;gap:8px;">';
            echo '<input type="hidden" data-field="' . $eName . '" name="' . $eName . '" value="' . $eDefault . '">';
            echo '<input type="text" class="nu-input" readonly placeholder="Click to select..." value="' . htmlspecialchars($displayValue, ENT_QUOTES) . '"
                  onclick="openLookupModal(' . json_encode($name) . ',' . json_encode($lookupTable) . ',' . json_encode($lookupId) . ',' . json_encode($lookupDisplay) . ',' . json_encode($lookupFilter) . ')" style="flex:1;cursor:pointer;">';
            echo '<button type="button" class="nu-btn nu-btn-ghost nu-btn-sm" onclick="clearLookup(' . json_encode($name) . ')">Clear</button>';
            echo '</div>';
            break;

        case 'subform':
            $subformId   = (int)($field['field_subform_id']  ?? $field['subform_id']  ?? 0);
            $subformFk   = htmlspecialchars($field['field_subform_fk']  ?? $field['subform_fk']  ?? '', ENT_QUOTES);
            $subformView = htmlspecialchars($field['field_subform_view'] ?? $field['subform_view'] ?? 'grid', ENT_QUOTES);
            $parentId    = htmlspecialchars($record['id'] ?? '0', ENT_QUOTES);
            echo '<div class="nu-subform" data-subform-id="' . $subformId . '" data-subform-fk="' . $subformFk . '" data-subform-view="' . $subformView . '" data-parent-id="' . $parentId . '">';
            echo '<div style="padding:20px;text-align:center;color:var(--text-secondary);">Subform loading…</div>';
            echo '</div>';
            break;

        case 'calculated':
            echo '<input type="text" class="nu-input nu-calculated" data-field="' . $eName . '" readonly value="' . $eDefault . '" ' . $eCalc . $eCss . '>';
            break;

        case 'html':
            // Raw HTML field — admin-authored only
            echo '<div data-field="' . $eName . '"' . $eCss . '>' . ($field['field_default_value'] ?? $field['default_value'] ?? '') . '</div>';
            break;

        case 'file':
            echo '<input type="file" class="nu-input" data-field="' . $eName . '" name="' . $eName . '" ' . $events . $eCss . '>';
            if ($defaultValue) {
                echo '<p style="font-size:12px;margin-top:4px;">Current: <a href="' . htmlspecialchars((string)$defaultValue) . '" target="_blank">' . htmlspecialchars(basename((string)$defaultValue)) . '</a></p>';
            }
            break;

        case 'divider':
            echo '<hr style="border:none;border-top:1px solid var(--border-color);margin:4px 0;">';
            break;

        case 'button':
            $btnLabel = htmlspecialchars($field['field_label'] ?? $field['label'] ?? 'Button');
            $btnClick = $field['field_js_onclick'] ?? '';
            echo '<button type="button" class="nu-btn nu-btn-ghost" ' . ($btnClick ? 'onclick="' . htmlspecialchars($btnClick, ENT_QUOTES) . '"' : '') . '>' . $btnLabel . '</button>';
            break;

        default:
            // Unknown field type — render a disabled text input so layout is preserved
            echo '<input type="text" class="nu-input" data-field="' . $eName . '" name="' . $eName . '" value="" disabled placeholder="[unsupported type: ' . htmlspecialchars($type, ENT_QUOTES) . ']">';
            break;
    }

    echo $help;
    echo '</div>';
}

function handleFields($db, $formCode) {
    $form = $db->fetchOne("SELECT form_id FROM nu_forms WHERE form_code = ? AND form_active = 1", [$formCode]);
    if (!$form) { echo json_encode(['success' => false, 'error' => 'Form not found']); exit; }
    $fields = $db->fetchAll(
        "SELECT * FROM nu_form_fields WHERE form_id = ? AND field_active = 1 ORDER BY field_order",
        [$form['form_id']]
    );
    echo json_encode(['success' => true, 'data' => $fields]);
}

function handleEvents($db, $formCode, $eventType) {
    $form = $db->fetchOne("SELECT form_id FROM nu_forms WHERE form_code = ? AND form_active = 1", [$formCode]);
    if (!$form) { echo json_encode(['success' => false, 'error' => 'Form not found']); exit; }
    $code = $db->fetchOne(
        "SELECT event_code FROM nu_form_events WHERE form_id = ? AND event_type = ? AND event_active = 1",
        [$form['form_id'], $eventType]
    );
    echo json_encode(['success' => !!$code, 'code' => $code['event_code'] ?? '']);
}

function handleSave($db, $formCode) {
    $form = $db->fetchOne("SELECT * FROM nu_forms WHERE form_code = ? AND form_active = 1", [$formCode]);
    if (!$form) { echo json_encode(['success' => false, 'error' => 'Form not found']); exit; }

    $input    = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        echo json_encode(['success' => false, 'error' => 'Invalid JSON payload']);
        exit;
    }

    $recordId = $_GET['id'] ?? null;
    $isNew    = !$recordId;

    // FIX: Whitelist fields using form_layout to prevent mass assignment
    $layout      = json_decode($form['form_layout'] ?? '[]', true) ?: [];
    $flatFields  = flatFieldsFromLayout($layout);
    $allowedCols = array_filter(array_map(fn($f) => preg_replace('/[^a-zA-Z0-9_]/', '', $f['name'] ?? ''), $flatFields));
    $allowedCols = array_values($allowedCols);

    $safeInput = [];
    foreach ($allowedCols as $col) {
        if (array_key_exists($col, $input)) {
            $safeInput[$col] = $input[$col];
        }
    }

    if ($isNew) {
        // FIX: Stamp created_by from session
        if (session_status() === PHP_SESSION_NONE) session_start();
        $safeInput['created_by'] = $_SESSION['nu_user_id'] ?? null;
    }
    // FIX: Always stamp updated_at
    $safeInput['updated_at'] = date('Y-m-d H:i:s');

    $events   = $db->fetchAll("SELECT * FROM nu_form_events WHERE form_id = ? AND event_active = 1", [$form['form_id']]);
    $eventMap = [];
    foreach ($events as $e) { $eventMap[$e['event_type']] = $e['event_code']; }

    if (!empty($eventMap['php_beforesave'])) {
        $data        = $safeInput;
        $hashCookies = $input['hashCookies'] ?? [];
        eval($eventMap['php_beforesave']);
        $safeInput = $data;
    }

    try {
        if ($isNew) {
            $db->insert($form['form_table'], $safeInput);
            $recordId = $db->lastInsertId();
        } else {
            $db->update($form['form_table'], $safeInput, 'id = ?', [$recordId]);
        }

        if (!empty($eventMap['php_aftersave'])) {
            $data        = $safeInput;
            $hashCookies = $input['hashCookies'] ?? [];
            $newId       = $recordId;
            eval($eventMap['php_aftersave']);
        }

        sendFormEmailNotification($db, $form, $safeInput, $recordId, $isNew);

        echo json_encode(['success' => true, 'id' => $recordId]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

/**
 * Sends email notification(s) after a form record is saved.
 */
function sendFormEmailNotification($db, array $form, array $input, $recordId, bool $isNew): void {
    $notifyEnabled = $form['form_email_notify'] ?? 0;
    if (!$notifyEnabled) return;

    $notifyOn = $form['form_email_notify_on'] ?? 'new';
    if ($notifyOn === 'new' && !$isNew) return;

    $notifyTo = trim($form['form_email_to'] ?? '');
    if (!$notifyTo) return;

    $templateSlug = $form['form_email_template'] ?? 'form_submission';
    $formName     = $form['form_name'] ?? $form['form_code'] ?? 'Unknown Form';

    $baseUrl   = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';
    $reviewUrl = $baseUrl . '/index.php?form_code=' . urlencode($form['form_code']) . '&id=' . urlencode((string)$recordId);

    $submittedBy = 'System';
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (!empty($_SESSION['user_name']))    $submittedBy = $_SESSION['user_name'];
    elseif (!empty($_SESSION['username'])) $submittedBy = $_SESSION['username'];

    $variables = [
        'form_name'    => $formName,
        'submitted_by' => $submittedBy,
        'submitted_at' => date('Y-m-d H:i:s'),
        'record_id'    => (string)$recordId,
        'review_url'   => $reviewUrl,
        'action'       => $isNew ? 'created' : 'updated',
    ];
    foreach ($input as $key => $value) {
        if (is_scalar($value)) $variables[$key] = (string)$value;
    }

    try {
        $svc      = new EmailService();
        $rendered = EmailService::renderTemplate($templateSlug, $variables, $db->getConnection());

        $recipients = array_filter(
            array_map('trim', explode(',', $notifyTo)),
            fn($r) => filter_var($r, FILTER_VALIDATE_EMAIL)
        );

        if ($rendered) {
            foreach ($recipients as $recipient) {
                $svc->send($recipient, $rendered['subject'], $rendered['body']);
            }
        } else {
            $subject  = "Form submission: {$formName}";
            $bodyRows = '';
            foreach ($input as $key => $value) {
                if (is_scalar($value)) {
                    $bodyRows .= '<tr><td style="padding:6px 12px;border:1px solid #ddd;"><strong>' . htmlspecialchars($key) . '</strong></td><td style="padding:6px 12px;border:1px solid #ddd;">' . htmlspecialchars((string)$value) . '</td></tr>';
                }
            }
            $body = "<h2>New submission: {$formName}</h2><p>Submitted by {$submittedBy} on " . date('Y-m-d H:i') . "</p><table style='border-collapse:collapse;width:100%'>{$bodyRows}</table><p><a href='{$reviewUrl}'>View record</a></p>";
            foreach ($recipients as $recipient) {
                $svc->send($recipient, $subject, $body);
            }
        }
    } catch (\Throwable $e) {
        error_log('[nub5-dev email] Form notification failed: ' . $e->getMessage());
    }
}

function handleBrowse($db, $formCode) {
    $form = $db->fetchOne("SELECT * FROM nu_forms WHERE form_code = ? AND form_active = 1", [$formCode]);
    if (!$form) { echo json_encode(['success' => false, 'error' => 'Form not found']); exit; }

    if (!isSafeIdentifier($form['form_table'] ?? '')) {
        echo json_encode(['success' => false, 'error' => 'Invalid form table name']);
        exit;
    }

    // FIX: Respect browse_page_size from form config (was hardcoded to 20)
    $limit  = max(1, min(500, (int)($form['browse_page_size'] ?? 20)));
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $offset = ($page - 1) * $limit;

    // FIX: Apply browse_default_sort safely
    $sortAllowed = ['ASC', 'DESC'];
    $sortRaw     = trim($form['browse_default_sort'] ?? '');
    $orderClause = 'ORDER BY id DESC'; // safe default
    if ($sortRaw) {
        // Expect "field_name ASC" or "field_name DESC"
        if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)\s+(ASC|DESC)$/i', $sortRaw, $m)) {
            $orderClause = 'ORDER BY `' . $m[1] . '` ' . strtoupper($m[2]);
        }
    }

    $fields = $db->fetchAll(
        "SELECT field_name, field_label FROM nu_form_fields WHERE form_id = ? AND field_active = 1 ORDER BY field_order LIMIT 5",
        [$form['form_id']]
    );

    if (empty($fields)) {
        $layout = json_decode($form['form_layout'] ?? '[]', true) ?: [];
        $flat   = flatFieldsFromLayout($layout);
        $fields = [];
        foreach (array_slice($flat, 0, 5) as $f) {
            $fields[] = [
                'field_name'  => $f['name']  ?? '',
                'field_label' => $f['label'] ?? ($f['name'] ?? ''),
            ];
        }
    }

    $total   = 0;
    $records = [];
    $pages   = 0;

    try {
        // FIX: Filter soft-deleted rows
        $where = "WHERE (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')";
        if (!empty($form['browse_sql'])) {
            // Custom SQL provided — use as subquery for safety
            $totalRow = $db->fetchOne("SELECT COUNT(*) as c FROM ({$form['browse_sql']}) _bsub");
        } else {
            $totalRow = $db->fetchOne("SELECT COUNT(*) as c FROM `{$form['form_table']}` {$where}");
        }
        $total   = (int)($totalRow['c'] ?? 0);
        $records = $db->fetchAll("SELECT * FROM `{$form['form_table']}` {$where} {$orderClause} LIMIT {$limit} OFFSET {$offset}");
        $pages   = $total > 0 ? (int)ceil($total / $limit) : 0;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Table error: ' . $e->getMessage()]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'data'    => [
            'records' => $records,
            'layout'  => $fields,
            'page'    => $page,
            'pages'   => $pages,
            'total'   => $total,
            'limit'   => $limit,
        ],
    ]);
}
