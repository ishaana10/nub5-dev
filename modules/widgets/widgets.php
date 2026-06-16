<?php
declare(strict_types=1);
if (!defined('NU_BOOTSTRAP_DONE')) {
    require_once dirname(__DIR__, 2) . '/core/module_bootstrap.php';
}

$db           = NuDatabase::getInstance();
$userId       = (int)($_SESSION['nu_user_id'] ?? 0);
$role         = strtolower((string)($_SESSION['nu_role'] ?? ''));
$isAdmin      = in_array($role, ['globeadmin', 'admin'], true);
$isGlobeAdmin = ($role === 'globeadmin');
$canManage    = $isGlobeAdmin;

function wu_resolve_widgets(NuDatabase $db, int $userId, string $role, bool $isGlobeAdmin): array {
    try {
        $personal = $db->fetchAll(
            'SELECT * FROM nu_dashboard_widgets WHERE widget_user_id=? AND widget_active=1 ORDER BY widget_position',
            [$userId]
        );
        if (!empty($personal)) return $personal;
        if ($isGlobeAdmin) {
            return $db->fetchAll(
                'SELECT * FROM nu_dashboard_widgets WHERE widget_user_id IS NULL AND widget_active=1 ORDER BY widget_role, widget_position'
            ) ?: [];
        }
        return $db->fetchAll(
            'SELECT * FROM nu_dashboard_widgets WHERE widget_user_id IS NULL AND widget_role=? AND widget_active=1 ORDER BY widget_position',
            [$role]
        ) ?: [];
    } catch (Throwable $e) {
        error_log('[widgets] resolve error: ' . $e->getMessage());
        return [];
    }
}

function wu_run_sql(NuDatabase $db, string $sql, int $userId): array {
    try {
        $sql = str_replace('{{user_id}}', (string)$userId, $sql);
        if (!preg_match('/^\s*SELECT\b/i', $sql)) return [];
        return $db->fetchAll($sql) ?: [];
    } catch (Throwable $e) {
        error_log('[widget] sql error: ' . $e->getMessage());
        return [['_error' => $e->getMessage()]];
    }
}

function wu_accent(string $color): string {
    switch ($color) {
        case 'success': return '#437a22';
        case 'warning': return '#964219';
        case 'error':   return '#a12c7b';
        default:        return '#01696f';
    }
}

function wu_chart_type(string $t): string {
    if ($t === 'chart_pie')  return 'pie';
    if ($t === 'chart_line') return 'line';
    return 'bar';
}

/** Returns hex accent color for a widget type */
function wu_type_accent(string $type): string {
    switch ($type) {
        case 'stat':       return '#01696f';
        case 'chart_bar':  return '#006494';
        case 'chart_line': return '#7a39bb';
        case 'chart_pie':  return '#da7101';
        case 'table':      return '#437a22';
        case 'list':       return '#006494';
        case 'progress':   return '#964219';
        case 'custom':     return '#a12c7b';
        default:           return '#01696f';
    }
}

function wu_empty_hint(int $wid): string {
    return '<div style="display:flex;flex-direction:column;align-items:center;justify-content:center;padding:24px 12px;color:#888;text-align:center;gap:8px;">'
         . '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">'
         . '<circle cx="12" cy="12" r="3"/>'
         . '<path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/>'
         . '</svg>'
         . '<span style="font-size:.75rem;">Not configured &mdash; click the gear icon to set up.</span></div>';
}

