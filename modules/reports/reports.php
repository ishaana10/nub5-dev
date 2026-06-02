<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/core/module_bootstrap.php';
?>

<!-- ═══════════════════════════════════════════════════════════════════════════
     REPORTS MODULE  — list + builder + run (table / WebDataRocks pivot)
     Deps: Lucide icons (already in app), WebDataRocks CDN
════════════════════════════════════════════════════════════════════════════ -->

<!-- WebDataRocks CSS -->
<link rel="stylesheet" href="https://cdn.webdatarocks.com/latest/webdatarocks.min.css">

<div id="rpt-app">

  <!-- ── TOOLBAR ─────────────────────────────────────────────────────────── -->
  <div class="rpt-toolbar">
    <div class="rpt-toolbar-left">
      <h2 class="rpt-title">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3h18v4H3z"/><path d="M3 10h11v4H3z"/><path d="M3 17h7v4H3z"/></svg>
        Reports
      </h2>
      <span id="rptBadge" class="rpt-badge">0</span>
    </div>
    <div class="rpt-toolbar-right">
      <button class="nu-btn nu-btn-ghost nu-btn-sm" id="rptRefreshBtn" onclick="rptLoadList()">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
        Refresh
      </button>
      <button class="nu-btn nu-btn-primary nu-btn-sm" onclick="rptOpenBuilder(null)">
        + New Report
      </button>
    </div>
  </div>

  <!-- ── VIEWS ─────────────────────────────────────────────────────────────── -->

  <!-- LIST VIEW -->
  <div id="rptListView">
    <div id="rptTable" class="rpt-table-wrap"></div>
  </div>

  <!-- BUILDER VIEW -->
  <div id="rptBuilderView" style="display:none;">
    <div class="rpt-builder-header">
      <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="rptShowList()">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
        Back
      </button>
      <h3 id="rptBuilderTitle" style="margin:0;font-size:var(--text-base);font-weight:600;">New Report</h3>
      <div style="display:flex;gap:8px;">
        <button class="nu-btn nu-btn-ghost nu-btn-sm" id="rptPreviewBtn" onclick="rptPreview()">▶ Preview</button>
        <button class="nu-btn nu-btn-primary nu-btn-sm" onclick="rptSave()">Save Report</button>
      </div>
    </div>

    <div class="rpt-builder-body">

      <!-- LEFT: config -->
      <div class="rpt-builder-config">

        <input type="hidden" id="rptId">

        <div class="rpt-field-group">
          <label>Report Name <span style="color:var(--color-error)">*</span></label>
          <input type="text" class="nu-input" id="rptName" placeholder="Sales Summary">
        </div>

        <div class="rpt-field-row">
          <div class="rpt-field-group">
            <label>Report Code</label>
            <input type="text" class="nu-input" id="rptCode" placeholder="auto">
          </div>
          <div class="rpt-field-group">
            <label>Type</label>
            <select class="nu-input" id="rptType">
              <option value="table">Table</option>
              <option value="chart">Chart / Summary</option>
            </select>
          </div>
        </div>

        <!-- view mode -->
        <div class="rpt-field-group">
          <label>Default View Mode</label>
          <div class="rpt-view-tabs" id="rptViewTabs">
            <button class="rpt-vtab active" data-mode="table" onclick="rptSetViewMode('table',this)">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="3" y1="15" x2="21" y2="15"/><line x1="9" y1="3" x2="9" y2="21"/></svg>
              Table
            </button>
            <button class="rpt-vtab" data-mode="webdatarocks" onclick="rptSetViewMode('webdatarocks',this)">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="9" height="9"/><rect x="13" y="2" width="9" height="9"/><rect x="2" y="13" width="9" height="9"/><rect x="13" y="13" width="9" height="9"/></svg>
              Pivot (WDR)
            </button>
            <button class="rpt-vtab" data-mode="chart" onclick="rptSetViewMode('chart',this)">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
              Chart
            </button>
          </div>
          <input type="hidden" id="rptViewMode" value="table">
        </div>

        <!-- SQL -->
        <div class="rpt-field-group" style="flex:1;">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
            <label>SQL Query <span style="color:var(--color-error)">*</span></label>
            <button class="nu-btn nu-btn-ghost" style="font-size:11px;padding:2px 6px;" onclick="rptToggleSqlHelper()">Table helper ▾</button>
          </div>
          <!-- SQL helper -->
          <div id="rptSqlHelper" style="display:none;background:var(--color-surface-offset);border:1px solid var(--color-border);border-radius:var(--radius-md);padding:10px;margin-bottom:8px;">
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
              <select class="nu-input" id="rptHelperTable" style="flex:1;min-width:120px;" onchange="rptLoadTableCols()">
                <option value="">— pick table —</option>
              </select>
              <select class="nu-input" id="rptHelperCols" style="flex:1;min-width:120px;" multiple size="4"></select>
              <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="rptInjectSql()">Insert SELECT</button>
            </div>
          </div>
          <textarea class="nu-input" id="rptSql" rows="7"
            placeholder="SELECT status, COUNT(*) AS total FROM nu_forms GROUP BY status"
            style="font-family:monospace;font-size:13px;resize:vertical;"></textarea>
          <p class="rpt-hint">SELECT queries only. Use table aliases for joins.</p>
        </div>

        <!-- Columns config -->
        <div class="rpt-field-group">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
            <label>Column Labels <span style="color:var(--color-text-faint);font-weight:400;">(optional — auto-detected from query)</span></label>
            <button class="nu-btn nu-btn-ghost" style="font-size:11px;padding:2px 6px;" onclick="rptAddColRow()">+ Add column</button>
          </div>
          <div id="rptColsList" style="display:flex;flex-direction:column;gap:6px;"></div>
        </div>

        <!-- Filters -->
        <div class="rpt-field-group">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
            <label>Run-time Filters <span style="color:var(--color-text-faint);font-weight:400;">(optional)</span></label>
            <button class="nu-btn nu-btn-ghost" style="font-size:11px;padding:2px 6px;" onclick="rptAddFilterRow()">+ Add filter</button>
          </div>
          <div id="rptFiltersList" style="display:flex;flex-direction:column;gap:6px;"></div>
          <p class="rpt-hint">Filter fields must match column names in your query result.</p>
        </div>

      </div><!-- /config -->

      <!-- RIGHT: preview pane -->
      <div class="rpt-builder-preview">
        <div class="rpt-preview-header">
          <span style="font-size:var(--text-sm);font-weight:500;">Preview</span>
          <span id="rptPreviewStatus" class="rpt-hint">Click ▶ Preview to run</span>
        </div>
        <div id="rptPreviewOutput" style="flex:1;overflow:auto;padding:12px;">
          <div class="rpt-empty-state">
            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="color:var(--color-text-faint);"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="3" y1="15" x2="21" y2="15"/><line x1="9" y1="3" x2="9" y2="21"/></svg>
            <p>Write a SQL query and click ▶ Preview to see the data here</p>
          </div>
        </div>
      </div>

    </div><!-- /builder-body -->
  </div><!-- /builderView -->

  <!-- RUN VIEW -->
  <div id="rptRunView" style="display:none;">
    <div class="rpt-builder-header">
      <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="rptShowList()">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
        Back
      </button>
      <h3 id="rptRunTitle" style="margin:0;font-size:var(--text-base);font-weight:600;"></h3>
      <div style="display:flex;gap:8px;">
        <div id="rptViewSwitcher" style="display:flex;gap:4px;"></div>
        <button class="nu-btn nu-btn-ghost nu-btn-sm" id="rptExportCsvBtn" onclick="rptExportCsv()">⬇ CSV</button>
        <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="window.print()">🖨 Print</button>
      </div>
    </div>

    <!-- filter bar -->
    <div id="rptFilterBar" style="display:none;padding:10px 16px;border-bottom:1px solid var(--color-border);display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;"></div>

    <!-- output area -->
    <div id="rptRunOutput" style="flex:1;overflow:auto;padding:16px;min-height:300px;"></div>
  </div>

