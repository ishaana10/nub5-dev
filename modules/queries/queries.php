<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/core/module_bootstrap.php';

$db      = NuDatabase::getInstance();
$queries = $db->fetchAll("SELECT * FROM nu_queries WHERE query_active = 1 ORDER BY query_updated_at DESC");
?>

<div class="nu-queries-module">

  <!-- ── HEADER ─────────────────────────────────────────────────────── -->
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
    <div>
      <h2 style="font-size:20px;font-weight:600;margin:0;">Query Builder</h2>
      <p style="color:var(--text-secondary);font-size:13px;margin:2px 0 0;">Build, run and manage saved SQL queries</p>
    </div>
    <?php if ($auth->hasPermission('queries', 'build')): ?>
    <button class="nu-btn nu-btn-primary" onclick="QB.openNew()">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-2px;margin-right:4px;"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      New Query
    </button>
    <?php endif; ?>
  </div>

  <!-- ── QUERY LIST ─────────────────────────────────────────────────── -->
  <div id="qbListPanel">
    <div class="nu-grid" id="qbCards" style="grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:12px;">
      <?php if (empty($queries)): ?>
      <div class="nu-card" style="grid-column:1/-1;text-align:center;padding:48px 24px;">
        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="color:var(--text-tertiary);margin:0 auto 12px;"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14a9 3 0 0 0 18 0V5"/><path d="M3 12a9 3 0 0 0 18 0"/></svg>
        <p style="color:var(--text-secondary);font-weight:500;">No queries yet</p>
        <p style="color:var(--text-tertiary);font-size:13px;margin-top:4px;">Click "New Query" to build your first saved query.</p>
      </div>
      <?php else: ?>
      <?php foreach ($queries as $q): ?>
      <div class="nu-card qb-card" data-id="<?php echo $q['query_id']; ?>">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px;">
          <div style="flex:1;min-width:0;">
            <h4 style="font-weight:600;font-size:14px;margin:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo htmlspecialchars($q['query_name']); ?></h4>
            <span class="nu-badge nu-badge-secondary" style="font-size:11px;margin-top:3px;display:inline-block;"><?php echo htmlspecialchars($q['query_code']); ?></span>
          </div>
          <span class="nu-badge <?php echo $q['query_active'] ? 'nu-badge-success' : 'nu-badge-muted'; ?>" style="margin-left:8px;flex-shrink:0;"><?php echo $q['query_active'] ? 'Active' : 'Inactive'; ?></span>
        </div>
        <p style="color:var(--text-secondary);font-size:12px;font-family:monospace;background:var(--bg-secondary);border-radius:4px;padding:6px 8px;margin-bottom:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
          <?php echo htmlspecialchars(substr(trim($q['query_sql']), 0, 80)) . (strlen(trim($q['query_sql'])) > 80 ? '…' : ''); ?>
        </p>
        <div style="display:flex;gap:6px;">
          <button class="nu-btn nu-btn-primary nu-btn-sm" onclick="QB.run(<?php echo $q['query_id']; ?>)">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor" style="vertical-align:-1px;margin-right:3px;"><polygon points="5,3 19,12 5,21"/></svg>Run
          </button>
          <?php if ($auth->hasPermission('queries', 'build')): ?>
          <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="QB.edit(<?php echo $q['query_id']; ?>)">Edit</button>
          <button class="nu-btn nu-btn-danger nu-btn-sm" onclick="QB.delete(<?php echo $q['query_id']; ?>, '<?php echo htmlspecialchars($q['query_name'], ENT_QUOTES); ?>')">Delete</button>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── BUILDER PANEL ──────────────────────────────────────────────── -->
  <div id="qbBuilderPanel" style="display:none;margin-top:24px;">
    <div class="nu-card">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
        <h3 class="nu-card-title" id="qbBuilderTitle">New Query</h3>
        <div style="display:flex;gap:8px;">
          <button class="nu-btn nu-btn-ghost nu-btn-sm" id="qbSqlToggle" onclick="QB.toggleSqlMode()">Show SQL</button>
          <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="QB.close()">Cancel</button>
          <button class="nu-btn nu-btn-primary nu-btn-sm" onclick="QB.save()">Save Query</button>
        </div>
      </div>

      <input type="hidden" id="qbEditId">

      <!-- Meta row -->
      <div style="display:grid;grid-template-columns:1fr 1fr auto;gap:12px;margin-bottom:16px;">
        <div class="nu-field" style="margin:0;">
          <label>Query Name <span style="color:var(--color-danger)">*</span></label>
          <input type="text" class="nu-input" id="qbName" placeholder="Monthly Sales Report" oninput="QB.autoCode()">
        </div>
        <div class="nu-field" style="margin:0;">
          <label>Query Code</label>
          <input type="text" class="nu-input" id="qbCode" placeholder="monthly_sales_report">
        </div>
        <div class="nu-field" style="margin:0;">
          <label>Active</label>
          <div style="padding-top:8px;"><input type="checkbox" id="qbActive" checked style="width:16px;height:16px;"></div>
        </div>
      </div>

      <div class="nu-field" style="margin-bottom:16px;">
        <label>Description</label>
        <input type="text" class="nu-input" id="qbDescription" placeholder="Optional description of this query">
      </div>

      <!-- ── VISUAL BUILDER ── -->
      <div id="qbVisualSection">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
          <div class="nu-field" style="margin:0;">
            <label>Table <span style="color:var(--color-danger)">*</span></label>
            <div style="display:flex;gap:6px;">
              <select class="nu-input" id="qbTable" onchange="QB.loadColumns()" style="flex:1;">
                <option value="">-- select table --</option>
              </select>
              <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="QB.loadTables()" title="Refresh tables">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
              </button>
            </div>
          </div>
          <div class="nu-field" style="margin:0;">
            <label>Columns</label>
            <select class="nu-input" id="qbColumns" multiple size="3" style="font-size:12px;">
              <option value="*" selected>* (all columns)</option>
            </select>
          </div>
        </div>

        <!-- WHERE conditions -->
        <div style="margin-bottom:12px;">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
            <label style="font-weight:500;font-size:13px;">WHERE Conditions</label>
            <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="QB.addCondition()">+ Add Condition</button>
          </div>
          <div id="qbConditions"></div>
        </div>

        <!-- ORDER BY + LIMIT -->
        <div style="display:grid;grid-template-columns:1fr 1fr 100px;gap:12px;margin-bottom:12px;">
          <div class="nu-field" style="margin:0;">
            <label>Order By</label>
            <select class="nu-input" id="qbOrderField">
              <option value="">-- none --</option>
            </select>
          </div>
          <div class="nu-field" style="margin:0;">
            <label>Direction</label>
            <select class="nu-input" id="qbOrderDir">
              <option value="ASC">ASC (ascending)</option>
              <option value="DESC">DESC (descending)</option>
            </select>
          </div>
          <div class="nu-field" style="margin:0;">
            <label>Limit</label>
            <input type="number" class="nu-input" id="qbLimit" placeholder="500" min="1" max="10000">
          </div>
        </div>

        <!-- SQL preview -->
        <div style="margin-bottom:12px;">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
            <label style="font-weight:500;font-size:13px;">Generated SQL</label>
            <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="QB.buildSql()">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:3px;"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>Rebuild
            </button>
          </div>
          <div id="qbSqlPreview" style="font-family:monospace;font-size:12px;background:var(--bg-secondary);border:1px solid var(--border-color);border-radius:6px;padding:10px 12px;color:var(--text-secondary);white-space:pre-wrap;min-height:40px;"></div>
        </div>
      </div>

      <!-- ── RAW SQL (toggled) ── -->
      <div id="qbRawSection" style="display:none;margin-bottom:12px;">
        <label style="font-weight:500;font-size:13px;display:flex;justify-content:space-between;">
          <span>SQL Statement <span style="color:var(--color-danger)">*</span></span>
          <span style="font-weight:400;color:var(--text-tertiary);font-size:12px;">SELECT only — no INSERT/UPDATE/DELETE</span>
        </label>
        <textarea class="nu-input" id="qbRawSql" rows="7" placeholder="SELECT * FROM nu_users WHERE usr_active = 1 ORDER BY usr_created_at DESC LIMIT 100" style="font-family:monospace;font-size:13px;resize:vertical;"></textarea>
      </div>

      <!-- ── PARAMETERS EDITOR ── -->
      <div style="border-top:1px solid var(--border-color);padding-top:14px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
          <label style="font-weight:500;font-size:13px;">Parameters <span style="color:var(--text-tertiary);font-weight:400;font-size:12px;">(runtime placeholders, e.g. :status)</span></label>
          <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="QB.addParam()">+ Add Parameter</button>
        </div>
        <div id="qbParams"></div>
      </div>
    </div>
  </div>

  <!-- ── RESULTS PANEL ──────────────────────────────────────────────── -->
  <div id="qbResultsPanel" style="display:none;margin-top:24px;">
    <div class="nu-card">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
        <div>
          <h3 class="nu-card-title" style="margin:0;" id="qbResultsTitle">Results</h3>
          <span id="qbResultsMeta" style="font-size:12px;color:var(--text-secondary);"></span>
        </div>
        <div style="display:flex;gap:8px;">
          <button class="nu-btn nu-btn-ghost nu-btn-sm" id="qbExportBtn" onclick="QB.exportCsv()">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:3px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>Export CSV
          </button>
          <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="document.getElementById('qbResultsPanel').style.display='none'">Close</button>
        </div>
      </div>
      <!-- Runtime parameter form -->
      <div id="qbRuntimeParams" style="display:none;background:var(--bg-secondary);border-radius:6px;padding:12px;margin-bottom:12px;"></div>
      <!-- Data table -->
      <div id="qbResultsContent" style="overflow-x:auto;"></div>
    </div>
  </div>