function wu_render(array $w, NuDatabase $db, int $userId): string {
    try {
        $cfg    = json_decode($w['widget_config'] ?? '{}', true) ?: [];
        $type   = $w['widget_type'] ?? 'custom';
        $accent = wu_accent($cfg['color'] ?? 'primary');

        switch ($type) {
            case 'stat':
                $sql = trim($cfg['sql'] ?? '');
                if ($sql === '') return wu_empty_hint((int)$w['widget_id']);
                $rows = wu_run_sql($db, $sql, $userId);
                if (isset($rows[0]['_error'])) return '<p style="color:#a12c7b;font-size:12px;">SQL error: ' . htmlspecialchars($rows[0]['_error']) . '</p>';
                $val = $rows[0]['value'] ?? (isset($rows[0]) ? reset($rows[0]) : 0);
                $sub = htmlspecialchars($cfg['subtitle'] ?? '');
                return '<div style="display:flex;flex-direction:column;gap:4px;padding:4px 0;">'
                     . '<div style="font-size:2.5rem;font-weight:800;line-height:1;color:' . $accent . ';font-variant-numeric:tabular-nums;">' . number_format((float)$val) . '</div>'
                     . ($sub ? '<div style="font-size:.75rem;color:#888;">' . $sub . '</div>' : '') . '</div>';

            case 'chart_bar':
            case 'chart_line':
            case 'chart_pie':
                $sql = trim($cfg['sql'] ?? '');
                if ($sql === '') return wu_empty_hint((int)$w['widget_id']);
                $rows = wu_run_sql($db, $sql, $userId);
                if (isset($rows[0]['_error'])) return '<p style="color:#a12c7b;font-size:12px;">SQL error: ' . htmlspecialchars($rows[0]['_error']) . '</p>';
                $ctype     = wu_chart_type($type);
                $id        = 'wc_' . $w['widget_id'];
                $bgColor   = ($ctype === 'pie') ? ['#01696f','#437a22','#006494','#7a39bb','#da7101','#a12c7b'] : 'rgba(1,105,111,0.75)';
                $chartJson = json_encode([
                    'type' => $ctype,
                    'data' => [
                        'labels'   => array_column($rows, 'label'),
                        'datasets' => [[
                            'label'           => $w['widget_title'],
                            'data'            => array_column($rows, 'value'),
                            'backgroundColor' => $bgColor,
                            'borderColor'     => 'rgba(1,105,111,1)',
                            'borderWidth'     => 1,
                            'tension'         => 0.4,
                            'fill'            => ($ctype === 'line'),
                        ]],
                    ],
                    'options' => [
                        'responsive'          => true,
                        'maintainAspectRatio' => false,
                        'plugins' => ['legend' => ['display' => ($ctype === 'pie')]],
                        'scales'  => ($ctype === 'pie') ? (object)[] : ['y' => ['beginAtZero' => true]],
                    ],
                ]);
                return '<div style="height:220px;"><canvas id="' . $id . '" data-chartjs=\'' . htmlspecialchars($chartJson, ENT_QUOTES) . '\'></canvas></div>';

            case 'table':
                $sql = trim($cfg['sql'] ?? '');
                if ($sql === '') return wu_empty_hint((int)$w['widget_id']);
                $rows = wu_run_sql($db, $sql, $userId);
                if (isset($rows[0]['_error'])) return '<p style="color:#a12c7b;font-size:12px;">SQL error: ' . htmlspecialchars($rows[0]['_error']) . '</p>';
                if (empty($rows)) return '<p style="color:#888;padding:12px 0;">No data</p>';
                $cols = array_keys($rows[0]);
                $html = '<div class="nu-table-wrap"><table class="nu-table"><thead><tr>';
                foreach ($cols as $c) $html .= '<th>' . htmlspecialchars(ucfirst($c)) . '</th>';
                $html .= '</tr></thead><tbody>';
                foreach ($rows as $row) {
                    $html .= '<tr>';
                    foreach ($row as $v) $html .= '<td>' . htmlspecialchars((string)$v) . '</td>';
                    $html .= '</tr>';
                }
                return $html . '</tbody></table></div>';

            case 'list':
                $items = $cfg['items'] ?? [];
                if (empty($items)) return wu_empty_hint((int)$w['widget_id']);
                $html = '<div style="display:flex;flex-direction:column;gap:6px;">';
                foreach ($items as $item) {
                    $lbl   = htmlspecialchars($item['label'] ?? '');
                    $mod   = htmlspecialchars($item['module'] ?? '');
                    $url   = htmlspecialchars($item['url']    ?? '');
                    $click = $mod ? "NuApp.loadModule('$mod')" : "window.open('$url','_blank')";
                    $html .= "<button class=\"nu-btn nu-btn-ghost\" style=\"justify-content:flex-start;\" onclick=\"$click\">$lbl</button>";
                }
                return $html . '</div>';

            case 'progress':
                $sql = trim($cfg['sql'] ?? '');
                if ($sql === '') return wu_empty_hint((int)$w['widget_id']);
                $rows  = wu_run_sql($db, $sql, $userId);
                if (isset($rows[0]['_error'])) return '<p style="color:#a12c7b;font-size:12px;">SQL error</p>';
                $total = (float)($rows[0]['total'] ?? 1);
                $done  = (float)($rows[0]['done']  ?? 0);
                $pct   = $total > 0 ? min(100, (int)round($done / $total * 100)) : 0;
                $lbl   = htmlspecialchars($cfg['label'] ?? "$done / $total");
                return '<div style="margin-top:4px;">'
                     . '<div style="display:flex;justify-content:space-between;font-size:.75rem;color:#888;margin-bottom:6px;"><span>' . $lbl . '</span><span>' . $pct . '%</span></div>'
                     . '<div style="height:8px;border-radius:9999px;background:#eee;overflow:hidden;">'
                     . '<div style="width:' . $pct . '%;height:100%;background:' . $accent . ';border-radius:inherit;transition:width .6s ease;"></div>'
                     . '</div></div>';

            case 'custom':
                $html = $cfg['html'] ?? '';
                return $html !== '' ? $html : wu_empty_hint((int)$w['widget_id']);

            default:
                return '<p style="color:#888;">Unknown widget type: ' . htmlspecialchars($type) . '</p>';
        }
    } catch (Throwable $e) {
        error_log('[widget render] id=' . ($w['widget_id'] ?? '?') . ' ' . $e->getMessage());
        return '<p style="color:#a12c7b;font-size:12px;">Widget error: ' . htmlspecialchars($e->getMessage()) . '</p>';
    }
}