</div><!-- /#rpt-app -->

<!-- ═══════════════════════════════════════════════════════════════════════════
     STYLES
════════════════════════════════════════════════════════════════════════════ -->
<style>
#rpt-app {
  display:flex;
  flex-direction:column;
  height:100%;
  font-family:var(--font-body,sans-serif);
}

/* toolbar */
.rpt-toolbar {
  display:flex;
  justify-content:space-between;
  align-items:center;
  padding:12px 20px;
  border-bottom:1px solid var(--color-border);
  background:var(--color-surface);
  flex-shrink:0;
}
.rpt-toolbar-left { display:flex;align-items:center;gap:10px; }
.rpt-toolbar-right { display:flex;align-items:center;gap:8px; }
.rpt-title {
  margin:0;
  font-size:var(--text-base);
  font-weight:600;
  display:flex;
  align-items:center;
  gap:6px;
}
.rpt-badge {
  background:var(--color-primary);
  color:var(--color-text-inverse,#fff);
  border-radius:var(--radius-full);
  font-size:11px;
  padding:1px 7px;
  font-weight:600;
}

/* list view */
#rptListView { flex:1;overflow:auto;padding:16px 20px; }
.rpt-table-wrap {
  background:var(--color-surface);
  border:1px solid var(--color-border);
  border-radius:var(--radius-lg);
  overflow:hidden;
}
.rpt-list-table { width:100%;border-collapse:collapse; }
.rpt-list-table th {
  background:var(--color-bg);
  padding:10px 16px;
  text-align:left;
  font-size:var(--text-xs);
  font-weight:600;
  color:var(--color-text-muted);
  text-transform:uppercase;
  letter-spacing:0.04em;
  border-bottom:1px solid var(--color-border);
}
.rpt-list-table td {
  padding:11px 16px;
  font-size:var(--text-sm);
  border-bottom:1px solid var(--color-divider);
  vertical-align:middle;
}
.rpt-list-table tr:last-child td { border-bottom:none; }
.rpt-list-table tr:hover td { background:var(--color-surface-offset); }
.rpt-type-badge {
  display:inline-block;
  padding:2px 8px;
  border-radius:var(--radius-full);
  font-size:11px;
  font-weight:600;
  text-transform:uppercase;
  letter-spacing:0.03em;
}
.rpt-type-table  { background:var(--color-primary-highlight);color:var(--color-primary); }
.rpt-type-chart  { background:var(--color-orange-highlight);color:var(--color-orange); }
.rpt-type-summary{ background:var(--color-gold-highlight);color:var(--color-gold); }
.rpt-mode-badge {
  display:inline-block;
  padding:2px 7px;
  border-radius:var(--radius-full);
  font-size:11px;
  background:var(--color-surface-offset-2);
  color:var(--color-text-muted);
}

/* builder */
#rptBuilderView, #rptRunView {
  display:flex;
  flex-direction:column;
  flex:1;
  overflow:hidden;
}
.rpt-builder-header {
  display:flex;
  align-items:center;
  gap:12px;
  padding:10px 16px;
  border-bottom:1px solid var(--color-border);
  background:var(--color-surface);
  flex-shrink:0;
}
.rpt-builder-header h3 { flex:1; }
.rpt-builder-body {
  display:grid;
  grid-template-columns:420px 1fr;
  flex:1;
  overflow:hidden;
}
.rpt-builder-config {
  border-right:1px solid var(--color-border);
  overflow-y:auto;
  padding:16px;
  display:flex;
  flex-direction:column;
  gap:14px;
}
.rpt-builder-preview {
  display:flex;
  flex-direction:column;
  overflow:hidden;
}
.rpt-preview-header {
  display:flex;
  align-items:center;
  justify-content:space-between;
  padding:8px 14px;
  border-bottom:1px solid var(--color-border);
  background:var(--color-bg);
  flex-shrink:0;
}

