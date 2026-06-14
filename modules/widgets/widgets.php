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

function wu_render(array $w, NuDatabase $db, int $userId): string {
    try {
        $cfg   = json_decode($w['widget_config'] ?? '{}', true) ?: [];
        $type  = $w['widget_type'] ?? 'custom';
        $accent = match($cfg['color'] ?? 'primary') {
            'success' => 'var(--color-success,#437a22)',
            'warning' => 'var(--color-warning,#964219)',
            'error'   => 'var(--color-error,#a12c7b)',
            default   => 'var(--color-primary,#01696f)',
        };
        switch ($type) {
            case 'stat':
                $rows = wu_run_sql($db, $cfg['sql'] ?? 'SELECT 0 as value', $userId);
                if (isset($rows[0]['_error'])) return '<p style="color:var(--color-error,#a12c7b);font-size:12px;">SQL error</p>';
                $val  = $rows[0]['value'] ?? (isset($rows[0]) ? reset($rows[0]) : 0);
                $sub  = htmlspecialchars($cfg['subtitle'] ?? '');
                return '<div style="display:flex;flex-direction:column;gap:4px;padding:4px 0;">'
                     . '<div style="font-size:2.5rem;font-weight:800;line-height:1;color:' . $accent . ';font-variant-numeric:tabular-nums;">'
                     . number_format((float)$val) . '</div>'
                     . ($sub ? '<div style="font-size:var(--text-xs,.75rem);color:var(--color-text-muted,#888);">' . $sub . '</div>' : '')
                     . '</div>';

            case 'chart_bar':
            case 'chart_line':
            case 'chart_pie':
                $rows   = wu_run_sql($db, $cfg['sql'] ?? '', $userId);
                if (isset($rows[0]['_error'])) return '<p style="color:var(--color-error,#a12c7b);font-size:12px;">SQL error</p>';
                $labels = array_column($rows, 'label');
                $values = array_column($rows, 'value');
                $ctype  = match($type) { 'chart_pie' => 'pie', 'chart_line' => 'line', default => 'bar' };
                $id     = 'wc_' . $w['widget_id'];
                $chartJson = json_encode([
                    'type' => $ctype,
                    'data' => ['labels' => $labels, 'datasets' => [[
                        'label'           => htmlspecialchars($w['widget_title']),
                        'data'            => $values,
                        'backgroundColor' => $ctype === 'pie'
                            ? ['#01696f','#437a22','#006494','#7a39bb','#da7101','#a12c7b']
                            : 'rgba(1,105,111,0.75)',
                        'borderColor' => 'rgba(1,105,111,1)',
                        'borderWidth' => 1,
                        'tension'     => 0.4,
                        'fill'        => $ctype === 'line',
                    ]]],
                    'options' => [
                        'responsive'          => true,
                        'maintainAspectRatio' => false,
                        'plugins'             => ['legend' => ['display' => $ctype === 'pie']],
                        'scales'              => $ctype === 'pie' ? (object)[] : ['y' => ['beginAtZero' => true]],
                    ],
                ]);
                return '<div style="height:220px;"><canvas id="' . $id . '" data-chartjs=\'' . htmlspecialchars($chartJson, ENT_QUOTES) . '\'></canvas></div>';

            case 'table':
                $rows = wu_run_sql($db, $cfg['sql'] ?? '', $userId);
                if (isset($rows[0]['_error'])) return '<p style="color:var(--color-error,#a12c7b);font-size:12px;">SQL error: ' . htmlspecialchars($rows[0]['_error']) . '</p>';
                if (empty($rows)) return '<p style="color:var(--color-text-muted,#888);padding:12px 0;">No data</p>';
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
                if (empty($items)) return '<p style="color:var(--color-text-muted);padding:12px 0;">No links configured</p>';
                $html = '<div style="display:flex;flex-direction:column;gap:6px;">';
                foreach ($items as $item) {
                    $label  = htmlspecialchars($item['label'] ?? '');
                    $module = htmlspecialchars($item['module'] ?? '');
                    $url    = htmlspecialchars($item['url'] ?? '');
                    $click  = $module ? "NuApp.loadModule('" . $module . "')" : "window.open('" . $url . "','_blank')";
                    $html  .= '<button class="nu-btn nu-btn-ghost" style="justify-content:flex-start;" onclick="' . $click . '">' . $label . '</button>';
                }
                return $html . '</div>';

            case 'progress':
                $rows   = wu_run_sql($db, $cfg['sql'] ?? '', $userId);
                if (isset($rows[0]['_error'])) return '<p style="color:var(--color-error,#a12c7b);font-size:12px;">SQL error</p>';
                $total  = (float)($rows[0]['total'] ?? 1);
                $done   = (float)($rows[0]['done']  ?? 0);
                $pct    = $total > 0 ? min(100, round($done / $total * 100)) : 0;
                $label  = htmlspecialchars($cfg['label'] ?? "$done / $total");
                return '<div style="margin-top:4px;">'
                     . '<div style="display:flex;justify-content:space-between;font-size:var(--text-xs,.75rem);color:var(--color-text-muted);margin-bottom:6px;"><span>' . $label . '</span><span>' . $pct . '%</span></div>'
                     . '<div style="height:8px;border-radius:var(--radius-full,9999px);background:var(--color-surface-offset,#eee);overflow:hidden;">'
                     . '<div style="width:' . $pct . '%;height:100%;background:' . $accent . ';border-radius:inherit;transition:width .6s ease;"></div></div></div>';

            case 'custom':
                return $cfg['html'] ?? '<p style="color:var(--color-text-muted);">No content set.</p>';

            default:
                return '<p style="color:var(--color-text-muted);">Unknown widget type: ' . htmlspecialchars($type) . '</p>';
        }
    } catch (Throwable $e) {
        error_log('[widget render] id=' . ($w['widget_id'] ?? '?') . ' ' . $e->getMessage());
        return '<p style="color:var(--color-error,#a12c7b);font-size:12px;">Widget error: ' . htmlspecialchars($e->getMessage()) . '</p>';
    }
}