// ── Resolve & prepare
$widgets = wu_resolve_widgets($db, $userId, $role, $isGlobeAdmin);

try {
    $hasPersonal = !empty($db->fetchAll(
        'SELECT widget_id FROM nu_dashboard_widgets WHERE widget_user_id=? AND widget_active=1 LIMIT 1',
        [$userId]
    ));
} catch (Throwable $e) {
    $hasPersonal = false;
}

$showRoleGroups = ($isGlobeAdmin && !$hasPersonal);
$roleGroups     = [];

if ($showRoleGroups) {
    foreach ($widgets as $w) {
        $isRoleWgt = ($w['widget_user_id'] === null || $w['widget_user_id'] === '');
        $key = $isRoleWgt ? ($w['widget_role'] ?? 'unassigned') : '__personal__';
        $roleGroups[$key][] = $w;
    }
} else {
    $roleGroups['__flat__'] = $widgets;
}

$roleNames = [];
if ($showRoleGroups) {
    try {
        $rRows = $db->fetchAll('SELECT role_code, role_name FROM nu_roles ORDER BY role_name');
        foreach ($rRows as $r) {
            $roleNames[$r['role_code']] = $r['role_name'];
        }
    } catch (Throwable $e) {}
}

$widgetsForJs = [];
if ($canManage) {
    foreach ($widgets as $w) {
        $widgetsForJs[(string)$w['widget_id']] = $w;
    }
}
$widgetsJson = json_encode($widgetsForJs, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);

// All hex so alpha suffix (cc) works correctly in CSS gradients
$groupAccents = ['#01696f','#437a22','#006494','#7a39bb','#964219','#a12c7b'];
$accentIdx = 0;
?>

