<?php
declare(strict_types=1);
// Admin gate — checked server-side too
if (!isset($auth) || !isset($currentUser)) {
    require_once __DIR__ . '/../../config.php';
    require_once __DIR__ . '/../../core/Auth.php';
    $auth = NuAuth::getInstance();
    $currentUser = $auth->getCurrentUser();
}
$role = strtolower((string)($currentUser['usr_role'] ?? ''));
if ($role !== 'admin') {
    echo '<div style="padding:40px;text-align:center;color:#c00;"><h3>&#x1F512; Admin access required</h3></div>';
    return;
}
?>
<div id="nuInspector" style="font-family:inherit;">

<!-- ── Tab Bar ────────────────────────────────────────────────────────────── -->
<div style="display:flex;gap:4px;margin-bottom:20px;border-bottom:2px solid var(--border-color,#ddd);padding-bottom:0;">
  <?php
  $tabs = [
    ['id'=>'db',     'label'=>'&#128202; Database'],
    ['id'=>'sql',    'label'=>'&#9881;&#65039; SQL Runner'],
    ['id'=>'files',  'label'=>'&#128193; File Browser'],
    ['id'=>'server', 'label'=>'&#128736;&#65039; Server Info'],
  ];
  foreach ($tabs as $i => $tab):
  ?>
  <button type="button"
    class="nu-btn nu-btn-ghost nu-btn-sm nu-inspector-tab"
    id="inspTab_<?= $tab['id'] ?>"
    onclick="nuInspector.switchTab('<?= $tab['id'] ?>')"
    style="border-radius:6px 6px 0 0;border-bottom:2px solid transparent;margin-bottom:-2px;<?= $i===0 ? 'border-bottom-color:var(--primary,#6366f1);font-weight:600;' : '' ?>">
    <?= $tab['label'] ?>
  </button>
  <?php endforeach; ?>
</div>

<!-- ── DATABASE TAB ──────────────────────────────────────────────────────── -->
<div id="inspPanel_db" class="nu-inspector-panel">
  <div style="display:flex;gap:16px;height:72vh;">
    <!-- Table list -->
    <div style="width:220px;flex-shrink:0;border:1px solid var(--border-color,#ddd);border-radius:8px;overflow-y:auto;">
      <div style="padding:10px 12px;font-weight:600;font-size:13px;border-bottom:1px solid var(--border-color,#ddd);background:var(--surface-2,#f5f5f5);">Tables</div>
      <div id="inspTableList" style="padding:4px;"></div>
    </div>
    <!-- Column / data panel -->
    <div style="flex:1;overflow:auto;border:1px solid var(--border-color,#ddd);border-radius:8px;">
      <div id="inspTableDetail" style="padding:16px;color:#888;font-size:14px;">&#8592; Select a table</div>
    </div>
  </div>
</div>

<!-- ── SQL RUNNER TAB ────────────────────────────────────────────────────── -->
<div id="inspPanel_sql" class="nu-inspector-panel" style="display:none;">
  <div style="margin-bottom:10px;display:flex;gap:8px;align-items:flex-start;">
    <textarea id="inspSqlInput" class="nu-input"
      placeholder="SELECT * FROM nu_forms LIMIT 10"
      rows="6"
      style="flex:1;font-family:monospace;font-size:13px;resize:vertical;"></textarea>
    <div style="display:flex;flex-direction:column;gap:6px;">
      <button class="nu-btn nu-btn-primary" onclick="nuInspector.runSql()" title="Ctrl+Enter">&#9654; Run</button>
      <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="document.getElementById('inspSqlInput').value=''">Clear</button>
    </div>
  </div>
  <div style="font-size:11px;color:#888;margin-bottom:8px;">Tip: Ctrl+Enter to run &nbsp;|&nbsp; SELECT, SHOW, DESCRIBE, INSERT, UPDATE, DELETE, ALTER all supported</div>
  <div id="inspSqlResult" style="overflow:auto;max-height:60vh;"></div>
</div>