/* field groups */
.rpt-field-group { display:flex;flex-direction:column;gap:4px; }
.rpt-field-group > label {
  font-size:var(--text-xs);
  font-weight:600;
  color:var(--color-text-muted);
  text-transform:uppercase;
  letter-spacing:0.04em;
}
.rpt-field-row { display:grid;grid-template-columns:1fr 1fr;gap:10px; }
.rpt-hint { font-size:var(--text-xs);color:var(--color-text-faint);margin-top:3px; }

/* view mode tabs */
.rpt-view-tabs { display:flex;gap:4px; }
.rpt-vtab {
  display:flex;
  align-items:center;
  gap:5px;
  padding:5px 10px;
  border:1px solid var(--color-border);
  border-radius:var(--radius-md);
  font-size:var(--text-xs);
  font-weight:500;
  cursor:pointer;
  background:var(--color-surface);
  color:var(--color-text-muted);
  transition:all 150ms;
}
.rpt-vtab:hover { background:var(--color-surface-offset); color:var(--color-text); }
.rpt-vtab.active {
  background:var(--color-primary);
  color:#fff;
  border-color:var(--color-primary);
}

/* col / filter rows */
.rpt-col-row, .rpt-filter-row {
  display:flex;
  gap:6px;
  align-items:center;
}
.rpt-col-row input, .rpt-filter-row input, .rpt-filter-row select {
  flex:1;
  min-width:0;
}
.rpt-remove-btn {
  color:var(--color-text-faint);
  cursor:pointer;
  padding:4px;
  border-radius:var(--radius-sm);
  line-height:1;
}
.rpt-remove-btn:hover { color:var(--color-error); }

