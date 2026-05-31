<?php
/**
 * modules/inspector/inspector.php
 * Admin DB & Server Inspector — rendered by NuApp.loadModule('inspector')
 * This file is fetched via XHR so it runs inside the app shell;
 * it MUST bootstrap config + auth itself.
 */
if (!defined('NU_VERSION')) {
    // Resolve project root (modules/inspector/ → ../../)
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
    echo '<div style="padding:24px;color:red;">Access denied — admin role required.</div>';
    return;
}
?>
<style>
/* ── Inspector layout ──────────────────────────────────────────────────────── */
#nuInspector { display:flex; flex-direction:column; height:100%; gap:0; font-size:14px; }

/* tabs */
.ni-tabs  { display:flex; gap:4px; padding:12px 16px 0; border-bottom:1px solid var(--border-color,#e2e8f0); flex-shrink:0; flex-wrap:wrap; }
.ni-tab   { padding:8px 16px; border:none; background:none; cursor:pointer; font-size:13px; font-weight:500;
            color:var(--text-muted,#64748b); border-bottom:3px solid transparent; margin-bottom:-1px; border-radius:4px 4px 0 0; transition:.15s; }
.ni-tab.active, .ni-tab:hover { color:var(--primary,#0ea5e9); border-bottom-color:var(--primary,#0ea5e9); background:var(--bg-hover,rgba(14,165,233,.06)); }

/* panels */
.ni-panel      { display:none; flex:1; overflow:hidden; }
.ni-panel.active { display:flex; }

/* ── DB panel ──────────────────────────────────────────────────────────────── */
#niDbPanel { flex-direction:row; overflow:hidden; }
.ni-table-list { width:220px; min-width:160px; border-right:1px solid var(--border-color,#e2e8f0);
                 overflow-y:auto; padding:8px; flex-shrink:0; }
.ni-table-item { padding:7px 10px; border-radius:6px; cursor:pointer; font-size:13px;
                 white-space:nowrap; overflow:hidden; text-overflow:ellipsis; transition:.12s; }
.ni-table-item:hover  { background:var(--bg-hover,rgba(0,0,0,.05)); }
.ni-table-item.active { background:var(--primary,#0ea5e9); color:#fff; }
.ni-schema { flex:1; overflow-y:auto; padding:16px; }
.ni-schema table { width:100%; border-collapse:collapse; font-size:13px; }
.ni-schema th,
.ni-schema td   { padding:8px 10px; text-align:left; border-bottom:1px solid var(--border-color,#e2e8f0); }
.ni-schema th   { font-weight:600; background:var(--bg-subtle,rgba(0,0,0,.03)); }
.ni-badge { display:inline-block; padding:2px 7px; border-radius:10px; font-size:11px; font-weight:600;
            background:var(--bg-subtle,#f1f5f9); color:var(--text-muted,#64748b); }
.ni-badge.pri { background:#fef3c7; color:#92400e; }
.ni-badge.nn  { background:#dcfce7; color:#166534; }

/* ── SQL panel ─────────────────────────────────────────────────────────────── */
#niSqlPanel { flex-direction:column; overflow:hidden; }
.ni-sql-bar { display:flex; gap:8px; padding:12px 16px; align-items:flex-end; border-bottom:1px solid var(--border-color,#e2e8f0); flex-shrink:0; }
.ni-sql-bar textarea { flex:1; font-family:monospace; font-size:13px; resize:vertical; min-height:80px;
                       padding:8px 10px; border:1px solid var(--border-color,#e2e8f0); border-radius:6px;
                       background:var(--input-bg,#fff); color:inherit; }
.ni-sql-results { flex:1; overflow:auto; padding:16px; }
.ni-sql-results table { width:100%; border-collapse:collapse; font-size:12px; }
.ni-sql-results th,
.ni-sql-results td { padding:6px 10px; border:1px solid var(--border-color,#e2e8f0); white-space:nowrap; }
.ni-sql-results th { background:var(--bg-subtle,rgba(0,0,0,.03)); font-weight:600; }
.ni-sql-results .ni-err { color:red; font-weight:600; }
.ni-sql-results .ni-ok  { color:green; font-weight:600; }

/* ── File panel ────────────────────────────────────────────────────────────── */
#niFilePanel { flex-direction:row; overflow:hidden; }
.ni-file-tree { width:260px; min-width:180px; border-right:1px solid var(--border-color,#e2e8f0);
                overflow-y:auto; padding:8px; flex-shrink:0; }
.ni-file-item { padding:5px 8px; border-radius:4px; cursor:pointer; font-size:12px;
                white-space:nowrap; overflow:hidden; text-overflow:ellipsis; display:flex; gap:6px; align-items:center; }
.ni-file-item:hover { background:var(--bg-hover,rgba(0,0,0,.05)); }
.ni-file-content { flex:1; overflow:auto; padding:16px; }
.ni-file-content pre { font-size:12px; font-family:monospace; white-space:pre-wrap; word-break:break-all;
                        background:var(--bg-subtle,#f8fafc); padding:12px; border-radius:6px;
                        border:1px solid var(--border-color,#e2e8f0); margin:0; }

/* ── Server panel ──────────────────────────────────────────────────────────── */
#niSrvPanel { flex-direction:column; overflow-y:auto; padding:20px; gap:16px; }
.ni-srv-card { background:var(--bg-subtle,#f8fafc); border:1px solid var(--border-color,#e2e8f0);
               border-radius:8px; padding:16px; }
.ni-srv-card h4 { margin:0 0 12px; font-size:13px; font-weight:700; text-transform:uppercase;
                  letter-spacing:.05em; color:var(--text-muted,#64748b); }
.ni-srv-row { display:flex; justify-content:space-between; gap:12px; padding:6px 0;
              border-bottom:1px solid var(--border-color,#e2e8f0); font-size:13px; }
.ni-srv-row:last-child { border:none; }
.ni-srv-label { color:var(--text-muted,#64748b); flex-shrink:0; }
.ni-srv-val   { font-weight:600; word-break:break-all; text-align:right; }
.ni-ext-list  { display:flex; flex-wrap:wrap; gap:4px; margin-top:8px; }
.ni-ext-badge { font-size:11px; padding:2px 7px; border-radius:10px;
                background:var(--bg-subtle,#e2e8f0); color:var(--text-muted,#64748b); }
</style>

<div id="nuInspector">

  <!-- Tabs -->
  <div class="ni-tabs">
    <button class="ni-tab active" onclick="niTab(this,'niDbPanel')">&#x1F5C4; Database</button>
    <button class="ni-tab"       onclick="niTab(this,'niSqlPanel')">&#x2699;&#xFE0F; SQL Runner</button>
    <button class="ni-tab"       onclick="niTab(this,'niFilePanel')">&#x1F4C1; File Browser</button>
    <button class="ni-tab"       onclick="niTab(this,'niSrvPanel')">&#x1F5A5; Server Info</button>
  </div>

  <!-- DB Panel -->
  <div class="ni-panel active" id="niDbPanel">
    <div class="ni-table-list" id="niTableList"><div style="padding:8px;color:#999;font-size:12px;">Loading&hellip;</div></div>
    <div class="ni-schema"    id="niSchema"><div style="padding:20px;color:#999;font-size:13px;">Select a table on the left.</div></div>
  </div>

  <!-- SQL Panel -->
  <div class="ni-panel" id="niSqlPanel">
    <div class="ni-sql-bar">
      <textarea id="niSqlInput" placeholder="SELECT * FROM nu_users LIMIT 10;
Ctrl+Enter to run"></textarea>
      <button class="nu-btn nu-btn-primary" onclick="niRunSql()">&#x25B6; Run</button>
    </div>
    <div class="ni-sql-results" id="niSqlResults">
      <p style="color:#999;font-size:13px;">Write a query above and press Run (or Ctrl+Enter).</p>
    </div>
  </div>

  <!-- File Panel -->
  <div class="ni-panel" id="niFilePanel">
    <div class="ni-file-tree"    id="niFileTree"><div style="padding:8px;color:#999;font-size:12px;">Loading&hellip;</div></div>
    <div class="ni-file-content" id="niFileContent"><p style="color:#999;font-size:13px;padding:20px;">Select a file on the left.</p></div>
  </div>

  <!-- Server Panel -->
  <div class="ni-panel" id="niSrvPanel">
    <div style="color:#999;font-size:13px;">Loading&hellip;</div>
  </div>

</div>

<script>
(function(){
  var _api = 'api/inspector.php';

  /* ── Tab switcher ── */
  window.niTab = function(btn, panelId) {
    document.querySelectorAll('.ni-tab').forEach(function(t){t.classList.remove('active');});
    document.querySelectorAll('.ni-panel').forEach(function(p){p.classList.remove('active');});
    btn.classList.add('active');
    document.getElementById(panelId).classList.add('active');
    if (panelId === 'niSrvPanel'   && !niSrvPanel._loaded) { niLoadServer(); niSrvPanel._loaded=true; }
    if (panelId === 'niFilePanel'  && !niFilePanel._loaded){ niLoadDir('/'); niFilePanel._loaded=true; }
  };

  /* ── DB: load table list ── */
  function niLoadTables() {
    fetch(_api + '?action=tables', {credentials:'same-origin'})
      .then(function(r){return r.json();})
      .then(function(j){
        var list = document.getElementById('niTableList');
        if (!j.success) { list.innerHTML='<div style="color:red;padding:8px;">'+j.error+'</div>'; return; }
        list.innerHTML = '';
        (j.tables||[]).forEach(function(t){
          var d = document.createElement('div');
          d.className = 'ni-table-item';
          d.textContent = t;
          d.title = t;
          d.onclick = function(){ niLoadTable(t, d); };
          list.appendChild(d);
        });
      }).catch(function(e){ document.getElementById('niTableList').innerHTML='<div style="color:red;padding:8px;">'+e.message+'</div>'; });
  }

  function niLoadTable(table, el) {
    document.querySelectorAll('.ni-table-item').forEach(function(i){i.classList.remove('active');});
    if(el) el.classList.add('active');
    var schema = document.getElementById('niSchema');
    schema.innerHTML = '<div style="padding:20px;color:#999;">Loading&hellip;</div>';
    fetch(_api + '?action=columns&table=' + encodeURIComponent(table), {credentials:'same-origin'})
      .then(function(r){return r.json();})
      .then(function(j){
        if (!j.success) { schema.innerHTML='<div style="color:red;padding:16px;">'+j.error+'</div>'; return; }
        var html = '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">' +
          '<h3 style="margin:0;font-size:15px;">'+table+'</h3>' +
          '<span style="font-size:12px;color:#888;">'+j.row_count+' rows &nbsp;' +
          '<button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="niPreviewData(\'' + table + '\')">&#x1F441; Preview</button></span>' +
          '</div>' +
          '<table><thead><tr>' +
          ['Field','Type','Null','Key','Default','Extra'].map(function(h){return '<th>'+h+'</th>';}).join('') +
          '</tr></thead><tbody>';
        (j.columns||[]).forEach(function(c){
          html += '<tr>' +
            '<td><strong>'+c.Field+'</strong></td>' +
            '<td><code style="font-size:12px;">'+c.Type+'</code></td>' +
            '<td>'+(c.Null==='YES'?'<span class="ni-badge">NULL</span>':'<span class="ni-badge nn">NOT NULL</span>')+'</td>' +
            '<td>'+(c.Key==='PRI'?'<span class="ni-badge pri">PK</span>':(c.Key||''))+'</td>' +
            '<td style="color:#888;font-size:12px;">'+(c.Default||'')+'</td>' +
            '<td style="font-size:12px;color:#666;">'+c.Extra+'</td>' +
            '</tr>';
        });
        html += '</tbody></table>';
        schema.innerHTML = html;
      });
  }

  window.niPreviewData = function(table, offset) {
    offset = offset || 0;
    var schema = document.getElementById('niSchema');
    schema.innerHTML = '<div style="padding:20px;color:#999;">Loading rows&hellip;</div>';
    fetch(_api + '?action=data&table=' + encodeURIComponent(table) + '&offset=' + offset + '&limit=100', {credentials:'same-origin'})
      .then(function(r){return r.json();})
      .then(function(j){
        if (!j.success) { schema.innerHTML='<div style="color:red;padding:16px;">'+j.error+'</div>'; return; }
        var cols = j.columns || [];
        var rows = j.rows    || [];
        var html = '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">' +
          '<h3 style="margin:0;font-size:15px;">'+table+' &mdash; rows '+(offset+1)+'&ndash;'+(offset+rows.length)+' of '+j.total+'</h3>' +
          '<div style="display:flex;gap:8px;">' +
          '<button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="niLoadTable(\''+table+'\', null)">&#x21A9; Schema</button>' +
          (offset > 0 ? '<button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="niPreviewData(\''+table+'\','+(offset-100)+')">&#x2190; Prev</button>' : '') +
          (offset + rows.length < j.total ? '<button class="nu-btn nu-btn-primary nu-btn-sm" onclick="niPreviewData(\''+table+'\','+(offset+100)+')">Next &#x2192;</button>' : '') +
          '</div></div>' +
          '<div style="overflow-x:auto;"><table><thead><tr>';
        cols.forEach(function(c){ html += '<th>'+c+'</th>'; });
        html += '</tr></thead><tbody>';
        if (!rows.length) { html += '<tr><td colspan="'+cols.length+'" style="text-align:center;padding:20px;color:#999;">No rows</td></tr>'; }
        rows.forEach(function(row){
          html += '<tr>';
          cols.forEach(function(c){
            var v = row[c];
            html += '<td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="'+(v!=null?String(v).replace(/"/g,'&quot;'):'')+'">'+(v!=null?String(v):'<span style="color:#aaa;">NULL</span>')+'</td>';
          });
          html += '</tr>';
        });
        html += '</tbody></table></div>';
        schema.innerHTML = html;
      });
  };

  /* ── SQL Runner ── */
  window.niRunSql = function() {
    var sql = (document.getElementById('niSqlInput')||{}).value || '';
    if (!sql.trim()) return;
    var out = document.getElementById('niSqlResults');
    out.innerHTML = '<div style="padding:12px;color:#999;">Running&hellip;</div>';
    fetch(_api + '?action=sql', {
      method:'POST', credentials:'same-origin',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({sql:sql})
    }).then(function(r){return r.json();})
      .then(function(j){
        if (!j.success) { out.innerHTML='<p class="ni-err">&#x274C; '+j.error+'</p>'; return; }
        if (j.type === 'select') {
          if (!j.rows.length) { out.innerHTML='<p class="ni-ok">&#x2705; Query OK &mdash; 0 rows</p>'; return; }
          var cols = Object.keys(j.rows[0]);
          var html = '<p class="ni-ok" style="margin-bottom:8px;">&#x2705; '+j.count+' row'+(j.count!==1?'s':'')+'</p><table><thead><tr>';
          cols.forEach(function(c){html+='<th>'+c+'</th>';});
          html += '</tr></thead><tbody>';
          j.rows.forEach(function(row){
            html += '<tr>';
            cols.forEach(function(c){
              var v=row[c]; html += '<td>'+(v!=null?String(v):'<em style="color:#aaa;">NULL</em>')+'</td>';
            });
            html += '</tr>';
          });
          html += '</tbody></table>';
          out.innerHTML = html;
        } else {
          out.innerHTML = '<p class="ni-ok">&#x2705; Query OK &mdash; '+j.affected+' row'+(j.affected!==1?'s':'')+' affected</p>';
        }
      }).catch(function(e){ out.innerHTML='<p class="ni-err">&#x274C; '+e.message+'</p>'; });
  };

  /* Ctrl+Enter shortcut */
  document.addEventListener('keydown', function(e){
    if ((e.ctrlKey||e.metaKey) && e.key==='Enter') {
      var inp = document.getElementById('niSqlInput');
      if (inp && document.activeElement === inp) { e.preventDefault(); niRunSql(); }
    }
  });

  /* ── File Browser ── */
  function niLoadDir(path) {
    var tree = document.getElementById('niFileTree');
    tree.innerHTML = '<div style="padding:8px;color:#999;font-size:12px;">Loading&hellip;</div>';
    fetch(_api + '?action=files&path=' + encodeURIComponent(path), {credentials:'same-origin'})
      .then(function(r){return r.json();})
      .then(function(j){
        if (!j.success) { tree.innerHTML='<div style="color:red;padding:8px;">'+j.error+'</div>'; return; }
        tree.innerHTML = '<div style="padding:4px 8px;font-size:11px;color:#999;border-bottom:1px solid var(--border-color,#e2e8f0);margin-bottom:4px;word-break:break-all;">'+j.path+'</div>';
        (j.entries||[]).forEach(function(e){
          var d = document.createElement('div');
          d.className = 'ni-file-item';
          d.innerHTML = (e.type==='dir' ? '&#x1F4C1;' : '&#x1F4C4;') + ' <span style="overflow:hidden;text-overflow:ellipsis;">'+e.name+'</span>';
          d.title = e.name + (e.size!=null?' ('+Math.round(e.size/1024)+'KB)':'');
          if (e.type==='dir') d.onclick = function(){ niLoadDir(e.path); };
          else                d.onclick = function(){ niLoadFile(e.path); };
          tree.appendChild(d);
        });
      }).catch(function(e){ tree.innerHTML='<div style="color:red;padding:8px;">'+e.message+'</div>'; });
  }

  function niLoadFile(path) {
    var out = document.getElementById('niFileContent');
    out.innerHTML = '<p style="padding:20px;color:#999;font-size:13px;">Loading&hellip;</p>';
    fetch(_api + '?action=files&path=' + encodeURIComponent(path), {credentials:'same-origin'})
      .then(function(r){return r.json();})
      .then(function(j){
        if (!j.success) { out.innerHTML='<div style="color:red;padding:16px;">'+j.error+'</div>'; return; }
        var sz = j.size > 1024 ? Math.round(j.size/1024)+'KB' : j.size+'B';
        out.innerHTML = '<div style="display:flex;justify-content:space-between;align-items:center;padding:0 0 10px;margin-bottom:10px;border-bottom:1px solid var(--border-color,#e2e8f0);">' +
          '<strong style="font-size:13px;">'+j.name+'</strong><span style="font-size:11px;color:#888;">'+sz+'</span></div>' +
          '<pre>'+(j.content||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')+'</pre>';
      }).catch(function(e){ out.innerHTML='<div style="color:red;padding:16px;">'+e.message+'</div>'; });
  }

  /* ── Server Info ── */
  function niLoadServer() {
    var p = document.getElementById('niSrvPanel');
    p.innerHTML = '<div style="color:#999;font-size:13px;">Loading&hellip;</div>';
    fetch(_api + '?action=serverinfo', {credentials:'same-origin'})
      .then(function(r){return r.json();})
      .then(function(j){
        if (!j.success) { p.innerHTML='<div style="color:red;">'+j.error+'</div>'; return; }
        function card(title, rows) {
          return '<div class="ni-srv-card"><h4>'+title+'</h4>'+
            rows.map(function(r){ return '<div class="ni-srv-row"><span class="ni-srv-label">'+r[0]+'</span><span class="ni-srv-val">'+r[1]+'</span></div>'; }).join('')+
            '</div>';
        }
        function fmt(b) { if (!b) return 'n/a'; var g=b/1073741824; return g>1?g.toFixed(1)+' GB':(b/1048576).toFixed(0)+' MB'; }
        p.innerHTML =
          card('Runtime', [
            ['PHP', j.php_version],
            ['MySQL', j.db_version],
            ['OS', j.server_os],
            ['App Root', j.app_root]
          ]) +
          card('Resources', [
            ['Disk Free', fmt(j.disk_free)],
            ['Disk Total', fmt(j.disk_total)],
            ['Memory Limit', j.memory_limit],
            ['Upload Max', j.upload_max]
          ]) +
          '<div class="ni-srv-card"><h4>Extensions ('+j.extensions.length+')</h4>' +
          '<div class="ni-ext-list">'+ j.extensions.map(function(e){return '<span class="ni-ext-badge">'+e+'</span>';}).join('') +'</div></div>';
      }).catch(function(e){ document.getElementById('niSrvPanel').innerHTML='<div style="color:red;">'+e.message+'</div>'; });
  }

  /* ── Boot ── */
  niLoadTables();
  niLoadDir('/');

})();
</script>
