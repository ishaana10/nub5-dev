<?php
declare(strict_types=1);
/**
 * modules/widgets/widgets.php
 *
 * The <script> block that was previously inline has been moved to widgets.js.
 * nubuilder-next.js injects this file via innerHTML / replaceChild which
 * HTML-decodes entities inside <script> tags before the JS engine sees them,
 * causing SyntaxError. An external <script src> is fetched separately and
 * never decoded that way.
 */
if (!defined('NU_BOOTSTRAP_DONE')) {
    require_once dirname(__DIR__, 2) . '/core/module_bootstrap.php';
}

$db           = NuDatabase::getInstance();
$userId       = (int)($_SESSION['nu_user_id'] ?? 0);
$role         = strtolower((string)($_SESSION['nu_role'] ?? ''));
$isAdmin      = in_array($role, ['globeadmin', 'admin'], true);
$isGlobeAdmin = ($role === 'globeadmin');

// ── helpers ───────────────────────────────────────────────────────────────────
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
        case 'success': return 'var(--color-success,#437a22)';
        case 'warning': return 'var(--color-warning,#964219)';
        case 'error':   return 'var(--color-error,#a12c7b)';
        default:        return 'var(--color-primary,#01696f)';
    }
}

function wu_chart_type(string $t): string {
    if ($t === 'chart_pie')  return 'pie';
    if ($t === 'chart_line') return 'line';
    return 'bar';
}

function wu_empty_hint(int $wid): string {
    return '<div style="display:flex;flex-direction:column;align-items:center;justify-content:center;padding:24px 12px;color:var(--color-text-muted,#888);text-align:center;gap:8px;">'
         . '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">'
         . '<circle cx="12" cy="12" r="3"/>'
         . '<path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/>'
         . '</svg>'
         . '<span style="font-size:var(--text-xs,.75rem);">Not configured - click the gear icon to set up.</span></div>';
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
                if (isset($rows[0]['_error'])) return '<p style="color:var(--color-error);font-size:12px;">SQL error: ' . htmlspecialchars($rows[0]['_error']) . '</p>';
                $val = $rows[0]['value'] ?? (isset($rows[0]) ? reset($rows[0]) : 0);
                $sub = htmlspecialchars($cfg['subtitle'] ?? '');
                return '<div style="display:flex;flex-direction:column;gap:4px;padding:4px 0;">'
                     . '<div style="font-size:2.5rem;font-weight:800;line-height:1;color:' . $accent . ';font-variant-numeric:tabular-nums;">' . number_format((float)$val) . '</div>'
                     . ($sub ? '<div style="font-size:var(--text-xs,.75rem);color:var(--color-text-muted,#888);">' . $sub . '</div>' : '') . '</div>';

            case 'chart_bar':
            case 'chart_line':
            case 'chart_pie':
                $sql = trim($cfg['sql'] ?? '');
                if ($sql === '') return wu_empty_hint((int)$w['widget_id']);
                $rows = wu_run_sql($db, $sql, $userId);
                if (isset($rows[0]['_error'])) return '<p style="color:var(--color-error);font-size:12px;">SQL error: ' . htmlspecialchars($rows[0]['_error']) . '</p>';
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
                if (isset($rows[0]['_error'])) return '<p style="color:var(--color-error);font-size:12px;">SQL error: ' . htmlspecialchars($rows[0]['_error']) . '</p>';
                if (empty($rows)) return '<p style="color:var(--color-text-muted,#888);padding:12px 0;">No data</p>';
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
                if (isset($rows[0]['_error'])) return '<p style="color:var(--color-error);font-size:12px;">SQL error</p>';
                $total = (float)($rows[0]['total'] ?? 1);
                $done  = (float)($rows[0]['done']  ?? 0);
                $pct   = $total > 0 ? min(100, (int)round($done / $total * 100)) : 0;
                $lbl   = htmlspecialchars($cfg['label'] ?? "$done / $total");
                return '<div style="margin-top:4px;">'
                     . '<div style="display:flex;justify-content:space-between;font-size:var(--text-xs,.75rem);color:var(--color-text-muted);margin-bottom:6px;"><span>' . $lbl . '</span><span>' . $pct . '%</span></div>'
                     . '<div style="height:8px;border-radius:var(--radius-full,9999px);background:var(--color-surface-offset,#eee);overflow:hidden;">'
                     . '<div style="width:' . $pct . '%;height:100%;background:' . $accent . ';border-radius:inherit;transition:width .6s ease;"></div>'
                     . '</div></div>';

            case 'custom':
                $html = $cfg['html'] ?? '';
                return $html !== '' ? $html : wu_empty_hint((int)$w['widget_id']);

            default:
                return '<p style="color:var(--color-text-muted);">Unknown widget type: ' . htmlspecialchars($type) . '</p>';
        }
    } catch (Throwable $e) {
        error_log('[widget render] id=' . ($w['widget_id'] ?? '?') . ' ' . $e->getMessage());
        return '<p style="color:var(--color-error);font-size:12px;">Widget error: ' . htmlspecialchars($e->getMessage()) . '</p>';
    }
}