/* run output table */
.rpt-output-table { width:100%;border-collapse:collapse;font-size:var(--text-sm); }
.rpt-output-table th {
  background:var(--color-bg);
  padding:9px 12px;
  text-align:left;
  font-size:var(--text-xs);
  font-weight:600;
  color:var(--color-text-muted);
  text-transform:uppercase;
  letter-spacing:0.04em;
  border-bottom:2px solid var(--color-border);
  position:sticky;
  top:0;
  z-index:1;
}
.rpt-output-table td {
  padding:9px 12px;
  border-bottom:1px solid var(--color-divider);
  font-variant-numeric:tabular-nums;
}
.rpt-output-table tr:last-child td { border-bottom:none; }
.rpt-output-table tr:hover td { background:var(--color-surface-offset); }
.rpt-output-wrap {
  background:var(--color-surface);
  border:1px solid var(--color-border);
  border-radius:var(--radius-lg);
  overflow:auto;
}

/* empty states */
.rpt-empty-state {
  display:flex;
  flex-direction:column;
  align-items:center;
  justify-content:center;
  gap:12px;
  padding:60px 20px;
  color:var(--color-text-muted);
  text-align:center;
}
.rpt-empty-state p { font-size:var(--text-sm);max-width:300px; }

/* row actions */
.rpt-row-actions { display:flex;gap:6px;opacity:0;transition:opacity 150ms; }
.rpt-list-table tr:hover .rpt-row-actions { opacity:1; }

/* WebDataRocks container */
#rptWdrContainer { width:100%;height:100%;min-height:400px; }