// ── Resolve widgets ────────────────────────────────────────────────────────
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
    $colSpan = max(1, min(4, (int)($w['widget_width'] ?? 2)));
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
            <div class="nu-widget-controls" style="display:none;gap:4px;">
                <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="nuDash.editWidget(<?= (int)$w['widget_id'] ?>)" title="Edit">&#x2699;&#xFE0F;</button>
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

function initCharts() {
    document.querySelectorAll('[data-chartjs]').forEach(canvas => {
        const id = canvas.id;
        if (chartInstances[id]) { chartInstances[id].destroy(); }
        try {
            const cfg = JSON.parse(canvas.dataset.chartjs);
            chartInstances[id] = new Chart(canvas, cfg);
        } catch(e) { console.warn('[nuDash chart]', e); }
    });
}

const TYPE_CONFIGS = {
    stat: `<div class="nu-field" style="margin-bottom:12px;"><label class="nu-label">SQL <small style="color:var(--color-text-muted)">must return a <code>value</code> column</small></label><textarea class="nu-input" id="nuWSql" rows="3" placeholder="SELECT COUNT(*) as value FROM my_table WHERE status='pending'"></textarea></div><div class="nu-field" style="margin-bottom:12px;"><label class="nu-label">Subtitle (optional)</label><input class="nu-input" id="nuWSubtitle" placeholder="Pending tasks"></div><div class="nu-field"><label class="nu-label">Accent colour</label><select class="nu-input" id="nuWColor"><option value="primary">Teal</option><option value="success">Green</option><option value="warning">Orange</option><option value="error">Red</option></select></div>`,
    chart_bar: `<div class="nu-field"><label class="nu-label">SQL <small style="color:var(--color-text-muted)">must return <code>label</code> and <code>value</code> columns</small></label><textarea class="nu-input" id="nuWSql" rows="4" placeholder="SELECT status AS label, COUNT(*) AS value FROM my_table GROUP BY status"></textarea></div>`,
    chart_line: `<div class="nu-field"><label class="nu-label">SQL (columns: <code>label</code>, <code>value</code>)</label><textarea class="nu-input" id="nuWSql" rows="4" placeholder="SELECT DATE(created_at) AS label, COUNT(*) AS value FROM my_table GROUP BY DATE(created_at) ORDER BY label"></textarea></div>`,
    chart_pie: `<div class="nu-field"><label class="nu-label">SQL (columns: <code>label</code>, <code>value</code>)</label><textarea class="nu-input" id="nuWSql" rows="4" placeholder="SELECT category AS label, COUNT(*) AS value FROM my_table GROUP BY category"></textarea></div>`,
    table: `<div class="nu-field"><label class="nu-label">SQL <small style="color:var(--color-text-muted)">column names become headers; use <code>{{user_id}}</code> to filter by current user</small></label><textarea class="nu-input" id="nuWSql" rows="4" placeholder="SELECT title AS Task, status AS Status FROM my_tasks WHERE assigned_to={{user_id}} LIMIT 10"></textarea></div>`,
    list: `<div class="nu-field"><label class="nu-label">Links (one per line: <code>Label|module_name</code> or <code>Label|https://url</code>)</label><textarea class="nu-input" id="nuWLinks" rows="5" placeholder="Open Forms|forms&#10;My Reports|reports&#10;Google|https://google.com"></textarea></div>`,
    progress: `<div class="nu-field" style="margin-bottom:12px;"><label class="nu-label">SQL <small style="color:var(--color-text-muted)">must return <code>done</code> and <code>total</code> columns</small></label><textarea class="nu-input" id="nuWSql" rows="3" placeholder="SELECT SUM(CASE WHEN status='done' THEN 1 ELSE 0 END) AS done, COUNT(*) AS total FROM my_tasks"></textarea></div><div class="nu-field"><label class="nu-label">Label</label><input class="nu-input" id="nuWSubtitle" placeholder="Tasks completed"></div>`,
    custom: `<div class="nu-field"><label class="nu-label">HTML Content</label><textarea class="nu-input" id="nuWHtml" rows="6" placeholder="<p>Any HTML here...</p>"></textarea></div>`,
};