// ── Resolve & prepare ─────────────────────────────────────────────────────────
$widgets = wu_resolve_widgets($db, $userId, $role, $isGlobeAdmin);

try {
    $hasPersonal = !empty($db->fetchAll(
        'SELECT widget_id FROM nu_dashboard_widgets WHERE widget_user_id=? AND widget_active=1 LIMIT 1',
        [$userId]
    ));
} catch (Throwable $e) {
    $hasPersonal = false;
}

// ── Pre-group widgets by role (globeadmin role-preview only) ──────────────────
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
    } catch (Throwable $e) { /* non-fatal */ }
}

$widgetsForJs = [];
foreach ($widgets as $w) {
    $widgetsForJs[(string)$w['widget_id']] = $w;
}
$widgetsJson = json_encode($widgetsForJs, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);

$groupAccents = [
    'var(--color-primary,#01696f)',
    'var(--color-success,#437a22)',
    '#006494',
    '#7a39bb',
    'var(--color-warning,#964219)',
    'var(--color-error,#a12c7b)',
];
$accentIdx = 0;
?>

<!-- Collapsible group styles (no external CSS file needed) -->
<style>
.nu-role-group-body {
    display: grid;
    grid-template-columns: repeat(4,1fr);
    gap: 16px;
    overflow: hidden;
    /* open state - max-height animated via JS */
    transition: max-height .35s ease, opacity .25s ease, margin-top .3s ease;
    opacity: 1;
}
.nu-role-group-body.nu-group-collapsed {
    max-height: 0 !important;
    opacity: 0;
    margin-top: 0 !important;
    pointer-events: none;
}
.nu-group-chevron {
    transition: transform .3s ease;
    flex-shrink: 0;
}
.nu-group-chevron.nu-group-collapsed {
    transform: rotate(-90deg);
}
</style>

<!-- Toolbar -->
<div id="nuDashToolbar" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:20px;">
    <div style="display:flex;align-items:center;gap:8px;">
        <span style="font-size:var(--text-sm,.875rem);font-weight:600;color:var(--color-text-muted);">My Dashboard
            <?php if (!$hasPersonal): ?><span style="font-size:var(--text-xs,.75rem);background:var(--color-surface-offset);border-radius:var(--radius-full);padding:2px 8px;margin-left:4px;"><?= $isGlobeAdmin ? 'all roles preview' : 'role default' ?></span><?php endif; ?>
        </span>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <button class="nu-btn nu-btn-primary nu-btn-sm" onclick="nuDash.openBuilder()">+ Add Widget</button>
        <button class="nu-btn nu-btn-ghost nu-btn-sm" id="nuDashEditBtn" onclick="nuDash.toggleEditMode()">Edit Layout</button>
        <?php if ($hasPersonal): ?>
        <button class="nu-btn nu-btn-ghost nu-btn-sm" style="color:var(--color-error,#a12c7b);" onclick="nuDash.resetLayout()">Reset to Default</button>
        <?php endif; ?>
        <?php if ($isGlobeAdmin): ?>
        <button class="nu-btn nu-btn-ghost nu-btn-sm" style="color:var(--color-warning,#964219);" onclick="nuDash.openRoleDesigner()">Design Role Layout</button>
        <?php endif; ?>
    </div>
</div>

