<?php
declare(strict_types=1);
/**
 * modules/widgets/widgets.php
 * Customisable dashboard widget builder.
 *
 * FRAGMENT — always included by dashboard.php or dashboard_user.php.
 * Bootstrap has already run. Do NOT add require_once bootstrap here.
 * $db, $_SESSION, $auth are all already available.
 */
if (!defined('NU_BOOTSTRAP_DONE')) {
    require_once dirname(__DIR__, 2) . '/core/module_bootstrap.php';
}

$db           = NuDatabase::getInstance();
$userId       = (int)($_SESSION['nu_user_id'] ?? 0);
$role         = strtolower((string)($_SESSION['nu_role'] ?? ''));
$isAdmin      = in_array($role, ['globeadmin', 'admin'], true);
$isGlobeAdmin = ($role === 'globeadmin');

// ── helpers ───────────────────────────────────────────────────────────────
function wu_resolve_widgets(NuDatabase $db, int $userId, string $role): array {
    try {
        $personal = $db->fetchAll(
            "SELECT * FROM nu_dashboard_widgets WHERE widget_user_id=? AND widget_active=1 ORDER BY widget_position",
            [$userId]
        );
        if (!empty($personal)) return $personal;
        return $db->fetchAll(
            "SELECT * FROM nu_dashboard_widgets WHERE widget_user_id IS NULL AND widget_role=? AND widget_active=1 ORDER BY widget_position",
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

function wu_chart_type(string $widgetType): string {
    switch ($widgetType) {
        case 'chart_pie':  return 'pie';
        case 'chart_line': return 'line';
        default:           return 'bar';
    }
}

// FIX 3: empty body hint — shown when a widget has no usable config yet
function wu_empty_hint(int $widgetId): string {
    return '<div style="display:flex;flex-direction:column;align-items:center;justify-content:center;'
         . 'padding:24px 12px;color:var(--color-text-muted,#888);text-align:center;gap:8px;">'
         . '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">'
         . '<circle cx="12" cy="12" r="3"/>'
         . '<path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2'
         . 'M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/>'
         . '</svg>'
         . '<span style="font-size:var(--text-xs,.75rem);">Not configured — click '
         . '<strong>⚙</strong> to set up this widget.</span>'
         . '</div>';
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
                if (isset($rows[0]['_error'])) {
                    return '<p style="color:var(--color-error,#a12c7b);font-size:12px;">SQL error: '
                         . htmlspecialchars($rows[0]['_error']) . '</p>';
                }
                $val = isset($rows[0]['value']) ? $rows[0]['value'] : (isset($rows[0]) ? reset($rows[0]) : 0);
                $sub = htmlspecialchars($cfg['subtitle'] ?? '');
                return '<div style="display:flex;flex-direction:column;gap:4px;padding:4px 0;">'
                     . '<div style="font-size:2.5rem;font-weight:800;line-height:1;color:' . $accent . ';font-variant-numeric:tabular-nums;">'
                     . number_format((float)$val) . '</div>'
                     . ($sub ? '<div style="font-size:var(--text-xs,.75rem);color:var(--color-text-muted,#888);">' . $sub . '</div>' : '')
                     . '</div>';

            case 'chart_bar':
            case 'chart_line':
            case 'chart_pie':
                $sql = trim($cfg['sql'] ?? '');
                if ($sql === '') return wu_empty_hint((int)$w['widget_id']);
                $rows   = wu_run_sql($db, $sql, $userId);
                if (isset($rows[0]['_error'])) {
                    return '<p style="color:var(--color-error,#a12c7b);font-size:12px;">SQL error: '
                         . htmlspecialchars($rows[0]['_error']) . '</p>';
                }
                $labels    = array_column($rows, 'label');
                $values    = array_column($rows, 'value');
                $ctype     = wu_chart_type($type);
                $id        = 'wc_' . $w['widget_id'];
                $bgColor   = ($ctype === 'pie')
                    ? ['#01696f','#437a22','#006494','#7a39bb','#da7101','#a12c7b']
                    : 'rgba(1,105,111,0.75)';
                $chartJson = json_encode([
                    'type' => $ctype,
                    'data' => ['labels' => $labels, 'datasets' => [[
                        'label'           => htmlspecialchars($w['widget_title']),
                        'data'            => $values,
                        'backgroundColor' => $bgColor,
                        'borderColor'     => 'rgba(1,105,111,1)',
                        'borderWidth'     => 1,
                        'tension'         => 0.4,
                        'fill'            => ($ctype === 'line'),
                    ]]],
                    'options' => [
                        'responsive'          => true,
                        'maintainAspectRatio' => false,
                        'plugins'             => ['legend' => ['display' => ($ctype === 'pie')]],
                        'scales'              => ($ctype === 'pie') ? (object)[] : ['y' => ['beginAtZero' => true]],
                    ],
                ]);
                return '<div style="height:220px;"><canvas id="' . $id . '" data-chartjs=\'' . htmlspecialchars($chartJson, ENT_QUOTES) . '\'></canvas></div>';

            case 'table':
                $sql = trim($cfg['sql'] ?? '');
                if ($sql === '') return wu_empty_hint((int)$w['widget_id']);
                $rows = wu_run_sql($db, $sql, $userId);
                if (isset($rows[0]['_error'])) {
                    return '<p style="color:var(--color-error,#a12c7b);font-size:12px;">SQL error: ' . htmlspecialchars($rows[0]['_error']) . '</p>';
                }
                if (empty($rows)) {
                    return '<p style="color:var(--color-text-muted,#888);padding:12px 0;">No data</p>';
                }
                $cols = array_keys($rows[0]);
                $html = '<div class="nu-table-wrap"><table class="nu-table"><thead><tr>';
                foreach ($cols as $c) $html .= '<th>' . htmlspecialchars(ucfirst($c)) . '</th>';
                $html .= '</tr></thead><tbody>';
                foreach ($rows as $row) {
                    $html .= '<tr>';
                    foreach ($row as $val) $html .= '<td>' . htmlspecialchars((string)$val) . '</td>';
                    $html .= '</tr>';
                }
                return $html . '</tbody></table></div>';

            case 'list':
                $items = $cfg['items'] ?? [];
                if (empty($items)) return wu_empty_hint((int)$w['widget_id']);
                $html = '<div style="display:flex;flex-direction:column;gap:6px;">';
                foreach ($items as $item) {
                    $label  = htmlspecialchars($item['label'] ?? '');
                    $module = htmlspecialchars($item['module'] ?? '');
                    $url    = htmlspecialchars($item['url'] ?? '');
                    $click  = $module
                        ? "NuApp.loadModule('" . $module . "')"
                        : "window.open('" . $url . "','_blank')";
                    $html  .= '<button class="nu-btn nu-btn-ghost" style="justify-content:flex-start;" onclick="' . $click . '">' . $label . '</button>';
                }
                return $html . '</div>';

            case 'progress':
                $sql = trim($cfg['sql'] ?? '');
                if ($sql === '') return wu_empty_hint((int)$w['widget_id']);
                $rows  = wu_run_sql($db, $sql, $userId);
                if (isset($rows[0]['_error'])) {
                    return '<p style="color:var(--color-error,#a12c7b);font-size:12px;">SQL error</p>';
                }
                $total = (float)(isset($rows[0]['total']) ? $rows[0]['total'] : 1);
                $done  = (float)(isset($rows[0]['done'])  ? $rows[0]['done']  : 0);
                $pct   = $total > 0 ? min(100, (int)round($done / $total * 100)) : 0;
                $label = htmlspecialchars($cfg['label'] ?? "$done / $total");
                return '<div style="margin-top:4px;">'
                     . '<div style="display:flex;justify-content:space-between;font-size:var(--text-xs,.75rem);color:var(--color-text-muted);margin-bottom:6px;">'
                     . '<span>' . $label . '</span><span>' . $pct . '%</span></div>'
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
        return '<p style="color:var(--color-error,#a12c7b);font-size:12px;">Widget error: ' . htmlspecialchars($e->getMessage()) . '</p>';
    }
}

// ── Resolve widgets ───────────────────────────────────────────────────────
$widgets = wu_resolve_widgets($db, $userId, $role);

try {
    $hasPersonal = !empty($db->fetchAll(
        "SELECT widget_id FROM nu_dashboard_widgets WHERE widget_user_id=? AND widget_active=1 LIMIT 1",
        [$userId]
    ));
} catch (Throwable $e) {
    error_log('[widgets] hasPersonal error: ' . $e->getMessage());
    $hasPersonal = false;
}

// Pass all widget data to JS for FIX 2 (populate edit form)
$widgetsJson = htmlspecialchars(json_encode(
    array_column($widgets, null, 'widget_id')
), ENT_QUOTES);
?>

<!-- Toolbar -->
<div id="nuDashToolbar" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:20px;">
    <div style="display:flex;align-items:center;gap:8px;">
        <span style="font-size:var(--text-sm,.875rem);font-weight:600;color:var(--color-text-muted);">&#x1F4CA; My Dashboard
            <?php if (!$hasPersonal): ?><span style="font-size:var(--text-xs,.75rem);background:var(--color-surface-offset);border-radius:var(--radius-full);padding:2px 8px;margin-left:4px;">role default</span><?php endif; ?>
        </span>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <button class="nu-btn nu-btn-primary nu-btn-sm" onclick="nuDash.openBuilder()">&#xFF0B; Add Widget</button>
        <button class="nu-btn nu-btn-ghost nu-btn-sm" id="nuDashEditBtn" onclick="nuDash.toggleEditMode()">&#x270F;&#xFE0F; Edit Layout</button>
        <?php if ($hasPersonal): ?>
        <button class="nu-btn nu-btn-ghost nu-btn-sm" style="color:var(--color-error,#a12c7b);" onclick="nuDash.resetLayout()">&#x21A9; Reset to Default</button>
        <?php endif; ?>
        <?php if ($isGlobeAdmin): ?>
        <button class="nu-btn nu-btn-ghost nu-btn-sm" style="color:var(--color-warning,#964219);" onclick="nuDash.openRoleDesigner()">&#x1F6E1;&#xFE0F; Design Role Layout</button>
        <?php endif; ?>
    </div>
</div>

<!-- Widget Grid -->
<div id="nuWidgetGrid" style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;">
<?php if (empty($widgets)): ?>
    <div id="nuWidgetEmpty" style="grid-column:1/-1;">
        <div class="nu-card" style="text-align:center;padding:48px 24px;">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"
                 style="margin:0 auto 16px;display:block;color:var(--color-text-faint);">
                <rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/>
                <rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>
            </svg>
            <p style="margin:0 0 16px;color:var(--color-text-muted);">No widgets yet &mdash; add your first one.</p>
            <button class="nu-btn nu-btn-primary" onclick="nuDash.openBuilder()">&#xFF0B; Add Widget</button>
        </div>
    </div>
<?php else: ?>
<?php foreach ($widgets as $w):
    $colSpan = max(1, min(4, (int)($w['widget_width']  ?? 2)));
    $rowSpan = max(1, min(3, (int)($w['widget_height'] ?? 1)));
?>
    <div class="nu-widget-card nu-card" data-widget-id="<?= (int)$w['widget_id'] ?>"
         style="grid-column:span <?= $colSpan ?>;grid-row:span <?= $rowSpan ?>;position:relative;">
        <div class="nu-card-header" style="margin-bottom:12px;">
            <h3 class="nu-card-title" style="font-size:var(--text-sm,.875rem);">
                <?php if (!empty($w['widget_icon'])): ?>
                <span style="margin-right:6px;"><?= htmlspecialchars($w['widget_icon']) ?></span>
                <?php endif; ?>
                <?= htmlspecialchars($w['widget_title']) ?>
            </h3>
            <!-- Controls always visible so user can always reach ⚙ to fix empty widgets -->
            <div class="nu-widget-controls" style="display:flex;gap:4px;">
                <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="nuDash.editWidget(<?= (int)$w['widget_id'] ?>)" title="Configure">&#x2699;&#xFE0F;</button>
                <button class="nu-btn nu-btn-ghost nu-btn-sm" style="color:var(--color-error);" onclick="nuDash.removeWidget(<?= (int)$w['widget_id'] ?>)" title="Remove">&times;</button>
            </div>
        </div>
        <div class="nu-widget-body"><?= wu_render($w, $db, $userId) ?></div>
    </div>
<?php endforeach; ?>
<?php endif; ?>
</div>

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
            <option value="stat">&#x1F4CA; Stat / KPI</option>
            <option value="chart_bar">&#x1F4CA; Bar Chart</option>
            <option value="chart_line">&#x1F4C8; Line Chart</option>
            <option value="chart_pie">&#x1F967; Pie Chart</option>
            <option value="table">&#x1F4CB; Data Table</option>
            <option value="list">&#x1F517; Quick Links</option>
            <option value="progress">&#x2B1B; Progress Bar</option>
            <option value="custom">&#x1F9E9; Custom HTML</option>
        </select>
    </div>
    <div class="nu-field" style="margin-bottom:14px;">
        <label class="nu-label">Title</label>
        <input class="nu-input" id="nuWTitle" placeholder="e.g. Pending Tasks">
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px;">
        <div class="nu-field"><label class="nu-label">Width (1-4 cols)</label>
            <select class="nu-input" id="nuWWidth"><option value="1">1 col</option><option value="2" selected>2 cols</option><option value="3">3 cols</option><option value="4">Full width</option></select>
        </div>
        <div class="nu-field"><label class="nu-label">Height (row spans)</label>
            <select class="nu-input" id="nuWHeight"><option value="1" selected>1 row</option><option value="2">2 rows</option><option value="3">3 rows</option></select>
        </div>
    </div>
    <div id="nuWConfigArea"></div>
    <?php if ($isGlobeAdmin): ?>
    <div class="nu-field" style="margin:14px 0;padding:12px;background:var(--color-surface-offset);border-radius:var(--radius-md);">
        <label class="nu-label" style="color:var(--color-warning);">&#x1F6E1;&#xFE0F; Save as Role Default (globeadmin only)</label>
        <select class="nu-input" id="nuWTargetRole">
            <option value="">&mdash; My personal dashboard only &mdash;</option>
            <option value="user">user</option>
            <option value="manager">manager</option>
            <option value="supervisor">supervisor</option>
            <option value="admin">admin</option>
        </select>
    </div>
    <?php endif; ?>
    <div id="nuWPreviewWrap" style="display:none;margin:14px 0;">
        <label class="nu-label">Live Preview</label>
        <div id="nuWPreview" class="nu-card" style="padding:16px;min-height:80px;background:var(--color-surface-offset);"></div>
    </div>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-top:20px;">
        <button class="nu-btn nu-btn-ghost" onclick="nuDash.runPreview()">&#x1F441; Preview</button>
        <div style="display:flex;gap:8px;">
            <button class="nu-btn nu-btn-ghost" onclick="nuDash.closeBuilder()">Cancel</button>
            <button class="nu-btn nu-btn-primary" onclick="nuDash.saveWidget()">Save Widget</button>
        </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
(function(){
'use strict';
const API = 'modules/dashboard/widget_api.php';
const chartInstances = {};

// FIX 2: all saved widget data indexed by id for repopulating edit form
const WIDGET_DATA = <?= $widgetsJson ?>;

function initCharts() {
    document.querySelectorAll('[data-chartjs]').forEach(function(canvas) {
        var id = canvas.id;
        if (chartInstances[id]) { chartInstances[id].destroy(); }
        try {
            var cfg = JSON.parse(canvas.dataset.chartjs);
            chartInstances[id] = new Chart(canvas, cfg);
        } catch(e) { console.warn('[nuDash chart]', e); }
    });
}

var TYPE_CONFIGS = {
    stat: '<div class="nu-field" style="margin-bottom:12px;"><label class="nu-label">SQL <small style="color:var(--color-text-muted)">must return a <code>value</code> column</small></label><textarea class="nu-input" id="nuWSql" rows="3" placeholder="SELECT COUNT(*) as value FROM my_table"></textarea></div><div class="nu-field" style="margin-bottom:12px;"><label class="nu-label">Subtitle (optional)</label><input class="nu-input" id="nuWSubtitle" placeholder="Pending tasks"></div><div class="nu-field"><label class="nu-label">Accent colour</label><select class="nu-input" id="nuWColor"><option value="primary">Teal</option><option value="success">Green</option><option value="warning">Orange</option><option value="error">Red</option></select></div>',
    chart_bar: '<div class="nu-field"><label class="nu-label">SQL <small style="color:var(--color-text-muted)">columns: <code>label</code>, <code>value</code></small></label><textarea class="nu-input" id="nuWSql" rows="4" placeholder="SELECT status AS label, COUNT(*) AS value FROM my_table GROUP BY status"></textarea></div>',
    chart_line: '<div class="nu-field"><label class="nu-label">SQL (columns: <code>label</code>, <code>value</code>)</label><textarea class="nu-input" id="nuWSql" rows="4" placeholder="SELECT DATE(created_at) AS label, COUNT(*) AS value FROM my_table GROUP BY DATE(created_at) ORDER BY label"></textarea></div>',
    chart_pie: '<div class="nu-field"><label class="nu-label">SQL (columns: <code>label</code>, <code>value</code>)</label><textarea class="nu-input" id="nuWSql" rows="4" placeholder="SELECT category AS label, COUNT(*) AS value FROM my_table GROUP BY category"></textarea></div>',
    table: '<div class="nu-field"><label class="nu-label">SQL <small style="color:var(--color-text-muted)">use <code>{{user_id}}</code> to filter by current user</small></label><textarea class="nu-input" id="nuWSql" rows="4" placeholder="SELECT title AS Task, status AS Status FROM my_tasks LIMIT 10"></textarea></div>',
    list: '<div class="nu-field"><label class="nu-label">Links (one per line: <code>Label|module_name</code> or <code>Label|https://url</code>)</label><textarea class="nu-input" id="nuWLinks" rows="5" placeholder="Open Forms|forms\nMy Reports|reports"></textarea></div>',
    progress: '<div class="nu-field" style="margin-bottom:12px;"><label class="nu-label">SQL <small style="color:var(--color-text-muted)">must return <code>done</code> and <code>total</code> columns</small></label><textarea class="nu-input" id="nuWSql" rows="3"></textarea></div><div class="nu-field"><label class="nu-label">Label</label><input class="nu-input" id="nuWSubtitle" placeholder="Tasks completed"></div>',
    custom: '<div class="nu-field"><label class="nu-label">HTML Content</label><textarea class="nu-input" id="nuWHtml" rows="6" placeholder="<p>Any HTML here...</p>"></textarea></div>'
};

window.nuDash = {
    editMode: false,
    editingId: null,

    openBuilder: function(id) {
        this.editingId = id || null;
        document.getElementById('nuWid').value = id || '';
        document.getElementById('nuWPreviewWrap').style.display = 'none';

        if (id && WIDGET_DATA[id]) {
            // FIX 2: Populate form with existing widget data
            var w   = WIDGET_DATA[id];
            var cfg = {};
            try { cfg = JSON.parse(w.widget_config || '{}'); } catch(e) {}

            document.getElementById('nuWType').value   = w.widget_type  || 'stat';
            document.getElementById('nuWTitle').value  = w.widget_title || '';
            document.getElementById('nuWWidth').value  = String(w.widget_width  || 2);
            document.getElementById('nuWHeight').value = String(w.widget_height || 1);

            // Render the config fields first, then fill them in
            this.onTypeChange();

            var type   = w.widget_type || 'stat';
            var sqlEl  = document.getElementById('nuWSql');
            var subEl  = document.getElementById('nuWSubtitle');
            var colEl  = document.getElementById('nuWColor');
            var lnkEl  = document.getElementById('nuWLinks');
            var htmEl  = document.getElementById('nuWHtml');

            if (sqlEl)  sqlEl.value  = cfg.sql      || '';
            if (subEl)  subEl.value  = cfg.subtitle || cfg.label || '';
            if (colEl)  colEl.value  = cfg.color    || 'primary';
            if (htmEl)  htmEl.value  = cfg.html     || '';
            if (lnkEl && cfg.items) {
                lnkEl.value = cfg.items.map(function(item) {
                    return item.label + '|' + (item.module || item.url || '');
                }).join('\n');
            }

            var rEl = document.getElementById('nuWTargetRole');
            if (rEl) rEl.value = w.widget_role || '';
        } else {
            // New widget — blank form
            document.getElementById('nuWType').value   = 'stat';
            document.getElementById('nuWTitle').value  = '';
            document.getElementById('nuWWidth').value  = '2';
            document.getElementById('nuWHeight').value = '1';
            var rEl = document.getElementById('nuWTargetRole');
            if (rEl) rEl.value = '';
            this.onTypeChange();
        }

        document.getElementById('nuBuilderModal').style.display = 'block';
    },

    closeBuilder: function() {
        document.getElementById('nuBuilderModal').style.display = 'none';
        document.getElementById('nuWPreviewWrap').style.display = 'none';
    },

    onTypeChange: function() {
        var type = document.getElementById('nuWType').value;
        document.getElementById('nuWConfigArea').innerHTML = TYPE_CONFIGS[type] || '';
    },

    buildConfig: function() {
        var type  = document.getElementById('nuWType').value;
        var sqlEl = document.getElementById('nuWSql');
        var sql   = sqlEl ? sqlEl.value.trim() : '';
        switch(type) {
            case 'stat':
                return {
                    sql:      sql,
                    subtitle: (document.getElementById('nuWSubtitle') || {}).value || '',
                    color:    (document.getElementById('nuWColor')    || {}).value || 'primary'
                };
            case 'chart_bar':
            case 'chart_line':
            case 'chart_pie':
            case 'table':
                return { sql: sql };
            case 'progress':
                return { sql: sql, label: (document.getElementById('nuWSubtitle') || {}).value || '' };
            case 'list':
                var lines = ((document.getElementById('nuWLinks') || {}).value || '').split('\n').filter(Boolean);
                return { items: lines.map(function(l) {
                    var parts  = l.split('|');
                    var label  = parts[0] ? parts[0].trim() : '';
                    var target = parts[1] ? parts[1].trim() : '';
                    return target.indexOf('http') === 0
                        ? { label: label, url: target }
                        : { label: label, module: target };
                })};
            case 'custom':
                return { html: (document.getElementById('nuWHtml') || {}).value || '' };
            default:
                return {};
        }
    },

    // FIX 1: validate before save
    validateConfig: function(type, config) {
        var sqlTypes = ['stat','chart_bar','chart_line','chart_pie','table','progress'];
        if (sqlTypes.indexOf(type) !== -1 && !config.sql) {
            return 'Please enter a SQL query for this widget type.';
        }
        if (type === 'list' && (!config.items || config.items.length === 0)) {
            return 'Please add at least one link (Label|module or Label|https://...).';
        }
        if (type === 'custom' && !config.html) {
            return 'Please enter some HTML content for this widget.';
        }
        return null; // valid
    },

    runPreview: function() {
        var self   = this;
        var config = this.buildConfig();
        var wrap   = document.getElementById('nuWPreviewWrap');
        var prev   = document.getElementById('nuWPreview');
        wrap.style.display = 'block';
        prev.innerHTML = '<span style="color:var(--color-text-muted)">Loading...</span>';
        if (config.sql) {
            fetch(API + '?action=run_sql', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ sql: config.sql })
            }).then(function(r) {
                return r.json();
            }).then(function(d) {
                if (d.error) {
                    prev.innerHTML = '<span style="color:var(--color-error)">' + d.error + '</span>';
                } else {
                    prev.innerHTML = '<pre style="font-size:12px;white-space:pre-wrap;">' + JSON.stringify((d.rows || []).slice(0, 3), null, 2) + '</pre>';
                }
            }).catch(function() {
                prev.innerHTML = '<span style="color:var(--color-error)">Request failed</span>';
            });
        } else {
            prev.innerHTML = '<em>No SQL to preview for this widget type.</em>';
        }
    },

    saveWidget: function() {
        var self       = this;
        var id         = document.getElementById('nuWid').value;
        var type       = document.getElementById('nuWType').value;
        var titleEl    = document.getElementById('nuWTitle');
        var title      = (titleEl && titleEl.value.trim()) ? titleEl.value.trim() : '';
        var width      = parseInt(document.getElementById('nuWWidth').value)  || 2;
        var height     = parseInt(document.getElementById('nuWHeight').value) || 1;
        var config     = this.buildConfig();
        var roleEl     = document.getElementById('nuWTargetRole');
        var targetRole = roleEl ? roleEl.value : null;

        // FIX 1: validate
        if (!title) {
            alert('Please enter a title for this widget.');
            document.getElementById('nuWTitle').focus();
            return;
        }
        var validationError = this.validateConfig(type, config);
        if (validationError) {
            alert(validationError);
            return;
        }

        var payload  = { type: type, title: title, width: width, height: height, config: config };
        if (targetRole) payload.target_role = targetRole;
        if (id) payload.id = id;
        var action = id ? 'update' : 'add';
        fetch(API + '?action=' + action, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        }).then(function(r) {
            return r.json();
        }).then(function(d) {
            if (d.ok) { self.closeBuilder(); location.reload(); }
            else alert('Error: ' + (d.error || 'Unknown'));
        }).catch(function(e) { alert('Request failed: ' + e.message); });
    },

    removeWidget: function(id) {
        if (!confirm('Remove this widget from your dashboard?')) return;
        fetch(API + '?action=remove', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: id })
        }).then(function(r) {
            return r.json();
        }).then(function(d) {
            if (d.ok) location.reload();
            else alert('Error: ' + (d.error || ''));
        }).catch(function() { alert('Request failed'); });
    },

    editWidget: function(id) { this.openBuilder(id); },

    toggleEditMode: function() {
        this.editMode = !this.editMode;
        var btn = document.getElementById('nuDashEditBtn');
        // In edit mode, hide the always-visible controls and show drag outlines only
        document.querySelectorAll('.nu-widget-card').forEach(function(el) {
            el.style.outline = this.editMode ? '2px dashed var(--color-primary,#01696f)' : '';
            el.draggable = this.editMode;
        }.bind(this));
        if (btn) btn.textContent = this.editMode ? '\u2705 Done Editing' : '\u270F\uFE0F Edit Layout';
        if (this.editMode) this.initDrag();
    },

    initDrag: function() {
        var self = this;
        var grid = document.getElementById('nuWidgetGrid');
        var dragSrc = null;
        grid.querySelectorAll('.nu-widget-card').forEach(function(card) {
            card.addEventListener('dragstart', function() { dragSrc = card; card.style.opacity = '.4'; });
            card.addEventListener('dragend',   function() { card.style.opacity = ''; });
            card.addEventListener('dragover',  function(e) { e.preventDefault(); });
            card.addEventListener('drop', function(e) {
                e.preventDefault();
                if (dragSrc && dragSrc !== card) {
                    var cards = Array.prototype.slice.call(grid.querySelectorAll('.nu-widget-card'));
                    if (cards.indexOf(dragSrc) < cards.indexOf(card)) card.after(dragSrc);
                    else card.before(dragSrc);
                    self.persistOrder();
                }
            });
        });
    },

    persistOrder: function() {
        var order = Array.prototype.slice.call(document.querySelectorAll('.nu-widget-card')).map(function(c, i) {
            return { id: parseInt(c.dataset.widgetId), position: (i + 1) * 10 };
        });
        fetch(API + '?action=reorder', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ order: order })
        });
    },

    resetLayout: function() {
        if (!confirm('Reset to role default layout? Your personal widgets will be removed.')) return;
        fetch(API + '?action=reset', { method: 'POST' })
            .then(function(r) { return r.json(); })
            .then(function(d) { if (d.ok) location.reload(); })
            .catch(function() { alert('Request failed'); });
    },

    openRoleDesigner: function() {
        var rEl = document.getElementById('nuWTargetRole');
        if (rEl) rEl.value = 'user';
        this.openBuilder();
    }
};

document.addEventListener('DOMContentLoaded', initCharts);
if (document.readyState !== 'loading') initCharts();
})();
</script>