window.nuDash = {
    editMode: false,
    editingId: null,

    openBuilder(id) {
        this.editingId = id || null;
        document.getElementById('nuWid').value = id || '';
        if (!id) {
            document.getElementById('nuWType').value   = 'stat';
            document.getElementById('nuWTitle').value  = '';
            document.getElementById('nuWWidth').value  = '2';
            document.getElementById('nuWHeight').value = '1';
            const rEl = document.getElementById('nuWTargetRole');
            if (rEl) rEl.value = '';
        }
        this.onTypeChange();
        document.getElementById('nuBuilderModal').style.display = 'block';
    },

    closeBuilder() {
        document.getElementById('nuBuilderModal').style.display = 'none';
        document.getElementById('nuWPreviewWrap').style.display = 'none';
    },

    onTypeChange() {
        const type = document.getElementById('nuWType').value;
        document.getElementById('nuWConfigArea').innerHTML = TYPE_CONFIGS[type] || '';
    },

    buildConfig() {
        const type = document.getElementById('nuWType').value;
        const sql  = document.getElementById('nuWSql')?.value?.trim();
        switch(type) {
            case 'stat':      return { sql, subtitle: document.getElementById('nuWSubtitle')?.value, color: document.getElementById('nuWColor')?.value };
            case 'chart_bar':
            case 'chart_line':
            case 'chart_pie': return { sql };
            case 'table':     return { sql };
            case 'progress':  return { sql, label: document.getElementById('nuWSubtitle')?.value };
            case 'list': {
                const lines = (document.getElementById('nuWLinks')?.value || '').split('\n').filter(Boolean);
                return { items: lines.map(l => {
                    const [label, target] = l.split('|');
                    return (target||'').trim().startsWith('http')
                        ? { label: label.trim(), url: target.trim() }
                        : { label: label.trim(), module: (target||'').trim() };
                })};
            }
            case 'custom': return { html: document.getElementById('nuWHtml')?.value };
            default: return {};
        }
    },

    async runPreview() {
        const config = this.buildConfig();
        const wrap   = document.getElementById('nuWPreviewWrap');
        const prev   = document.getElementById('nuWPreview');
        wrap.style.display = 'block';
        prev.innerHTML = '<span style="color:var(--color-text-muted)">Loading...</span>';
        if (config.sql) {
            try {
                const r = await fetch(API + '?action=run_sql', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({sql:config.sql}) });
                const d = await r.json();
                if (d.error) { prev.innerHTML = '<span style="color:var(--color-error)">' + d.error + '</span>'; return; }
                prev.innerHTML = '<pre style="font-size:12px;white-space:pre-wrap;">' + JSON.stringify(d.rows?.slice(0,3), null, 2) + '</pre>';
            } catch(e) { prev.innerHTML = '<span style="color:var(--color-error)">Request failed</span>'; }
        } else {
            prev.innerHTML = '<em>No SQL to preview for this widget type.</em>';
        }
    },

    async saveWidget() {
        const id     = document.getElementById('nuWid').value;
        const type   = document.getElementById('nuWType').value;
        const title  = (document.getElementById('nuWTitle').value.trim()) || 'Widget';
        const width  = parseInt(document.getElementById('nuWWidth').value);
        const height = parseInt(document.getElementById('nuWHeight').value);
        const config = this.buildConfig();
        const roleEl = document.getElementById('nuWTargetRole');
        const targetRole = roleEl?.value || null;
        const payload = { type, title, width, height, config };
        if (targetRole) payload.target_role = targetRole;
        if (id) payload.id = id;
        const action = id ? 'update' : 'add';
        try {
            const r = await fetch(API + '?action=' + action, { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(payload) });
            const d = await r.json();
            if (d.ok) { this.closeBuilder(); location.reload(); }
            else alert('Error: ' + (d.error || 'Unknown'));
        } catch(e) { alert('Request failed: ' + e.message); }
    },

    async removeWidget(id) {
        if (!confirm('Remove this widget from your dashboard?')) return;
        try {
            const r = await fetch(API + '?action=remove', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({id}) });
            const d = await r.json();
            if (d.ok) location.reload();
            else alert('Error: ' + (d.error||''));
        } catch(e) { alert('Request failed'); }
    },

    editWidget(id) { this.openBuilder(id); },

    toggleEditMode() {
        this.editMode = !this.editMode;
        const btn = document.getElementById('nuDashEditBtn');
        document.querySelectorAll('.nu-widget-controls').forEach(el => el.style.display = this.editMode ? 'flex' : 'none');
        document.querySelectorAll('.nu-widget-card').forEach(el => {
            el.style.outline = this.editMode ? '2px dashed var(--color-primary,#01696f)' : '';
            el.draggable = this.editMode;
        });
        btn.textContent = this.editMode ? '\u2705 Done Editing' : '\u270F\uFE0F Edit Layout';
        if (this.editMode) this.initDrag();
    },

    initDrag() {
        const grid = document.getElementById('nuWidgetGrid');
        let dragSrc = null;
        grid.querySelectorAll('.nu-widget-card').forEach(card => {
            card.addEventListener('dragstart', () => { dragSrc = card; card.style.opacity = '.4'; });
            card.addEventListener('dragend',   () => { card.style.opacity = ''; });
            card.addEventListener('dragover',  e  => e.preventDefault());
            card.addEventListener('drop', e => {
                e.preventDefault();
                if (dragSrc && dragSrc !== card) {
                    const cards = [...grid.querySelectorAll('.nu-widget-card')];
                    if (cards.indexOf(dragSrc) < cards.indexOf(card)) card.after(dragSrc);
                    else card.before(dragSrc);
                    this.persistOrder();
                }
            });
        });
    },

    async persistOrder() {
        const order = [...document.querySelectorAll('.nu-widget-card')].map((c,i) => ({ id: parseInt(c.dataset.widgetId), position: (i+1)*10 }));
        await fetch(API + '?action=reorder', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({order}) });
    },

    async resetLayout() {
        if (!confirm('Reset to role default layout? Your personal widgets will be removed.')) return;
        try {
            const r = await fetch(API + '?action=reset', { method:'POST' });
            const d = await r.json();
            if (d.ok) location.reload();
        } catch(e) { alert('Request failed'); }
    },

    openRoleDesigner() {
        const rEl = document.getElementById('nuWTargetRole');
        if (rEl) rEl.value = 'user';
        this.openBuilder();
    },
};

document.addEventListener('DOMContentLoaded', initCharts);
if (document.readyState !== 'loading') initCharts();
})();
</script>