<!-- ── FILE BROWSER TAB ──────────────────────────────────────────────────── -->
<div id="inspPanel_files" class="nu-inspector-panel" style="display:none;">
  <div style="display:flex;gap:16px;height:72vh;">
    <!-- Directory tree -->
    <div style="width:280px;flex-shrink:0;border:1px solid var(--border-color,#ddd);border-radius:8px;overflow-y:auto;">
      <div style="padding:10px 12px;font-weight:600;font-size:13px;border-bottom:1px solid var(--border-color,#ddd);background:var(--surface-2,#f5f5f5);display:flex;align-items:center;gap:8px;">
        <span id="inspFilePath" style="font-size:12px;font-weight:400;color:#888;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">/</span>
      </div>
      <div id="inspFileList" style="padding:4px;"></div>
    </div>
    <!-- File content -->
    <div style="flex:1;border:1px solid var(--border-color,#ddd);border-radius:8px;overflow:hidden;display:flex;flex-direction:column;">
      <div id="inspFileHeader" style="padding:8px 14px;font-size:12px;font-weight:600;background:var(--surface-2,#f5f5f5);border-bottom:1px solid var(--border-color,#ddd);">Select a file</div>
      <pre id="inspFileContent" style="flex:1;overflow:auto;padding:14px;margin:0;font-size:12px;line-height:1.6;background:var(--surface-1,#fff);white-space:pre-wrap;word-break:break-all;">&#8592; Click a file to view its contents</pre>
    </div>
  </div>
</div>

<!-- ── SERVER INFO TAB ───────────────────────────────────────────────────── -->
<div id="inspPanel_server" class="nu-inspector-panel" style="display:none;">
  <div id="inspServerInfo" style="padding:8px;"><div class="nu-spinner" style="margin:40px auto;"></div></div>
</div>

</div><!-- #nuInspector -->