</div><!-- /.nu-queries-module -->

<script>
/* ============================================================
   QB — Query Builder controller
   API paths are root-relative (api/queries.php) because this
   module is AJAX-loaded into index.php at the site root, so
   relative paths like ../../api/ would resolve incorrectly.
   ============================================================ */
const QB = (() => {
  let _mode     = 'visual'; // 'visual' | 'raw'
  let _runCode  = '';       // query_code being run (for CSV export)
  let _condCount = 0;
  let _paramCount = 0;
  let _tables   = [];
  let _columns  = [];

  /* ── helpers ──────────────────────────────────────────── */
  const $  = (id) => document.getElementById(id);
  const esc = (s) => String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');

  function toast(msg, type = 'info') {
    if (typeof nuToast === 'function') { nuToast(msg, type); return; }
    console.log('[QB]', msg);
  }

  /* ── open new ──────────────────────────────────────────── */
  function openNew() {
    reset();
    $('qbBuilderTitle').textContent = 'New Query';
    $('qbBuilderPanel').style.display = 'block';
    $('qbBuilderPanel').scrollIntoView({ behavior: 'smooth', block: 'start' });
    loadTables();
  }

  /* ── edit existing ─────────────────────────────────────── */
  async function edit(id) {
    try {
      const r = await fetch(`api/queries.php?action=get&id=${id}`);
      const d = await r.json();
      if (!d.success) { toast(d.error, 'error'); return; }
      const q = d.query;
      reset();
      $('qbEditId').value      = q.query_id;
      $('qbName').value        = q.query_name;
      $('qbCode').value        = q.query_code;
      $('qbDescription').value = q.query_description || '';
      $('qbActive').checked    = q.query_active == 1;
      $('qbRawSql').value      = q.query_sql;
      $('qbSqlPreview').textContent = q.query_sql;
      $('qbBuilderTitle').textContent = 'Edit Query';
      // Load params
      const params = JSON.parse(q.query_parameters || 'null') || {};
      Object.entries(params).forEach(([key, cfg]) => addParam(key, cfg));
      // Switch to raw mode so user can see/edit the SQL directly
      setMode('raw');
      $('qbBuilderPanel').style.display = 'block';
      $('qbBuilderPanel').scrollIntoView({ behavior: 'smooth', block: 'start' });
      loadTables();
    } catch (e) {
      toast('Failed to load query', 'error');
    }
  }

  /* ── reset form ────────────────────────────────────────── */
  function reset() {
    $('qbEditId').value      = '';
    $('qbName').value        = '';
    $('qbCode').value        = '';
    $('qbDescription').value = '';
    $('qbActive').checked    = true;
    $('qbRawSql').value      = '';
    $('qbSqlPreview').textContent = '';
    $('qbTable').innerHTML   = '<option value="">-- select table --</option>';
    $('qbColumns').innerHTML = '<option value="*" selected>* (all columns)</option>';
    $('qbConditions').innerHTML = '';
    $('qbParams').innerHTML  = '';
    $('qbOrderField').innerHTML = '<option value="">-- none --</option>';
    $('qbLimit').value       = '';
    _condCount  = 0;
    _paramCount = 0;
    _columns    = [];
    setMode('visual');
  }

  /* ── toggle SQL / visual mode ──────────────────────────── */
  function toggleSqlMode() {
    setMode(_mode === 'visual' ? 'raw' : 'visual');
  }

  function setMode(m) {
    _mode = m;
    if (m === 'raw') {
      $('qbVisualSection').style.display = 'none';
      $('qbRawSection').style.display    = 'block';
      $('qbSqlToggle').textContent       = 'Visual Builder';
      // copy last built SQL into raw textarea
      if (!$('qbRawSql').value && $('qbSqlPreview').textContent) {
        $('qbRawSql').value = $('qbSqlPreview').textContent;
      }
    } else {
      $('qbVisualSection').style.display = 'block';
      $('qbRawSection').style.display    = 'none';
      $('qbSqlToggle').textContent       = 'Show SQL';
    }
  }

  /* ── load tables ───────────────────────────────────────── */
  async function loadTables() {
    try {
      const r = await fetch('api/queries.php?action=get_tables');
      const d = await r.json();
      if (!d.success) return;
      _tables = d.tables;
      const sel = $('qbTable');
      const cur = sel.value;
      sel.innerHTML = '<option value="">-- select table --</option>';
      _tables.forEach(t => {
        const o = document.createElement('option');
        o.value = t; o.textContent = t;
        if (t === cur) o.selected = true;
        sel.appendChild(o);
      });
    } catch (e) { /* silent */ }
  }

  /* ── load columns ──────────────────────────────────────── */
  async function loadColumns() {
    const table = $('qbTable').value;
    if (!table) return;
    try {
      const r = await fetch(`api/queries.php?action=get_columns&table=${encodeURIComponent(table)}`);
      const d = await r.json();
      if (!d.success) return;
      _columns = d.columns;
      const colSel   = $('qbColumns');
      const orderSel = $('qbOrderField');
      colSel.innerHTML   = '<option value="*" selected>* (all columns)</option>';
      orderSel.innerHTML = '<option value="">-- none --</option>';
      _columns.forEach(c => {
        const o1 = document.createElement('option'); o1.value = c; o1.textContent = c;
        colSel.appendChild(o1);
        const o2 = document.createElement('option'); o2.value = c; o2.textContent = c;
        orderSel.appendChild(o2);
      });
      // refresh condition dropdowns
      document.querySelectorAll('.qb-cond-field').forEach(s => {
        const cur2 = s.value;
        s.innerHTML = '<option value="">-- field --</option>';
        _columns.forEach(c => {
          const o = document.createElement('option'); o.value = c; o.textContent = c;
          if (c === cur2) o.selected = true;
          s.appendChild(o);
        });
      });
      buildSql();
    } catch (e) { /* silent */ }
  }

  /* ── add WHERE condition row ───────────────────────────── */
  function addCondition(field, op, val, logic) {
    const id = ++_condCount;
    const row = document.createElement('div');
    row.id = `qbCond_${id}`;
    row.style.cssText = 'display:grid;grid-template-columns:60px 1fr 120px 1fr 32px;gap:6px;margin-bottom:6px;align-items:center;';
    const cols = _columns.map(c => `<option value="${esc(c)}" ${c===field?'selected':''}>${esc(c)}</option>`).join('');
    row.innerHTML = `
      <select class="nu-input nu-input-sm qb-cond-logic" onchange="QB.buildSql()" style="font-size:12px;padding:4px 6px;">
        <option value="AND" ${(!logic||logic==='AND')?'selected':''}>AND</option>
        <option value="OR"  ${logic==='OR'?'selected':''}>OR</option>
      </select>
      <select class="nu-input nu-input-sm qb-cond-field" onchange="QB.buildSql()" style="font-size:12px;padding:4px 6px;">
        <option value="">-- field --</option>${cols}
      </select>
      <select class="nu-input nu-input-sm qb-cond-op" onchange="QB.buildSql()" style="font-size:12px;padding:4px 6px;">
        <option value="="   ${op==='='?'selected':''}>= equals</option>
        <option value="!="  ${op==='!='?'selected':''}>≠ not equals</option>
        <option value=">"   ${op==='>'?'selected':''}>&#62; greater</option>
        <option value=">="  ${op==='>='?'selected':''}>&#62;= greater eq</option>
        <option value="<"   ${op==='<'?'selected':''}>&#60; less</option>
        <option value="<="  ${op==='<='?'selected':''}>&#60;= less eq</option>
        <option value="LIKE" ${op==='LIKE'?'selected':''}>LIKE contains</option>
        <option value="IS NULL" ${op==='IS NULL'?'selected':''}>IS NULL</option>
        <option value="IS NOT NULL" ${op==='IS NOT NULL'?'selected':''}>IS NOT NULL</option>
      </select>
      <input type="text" class="nu-input nu-input-sm qb-cond-val" placeholder="value or :param" value="${esc(val||'')}" oninput="QB.buildSql()" style="font-size:12px;padding:4px 8px;">
      <button class="nu-btn nu-btn-danger nu-btn-sm" onclick="QB.removeCondition(${id})" style="padding:4px 8px;font-size:11px;">×</button>
    `;
    $('qbConditions').appendChild(row);
    buildSql();
  }

  function removeCondition(id) {
    const el = $(`qbCond_${id}`);
    if (el) el.remove();
    buildSql();
  }

  /* ── build SQL from visual inputs ─────────────────────── */
  function buildSql() {
    const table = $('qbTable').value;
    if (!table) { $('qbSqlPreview').textContent = ''; return; }

    // Columns
    const colSel = $('qbColumns');
    const selCols = [...colSel.selectedOptions].map(o => o.value);
    const colStr  = selCols.includes('*') || selCols.length === 0 ? '*' : selCols.map(c => '`' + c + '`').join(', ');

    let sql = `SELECT ${colStr}\nFROM \`${table}\``;

    // WHERE
    const condRows = $('qbConditions').querySelectorAll('[id^="qbCond_"]');
    const wheres = [];
    condRows.forEach((row, i) => {
      const field  = row.querySelector('.qb-cond-field').value;
      const op     = row.querySelector('.qb-cond-op').value;
      const val    = row.querySelector('.qb-cond-val').value.trim();
      const logic  = row.querySelector('.qb-cond-logic').value;
      if (!field) return;
      const prefix = (i === 0) ? '' : ` ${logic} `;
      if (op === 'IS NULL' || op === 'IS NOT NULL') {
        wheres.push(prefix + '`' + field + '` ' + op);
      } else if (val !== '') {
        const quotedVal = val.startsWith(':') ? val : `'${val.replace(/'/g,"''")}'`;
        wheres.push(prefix + '`' + field + '` ' + op + ' ' + quotedVal);
      }
    });
    if (wheres.length) sql += `\nWHERE ${wheres.join('')}`;

    // ORDER BY
    const orderField = $('qbOrderField').value;
    const orderDir   = $('qbOrderDir').value;
    if (orderField) sql += `\nORDER BY \`${orderField}\` ${orderDir}`;

    // LIMIT
    const lim = parseInt($('qbLimit').value);
    if (!isNaN(lim) && lim > 0) sql += `\nLIMIT ${lim}`;

    $('qbSqlPreview').textContent = sql;
    return sql;
  }

  /* ── auto slug query code ──────────────────────────────── */
  function autoCode() {
    if ($('qbEditId').value) return; // don't overwrite when editing
    const name = $('qbName').value;
    $('qbCode').value = name.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '');
  }

  /* ── add parameter row ─────────────────────────────────── */
  function addParam(key, cfg) {
    const id = ++_paramCount;
    const k   = key  || '';
    const lbl = cfg?.label   || '';
    const typ = cfg?.type    || 'text';
    const def = cfg?.default || '';
    const row = document.createElement('div');
    row.id = `qbParam_${id}`;
    row.style.cssText = 'display:grid;grid-template-columns:1fr 1fr 100px 1fr 32px;gap:6px;margin-bottom:6px;align-items:center;';
    row.innerHTML = `
      <input type="text" class="nu-input nu-input-sm qb-param-key"   value="${esc(k)}"   placeholder="param_key"   style="font-size:12px;padding:4px 8px;font-family:monospace;">
      <input type="text" class="nu-input nu-input-sm qb-param-label" value="${esc(lbl)}" placeholder="Display Label" style="font-size:12px;padding:4px 8px;">
      <select class="nu-input nu-input-sm qb-param-type" style="font-size:12px;padding:4px 6px;">
        <option value="text"   ${typ==='text'?'selected':''}>Text</option>
        <option value="number" ${typ==='number'?'selected':''}>Number</option>
        <option value="date"   ${typ==='date'?'selected':''}>Date</option>
        <option value="select" ${typ==='select'?'selected':''}>Select</option>
      </select>
      <input type="text" class="nu-input nu-input-sm qb-param-default" value="${esc(def)}" placeholder="default value" style="font-size:12px;padding:4px 8px;">
      <button class="nu-btn nu-btn-danger nu-btn-sm" onclick="QB.removeParam(${id})" style="padding:4px 8px;font-size:11px;">×</button>
    `;
    $('qbParams').appendChild(row);
  }

  function removeParam(id) {
    const el = $(`qbParam_${id}`);
    if (el) el.remove();
  }

  /* ── collect parameters JSON ───────────────────────────── */
  function collectParams() {
    const params = {};
    document.querySelectorAll('[id^="qbParam_"]').forEach(row => {
      const key = row.querySelector('.qb-param-key').value.trim();
      if (!key) return;
      params[key] = {
        label:   row.querySelector('.qb-param-label').value.trim() || key,
        type:    row.querySelector('.qb-param-type').value,
        default: row.querySelector('.qb-param-default').value.trim()
      };
    });
    return params;
  }

  /* ── save ──────────────────────────────────────────────── */
  async function save() {
    const name = $('qbName').value.trim();
    if (!name) { toast('Query name is required', 'error'); return; }

    const sql = _mode === 'raw'
      ? $('qbRawSql').value.trim()
      : (buildSql() || '').trim();

    if (!sql) { toast('SQL query is required', 'error'); return; }

    const payload = {
      query_id:          $('qbEditId').value || null,
      query_name:        name,
      query_code:        $('qbCode').value.trim() || null,
      query_description: $('qbDescription').value.trim(),
      query_sql:         sql,
      query_active:      $('qbActive').checked ? 1 : 0,
      query_parameters:  collectParams()
    };

    try {
      const r = await fetch('api/queries.php?action=save', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });
      const d = await r.json();
      if (!d.success) { toast(d.error, 'error'); return; }
      toast('Query saved', 'success');
      close();
      setTimeout(() => location.reload(), 600);
    } catch (e) {
      toast('Save failed: ' + e.message, 'error');
    }
  }

  /* ── run query ─────────────────────────────────────────── */
  async function run(id) {
    try {
      // First get the query to check for params
      const r1 = await fetch(`api/queries.php?action=get&id=${id}`);
      const d1 = await r1.json();
      if (!d1.success) { toast(d1.error, 'error'); return; }
      const q = d1.query;
      _runCode = q.query_code;

      $('qbResultsTitle').textContent  = 'Results: ' + q.query_name;
      $('qbResultsPanel').style.display = 'block';
      $('qbResultsContent').innerHTML  = '<p style="color:var(--text-tertiary);font-size:13px;">Loading…</p>';
      $('qbResultsMeta').textContent   = '';

      const params = JSON.parse(q.query_parameters || 'null') || {};
      const hasParams = Object.keys(params).length > 0;

      if (hasParams) {
        renderParamForm(params, q.query_code);
        $('qbResultsContent').innerHTML = '<p style="color:var(--text-tertiary);font-size:13px;">Fill in parameters above, then click Run.</p>';
        return;
      }

      $('qbRuntimeParams').style.display = 'none';
      await executeQuery(q.query_code, {});
      $('qbResultsPanel').scrollIntoView({ behavior: 'smooth', block: 'start' });
    } catch (e) {
      toast('Failed to run query', 'error');
    }
  }

  function renderParamForm(params, code) {
    const rp = $('qbRuntimeParams');
    let html = '<form onsubmit="event.preventDefault();QB.runWithParams()" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px;align-items:end;">';
    Object.entries(params).forEach(([key, cfg]) => {
      const type  = cfg.type  || 'text';
      const label = cfg.label || key;
      html += `<div class="nu-field" style="margin:0;"><label>${esc(label)}</label>`;
      if (type === 'date')   html += `<input type="date" name="${esc(key)}" class="nu-input" style="font-size:13px;">`;
      else if (type === 'number') html += `<input type="number" name="${esc(key)}" class="nu-input" style="font-size:13px;" placeholder="${esc(label)}">`;
      else html += `<input type="text" name="${esc(key)}" class="nu-input" style="font-size:13px;" placeholder="${esc(label)}">`;
      html += '</div>';
    });
    html += '<div style="margin:0;"><label>&nbsp;</label><button type="submit" class="nu-btn nu-btn-primary" style="width:100%;">Run</button></div>';
    html += '</form>';
    rp.innerHTML = html;
    rp.style.display = 'block';
  }

  async function runWithParams() {
    const form   = $('qbRuntimeParams').querySelector('form');
    const inputs = [...form.querySelectorAll('[name]')];
    const params = {};
    inputs.forEach(i => { if (i.name) params[i.name] = i.value; });
    await executeQuery(_runCode, params);
  }

  async function executeQuery(code, params) {
    $('qbResultsContent').innerHTML = '<p style="color:var(--text-tertiary);font-size:13px;">Loading…</p>';
    try {
      const qs = new URLSearchParams({ action: 'run', code });
      Object.entries(params).forEach(([k, v]) => qs.set(k, v));
      const r = await fetch('api/queries.php?' + qs.toString());
      const d = await r.json();
      if (!d.success) {
        $('qbResultsContent').innerHTML = `<div style="color:var(--color-danger);font-size:13px;padding:8px;">${esc(d.error)}</div>`;
        return;
      }
      renderTable(d.data);
    } catch (e) {
      $('qbResultsContent').innerHTML = `<div style="color:var(--color-danger);font-size:13px;">${esc(e.message)}</div>`;
    }
  }

  function renderTable(data) {
    const { records, columns, total } = data;
    $('qbResultsMeta').textContent = `${total} row${total !== 1 ? 's' : ''}`;
    if (!records || records.length === 0) {
      $('qbResultsContent').innerHTML = '<p style="color:var(--text-tertiary);font-size:13px;padding:12px 0;">No records returned.</p>';
      return;
    }
    let html = '<table class="nu-table" style="font-size:13px;">';
    html += '<thead><tr>' + columns.map(c => `<th style="white-space:nowrap;">${esc(c)}</th>`).join('') + '</tr></thead>';
    html += '<tbody>';
    records.forEach(row => {
      html += '<tr>' + columns.map(c => {
        const v = row[c];
        const disp = v === null || v === undefined ? '<span style="color:var(--text-tertiary);font-style:italic;">NULL</span>' : esc(String(v));
        return `<td style="max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${esc(String(v ?? ''))}">` + disp + '</td>';
      }).join('') + '</tr>';
    });
    html += '</tbody></table>';
    $('qbResultsContent').innerHTML = html;
  }

  /* ── export CSV ────────────────────────────────────────── */
  function exportCsv() {
    if (!_runCode) return;
    window.open(`api/query.php?action=export&code=${_runCode}`, '_blank');
  }

  /* ── delete ────────────────────────────────────────────── */
  async function del(id, name) {
    if (!confirm(`Delete query "${name}"? This cannot be undone.`)) return;
    try {
      const r = await fetch(`api/queries.php?action=delete&id=${id}`);
      const d = await r.json();
      if (!d.success) { toast(d.error, 'error'); return; }
      toast('Query deleted', 'success');
      setTimeout(() => location.reload(), 600);
    } catch (e) {
      toast('Delete failed', 'error');
    }
  }

  /* ── close builder ─────────────────────────────────────── */
  function close() {
    $('qbBuilderPanel').style.display = 'none';
    reset();
  }

  /* ── public API ────────────────────────────────────────── */
  return { openNew, edit, save, run, runWithParams, exportCsv,
           delete: del, close, loadTables, loadColumns,
           buildSql, addCondition, removeCondition,
           addParam, removeParam, autoCode, toggleSqlMode };
})();
</script>