<!-- Outer wrapper - not a grid itself when role groups active; each group has its own sub-grid -->
<div id="nuWidgetGrid"<?= !$showRoleGroups ? ' style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;"' : '' ?>>
<?php if (empty($widgets)): ?>
    <div id="nuWidgetEmpty" style="<?= $showRoleGroups ? '' : 'grid-column:1/-1;' ?>">
        <div class="nu-card" style="text-align:center;padding:48px 24px;">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin:0 auto 16px;display:block;color:var(--color-text-faint);">
                <rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/>
                <rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>
            </svg>
            <p style="margin:0 0 16px;color:var(--color-text-muted);">No widgets yet - add your first one.</p>
            <button class="nu-btn nu-btn-primary" onclick="nuDash.openBuilder()">+ Add Widget</button>
        </div>
    </div>
<?php else: ?>

<?php foreach ($roleGroups as $groupKey => $groupWidgets):
    $isNamedGroup = ($showRoleGroups && $groupKey !== '__personal__');
    $accentColor  = $groupAccents[$accentIdx % count($groupAccents)];
    $accentIdx++;
    $displayRole  = htmlspecialchars($roleNames[$groupKey] ?? ucfirst($groupKey));
    $roleCode     = htmlspecialchars($groupKey);
    $widgetCount  = count($groupWidgets);
    $groupBodyId  = 'nuRoleGroup_' . preg_replace('/[^a-z0-9_]/i', '_', $groupKey);
?>