<style>
#nuWidgetGrid {
    display: grid;
    grid-template-columns: repeat(12, 1fr);
    gap: 16px;
}
.nu-role-group-body {
    display: grid;
    grid-template-columns: repeat(12, 1fr);
    gap: 16px;
    overflow: hidden;
    transition: max-height .35s ease, opacity .25s ease, margin-top .3s ease;
    opacity: 1;
}
.nu-role-group-body.nu-group-collapsed {
    max-height: 0 !important;
    opacity: 0;
    margin-top: 0 !important;
    pointer-events: none;
}
.nu-widget-card {
    transition: transform .18s ease, box-shadow .18s ease;
    border-radius: var(--radius-lg, .75rem);
    overflow: hidden;
}
.nu-widget-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 24px rgba(0,0,0,.10);
}
/* Title badge */
.nu-widget-title-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: .8rem;
    font-weight: 700;
    padding: 4px 12px 4px 8px;
    border-radius: 9999px;
    color: #fff;
    line-height: 1.4;
    letter-spacing: .01em;
    max-width: 100%;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.nu-widget-title-icon {
    font-size: 1rem;
    line-height: 1;
    flex-shrink: 0;
}
/* Toolbar */
.nu-dash-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 10px;
    margin-bottom: 20px;
    padding: 10px 14px;
    background: var(--color-surface-offset, #f8f9fa);
    border-radius: var(--radius-lg, .75rem);
    border: 1px solid var(--color-border, #e5e7eb);
}
.nu-dash-toolbar-title {
    font-size: .875rem;
    font-weight: 700;
    color: var(--color-text, #111);
    display: flex;
    align-items: center;
    gap: 8px;
}
.nu-dash-mode-badge {
    font-size: .75rem;
    font-weight: 500;
    background: var(--color-surface, #fff);
    border: 1px solid var(--color-border, #e5e7eb);
    border-radius: 9999px;
    padding: 2px 10px;
    color: var(--color-text-muted, #888);
}
.nu-dash-btn-group { display:flex;gap:6px;flex-wrap:wrap;align-items:center; }
/* Role group */
.nu-role-group-header {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    border-radius: var(--radius-md, .5rem);
    cursor: pointer;
    user-select: none;
    margin-bottom: 12px;
    transition: filter .15s ease;
}
.nu-role-group-header:hover { filter: brightness(.94); }
.nu-group-chevron { transition: transform .3s ease; flex-shrink: 0; }
.nu-group-chevron.nu-group-collapsed { transform: rotate(-90deg); }
.nu-role-code-badge {
    font-size: 11px; font-weight: 700;
    padding: 3px 10px;
    border-radius: 9999px;
    color: #fff;
    letter-spacing: .05em;
    background: rgba(255,255,255,.22);
}
.nu-role-count-badge {
    font-size: 11px; font-weight: 600;
    padding: 2px 8px;
    border-radius: 9999px;
    background: var(--color-surface, #fff);
    border: 1px solid var(--color-border, #e5e7eb);
    color: var(--color-text-muted, #888);
}
/* Empty state */
.nu-widget-empty-state {
    grid-column: 1 / -1;
    border: 2px dashed var(--color-border, #e5e7eb);
    border-radius: var(--radius-lg, .75rem);
    padding: 56px 24px;
    text-align: center;
    background: var(--color-surface-offset, #f8f9fa);
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 14px;
}
</style>

<?php if ($canManage): ?>
<div class="nu-dash-toolbar" id="nuDashToolbar">
    <div class="nu-dash-toolbar-title">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color:#01696f;">
            <rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/>
            <rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>
        </svg>
        My Dashboard
        <span class="nu-dash-mode-badge"><?= $hasPersonal ? 'personal' : 'all roles preview' ?></span>
    </div>
    <div class="nu-dash-btn-group">
        <button class="nu-btn nu-btn-primary nu-btn-sm" onclick="nuDash.openBuilder()">&#xff0b;&nbsp;Add Widget</button>
        <button class="nu-btn nu-btn-ghost nu-btn-sm" id="nuDashEditBtn" onclick="nuDash.toggleEditMode()">&#9999;&#65039;&nbsp;Edit Layout</button>
        <?php if ($hasPersonal): ?>
        <button class="nu-btn nu-btn-ghost nu-btn-sm" style="color:#a12c7b;" onclick="nuDash.resetLayout()">&#8635;&nbsp;Reset to Default</button>
        <?php endif; ?>
        <button class="nu-btn nu-btn-ghost nu-btn-sm" style="color:#964219;" onclick="nuDash.openRoleDesigner()">&#127775;&nbsp;Design Role Layout</button>
    </div>
</div>
<?php else: ?>
<div style="display:flex;align-items:center;margin-bottom:20px;gap:8px;">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color:#888;">
        <rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/>
        <rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>
    </svg>
    <span style="font-size:.875rem;font-weight:700;color:var(--color-text,#111);">My Dashboard</span>
    <span style="font-size:.75rem;background:var(--color-surface-offset);border:1px solid var(--color-border,#e5e7eb);border-radius:9999px;padding:2px 10px;color:#888;">role default</span>
</div>
<?php endif; ?>

<div id="nuWidgetGrid"<?= $showRoleGroups ? ' style="display:block;"' : '' ?>>

<?php if (empty($widgets)): ?>
    <div class="nu-widget-empty-state">
        <svg width="52" height="52" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" style="color:#ccc;">
            <rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/>
            <rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>
        </svg>
        <?php if ($canManage): ?>
            <p style="margin:0;font-size:1rem;font-weight:600;">No widgets yet</p>
            <p style="margin:0;font-size:.875rem;color:#888;">Add your first widget to start building the dashboard.</p>
            <button class="nu-btn nu-btn-primary" onclick="nuDash.openBuilder()">&#xff0b;&nbsp;Add Widget</button>
        <?php else: ?>
            <p style="margin:0;font-size:1rem;font-weight:600;">No widgets configured</p>
            <p style="margin:0;font-size:.875rem;color:#888;">No widgets have been set up for your role yet.</p>
        <?php endif; ?>
    </div>

<?php else: ?>

<?php foreach ($roleGroups as $groupKey => $groupWidgets):
    $isNamedGroup = ($showRoleGroups && $groupKey !== '__personal__');
    $accentHex    = $groupAccents[$accentIdx % count($groupAccents)];
    $accentIdx++;
    $displayRole  = htmlspecialchars($roleNames[$groupKey] ?? ucfirst($groupKey));
    $roleCode     = htmlspecialchars($groupKey);
    $widgetCount  = count($groupWidgets);
    $groupBodyId  = 'nuRoleGroup_' . preg_replace('/[^a-z0-9_]/i', '_', $groupKey);
?>

<?php if ($isNamedGroup): ?>
<div class="nu-role-group" style="margin-bottom:28px;">
    <div
        class="nu-role-group-header"
        onclick="nuDash.toggleGroup('<?= $groupBodyId ?>', '<?= $roleCode ?>')"
        style="background:linear-gradient(135deg,<?= $accentHex ?> 0%,<?= $accentHex ?>cc 100%);"
    >
        <svg id="<?= $groupBodyId ?>_chevron" class="nu-group-chevron" width="16" height="16"
             viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5">
            <polyline points="6 9 12 15 18 9"/>
        </svg>
        <div style="display:flex;align-items:center;gap:8px;flex:1;min-width:0;">
            <span style="font-size:.875rem;font-weight:700;color:#fff;"><?= $displayRole ?></span>
            <span class="nu-role-code-badge"><?= $roleCode ?></span>
            <span class="nu-role-count-badge"><?= $widgetCount ?> widget<?= $widgetCount !== 1 ? 's' : '' ?></span>
        </div>
        <button
            class="nu-btn nu-btn-sm"
            style="background:rgba(255,255,255,.18);color:#fff;border:1px solid rgba(255,255,255,.35);white-space:nowrap;"
            onclick="event.stopPropagation();nuDash.openBuilderForRole('<?= $roleCode ?>')"
        >&#xff0b;&nbsp;Add</button>
    </div>
    <div id="<?= $groupBodyId ?>" class="nu-role-group-body" style="margin-top:0;">
<?php else: ?>
<?php endif; ?>

<?php foreach ($groupWidgets as $w):
    $ww          = max(1, min(4, (int)($w['widget_width']  ?? 2)));
    $colSpan     = $ww * 3;
    $rowSpan     = max(1, min(3, (int)($w['widget_height'] ?? 1)));
    $typeAccent  = wu_type_accent($w['widget_type'] ?? 'custom');
    $icon        = trim($w['widget_icon'] ?? '');
?>
    <div class="nu-widget-card nu-card" data-widget-id="<?= (int)$w['widget_id'] ?>"
         style="grid-column:span <?= $colSpan ?>;grid-row:span <?= $rowSpan ?>;border-top:3px solid <?= $typeAccent ?>;">

        <div class="nu-card-header" style="margin-bottom:12px;align-items:center;">
            <!-- Title as badge with optional custom icon -->
            <span class="nu-widget-title-badge" style="background:<?= $typeAccent ?>;" title="<?= htmlspecialchars($w['widget_title']) ?>">
                <?php if ($icon !== ''): ?>
                    <span class="nu-widget-title-icon"><?= htmlspecialchars($icon) ?></span>
                <?php endif; ?>
                <?= htmlspecialchars($w['widget_title']) ?>
            </span>
            <?php if ($canManage): ?>
            <div class="nu-widget-controls" style="display:flex;gap:4px;flex-shrink:0;margin-left:auto;">
                <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="nuDash.editWidget(<?= (int)$w['widget_id'] ?>)" title="Configure">&#9881;</button>
                <button class="nu-btn nu-btn-ghost nu-btn-sm" style="color:#a12c7b;" onclick="nuDash.removeWidget(<?= (int)$w['widget_id'] ?>)" title="Remove">&times;</button>
            </div>
            <?php endif; ?>
        </div>

        <div class="nu-widget-body"><?= wu_render($w, $db, $userId) ?></div>
    </div>
<?php endforeach; ?>

<?php if ($isNamedGroup): ?>
    </div><!-- /.nu-role-group-body -->
</div><!-- /.nu-role-group -->
<?php endif; ?>

<?php endforeach; ?>
<?php endif; ?>
</div><!-- /#nuWidgetGrid -->

<?php if ($canManage): ?>
<div id="nuBuilderModal" style="display:none;position:fixed;inset:0;z-index:1000;background:rgba(0,0,0,.45);overflow-y:auto;">
  <div style="background:var(--color-surface,#fff);border-radius:.75rem;max-width:600px;margin:40px auto;padding:28px;box-shadow:var(--shadow-lg);">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
        <h3 style="margin:0;font-size:1.125rem;">&#129529;&nbsp;Widget Builder</h3>
        <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="nuDash.closeBuilder()">&times;</button>
    </div>
    <input type="hidden" id="nuWid" value="">
    <div class="nu-field" style="margin-bottom:14px;">
        <label class="nu-label">Widget Type</label>
        <select class="nu-input" id="nuWType" onchange="nuDash.onTypeChange()">
            <option value="stat">🔢 Stat / KPI</option>
            <option value="chart_bar">📊 Bar Chart</option>
            <option value="chart_line">📈 Line Chart</option>
            <option value="chart_pie">🥧 Pie Chart</option>
            <option value="table">📋 Data Table</option>
            <option value="list">🔗 Quick Links</option>
            <option value="progress">⏳ Progress Bar</option>
            <option value="custom">✏️ Custom HTML</option>
        </select>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px;">
        <div class="nu-field">
            <label class="nu-label">Title</label>
            <input class="nu-input" id="nuWTitle" placeholder="e.g. Pending Tasks">
        </div>
        <div class="nu-field">
            <label class="nu-label">Icon <small style="color:#888;font-weight:400;">(emoji or text)</small></label>
            <input class="nu-input" id="nuWIcon" placeholder="e.g. 📅 or ⚠️" maxlength="8"
                   style="font-size:1.2rem;" oninput="nuDash.previewIcon(this.value)">
        </div>
    </div>
    <!-- Icon preview strip -->
    <div id="nuWIconPreview" style="display:none;margin:-6px 0 12px;padding:8px 12px;background:var(--color-surface-offset,#f8f9fa);border-radius:.5rem;font-size:.8rem;color:#888;">Preview: <span id="nuWIconPreviewBadge" style="display:inline-flex;align-items:center;gap:6px;background:#01696f;color:#fff;padding:3px 10px 3px 8px;border-radius:9999px;font-weight:700;font-size:.8rem;"></span></div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px;">
        <div class="nu-field">
            <label class="nu-label">Width</label>
            <select class="nu-input" id="nuWWidth">
                <option value="1">&#188; width (3 cols)</option>
                <option value="2" selected>&#189; width (6 cols)</option>
                <option value="3">&#190; width (9 cols)</option>
                <option value="4">Full width (12 cols)</option>
            </select>
        </div>
        <div class="nu-field">
            <label class="nu-label">Height</label>
            <select class="nu-input" id="nuWHeight">
                <option value="1" selected>1 row</option>
                <option value="2">2 rows</option>
                <option value="3">3 rows</option>
            </select>
        </div>
    </div>
    <div id="nuWConfigArea"></div>
    <div class="nu-field" style="margin:14px 0;padding:12px;background:var(--color-surface-offset);border-radius:.5rem;border-left:3px solid #964219;">
        <label class="nu-label" style="color:#964219;">&#127775;&nbsp;Assign to Role</label>
        <select class="nu-input" id="nuWTargetRole">
            <option value="">-- My personal dashboard only --</option>
        </select>
        <small style="color:#888;font-size:11px;">Saving to a role sets the default for all users with that role.</small>
    </div>
    <div id="nuWPreviewWrap" style="display:none;margin:14px 0;">
        <label class="nu-label">Live Preview</label>
        <div id="nuWPreview" class="nu-card" style="padding:16px;min-height:80px;background:var(--color-surface-offset);"></div>
    </div>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-top:20px;">
        <button class="nu-btn nu-btn-ghost" onclick="nuDash.runPreview()">&#128064;&nbsp;Preview</button>
        <div style="display:flex;gap:8px;">
            <button class="nu-btn nu-btn-ghost" onclick="nuDash.closeBuilder()">Cancel</button>
            <button class="nu-btn nu-btn-primary" onclick="nuDash.saveWidget()">&#10003;&nbsp;Save Widget</button>
        </div>
    </div>
  </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
window.NUDASH_WIDGET_DATA = <?= $widgetsJson ?>;
window.NUDASH_CAN_MANAGE  = <?= $canManage ? 'true' : 'false' ?>;
</script>
<script src="modules/widgets/widgets.js?v=<?= filemtime(__DIR__ . '/widgets.js') ?>"></script>