<style>
.nu-inspector-tab.active-tab { border-bottom-color: var(--primary,#6366f1) !important; font-weight:600; }
.insp-table-btn { display:block; width:100%; text-align:left; padding:7px 10px; border:none;
  background:none; cursor:pointer; border-radius:4px; font-size:13px; color:inherit; }
.insp-table-btn:hover { background:var(--surface-2,#f5f5f5); }
.insp-table-btn.active { background:var(--primary-alpha,rgba(99,102,241,.12)); color:var(--primary,#6366f1); font-weight:600; }
.insp-file-btn { display:flex; align-items:center; gap:6px; width:100%; text-align:left;
  padding:5px 10px; border:none; background:none; cursor:pointer; border-radius:4px;
  font-size:12px; color:inherit; }
.insp-file-btn:hover { background:var(--surface-2,#f5f5f5); }
.insp-file-btn.active { background:var(--primary-alpha,rgba(99,102,241,.12)); color:var(--primary,#6366f1); }
.insp-result-table { width:100%; border-collapse:collapse; font-size:12px; }
.insp-result-table th { background:var(--surface-2,#f5f5f5); padding:6px 10px; text-align:left;
  font-weight:600; border-bottom:2px solid var(--border-color,#ddd); position:sticky; top:0; }
.insp-result-table td { padding:5px 10px; border-bottom:1px solid var(--border-color,#eee);
  max-width:300px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; vertical-align:top; }
.insp-result-table tr:hover td { background:var(--surface-2,#f9f9f9); }
.insp-info-grid { display:grid; grid-template-columns:200px 1fr; gap:1px; background:var(--border-color,#ddd); border:1px solid var(--border-color,#ddd); border-radius:8px; overflow:hidden; }
.insp-info-label { background:var(--surface-2,#f5f5f5); padding:8px 12px; font-weight:600; font-size:13px; }
.insp-info-value { background:var(--surface-1,#fff); padding:8px 12px; font-size:13px; word-break:break-all; }
</style>

<script>
(function () {
  var _currentTable = null;
  var _currentPath  = '';

  window.nuInspector = {

    switchTab: function (id) {
      document.querySelectorAll('.nu-inspector-panel').forEach(function (p) { p.style.display = 'none'; });
      document.querySelectorAll('.nu-inspector-tab').forEach(function (t) { t.style.borderBottomColor = 'transparent'; t.style.fontWeight = ''; });
      var panel = document.getElementById('inspPanel_' + id);
      var tab   = document.getElementById('inspTab_' + id);
      if (panel) panel.style.display = '';
      if (tab)   { tab.style.borderBottomColor = 'var(--primary,#6366f1)'; tab.style.fontWeight = '600'; }
      if (id === 'db'     && !document.getElementById('inspTableList').children.length) this.loadTables();
      if (id === 'files'  && !document.getElementById('inspFileList').children.length)  this.browseFiles('');
      if (id === 'server')  this.loadServerInfo();
    },

    _api: async function (params) {
      var qs = Object.keys(params).map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); }).join('&');
      var res = await fetch('api/inspector.php?' + qs, { credentials: 'same-origin' });
      return res.json();
    },

    _apiPost: async function (action, body) {
      var res = await fetch('api/inspector.php?action=' + action, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body)
      });
      return res.json();
    },

    // ── Database tab ──────────────────────────────────────────────────────────
    loadTables: async function () {
      var el = document.getElementById('inspTableList');
      el.innerHTML = '<div style="padding:8px;color:#888;font-size:12px;">Loading...</div>';
      var json = await this._api({ action: 'tables' });
      if (!json.success) { el.innerHTML = '<div style="padding:8px;color:#c00;">' + (json.error || 'Error') + '</div>'; return; }
      el.innerHTML = '';
      var self = this;
      json.tables.forEach(function (t) {
        var btn = document.createElement('button');
        btn.className = 'insp-table-btn';
        btn.textContent = t;
        btn.onclick = function () { self.loadTableDetail(t); };
        el.appendChild(btn);
      });
    },

    loadTableDetail: async function (table) {
      _currentTable = table;
      document.querySelectorAll('.insp-table-btn').forEach(function (b) {
        b.classList.toggle('active', b.textContent === table);
      });
      var detail = document.getElementById('inspTableDetail');
      detail.innerHTML = '<div class="nu-spinner" style="margin:30px auto;"></div>';
      var json = await this._api({ action: 'columns', table: table });
      if (!json.success) { detail.innerHTML = '<div style="padding:16px;color:#c00;">' + (json.error||'Error') + '</div>'; return; }

      var html = '<div style="padding:12px 16px;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--border-color,#ddd);">';
      html += '<h3 style="margin:0;font-size:15px;">' + table + '</h3>';
      html += '<span style="font-size:12px;color:#888;">' + json.row_count + ' rows</span>';
      html += '<button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="nuInspector.loadTableData(\'' + table + '\',0)">Preview Data &#8594;</button></div>';

      html += '<div style="overflow:auto;"><table class="insp-result-table"><thead><tr>';
      ['Field','Type','Null','Key','Default','Extra'].forEach(function (h) {
        html += '<th>' + h + '</th>';
      });
      html += '</tr></thead><tbody>';
      json.columns.forEach(function (col) {
        html += '<tr>';
        ['Field','Type','Null','Key','Default','Extra'].forEach(function (k) {
          html += '<td>' + _esc(col[k] || '') + '</td>';
        });
        html += '</tr>';
      });
      html += '</tbody></table></div>';
      detail.innerHTML = html;
    },

    loadTableData: async function (table, offset) {
      var detail = document.getElementById('inspTableDetail');
      detail.innerHTML = '<div class="nu-spinner" style="margin:30px auto;"></div>';
      var json = await this._api({ action: 'data', table: table, limit: 100, offset: offset || 0 });
      if (!json.success) { detail.innerHTML = '<div style="padding:16px;color:#c00;">' + (json.error||'Error') + '</div>'; return; }

      var html = '<div style="padding:12px 16px;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--border-color,#ddd);">';
      html += '<div style="display:flex;gap:8px;align-items:center;"><button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="nuInspector.loadTableDetail(\'' + table + \')">&#8592; Schema</button><h3 style="margin:0;font-size:15px;">' + table + ' — Data</h3></div>';
      html += '<span style="font-size:12px;color:#888;">Showing ' + (offset+1) + '–' + (offset + json.rows.length) + ' of ' + json.total + '</span></div>';

      if (!json.rows.length) { detail.innerHTML += '<div style="padding:40px;text-align:center;color:#888;">No rows</div>'; return; }

      html += '<div style="overflow:auto;"><table class="insp-result-table"><thead><tr>';
      json.columns.forEach(function (c) { html += '<th>' + _esc(c) + '</th>'; });
      html += '</tr></thead><tbody>';
      json.rows.forEach(function (row) {
        html += '<tr>';
        json.columns.forEach(function (c) {
          var v = row[c];
          html += '<td title="' + _esc(String(v ?? '')) + '">' + _esc(String(v ?? '')) + '</td>';
        });
        html += '</tr>';
      });
      html += '</tbody></table></div>';

      // Pagination
      if (json.total > 100) {
        html += '<div style="padding:10px 16px;display:flex;gap:6px;">';
        if (offset > 0) html += '<button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="nuInspector.loadTableData(\'' + table + '\',' + (offset-100) + ')">&#8592; Prev</button>';
        if (offset + 100 < json.total) html += '<button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="nuInspector.loadTableData(\'' + table + '\',' + (offset+100) + ')">Next &#8594;</button>';
        html += '</div>';
      }
      detail.innerHTML = html;
    },

    // ── SQL Runner ────────────────────────────────────────────────────────────
    runSql: async function () {
      var sql = (document.getElementById('inspSqlInput').value || '').trim();
      var res = document.getElementById('inspSqlResult');
      if (!sql) { res.innerHTML = '<div style="color:#c00;padding:8px;">No SQL entered.</div>'; return; }
      res.innerHTML = '<div class="nu-spinner" style="margin:20px auto;"></div>';
      var t0 = Date.now();
      var json = await this._apiPost('sql', { sql: sql });
      var elapsed = Date.now() - t0;
      if (!json.success) {
        res.innerHTML = '<div style="padding:10px;background:#fee;border:1px solid #f88;border-radius:6px;color:#c00;font-family:monospace;font-size:13px;white-space:pre-wrap;">' + _esc(json.error || 'Error') + '</div>';
        return;
      }
      if (json.type === 'write') {
        res.innerHTML = '<div style="padding:10px;background:#efe;border:1px solid #8c8;border-radius:6px;font-size:13px;">&#10003; Query OK — ' + json.affected + ' row(s) affected &nbsp;<span style="color:#888;font-size:11px;">' + elapsed + 'ms</span></div>';
        return;
      }
      if (!json.rows || !json.rows.length) {
        res.innerHTML = '<div style="padding:10px;color:#888;">Query returned 0 rows &nbsp;<span style="font-size:11px;">' + elapsed + 'ms</span></div>';
        return;
      }
      var cols = Object.keys(json.rows[0]);
      var html = '<div style="font-size:11px;color:#888;margin-bottom:6px;">' + json.count + ' row(s) &nbsp;•&nbsp; ' + elapsed + 'ms</div>';
      html += '<table class="insp-result-table"><thead><tr>';
      cols.forEach(function (c) { html += '<th>' + _esc(c) + '</th>'; });
      html += '</tr></thead><tbody>';
      json.rows.forEach(function (row) {
        html += '<tr>';
        cols.forEach(function (c) { html += '<td title="' + _esc(String(row[c]??'')) + '">' + _esc(String(row[c]??'')) + '</td>'; });
        html += '</tr>';
      });
      html += '</tbody></table>';
      res.innerHTML = html;
    },

    // ── File browser ──────────────────────────────────────────────────────────
    browseFiles: async function (path) {
      _currentPath = path;
      var list = document.getElementById('inspFileList');
      var pathEl = document.getElementById('inspFilePath');
      list.innerHTML = '<div style="padding:8px;color:#888;font-size:12px;">Loading...</div>';
      var json = await this._api({ action: 'files', path: path });
      if (!json.success) { list.innerHTML = '<div style="padding:8px;color:#c00;font-size:12px;">' + (json.error||'Error') + '</div>'; return; }
      if (json.type === 'file') {
        // Shouldn't happen via browseFiles, handled by viewFile
        return;
      }
      if (pathEl) pathEl.textContent = json.path || '/';
      list.innerHTML = '';
      var self = this;
      json.entries.forEach(function (e) {
        var btn = document.createElement('button');
        btn.className = 'insp-file-btn';
        btn.innerHTML = (e.is_parent ? '&#x21A9;' : e.type === 'dir' ? '&#128193;' : '&#128196;') + ' <span>' + _esc(e.name) + '</span>';
        if (e.type === 'dir') {
          btn.onclick = function () { self.browseFiles(e.path); };
        } else {
          btn.style.color = 'var(--text,inherit)';
          btn.onclick = function () {
            document.querySelectorAll('.insp-file-btn').forEach(function (b) { b.classList.remove('active'); });
            btn.classList.add('active');
            self.viewFile(e.path, e.name);
          };
        }
        list.appendChild(btn);
      });
    },

    viewFile: async function (path, name) {
      var header  = document.getElementById('inspFileHeader');
      var content = document.getElementById('inspFileContent');
      header.textContent  = name;
      content.textContent = 'Loading...';
      var json = await this._api({ action: 'files', path: path });
      if (!json.success) { content.textContent = 'Error: ' + (json.error||'Unknown'); return; }
      content.textContent = json.content || '(empty file)';
    },

    // ── Server info ───────────────────────────────────────────────────────────
    loadServerInfo: async function () {
      var el = document.getElementById('inspServerInfo');
      el.innerHTML = '<div class="nu-spinner" style="margin:30px auto;"></div>';
      var json = await this._api({ action: 'serverinfo' });
      if (!json.success) { el.innerHTML = '<div style="color:#c00;padding:12px;">' + (json.error||'Error') + '</div>'; return; }
      var fmt = function (bytes) {
        if (!bytes) return 'n/a';
        return (bytes / (1024*1024*1024)).toFixed(1) + ' GB';
      };
      var rows = [
        ['PHP Version',     json.php_version],
        ['MySQL Version',   json.db_version],
        ['Server OS',       json.server_os],
        ['App Root',        json.app_root],
        ['Memory Limit',    json.memory_limit],
        ['Upload Max',      json.upload_max],
        ['Disk Free',       fmt(json.disk_free)],
        ['Disk Total',      fmt(json.disk_total)],
      ];
      var html = '<div class="insp-info-grid">';
      rows.forEach(function (r) {
        html += '<div class="insp-info-label">' + r[0] + '</div><div class="insp-info-value">' + _esc(String(r[1]||'')) + '</div>';
      });
      html += '</div>';
      html += '<h4 style="margin:16px 0 8px;">Loaded PHP Extensions</h4>';
      html += '<div style="font-size:12px;display:flex;flex-wrap:wrap;gap:4px;">';
      (json.extensions || []).sort().forEach(function (ext) {
        html += '<span style="background:var(--surface-2,#f0f0f0);padding:2px 7px;border-radius:10px;">' + _esc(ext) + '</span>';
      });
      html += '</div>';
      el.innerHTML = html;
    }
  };

  function _esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  // Ctrl+Enter to run SQL
  var sqlInput = document.getElementById('inspSqlInput');
  if (sqlInput) {
    sqlInput.addEventListener('keydown', function (e) {
      if (e.ctrlKey && e.key === 'Enter') { e.preventDefault(); nuInspector.runSql(); }
    });
  }

  // Auto-load tables on DB tab (it's the default)
  nuInspector.loadTables();

})();
</script>
