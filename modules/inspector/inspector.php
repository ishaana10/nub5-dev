<?php
/**
 * modules/inspector/inspector.php
 * Admin DB & Server Inspector
 */
if (!defined('NU_VERSION')) {
    $nuRoot = realpath(__DIR__ . '/../../');
    require_once $nuRoot . '/config.php';
    require_once $nuRoot . '/core/Database.php';
    require_once $nuRoot . '/core/Auth.php';
}

$_auth = NuAuth::getInstance();
if (!$_auth->checkAuth()) {
    echo '<div style="padding:24px;color:red;">Not authenticated.</div>';
    return;
}
$_u    = $_auth->getCurrentUser();
$_role = strtolower((string)($_u['usr_role'] ?? ''));
if ($_role !== 'globeadmin' && $_role !== 'admin') {
    echo '<div style="padding:24px;color:red;">Access denied.</div>';
    return;
}

// ── Resolve server-side URLs so JS gets correct values ──────────────────────
$_proto   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$_host    = $_SERVER['HTTP_HOST'] ?? '';
$_bareHost = preg_replace('/^www\./', '', strtok($_host, ':'));  // ict-fj.com

// phpMyAdmin: A2Hosting / cPanel hosts it at /cpanel -> phpMyAdmin link,
// OR via the cPanel-issued URL. Best general approach: link to cPanel MySQL.
// The user can also override via the config dialog in the UI.
$_pmaUrl      = $_proto . '://' . $_host . '/cpanel';            // safe fallback — takes you to cPanel where PMA link exists
$_cpanelUrl   = $_proto . '://' . $_bareHost . ':2083/';         // cPanel direct port
$_cpanelHttps = $_proto . '://' . $_bareHost . ':2083/';         // same
$_fileManUrl  = $_proto . '://' . $_bareHost . ':2083/frontend/jupiter/filemanager/index.html';