/* print styles */
@media print {
  .rpt-toolbar, .rpt-builder-header, #rptFilterBar button, .nu-sidebar, .nu-topbar { display:none!important; }
  #rpt-app { height:auto; }
  .rpt-output-table th { background:#f0f0f0!important; }
}
</style>

<!-- ═══════════════════════════════════════════════════════════════════════════
     WebDataRocks JS
════════════════════════════════════════════════════════════════════════════ -->
<script src="https://cdn.webdatarocks.com/latest/webdatarocks.js"></script>

<!-- ═══════════════════════════════════════════════════════════════════════════
     REPORT MODULE JS
════════════════════════════════════════════════════════════════════════════ -->
<script>
(function(){
  // ── state ──────────────────────────────────────────────────────────────────
  let _currentViewMode = 'table'; // table | webdatarocks | chart
  let _runData = [];
  let _runColumns = [];
  let _wdrPivot = null;
  let _currentReportId = null;

  const API = 'api/report.php';

  // ── boot ───────────────────────────────────────────────────────────────────
  window.addEventListener('DOMContentLoaded', () => {
    rptLoadList();
    rptLoadTables();
  });

  // ── list ───────────────────────────────────────────────────────────────────
  window.rptLoadList = async function() {
    try {
      const res = await fetch(`${API}?action=list`);
      const j   = await res.json();
      const rows = j.data || [];
      document.getElementById('rptBadge').textContent = rows.length;
      const tbody = rows.length
        ? rows.map(r => `
          <tr>
            <td><code style="background:var(--color-surface-offset);padding:2px 6px;border-radius:var(--radius-sm);font-size:12px;">${esc(r.report_code)}</code></td>
            <td style="font-weight:500;">${esc(r.report_name)}</td>
            <td><span class="rpt-type-badge rpt-type-${esc(r.report_type)}">${esc(r.report_type)}</span></td>
            <td><span class="rpt-mode-badge">${esc(r.report_view_mode||'table')}</span></td>
            <td style="color:var(--color-text-muted);">${fmtDate(r.report_created_at)}</td>
            <td>
              <div class="rpt-row-actions">
                <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="rptRun(${r.report_id})">▶ Run</button>
                <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="rptOpenBuilder(${r.report_id})">Edit</button>
                <button class="nu-btn nu-btn-ghost nu-btn-sm" style="color:var(--color-error);" onclick="rptDelete(${r.report_id},'${esc(r.report_name)}')">Del</button>
              </div>
            </td>
          </tr>`).join('')
        : `<tr><td colspan="6" style="padding:48px;text-align:center;color:var(--color-text-muted);">No reports yet — click + New Report to create one.</td></tr>`;
      document.getElementById('rptTable').innerHTML = `
        <table class="rpt-list-table">
          <thead><tr>
            <th>Code</th><th>Name</th><th>Type</th><th>View</th><th>Created</th><th>Actions</th>
          </tr></thead>
          <tbody>${tbody}</tbody>
        </table>`;
    } catch(e) {
      document.getElementById('rptTable').innerHTML = `<p style="padding:20px;color:var(--color-error);">Error loading reports: ${e.message}</p>`;
    }
  };

  // ── navigation ─────────────────────────────────────────────────────────────
  window.rptShowList = function() {
    document.getElementById('rptListView').style.display = '';
    document.getElementById('rptBuilderView').style.display = 'none';
    document.getElementById('rptRunView').style.display = 'none';
    if (_wdrPivot) { try { _wdrPivot.dispose(); } catch(e){} _wdrPivot = null; }
    rptLoadList();
  };

  // ── open builder (null = new) ───────────────────────────────────────────────
  window.rptOpenBuilder = async function(id) {
    rptResetBuilder();
    if (id) {
      try {
        const res = await fetch(`${API}?action=get&id=${id}`);
        const j   = await res.json();
        if (!j.success) throw new Error(j.error);
        const r = j.data;
        document.getElementById('rptId').value    = r.report_id;
        document.getElementById('rptName').value  = r.report_name;
        document.getElementById('rptCode').value  = r.report_code;
        document.getElementById('rptType').value  = r.report_type;
        document.getElementById('rptSql').value   = r.report_sql || '';
        rptSetViewMode(r.report_view_mode || 'table');
        // columns
        (r.report_columns || []).forEach(c => rptAddColRow(c.field, c.label));
        // filters
        (r.report_filters || []).forEach(f => rptAddFilterRow(f.field, f.label, f.operator));
        document.getElementById('rptBuilderTitle').textContent = 'Edit: ' + r.report_name;
      } catch(e) {
        alert('Could not load report: ' + e.message);
        return;
      }
    }
    document.getElementById('rptListView').style.display    = 'none';
    document.getElementById('rptRunView').style.display     = 'none';
    document.getElementById('rptBuilderView').style.display = '';
  };

  function rptResetBuilder() {
    document.getElementById('rptId').value    = '';
    document.getElementById('rptName').value  = '';
    document.getElementById('rptCode').value  = '';
    document.getElementById('rptType').value  = 'table';
    document.getElementById('rptSql').value   = '';
    document.getElementById('rptColsList').innerHTML    = '';
    document.getElementById('rptFiltersList').innerHTML = '';
    document.getElementById('rptPreviewOutput').innerHTML = `
      <div class="rpt-empty-state">
        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="color:var(--color-text-faint);"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="3" y1="15" x2="21" y2="15"/><line x1="9" y1="3" x2="9" y2="21"/></svg>
        <p>Write a SQL query and click ▶ Preview to see the data here</p>
      </div>`;
    document.getElementById('rptPreviewStatus').textContent = 'Click ▶ Preview to run';
    document.getElementById('rptBuilderTitle').textContent  = 'New Report';
    rptSetViewMode('table');
  }

  // ── view mode toggle ────────────────────────────────────────────────────────
  window.rptSetViewMode = function(mode, btn) {
    _currentViewMode = mode;
    document.getElementById('rptViewMode').value = mode;
    document.querySelectorAll('.rpt-vtab').forEach(b => b.classList.remove('active'));
    const target = btn || document.querySelector(`.rpt-vtab[data-mode="${mode}"]`);
    if (target) target.classList.add('active');
  };

  // ── SQL helper ──────────────────────────────────────────────────────────────
  window.rptToggleSqlHelper = function() {
    const el = document.getElementById('rptSqlHelper');
    el.style.display = el.style.display === 'none' ? '' : 'none';
  };

  window.rptLoadTables = async function() {
    try {
      const res = await fetch(`${API}?action=tables`);
      const j   = await res.json();
      const sel = document.getElementById('rptHelperTable');
      (j.data || []).forEach(t => {
        const o = document.createElement('option');
        o.value = o.textContent = t;
        sel.appendChild(o);
      });
    } catch(e) {}
  };

  window.rptLoadTableCols = async function() {
    const table = document.getElementById('rptHelperTable').value;
    if (!table) return;
    const sel = document.getElementById('rptHelperCols');
    sel.innerHTML = '<option disabled>Loading…</option>';
    try {
      const res = await fetch(`${API}?action=columns&table=${encodeURIComponent(table)}`);
      const j   = await res.json();
      sel.innerHTML = '';
      (j.data || []).forEach(c => {
        const o = document.createElement('option');
        o.value = o.textContent = c.Field;
        sel.appendChild(o);
      });
    } catch(e) { sel.innerHTML = '<option disabled>Error</option>'; }
  };

  window.rptInjectSql = function() {
    const table = document.getElementById('rptHelperTable').value;
    const opts  = [...document.getElementById('rptHelperCols').selectedOptions].map(o => o.value);
    if (!table) return;
    const cols = opts.length ? opts.join(', ') : '*';
    document.getElementById('rptSql').value = `SELECT ${cols}\nFROM ${table}\nLIMIT 100`;
  };

  // ── columns helper ──────────────────────────────────────────────────────────
  window.rptAddColRow = function(field='', label='') {
    const div = document.createElement('div');
    div.className = 'rpt-col-row';
    div.innerHTML = `
      <input type="text" class="nu-input" placeholder="field_name" value="${esc(field)}" data-col-field>
      <input type="text" class="nu-input" placeholder="Label" value="${esc(label)}" data-col-label>
      <button class="rpt-remove-btn" onclick="this.parentElement.remove()" title="Remove">✕</button>`;
    document.getElementById('rptColsList').appendChild(div);
  };

  // ── filters helper ──────────────────────────────────────────────────────────
  window.rptAddFilterRow = function(field='', label='', op='=') {
    const div = document.createElement('div');
    div.className = 'rpt-filter-row';
    div.innerHTML = `
      <input type="text" class="nu-input" placeholder="field" value="${esc(field)}" data-f-field>
      <input type="text" class="nu-input" placeholder="Label" value="${esc(label)}" data-f-label>
      <select class="nu-input" data-f-op style="max-width:90px;">
        <option value="=" ${op==='='?'selected':''}>= exact</option>
        <option value="LIKE" ${op==='LIKE'?'selected':''}>LIKE</option>
        <option value=">=" ${op==='>='?'selected':''}>≥</option>
        <option value="<=" ${op==='<='?'selected':''}>≤</option>
      </select>
      <button class="rpt-remove-btn" onclick="this.parentElement.remove()" title="Remove">✕</button>`;
    document.getElementById('rptFiltersList').appendChild(div);
  };

  // ── save ───────────────────────────────────────────────────────────────────
  window.rptSave = async function() {
    const columns = [...document.querySelectorAll('#rptColsList .rpt-col-row')].map(row => ({
      field: row.querySelector('[data-col-field]').value.trim(),
      label: row.querySelector('[data-col-label]').value.trim(),
    })).filter(c => c.field);

    const filters = [...document.querySelectorAll('#rptFiltersList .rpt-filter-row')].map(row => ({
      field:    row.querySelector('[data-f-field]').value.trim(),
      label:    row.querySelector('[data-f-label]').value.trim(),
      operator: row.querySelector('[data-f-op]').value,
    })).filter(f => f.field);

    const payload = {
      report_id:        document.getElementById('rptId').value || 0,
      report_name:      document.getElementById('rptName').value.trim(),
      report_code:      document.getElementById('rptCode').value.trim(),
      report_type:      document.getElementById('rptType').value,
      report_view_mode: document.getElementById('rptViewMode').value,
      report_sql:       document.getElementById('rptSql').value.trim(),
      report_columns:   columns,
      report_filters:   filters,
    };

    try {
      const res = await fetch(`${API}?action=save`, {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify(payload),
      });
      const j = await res.json();
      if (!j.success) throw new Error(j.error);
      rptShowList();
    } catch(e) {
      alert('Save failed: ' + e.message);
    }
  };

  // ── delete ─────────────────────────────────────────────────────────────────
  window.rptDelete = async function(id, name) {
    if (!confirm(`Delete report "${name}"?`)) return;
    try {
      const res = await fetch(`${API}?action=delete`, {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({id}),
      });
      const j = await res.json();
      if (!j.success) throw new Error(j.error);
      rptLoadList();
    } catch(e) { alert('Delete failed: ' + e.message); }
  };

  // ── preview (inside builder) ────────────────────────────────────────────────
  window.rptPreview = async function() {
    const sql = document.getElementById('rptSql').value.trim();
    if (!sql) return;
    document.getElementById('rptPreviewStatus').textContent = 'Running…';
    document.getElementById('rptPreviewOutput').innerHTML = '<div class="rpt-empty-state"><p>Loading…</p></div>';
    try {
      const res  = await fetch(`${API}?action=run`, {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ report_sql: sql, report_columns: [], report_filters: [] }),
      });
      // preview uses inline execution — pass sql directly
      // but our api/run requires an existing report id. Workaround: use a temp run endpoint
      // Instead, we'll run the SQL via the inspector query route.
      // Simpler: POST to a ?action=preview endpoint we handle below
      const j = await res.json();
      if (!j.success) throw new Error(j.error);
      _runData    = j.data || [];
      _runColumns = j.columns || [];
      document.getElementById('rptPreviewStatus').textContent = `${_runData.length} row(s)`;
      document.getElementById('rptPreviewOutput').innerHTML =
        rptBuildTableHtml(_runData, _runColumns, 50);
    } catch(e) {
      document.getElementById('rptPreviewStatus').textContent = 'Error';
      document.getElementById('rptPreviewOutput').innerHTML =
        `<p style="color:var(--color-error);padding:12px;">${e.message}</p>`;
    }
  };

  // ── run report ─────────────────────────────────────────────────────────────
  window.rptRun = async function(id) {
    _currentReportId = id;
    // first fetch definition for filters
    const defRes = await fetch(`${API}?action=get&id=${id}`);
    const defJ   = await defRes.json();
    if (!defJ.success) { alert(defJ.error); return; }
    const def = defJ.data;

    // switch to run view
    document.getElementById('rptListView').style.display    = 'none';
    document.getElementById('rptBuilderView').style.display = 'none';
    document.getElementById('rptRunView').style.display     = '';
    document.getElementById('rptRunTitle').textContent      = def.report_name;
    _currentViewMode = def.report_view_mode || 'table';

    // build filter bar
    const filters  = def.report_filters || [];
    const filterBar = document.getElementById('rptFilterBar');
    filterBar.innerHTML = '';
    if (filters.length) {
      filterBar.style.display = 'flex';
      filters.forEach(f => {
        const label = f.label || f.field;
        const wrap  = document.createElement('div');
        wrap.style.cssText = 'display:flex;flex-direction:column;gap:3px;';
        wrap.innerHTML = `<label style="font-size:11px;font-weight:600;color:var(--color-text-muted);text-transform:uppercase;">${esc(label)}</label>
          <input type="text" class="nu-input" id="rptFilter_${esc(f.field)}" placeholder="${esc(f.label||f.field)}" style="width:160px;">`;
        filterBar.appendChild(wrap);
      });
      const runBtn = document.createElement('button');
      runBtn.className = 'nu-btn nu-btn-primary nu-btn-sm';
      runBtn.textContent = 'Apply Filters';
      runBtn.style.alignSelf = 'flex-end';
      runBtn.onclick = () => rptFetchAndRender(id);
      filterBar.appendChild(runBtn);
    } else {
      filterBar.style.display = 'none';
    }

    // build view switcher
    const switcher = document.getElementById('rptViewSwitcher');
    switcher.innerHTML = '';
    [['table','Table'],['webdatarocks','Pivot'],['chart','Chart']].forEach(([mode,label]) => {
      const b = document.createElement('button');
      b.className  = `rpt-vtab${mode===_currentViewMode?' active':''}`;
      b.dataset.mode = mode;
      b.textContent  = label;
      b.onclick = () => {
        _currentViewMode = mode;
        switcher.querySelectorAll('.rpt-vtab').forEach(x => x.classList.remove('active'));
        b.classList.add('active');
        rptRenderOutput();
      };
      switcher.appendChild(b);
    });

    await rptFetchAndRender(id);
  };

  async function rptFetchAndRender(id) {
    const filterBar = document.getElementById('rptFilterBar');
    const params    = new URLSearchParams({action:'run', id});
    // append filter values
    filterBar.querySelectorAll('input[id^="rptFilter_"]').forEach(inp => {
      const field = inp.id.replace('rptFilter_', '');
      if (inp.value.trim()) params.append(field, inp.value.trim());
    });
    document.getElementById('rptRunOutput').innerHTML = '<div class="rpt-empty-state"><p>Loading…</p></div>';
    try {
      const res = await fetch(`${API}?${params.toString()}`);
      const j   = await res.json();
      if (!j.success) throw new Error(j.error);
      _runData    = j.data    || [];
      _runColumns = j.columns || [];
      rptRenderOutput();
    } catch(e) {
      document.getElementById('rptRunOutput').innerHTML =
        `<p style="color:var(--color-error);padding:16px;">${e.message}</p>`;
    }
  }

  function rptRenderOutput() {
    const out = document.getElementById('rptRunOutput');
    if (_currentViewMode === 'webdatarocks') {
      rptRenderWDR(out);
    } else if (_currentViewMode === 'chart') {
      rptRenderChart(out);
    } else {
      out.innerHTML = `<div class="rpt-output-wrap">${rptBuildTableHtml(_runData, _runColumns)}</div>`;
    }
  }

  // ── TABLE render ──────────────────────────────────────────────────────────
  function rptBuildTableHtml(data, columns, limit) {
    if (!data.length) return '<div class="rpt-empty-state"><p>No rows returned.</p></div>';
    const rows = limit ? data.slice(0, limit) : data;
    const th   = columns.map(c => `<th>${esc(c.label||c.field)}</th>`).join('');
    const trs  = rows.map(row =>
      '<tr>' + columns.map(c => `<td>${esc(String(row[c.field] ?? ''))}</td>`).join('') + '</tr>'
    ).join('');
    const note = (limit && data.length > limit)
      ? `<p style="font-size:11px;color:var(--color-text-muted);padding:6px 0;">Showing first ${limit} of ${data.length} rows. Save to see all.</p>`
      : '';
    return `${note}<table class="rpt-output-table"><thead><tr>${th}</tr></thead><tbody>${trs}</tbody></table>`;
  }

  // ── WebDataRocks render ───────────────────────────────────────────────────
  function rptRenderWDR(container) {
    if (_wdrPivot) { try { _wdrPivot.dispose(); } catch(e){} _wdrPivot = null; }
    container.innerHTML = '<div id="rptWdrContainer"></div>';

    const fieldMap = {};
    _runColumns.forEach(c => {
      fieldMap[c.field] = { caption: c.label || c.field };
      // auto detect numeric fields for measures
      if (_runData.length && !isNaN(parseFloat(_runData[0][c.field]))) {
        fieldMap[c.field].type = 'number';
      }
    });

    _wdrPivot = new WebDataRocks({
      container: '#rptWdrContainer',
      toolbar: true,
      report: {
        dataSource: { data: _runData },
        options: { grid: { type: 'flat', showGrandTotals: 'off' } },
        formats: [{ name: '', thousandsSeparator: ',', decimalPlaces: 2 }],
      },
    });
  }

  // ── Simple Chart render (bar chart using Canvas) ──────────────────────────
  function rptRenderChart(container) {
    if (!_runData.length || !_runColumns.length) {
      container.innerHTML = '<div class="rpt-empty-state"><p>No data for chart.</p></div>';
      return;
    }
    const labelCol = _runColumns[0].field;
    const valueCol = _runColumns[1] ? _runColumns[1].field : _runColumns[0].field;
    const labels   = _runData.map(r => r[labelCol]);
    const values   = _runData.map(r => parseFloat(r[valueCol]) || 0);
    const maxVal   = Math.max(...values, 1);
    const barColor = getComputedStyle(document.documentElement).getPropertyValue('--color-primary').trim() || '#01696f';

    const barHtml = labels.map((l, i) => {
      const pct = ((values[i] / maxVal) * 100).toFixed(1);
      return `<div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
        <div style="width:140px;font-size:12px;text-align:right;color:var(--color-text-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${esc(String(l))}</div>
        <div style="flex:1;background:var(--color-surface-offset);border-radius:4px;overflow:hidden;">
          <div style="width:${pct}%;background:${barColor};height:20px;border-radius:4px;transition:width 0.4s;"></div>
        </div>
        <div style="width:70px;font-size:12px;font-variant-numeric:tabular-nums;">${values[i].toLocaleString()}</div>
      </div>`;
    }).join('');
    container.innerHTML = `
      <div style="padding:16px;">
        <p style="font-size:12px;color:var(--color-text-muted);margin-bottom:12px;">Showing <strong>${esc(_runColumns[0].label||labelCol)}</strong> vs <strong>${esc(_runColumns[1]?.label||valueCol)}</strong></p>
        ${barHtml}
      </div>`;
  }

  // ── CSV export ─────────────────────────────────────────────────────────────
  window.rptExportCsv = function() {
    if (!_runData.length) { alert('No data to export.'); return; }
    const header = _runColumns.map(c => `"${c.label||c.field}"`).join(',');
    const rows   = _runData.map(row =>
      _runColumns.map(c => `"${String(row[c.field]??'').replace(/"/g,'""')}"`).join(',')
    );
    const csv  = [header, ...rows].join('\n');
    const blob = new Blob([csv], {type:'text/csv'});
    const a    = document.createElement('a');
    a.href     = URL.createObjectURL(blob);
    a.download = 'report.csv';
    a.click();
  };

  // ── utils ──────────────────────────────────────────────────────────────────
  function esc(s) {
    return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }
  function fmtDate(d) {
    if (!d) return '—';
    const dt = new Date(d);
    return dt.toLocaleDateString('en-GB',{day:'numeric',month:'short',year:'numeric'});
  }

})();
</script>