<?php if ($isNamedGroup): ?>
<!-- Role group wrapper -->
<div class="nu-role-group" style="margin-top:<?= $accentIdx > 1 ? '28px' : '0' ?>;">

    <!-- Clickable header -->
    <div
        class="nu-role-group-header"
        onclick="nuDash.toggleGroup('<?= $groupBodyId ?>', '<?= $roleCode ?>')"
        style="
            display:flex;
            align-items:center;
            gap:12px;
            padding:14px 16px;
            background:var(--color-surface-offset,#f8f9fa);
            border-radius:var(--radius-md,.5rem);
            border-left:4px solid <?= $accentColor ?>;
            cursor:pointer;
            user-select:none;
            margin-bottom:12px;
        "
    >
        <!-- Chevron -->
        <svg id="<?= $groupBodyId ?>_chevron" class="nu-group-chevron" width="16" height="16" viewBox="0 0 24 24"
             fill="none" stroke="currentColor" stroke-width="2.5" style="color:var(--color-text-muted,#888);">
            <polyline points="6 9 12 15 18 9"/>
        </svg>

        <!-- Role name + code pill + count -->
        <div style="display:flex;align-items:center;gap:8px;flex:1;min-width:0;">
            <span style="font-size:var(--text-sm,.875rem);font-weight:700;color:var(--color-text,#111);"><?= $displayRole ?></span>
            <span style="
                font-size:var(--text-xs,.75rem);
                font-weight:600;
                padding:2px 8px;
                border-radius:var(--radius-full,9999px);
                background:<?= $accentColor ?>;
                color:#fff;
                letter-spacing:.04em;
            "><?= $roleCode ?></span>
            <span style="font-size:var(--text-xs,.75rem);color:var(--color-text-muted,#888);">
                <?= $widgetCount ?> widget<?= $widgetCount !== 1 ? 's' : '' ?>
            </span>
        </div>

        <!-- Quick action: add widget for this role (stop propagation so it doesn't toggle) -->
        <button
            class="nu-btn nu-btn-ghost nu-btn-sm"
            style="color:<?= $accentColor ?>;white-space:nowrap;"
            onclick="event.stopPropagation();nuDash.openBuilderForRole('<?= $roleCode ?>')"
            title="Add widget for this role"
        >+ Add</button>
    </div>

    <!-- Collapsible sub-grid body -->
    <div
        id="<?= $groupBodyId ?>"
        class="nu-role-group-body"
        style="margin-top:0;"
    >
<?php else: ?>
<!-- Flat (non-grouped) widgets go straight into the outer grid -->
<?php endif; ?>

<?php foreach ($groupWidgets as $w):
    $colSpan = max(1, min(4, (int)($w['widget_width']  ?? 2)));
    $rowSpan = max(1, min(3, (int)($w['widget_height'] ?? 1)));
?>
    <div class="nu-widget-card nu-card" data-widget-id="<?= (int)$w['widget_id'] ?>"
         style="grid-column:span <?= $colSpan ?>;grid-row:span <?= $rowSpan ?>;position:relative;<?= $isNamedGroup ? 'border-top:2px solid ' . $accentColor . ';' : '' ?>">
        <div class="nu-card-header" style="margin-bottom:12px;">
            <h3 class="nu-card-title" style="font-size:var(--text-sm,.875rem);">
                <?php if (!empty($w['widget_icon'])): ?><span style="margin-right:6px;"><?= htmlspecialchars($w['widget_icon']) ?></span><?php endif; ?>
                <?= htmlspecialchars($w['widget_title']) ?>
            </h3>
            <div class="nu-widget-controls" style="display:flex;gap:4px;">
                <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="nuDash.editWidget(<?= (int)$w['widget_id'] ?>)" title="Configure">&#9881;</button>
                <button class="nu-btn nu-btn-ghost nu-btn-sm" style="color:var(--color-error);" onclick="nuDash.removeWidget(<?= (int)$w['widget_id'] ?>)" title="Remove">&times;</button>
            </div>
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

<!-- Add/Edit Widget Modal -->
<div id="nuBuilderModal" style="display:none;position:fixed;inset:0;z-index:1000;background:rgba(0,0,0,.45);overflow-y:auto;">
  <div style="background:var(--color-surface,#fff);border-radius:var(--radius-lg,.75rem);max-width:600px;margin:40px auto;padding:28px;box-shadow:var(--shadow-lg);">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
        <h3 style="margin:0;font-size:var(--text-lg,1.125rem);">Widget Builder</h3>
        <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="nuDash.closeBuilder()">&times;</button>
    </div>
    <input type="hidden" id="nuWid" value="">
    <div class="nu-field" style="margin-bottom:14px;">
        <label class="nu-label">Widget Type</label>
        <select class="nu-input" id="nuWType" onchange="nuDash.onTypeChange()">
            <option value="stat">Stat / KPI</option>
            <option value="chart_bar">Bar Chart</option>
            <option value="chart_line">Line Chart</option>
            <option value="chart_pie">Pie Chart</option>
            <option value="table">Data Table</option>
            <option value="list">Quick Links</option>
            <option value="progress">Progress Bar</option>
            <option value="custom">Custom HTML</option>
        </select>
    </div>
    <div class="nu-field" style="margin-bottom:14px;">
        <label class="nu-label">Title</label>
        <input class="nu-input" id="nuWTitle" placeholder="e.g. Pending Tasks">
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px;">
        <div class="nu-field"><label class="nu-label">Width (1-4 cols)</label>
            <select class="nu-input" id="nuWWidth">
                <option value="1">1 col</option>
                <option value="2" selected>2 cols</option>
                <option value="3">3 cols</option>
                <option value="4">Full width</option>
            </select>
        </div>
        <div class="nu-field"><label class="nu-label">Height (row spans)</label>
            <select class="nu-input" id="nuWHeight">
                <option value="1" selected>1 row</option>
                <option value="2">2 rows</option>
                <option value="3">3 rows</option>
            </select>
        </div>
    </div>
    <div id="nuWConfigArea"></div>
    <?php if ($isGlobeAdmin): ?>
    <div class="nu-field" style="margin:14px 0;padding:12px;background:var(--color-surface-offset);border-radius:var(--radius-md);">
        <label class="nu-label" style="color:var(--color-warning);">Assign to Role (globeadmin only)</label>
        <select class="nu-input" id="nuWTargetRole">
            <option value="">-- My personal dashboard only --</option>
        </select>
        <small style="color:var(--color-text-muted);font-size:11px;">Saving to a role sets the default for all users with that role.</small>
    </div>
    <?php endif; ?>
    <div id="nuWPreviewWrap" style="display:none;margin:14px 0;">
        <label class="nu-label">Live Preview</label>
        <div id="nuWPreview" class="nu-card" style="padding:16px;min-height:80px;background:var(--color-surface-offset);"></div>
    </div>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-top:20px;">
        <button class="nu-btn nu-btn-ghost" onclick="nuDash.runPreview()">Preview</button>
        <div style="display:flex;gap:8px;">
            <button class="nu-btn nu-btn-ghost" onclick="nuDash.closeBuilder()">Cancel</button>
            <button class="nu-btn nu-btn-primary" onclick="nuDash.saveWidget()">Save Widget</button>
        </div>
    </div>
  </div>
</div>

<!-- chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>

<script>
window.NUDASH_WIDGET_DATA = <?= $widgetsJson ?>;
</script>

<script src="modules/widgets/widgets.js?v=<?= filemtime(__DIR__ . '/widgets.js') ?>"></script>