// Check if /phpmyadmin or /phpMyAdmin actually exists on disk (some hosts put it in docroot)
$_docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');
foreach (['phpmyadmin','phpMyAdmin','pma'] as $_pmaDir) {
    if (is_dir($_docRoot . '/' . $_pmaDir)) {
        $_pmaUrl = $_proto . '://' . $_host . '/' . $_pmaDir . '/';
        break;
    }
}
unset($_pmaDir);
?>
<style>
#nuInspector { display:flex; flex-direction:column; height:calc(100vh - 60px); font-size:14px; }
.nu-ins-header { display:flex; align-items:center; gap:8px; padding:10px 16px;
                 border-bottom:2px solid var(--border-color,#e2e8f0); flex-shrink:0;
                 background:var(--bg-subtle,#f8fafc); flex-wrap:wrap; }
.nu-ins-header h3 { margin:0; font-size:15px; flex:1; min-width:120px; }
.nu-ins-launch-btn { display:inline-flex; align-items:center; gap:6px; padding:7px 14px;
                     border:none; border-radius:7px; cursor:pointer; font-size:13px; font-weight:600;
                     text-decoration:none; transition:.15s; white-space:nowrap; }
.nu-ins-launch-db     { background:#1e40af; color:#fff; }
.nu-ins-launch-db:hover { background:#1d3a9e; }
.nu-ins-launch-ftp    { background:#065f46; color:#fff; }
.nu-ins-launch-ftp:hover { background:#054f3a; }
.nu-ins-launch-cpanel { background:#e65100; color:#fff; }
.nu-ins-launch-cpanel:hover { background:#bf4500; }
.nu-ins-launch-cfg    { background:none; color:var(--text-muted,#64748b); border:1px solid var(--border-color,#e2e8f0); }
.nu-ins-launch-cfg:hover { background:rgba(0,0,0,.05); }
/* URL config modal */
.nu-ins-cfg-modal { position:fixed; top:0; left:0; right:0; bottom:0; z-index:9999;
                    background:rgba(0,0,0,.45); display:flex; align-items:center; justify-content:center; }
.nu-ins-cfg-box   { background:#fff; border-radius:10px; padding:24px; width:520px; max-width:95vw;
                    box-shadow:0 8px 32px rgba(0,0,0,.2); }
.nu-ins-cfg-box h4 { margin:0 0 16px; font-size:15px; }
.nu-ins-cfg-row   { margin-bottom:12px; }
.nu-ins-cfg-row label { display:block; font-size:12px; font-weight:600; color:#64748b; margin-bottom:4px; }
.nu-ins-cfg-row input { width:100%; box-sizing:border-box; padding:7px 10px; border:1px solid #e2e8f0;
                        border-radius:6px; font-size:13px; font-family:monospace; }
.nu-ins-cfg-hint  { font-size:11px; color:#94a3b8; margin-top:3px; }
/* Tabs */
.nu-ins-tabs { display:flex; gap:4px; padding:10px 16px 0; border-bottom:1px solid var(--border-color,#e2e8f0); flex-shrink:0; flex-wrap:wrap; }
.nu-ins-tab  { padding:7px 14px; border:none; background:none; cursor:pointer; font-size:13px; font-weight:500;
               color:var(--text-muted,#64748b); border-bottom:3px solid transparent; margin-bottom:-1px;
               border-radius:4px 4px 0 0; transition:.15s; }
.nu-ins-tab.active,.nu-ins-tab:hover { color:var(--primary,#0ea5e9); border-bottom-color:var(--primary,#0ea5e9); background:rgba(14,165,233,.06); }
.nu-ins-panel        { display:none; flex:1; overflow:hidden; min-height:0; }
.nu-ins-panel.active { display:flex; }
/* DB */
#nuInsDbPanel      { flex-direction:row; }
.nu-ins-table-list { width:220px; min-width:140px; border-right:1px solid var(--border-color,#e2e8f0); overflow-y:auto; padding:8px; flex-shrink:0; }
.nu-ins-table-item { padding:7px 10px; border-radius:6px; cursor:pointer; font-size:13px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; transition:.12s; }
.nu-ins-table-item:hover  { background:rgba(0,0,0,.05); }
.nu-ins-table-item.active { background:var(--primary,#0ea5e9); color:#fff; }
.nu-ins-schema       { flex:1; overflow-y:auto; padding:16px; }
.nu-ins-schema table { width:100%; border-collapse:collapse; font-size:13px; }
.nu-ins-schema th,.nu-ins-schema td { padding:8px 10px; text-align:left; border-bottom:1px solid var(--border-color,#e2e8f0); }
.nu-ins-schema th { font-weight:600; background:var(--bg-subtle,rgba(0,0,0,.03)); }
.nu-badge     { display:inline-block; padding:2px 7px; border-radius:10px; font-size:11px; font-weight:600; background:#f1f5f9; color:#64748b; }
.nu-badge.pri { background:#fef3c7; color:#92400e; }
.nu-badge.nn  { background:#dcfce7; color:#166534; }
/* SQL */
#nuInsSqlPanel      { flex-direction:column; }
.nu-ins-sql-bar     { display:flex; gap:8px; padding:12px 16px; align-items:flex-end; border-bottom:1px solid var(--border-color,#e2e8f0); flex-shrink:0; }
.nu-ins-sql-bar textarea { flex:1; font-family:monospace; font-size:13px; resize:vertical; min-height:80px;
                           padding:8px 10px; border:1px solid var(--border-color,#e2e8f0); border-radius:6px;
                           background:var(--input-bg,#fff); color:inherit; }
.nu-ins-sql-results { flex:1; overflow:auto; padding:16px; }
.nu-ins-sql-results table { width:100%; border-collapse:collapse; font-size:12px; }
.nu-ins-sql-results th,.nu-ins-sql-results td { padding:6px 10px; border:1px solid var(--border-color,#e2e8f0); white-space:nowrap; }
.nu-ins-sql-results th { background:var(--bg-subtle,rgba(0,0,0,.03)); font-weight:600; }
.nu-ins-err { color:#dc2626; font-weight:600; }
.nu-ins-ok  { color:#16a34a; font-weight:600; }
/* Files */
#nuInsFilePanel     { flex-direction:row; }
.nu-ins-file-tree   { width:260px; min-width:160px; border-right:1px solid var(--border-color,#e2e8f0); overflow-y:auto; padding:8px; flex-shrink:0; }
.nu-ins-file-item   { padding:5px 8px; border-radius:4px; cursor:pointer; font-size:12px; white-space:nowrap; overflow:hidden;
                      text-overflow:ellipsis; display:flex; gap:6px; align-items:center; transition:.1s; }
.nu-ins-file-item:hover  { background:rgba(0,0,0,.05); }
.nu-ins-file-item.active { background:var(--primary,#0ea5e9); color:#fff; }
.nu-ins-file-content  { flex:1; overflow:auto; padding:0; display:flex; flex-direction:column; }
.nu-ins-file-toolbar  { display:flex; align-items:center; gap:8px; padding:8px 16px;
                        border-bottom:1px solid var(--border-color,#e2e8f0); flex-shrink:0;
                        background:var(--bg-subtle,#f8fafc); flex-wrap:wrap; }
.nu-ins-file-toolbar strong { flex:1; font-size:13px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.nu-ins-editor { flex:1; font-size:12px; font-family:monospace; resize:none; width:100%; border:none;
                 background:var(--bg-subtle,#f8fafc); color:inherit; padding:12px 16px;
                 outline:none; tab-size:4; line-height:1.6; }
/* Server */
#nuInsSrvPanel     { flex-direction:column; overflow-y:auto; padding:20px; gap:16px; }
.nu-ins-srv-card   { background:var(--bg-subtle,#f8fafc); border:1px solid var(--border-color,#e2e8f0); border-radius:8px; padding:16px; margin-bottom:12px; }
.nu-ins-srv-card h4 { margin:0 0 12px; font-size:13px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:#64748b; }
.nu-ins-srv-row    { display:flex; justify-content:space-between; gap:12px; padding:6px 0; border-bottom:1px solid var(--border-color,#e2e8f0); font-size:13px; }
.nu-ins-srv-row:last-child { border:none; }
.nu-ins-srv-label  { color:#64748b; flex-shrink:0; }
.nu-ins-srv-val    { font-weight:600; word-break:break-all; text-align:right; }
.nu-ins-ext-list   { display:flex; flex-wrap:wrap; gap:4px; margin-top:8px; }
.nu-ins-ext-badge  { font-size:11px; padding:2px 7px; border-radius:10px; background:#e2e8f0; color:#64748b; }
</style>

<div id="nuInspector">

  <!-- Header quick-launch -->
  <div class="nu-ins-header">
    <h3>&#x1F6E0; DB &amp; Server Inspector</h3>
    <button class="nu-ins-launch-btn nu-ins-launch-db"     onclick="nuInsOpenPhpMyAdmin()"  title="Open phpMyAdmin / MySQL">&#x1F5C4; MySQL Database</button>
    <button class="nu-ins-launch-btn nu-ins-launch-ftp"    onclick="nuInsOpenFileManager()" title="Open cPanel File Manager">&#x1F4C2; File Manager</button>
    <button class="nu-ins-launch-btn nu-ins-launch-cpanel" onclick="nuInsOpenCpanel()"       title="Open cPanel">&#x2699; cPanel</button>
    <button class="nu-ins-launch-btn nu-ins-launch-cfg"    onclick="nuInsShowCfg()"          title="Configure URLs">&#x2699; Configure Links</button>
  </div>

  <!-- URL config modal (hidden by default) -->
  <div id="nuInsCfgModal" class="nu-ins-cfg-modal" style="display:none;" onclick="if(event.target===this)nuInsHideCfg()">
    <div class="nu-ins-cfg-box">
      <h4>&#x2699; Configure Quick-Launch URLs</h4>
      <div class="nu-ins-cfg-row">
        <label>phpMyAdmin / MySQL URL</label>
        <input type="url" id="nuInsCfgPma" placeholder="https://ict-fj.com/cpanel">
        <div class="nu-ins-cfg-hint">On A2Hosting: log into cPanel &rarr; Databases &rarr; phpMyAdmin, then copy that URL here.</div>
      </div>
      <div class="nu-ins-cfg-row">
        <label>File Manager URL</label>
        <input type="url" id="nuInsCfgFm" placeholder="https://ict-fj.com:2083/frontend/jupiter/filemanager/">
        <div class="nu-ins-cfg-hint">cPanel File Manager direct link (port 2083).</div>
      </div>
      <div class="nu-ins-cfg-row">
        <label>cPanel URL</label>
        <input type="url" id="nuInsCfgCp" placeholder="https://ict-fj.com:2083/">
      </div>
      <div style="display:flex;gap:8px;margin-top:16px;">
        <button class="nu-btn nu-btn-primary" onclick="nuInsSaveCfg()">&#x1F4BE; Save</button>
        <button class="nu-btn nu-btn-ghost"   onclick="nuInsHideCfg()">Cancel</button>
        <button class="nu-btn nu-btn-ghost" style="margin-left:auto;color:#e53e3e;" onclick="nuInsResetCfg()">Reset defaults</button>
      </div>
    </div>
  </div>

  <!-- Tabs -->
  <div class="nu-ins-tabs">
    <button class="nu-ins-tab active" onclick="nuInsTab(this,'nuInsDbPanel')">&#x1F5C4; Database</button>
    <button class="nu-ins-tab"       onclick="nuInsTab(this,'nuInsSqlPanel')">&#x2699; SQL Runner</button>
    <button class="nu-ins-tab"       onclick="nuInsTab(this,'nuInsFilePanel')">&#x1F4C1; File Browser</button>
    <button class="nu-ins-tab"       onclick="nuInsTab(this,'nuInsSrvPanel')">&#x1F5A5; Server Info</button>
  </div>

  <!-- DB Panel -->
  <div class="nu-ins-panel active" id="nuInsDbPanel">
    <div class="nu-ins-table-list" id="nuInsTableList"><div style="padding:8px;color:#999;font-size:12px;">Loading&hellip;</div></div>
    <div class="nu-ins-schema"     id="nuInsSchema"><div style="padding:20px;color:#999;font-size:13px;">Select a table.</div></div>
  </div>

  <!-- SQL Panel -->
  <div class="nu-ins-panel" id="nuInsSqlPanel">
    <div class="nu-ins-sql-bar">
      <textarea id="nuInsSqlInput" placeholder="SELECT * FROM nu_users LIMIT 10;  (Ctrl+Enter to run)"></textarea>
      <button class="nu-btn nu-btn-primary" onclick="nuInsRunSql()">&#x25B6; Run</button>
    </div>
    <div class="nu-ins-sql-results" id="nuInsSqlResults"><p style="color:#999;font-size:13px;">Write a query and press Run.</p></div>
  </div>

  <!-- File Browser -->
  <div class="nu-ins-panel" id="nuInsFilePanel">
    <div class="nu-ins-file-tree" id="nuInsFileTree"><div style="padding:8px;color:#999;font-size:12px;">Loading&hellip;</div></div>
    <div class="nu-ins-file-content" id="nuInsFileContent">
      <div class="nu-ins-file-toolbar">
        <strong id="nuInsFileName">Select a file</strong>
        <span id="nuInsFileSize" style="font-size:11px;color:#888;"></span>
        <button class="nu-btn nu-btn-primary nu-btn-sm" id="nuInsSaveBtn" onclick="nuInsSaveFile()" style="display:none;">&#x1F4BE; Save</button>
        <span id="nuInsSaveStatus" style="font-size:12px;"></span>
      </div>
      <textarea class="nu-ins-editor" id="nuInsEditor" placeholder="Select a file from the tree to edit it here..." readonly></textarea>
    </div>
  </div>

  <!-- Server Panel -->
  <div class="nu-ins-panel" id="nuInsSrvPanel">
    <div style="color:#999;font-size:13px;padding:20px;">Loading&hellip;</div>
  </div>

</div>

<script>
(function(){
  var _api = 'api/inspector.php';
  var _currentFilePath    = null;
  var _currentFileEditable = false;

  // PHP-resolved defaults (server-side)
  var _defaults = {
    pma : <?= json_encode($_pmaUrl) ?>,
    fm  : <?= json_encode($_fileManUrl) ?>,
    cp  : <?= json_encode($_cpanelUrl) ?>
  };

  // Load saved overrides from localStorage
  function _loadCfg() {
    try {
      var s = localStorage.getItem('nu-ins-links');
      return s ? JSON.parse(s) : {};
    } catch(e) { return {}; }
  }
  function _cfg(key) { var c = _loadCfg(); return (c[key] && c[key].trim()) ? c[key].trim() : _defaults[key]; }

  /* ── Quick-launch ── */
  window.nuInsOpenPhpMyAdmin  = function() { window.open(_cfg('pma'), '_blank'); };
  window.nuInsOpenFileManager = function() { window.open(_cfg('fm'),  '_blank'); };
  window.nuInsOpenCpanel      = function() { window.open(_cfg('cp'),  '_blank'); };

  /* ── Config modal ── */
  window.nuInsShowCfg = function() {
    var c = _loadCfg();
    document.getElementById('nuInsCfgPma').value = c.pma || _defaults.pma;
    document.getElementById('nuInsCfgFm').value  = c.fm  || _defaults.fm;
    document.getElementById('nuInsCfgCp').value  = c.cp  || _defaults.cp;
    document.getElementById('nuInsCfgModal').style.display = 'flex';
  };
  window.nuInsHideCfg = function() {
    document.getElementById('nuInsCfgModal').style.display = 'none';
  };
  window.nuInsSaveCfg = function() {
    var obj = {
      pma : document.getElementById('nuInsCfgPma').value.trim(),
      fm  : document.getElementById('nuInsCfgFm').value.trim(),
      cp  : document.getElementById('nuInsCfgCp').value.trim()
    };
    try { localStorage.setItem('nu-ins-links', JSON.stringify(obj)); } catch(e) {}
    window.nuInsHideCfg();
    NuApp.toast('Links saved', 'success');
  };
  window.nuInsResetCfg = function() {
    try { localStorage.removeItem('nu-ins-links'); } catch(e) {}
    document.getElementById('nuInsCfgPma').value = _defaults.pma;
    document.getElementById('nuInsCfgFm').value  = _defaults.fm;
    document.getElementById('nuInsCfgCp').value  = _defaults.cp;
  };

  /* ── Tab switcher ── */
  window.nuInsTab = function(btn, panelId) {
    document.querySelectorAll('#nuInspector .nu-ins-tab').forEach(function(t){ t.classList.remove('active'); });
    document.querySelectorAll('#nuInspector .nu-ins-panel').forEach(function(p){ p.classList.remove('active'); });
    btn.classList.add('active');
    var panel = document.getElementById(panelId);
    if (panel) panel.classList.add('active');
    if (panelId === 'nuInsSrvPanel'  && !window._nuInsSrvLoaded)  { window._nuInsSrvLoaded  = true; nuInsLoadServer(); }
    if (panelId === 'nuInsFilePanel' && !window._nuInsFileLoaded) { window._nuInsFileLoaded = true; nuInsLoadDir('/'); }
  };

  /* ── DB: tables ── */
  function nuInsLoadTables() {
    fetch(_api + '?action=tables', {credentials:'same-origin'})
      .then(function(r){ return r.json(); })
      .then(function(j){
        var list = document.getElementById('nuInsTableList');
        if (!j.success) { list.innerHTML = '<div style="color:red;padding:8px;">' + j.error + '</div>'; return; }
        list.innerHTML = '';
        (j.tables || []).forEach(function(t){
          var d = document.createElement('div');
          d.className = 'nu-ins-table-item'; d.textContent = t; d.title = t;
          d.onclick = function(){ window.nuInsLoadTable(t, d); };
          list.appendChild(d);
        });
      })
      .catch(function(e){
        var list = document.getElementById('nuInsTableList');
        if (list) list.innerHTML = '<div style="color:red;padding:8px;">' + e.message + '</div>';
      });
  }

  window.nuInsLoadTable = function(table, el) {
    document.querySelectorAll('.nu-ins-table-item').forEach(function(i){ i.classList.remove('active'); });
    if (el) el.classList.add('active');
    var schema = document.getElementById('nuInsSchema');
    schema.innerHTML = '<div style="padding:20px;color:#999;">Loading&hellip;</div>';
    fetch(_api + '?action=columns&table=' + encodeURIComponent(table), {credentials:'same-origin'})
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (!j.success) { schema.innerHTML = '<div style="color:red;padding:16px;">' + j.error + '</div>'; return; }
        var html = '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">' +
          '<h3 style="margin:0;font-size:15px;">' + table + '</h3>' +
          '<span style="font-size:12px;color:#888;">' + j.row_count + ' rows &nbsp;' +
          '<button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="nuInsPreviewData(\'' + table + '\')">&#x1F441; Preview</button></span>' +
          '</div><table><thead><tr>' +
          ['Field','Type','Null','Key','Default','Extra'].map(function(h){ return '<th>' + h + '</th>'; }).join('') +
          '</tr></thead><tbody>';
        (j.columns || []).forEach(function(c){
          html += '<tr>' +
            '<td><strong>' + c.Field + '</strong></td>' +
            '<td><code style="font-size:12px;">' + c.Type + '</code></td>' +
            '<td>' + (c.Null==='YES' ? '<span class="nu-badge">NULL</span>' : '<span class="nu-badge nn">NOT NULL</span>') + '</td>' +
            '<td>' + (c.Key==='PRI' ? '<span class="nu-badge pri">PK</span>' : (c.Key||'')) + '</td>' +
            '<td style="color:#888;font-size:12px;">' + (c.Default||'') + '</td>' +
            '<td style="font-size:12px;color:#666;">' + c.Extra + '</td></tr>';
        });
        schema.innerHTML = html + '</tbody></table>';
      });
  };

  window.nuInsPreviewData = function(table, offset) {
    offset = offset || 0;
    var schema = document.getElementById('nuInsSchema');
    schema.innerHTML = '<div style="padding:20px;color:#999;">Loading rows&hellip;</div>';
    fetch(_api + '?action=data&table=' + encodeURIComponent(table) + '&offset=' + offset + '&limit=100', {credentials:'same-origin'})
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (!j.success) { schema.innerHTML = '<div style="color:red;padding:16px;">' + j.error + '</div>'; return; }
        var cols = j.columns||[], rows = j.rows||[];
        var html = '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">' +
          '<h3 style="margin:0;font-size:15px;">' + table + ' &mdash; ' + (offset+1) + '&ndash;' + (offset+rows.length) + ' of ' + j.total + '</h3>' +
          '<div style="display:flex;gap:8px;">' +
          '<button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="nuInsLoadTable(\'' + table + '\',null)">&#x21A9; Schema</button>' +
          (offset>0 ? '<button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="nuInsPreviewData(\'' + table + '\',' + (offset-100) + ')">&#x2190; Prev</button>' : '') +
          (offset+rows.length < j.total ? '<button class="nu-btn nu-btn-primary nu-btn-sm" onclick="nuInsPreviewData(\'' + table + '\',' + (offset+100) + ')">Next &#x2192;</button>' : '') +
          '</div></div><div style="overflow-x:auto;"><table><thead><tr>';
        cols.forEach(function(c){ html += '<th>' + c + '</th>'; });
        html += '</tr></thead><tbody>';
        if (!rows.length) { html += '<tr><td colspan="' + cols.length + '" style="text-align:center;padding:20px;color:#999;">No rows</td></tr>'; }
        rows.forEach(function(row){
          html += '<tr>';
          cols.forEach(function(c){
            var v = row[c];
            html += '<td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' +
              (v!=null?String(v).replace(/"/g,'&quot;'):'') + '">' +
              (v!=null?String(v):'<span style="color:#aaa;">NULL</span>') + '</td>';
          });
          html += '</tr>';
        });
        schema.innerHTML = html + '</tbody></table></div>';
      });
  };

  /* ── SQL Runner ── */
  window.nuInsRunSql = function() {
    var sql = (document.getElementById('nuInsSqlInput')||{}).value||'';
    if (!sql.trim()) return;
    var out = document.getElementById('nuInsSqlResults');
    out.innerHTML = '<div style="padding:12px;color:#999;">Running&hellip;</div>';
    fetch(_api + '?action=sql', {
      method:'POST', credentials:'same-origin',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({sql:sql})
    })
    .then(function(r){ return r.json(); })
    .then(function(j){
      if (!j.success) { out.innerHTML = '<p class="nu-ins-err">&#x274C; ' + j.error + '</p>'; return; }
      if (j.type==='select') {
        if (!j.rows.length) { out.innerHTML = '<p class="nu-ins-ok">&#x2705; 0 rows</p>'; return; }
        var cols = Object.keys(j.rows[0]);
        var html = '<p class="nu-ins-ok" style="margin-bottom:8px;">&#x2705; ' + j.count + ' row' + (j.count!==1?'s':'') + '</p><table><thead><tr>';
        cols.forEach(function(c){ html += '<th>' + c + '</th>'; });
        html += '</tr></thead><tbody>';
        j.rows.forEach(function(row){
          html += '<tr>';
          cols.forEach(function(c){ var v=row[c]; html += '<td>'+(v!=null?String(v):'<em style="color:#aaa;">NULL</em>')+'</td>'; });
          html += '</tr>';
        });
        out.innerHTML = html + '</tbody></table>';
      } else {
        out.innerHTML = '<p class="nu-ins-ok">&#x2705; OK &mdash; ' + j.affected + ' row' + (j.affected!==1?'s':'') + ' affected</p>';
      }
    })
    .catch(function(e){ out.innerHTML = '<p class="nu-ins-err">&#x274C; ' + e.message + '</p>'; });
  };

  document.addEventListener('keydown', function(e){
    if ((e.ctrlKey||e.metaKey) && e.key==='Enter') {
      var inp = document.getElementById('nuInsSqlInput');
      if (inp && document.activeElement===inp) { e.preventDefault(); window.nuInsRunSql(); }
    }
    if ((e.ctrlKey||e.metaKey) && e.key==='s') {
      var ed = document.getElementById('nuInsEditor');
      if (ed && document.activeElement===ed && _currentFileEditable) { e.preventDefault(); window.nuInsSaveFile(); }
    }
    if (e.key==='Escape') { window.nuInsHideCfg(); }
  });

  /* ── File Browser ── */
  window.nuInsLoadDir = function(path) {
    var tree = document.getElementById('nuInsFileTree');
    tree.innerHTML = '<div style="padding:8px;color:#999;font-size:12px;">Loading&hellip;</div>';
    fetch(_api + '?action=files&path=' + encodeURIComponent(path), {credentials:'same-origin'})
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (!j.success) { tree.innerHTML = '<div style="color:red;padding:8px;">' + j.error + '</div>'; return; }
        tree.innerHTML = '<div style="padding:4px 8px 6px;font-size:11px;color:#999;border-bottom:1px solid var(--border-color,#e2e8f0);margin-bottom:4px;word-break:break-all;">' + j.path + '</div>';
        (j.entries||[]).forEach(function(entry){
          var d = document.createElement('div');
          d.className = 'nu-ins-file-item';
          d.innerHTML = (entry.type==='dir'?'&#x1F4C1;':'&#x1F4C4;') + ' <span>' + entry.name + '</span>';
          d.title = entry.name + (entry.size!=null?' ('+Math.round(entry.size/1024)+'KB)':'');
          if (entry.type==='dir') d.onclick = function(){ window.nuInsLoadDir(entry.path); };
          else                    d.onclick = function(){ window.nuInsLoadFile(entry.path, d); };
          tree.appendChild(d);
        });
      })
      .catch(function(e){ document.getElementById('nuInsFileTree').innerHTML = '<div style="color:red;padding:8px;">' + e.message + '</div>'; });
  };

  window.nuInsLoadFile = function(path, treeEl) {
    document.querySelectorAll('.nu-ins-file-item').forEach(function(i){ i.classList.remove('active'); });
    if (treeEl) treeEl.classList.add('active');
    var nameEl   = document.getElementById('nuInsFileName');
    var sizeEl   = document.getElementById('nuInsFileSize');
    var saveBtn  = document.getElementById('nuInsSaveBtn');
    var statusEl = document.getElementById('nuInsSaveStatus');
    var editor   = document.getElementById('nuInsEditor');
    nameEl.textContent = 'Loading…'; sizeEl.textContent = ''; statusEl.textContent = '';
    editor.value = ''; editor.readOnly = true; saveBtn.style.display = 'none';
    fetch(_api + '?action=files&path=' + encodeURIComponent(path), {credentials:'same-origin'})
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (!j.success) { nameEl.textContent = 'Error'; editor.value = j.error; return; }
        _currentFilePath     = j.path;
        _currentFileEditable = !!j.editable;
        nameEl.textContent   = j.name;
        sizeEl.textContent   = j.size > 1024 ? Math.round(j.size/1024)+'KB' : j.size+'B';
        editor.value         = j.content || '';
        if (_currentFileEditable) {
          editor.readOnly = false; saveBtn.style.display = '';
          statusEl.innerHTML = '<span style="color:#16a34a;">&#x270F; Editable</span>';
        } else {
          editor.readOnly = true; saveBtn.style.display = 'none';
          statusEl.innerHTML = '<span style="color:#f59e0b;">&#x1F512; Read-only</span>';
        }
      })
      .catch(function(e){ nameEl.textContent = 'Error'; editor.value = e.message; });
  };

  window.nuInsSaveFile = function() {
    if (!_currentFilePath || !_currentFileEditable) return;
    var editor   = document.getElementById('nuInsEditor');
    var statusEl = document.getElementById('nuInsSaveStatus');
    var saveBtn  = document.getElementById('nuInsSaveBtn');
    statusEl.innerHTML = '<span style="color:#64748b;">Saving&hellip;</span>';
    saveBtn.disabled   = true;
    fetch(_api + '?action=file_write', {
      method:'POST', credentials:'same-origin',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({path:_currentFilePath, content:editor.value})
    })
    .then(function(r){ return r.json(); })
    .then(function(j){
      saveBtn.disabled = false;
      if (j.success) {
        statusEl.innerHTML = '<span style="color:#16a34a;">&#x2705; Saved (' + j.bytes + 'B) &mdash; backup: ' + j.backup + '</span>';
      } else {
        statusEl.innerHTML = '<span style="color:red;">&#x274C; ' + j.error + '</span>';
      }
    })
    .catch(function(e){ saveBtn.disabled=false; statusEl.innerHTML='<span style="color:red;">&#x274C; '+e.message+'</span>'; });
  };

  /* ── Server Info ── */
  function nuInsLoadServer() {
    var p = document.getElementById('nuInsSrvPanel');
    p.innerHTML = '<div style="padding:20px;color:#999;font-size:13px;">Loading&hellip;</div>';
    fetch(_api + '?action=serverinfo', {credentials:'same-origin'})
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (!j.success) { p.innerHTML = '<div style="color:red;padding:20px;">' + j.error + '</div>'; return; }
        function card(title, rows) {
          return '<div class="nu-ins-srv-card"><h4>' + title + '</h4>' +
            rows.map(function(r){ return '<div class="nu-ins-srv-row"><span class="nu-ins-srv-label">'+r[0]+'</span><span class="nu-ins-srv-val">'+r[1]+'</span></div>'; }).join('') + '</div>';
        }
        function fmt(b){ if(!b) return 'n/a'; return b>1073741824?(b/1073741824).toFixed(1)+' GB':Math.round(b/1048576)+' MB'; }
        p.innerHTML =
          card('Runtime',   [['PHP',j.php_version],['MySQL',j.db_version],['OS',j.server_os],['Server IP',j.server_addr]]) +
          card('Paths',     [['App Root',j.app_root],['Doc Root',j.doc_root]]) +
          card('Resources', [['Disk Free',fmt(j.disk_free)],['Disk Total',fmt(j.disk_total)],
                              ['Memory Limit',j.memory_limit],['Upload Max',j.upload_max],['Max Exec',j.max_exec]]) +
          '<div class="nu-ins-srv-card"><h4>Extensions (' + j.extensions.length + ')</h4>' +
          '<div class="nu-ins-ext-list">' + j.extensions.map(function(ex){ return '<span class="nu-ins-ext-badge">'+ex+'</span>'; }).join('') + '</div></div>';
      })
      .catch(function(e){ document.getElementById('nuInsSrvPanel').innerHTML = '<div style="color:red;padding:20px;">'+e.message+'</div>'; });
  }

  /* ── Boot ── */
  window._nuInsSrvLoaded  = false;
  window._nuInsFileLoaded = false;
  nuInsLoadTables();

})();
</script>
