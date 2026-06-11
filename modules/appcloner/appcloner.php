<?php
/**
 * nuBuilder 5 – App Cloner Module
 * Admin UI: full clone wizard with live progress, SQL export, and dry-run.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../core/module_bootstrap.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>App Cloner – nuBuilder 5</title>
<style>
  :root{--accent:#4a90d9;--bg:#f4f6f9;--card:#fff;--border:#dde3ec;--red:#e74c3c;--green:#27ae60;--grey:#6c757d}
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:'Segoe UI',sans-serif;background:var(--bg);color:#333;font-size:14px}
  .wrap{max-width:900px;margin:30px auto;padding:0 16px}
  h1{font-size:22px;font-weight:600;margin-bottom:20px;color:var(--accent)}
  .card{background:var(--card);border:1px solid var(--border);border-radius:8px;padding:20px;margin-bottom:20px;box-shadow:0 1px 4px rgba(0,0,0,.06)}
  .card h2{font-size:15px;font-weight:600;margin-bottom:14px;border-bottom:1px solid var(--border);padding-bottom:8px}
  .grid2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
  label{display:block;font-size:12px;font-weight:600;color:var(--grey);margin-bottom:4px;text-transform:uppercase;letter-spacing:.4px}
  input[type=text],input[type=password],input[type=number],select{
    width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px;outline:none;transition:border .2s
  }
  input:focus,select:focus{border-color:var(--accent)}
  .opts-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}
  .opt-box{display:flex;align-items:center;gap:6px;background:var(--bg);border:1px solid var(--border);border-radius:6px;padding:8px 10px;cursor:pointer;font-size:12px;transition:border .2s}
  .opt-box:hover,.opt-box.active{border-color:var(--accent);background:#eef4fd}
  .opt-box input{width:auto;margin:0}
  .toggle-row{display:flex;align-items:center;gap:8px;margin-top:8px;font-size:13px}
  .toggle-row input{width:auto}
  .btn-row{display:flex;gap:10px;margin-top:8px;flex-wrap:wrap}
  .btn{padding:9px 20px;border:none;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;transition:opacity .2s}
  .btn:hover{opacity:.85}
  .btn-primary{background:var(--accent);color:#fff}
  .btn-success{background:var(--green);color:#fff}
  .btn-danger{background:var(--red);color:#fff}
  .btn-grey{background:#e0e5ee;color:#333}
  #progress-panel{display:none}
  #progress-log{list-style:none;font-size:12px;max-height:320px;overflow-y:auto;background:#1e2330;color:#c9d1d9;border-radius:6px;padding:12px}
  #progress-log li{padding:3px 0;border-bottom:1px solid #2d3244;line-height:1.5}
  #progress-log li.done{color:#4caf50}
  #progress-log li.error{color:#f44336}
  #progress-log li.running{color:#90caf9}
  .badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;text-transform:uppercase}
  .badge-success{background:#d4edda;color:var(--green)}
  .badge-error{background:#fce4e4;color:var(--red)}
  .badge-running{background:#e3f0fd;color:var(--accent)}
  .table-picker{max-height:200px;overflow-y:auto;border:1px solid var(--border);border-radius:6px;padding:6px}
  .table-picker label{font-size:12px;font-weight:400;text-transform:none;letter-spacing:0;display:flex;align-items:center;gap:6px;padding:3px 4px;border-radius:4px;cursor:pointer}
  .table-picker label:hover{background:var(--bg)}
  .section-title{font-size:11px;font-weight:700;color:var(--grey);text-transform:uppercase;letter-spacing:.6px;margin:12px 0 6px}
</style>
</head>
<body>
<div class="wrap">
  <h1>⚙️ App Cloner</h1>

  <!-- TARGET DATABASE -->
  <div class="card">
    <h2>Target Database</h2>
    <div class="grid2">
      <div><label>Host</label><input id="tgtHost" type="text" value="localhost"></div>
      <div><label>Port</label><input id="tgtPort" type="number" value="3306"></div>
      <div><label>Database Name (new)</label><input id="tgtDB" type="text" placeholder="my_cloned_app"></div>
      <div><label>Charset</label>
        <select id="tgtCharset">
          <option value="utf8mb4" selected>utf8mb4 (recommended)</option>
          <option value="utf8">utf8</option>
          <option value="latin1">latin1</option>
        </select>
      </div>
      <div><label>Username</label><input id="tgtUser" type="text"></div>
      <div><label>Password</label><input id="tgtPass" type="password"></div>
    </div>
    <div class="grid2" style="margin-top:12px">
      <div>
        <label>If DB Exists</label>
        <select id="dbMode">
          <option value="fail">Abort (fail)</option>
          <option value="create">Use existing</option>
          <option value="clear">Clear &amp; overwrite</option>
        </select>
      </div>
      <div>
        <label>File Mode</label>
        <select id="fileMode">
          <option value="fail">Abort if target dir exists</option>
          <option value="create">Create / use existing</option>
          <option value="clear">Clear then copy</option>
          <option value="overwrite">Overwrite files</option>
        </select>
      </div>
    </div>
  </div>

  <!-- FILE PATHS -->
  <div class="card">
    <h2>File Paths</h2>
    <div class="grid2">
      <div><label>Source Path (this install)</label><input id="srcPath" type="text" value="<?= htmlspecialchars(dirname(__DIR__, 2)) ?>"></div>
      <div><label>Target Path</label><input id="tgtPath" type="text" placeholder="/var/www/myapp_clone"></div>
    </div>
    <div class="toggle-row"><input type="checkbox" id="copyFiles" checked><label for="copyFiles" style="font-weight:400">Copy files to target path</label></div>
  </div>

  <!-- CLONE OPTIONS -->
  <div class="card">
    <h2>What to Clone</h2>
    <div class="opts-grid">
      <?php
      $optDefs = [
        1 => ['icon'=>'🏗️','label'=>'System Tables/Views','desc'=>'zzzzsys_* CREATE'],
        2 => ['icon'=>'📦','label'=>'User Tables/Views',  'desc'=>'Your tables CREATE'],
        3 => ['icon'=>'⚙️','label'=>'System Records',     'desc'=>'nuBuilder core data'],
        4 => ['icon'=>'📋','label'=>'App Definitions',    'desc'=>'Forms, reports, menus'],
        5 => ['icon'=>'📊','label'=>'User Data',          'desc'=>'Your table rows'],
        6 => ['icon'=>'ƒ', 'label'=>'Functions',          'desc'=>'DB functions'],
        7 => ['icon'=>'🔧','label'=>'Procedures',         'desc'=>'Stored procedures'],
        8 => ['icon'=>'⚡','label'=>'Triggers',           'desc'=>'DB triggers'],
        9 => ['icon'=>'🕐','label'=>'Events',             'desc'=>'Scheduled events'],
      ];
      foreach ($optDefs as $n => $d): ?>
      <label class="opt-box" onclick="this.classList.toggle('active')">
        <input type="checkbox" class="opt-cb" value="<?= $n ?>" <?= in_array($n,[1,2,3,4]) ? 'checked' : '' ?>>
        <span><?= $d['icon'] ?></span>
        <span>
          <strong><?= $d['label'] ?></strong><br>
          <span style="color:var(--grey);font-size:11px"><?= $d['desc'] ?></span>
        </span>
      </label>
      <?php endforeach; ?>
    </div>

    <div class="section-title">Insert Method</div>
    <select id="insertType" style="max-width:220px">
      <option value="INSERT">INSERT</option>
      <option value="INSERT IGNORE">INSERT IGNORE (skip dupes)</option>
      <option value="REPLACE">REPLACE (upsert)</option>
    </select>

    <div class="section-title" style="margin-top:14px">Advanced Options</div>
    <div style="display:flex;flex-wrap:wrap;gap:14px">
      <label class="toggle-row"><input type="checkbox" id="dryRun"> Dry Run (simulate only)</label>
      <label class="toggle-row"><input type="checkbox" id="schemaOnly"> Schema Only (no data)</label>
    </div>
  </div>

  <!-- TABLE FILTER -->
  <div class="card">
    <h2>Table Filter <span style="font-weight:400;font-size:12px;color:var(--grey)">(optional – leave empty to clone all)</span></h2>
    <p style="font-size:12px;color:var(--grey);margin-bottom:10px">Check only the tables you want to include. zzzzsys_* tables are always included.</p>
    <div class="table-picker" id="tablePicker"><em style="color:var(--grey);font-size:12px">Loading tables…</em></div>
  </div>

  <!-- SQL EXPORT -->
  <div class="card">
    <h2>SQL Export</h2>
    <div class="grid2">
      <div>
        <label>Export Format</label>
        <select id="sqlFormat">
          <option value="mysql">MySQL</option>
          <option value="mssql">MS SQL Server</option>
        </select>
      </div>
      <div>
        <label>Batch Size (rows per INSERT)</label>
        <input type="number" id="batchSize" value="500" min="1" max="5000">
      </div>
    </div>
    <div style="display:flex;flex-wrap:wrap;gap:14px;margin-top:10px">
      <label class="toggle-row"><input type="checkbox" id="inclDrops" checked> Include DROP statements</label>
      <label class="toggle-row"><input type="checkbox" id="gzipExport"> Compress as .sql.gz</label>
      <label class="toggle-row"><input type="checkbox" id="schemaExport"> Schema only (no data)</label>
    </div>
  </div>

  <!-- WEBHOOK -->
  <div class="card">
    <h2>Webhook <span style="font-weight:400;font-size:12px;color:var(--grey)">(optional – POST result JSON to URL)</span></h2>
    <input type="text" id="webhookUrl" placeholder="https://your-app.com/clone-done">
  </div>

  <!-- ACTIONS -->
  <div class="btn-row">
    <button class="btn btn-primary" onclick="startClone()">▶ Start Clone</button>
    <button class="btn btn-success" onclick="exportSQL()">⬇ Download SQL</button>
    <button class="btn btn-grey" onclick="testConn()">🔌 Test Connection</button>
  </div>

  <!-- PROGRESS -->
  <div class="card" id="progress-panel" style="margin-top:20px">
    <h2>Progress <span id="progress-badge"></span></h2>
    <ul id="progress-log"></ul>
  </div>
</div>

<script>
// Load tables for picker
fetch('<?= $nuConfig['baseUrl'] ?? '' ?>/api/appcloner.php?action=list_tables')
  .then(r=>r.json())
  .then(d=>{
    const el = document.getElementById('tablePicker');
    el.innerHTML = '';
    (d.tables||[]).forEach(t=>{
      if(t.TABLE_NAME.startsWith('zzzzsys_')) return; // always included, skip
      const lbl = document.createElement('label');
      lbl.innerHTML = `<input type="checkbox" class="tbl-cb" value="${t.TABLE_NAME}"> ${t.TABLE_NAME} <span style="color:#999;font-size:11px">(~${t.TABLE_ROWS||0} rows)</span>`;
      el.appendChild(lbl);
    });
  }).catch(()=>{ document.getElementById('tablePicker').innerHTML = '<em style="color:#999">Could not load tables.</em>'; });

function getOpts(){ return [...document.querySelectorAll('.opt-cb:checked')].map(e=>+e.value); }
function getTables(){ return [...document.querySelectorAll('.tbl-cb:checked')].map(e=>e.value); }

function payload(){
  return {
    targetDB:      document.getElementById('tgtDB').value.trim(),
    targetHost:    document.getElementById('tgtHost').value.trim(),
    targetUser:    document.getElementById('tgtUser').value.trim(),
    targetPass:    document.getElementById('tgtPass').value,
    targetCharset: document.getElementById('tgtCharset').value,
    targetPort:    +document.getElementById('tgtPort').value,
    targetPath:    document.getElementById('tgtPath').value.trim(),
    sourcePath:    document.getElementById('srcPath').value.trim(),
    databaseMode:  document.getElementById('dbMode').value,
    fileMode:      document.getElementById('fileMode').value,
    copyFiles:     document.getElementById('copyFiles').checked,
    opts:          getOpts(),
    insertType:    document.getElementById('insertType').value,
    dryRun:        document.getElementById('dryRun').checked,
    schemaOnly:    document.getElementById('schemaOnly').checked,
    includeTables: getTables(),
    webhookUrl:    document.getElementById('webhookUrl').value.trim(),
  };
}

async function testConn(){
  const p = payload();
  const r = await fetch('<?= $nuConfig['baseUrl'] ?? '' ?>/api/appcloner.php?action=list_databases',{
    method:'POST', body: new URLSearchParams({host:p.targetHost, user:p.targetUser, pass:p.targetPass, port:p.targetPort})
  });
  const d = await r.json();
  if(d.databases) alert('✅ Connected! Found ' + d.databases.length + ' database(s) on ' + p.targetHost);
  else alert('❌ Connection failed: ' + (d.error||'unknown'));
}

async function startClone(){
  const p = payload();
  if(!p.targetDB){ alert('Please enter a target database name.'); return; }
  const panel = document.getElementById('progress-panel');
  const log   = document.getElementById('progress-log');
  panel.style.display = 'block';
  log.innerHTML = '<li>Starting clone job…</li>';
  document.getElementById('progress-badge').innerHTML = '<span class="badge badge-running">Running</span>';

  const r = await fetch('<?= $nuConfig['baseUrl'] ?? '' ?>/api/appcloner.php?action=start',{
    method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(p)
  });
  const d = await r.json();
  if(d.error){ log.innerHTML += `<li class="error">❌ ${d.error}</li>`; return; }
  pollProgress(d.jobId, log);
}

function pollProgress(jobId, log){
  let lastLen = 0;
  const iv = setInterval(async ()=>{
    const r  = await fetch(`<?= $nuConfig['baseUrl'] ?? '' ?>/api/appcloner.php?action=progress&jobId=${jobId}`);
    const d  = await r.json();
    const steps = d.steps || [];
    for(let i = lastLen; i < steps.length; i++){
      const s   = steps[i];
      const li  = document.createElement('li');
      li.className = s.status;
      li.textContent = `[${s.status.toUpperCase()}] ${s.msg}`;
      log.appendChild(li);
      log.scrollTop = log.scrollHeight;
    }
    lastLen = steps.length;
    if(d.done){
      clearInterval(iv);
      const last = steps[steps.length-1];
      const badge = document.getElementById('progress-badge');
      if(last && last.status === 'success'){
        badge.innerHTML = '<span class="badge badge-success">Done ✓</span>';
      } else {
        badge.innerHTML = '<span class="badge badge-error">Error ✗</span>';
      }
    }
  }, 1500);
}

async function exportSQL(){
  const p = payload();
  const body = JSON.stringify({
    opts:         getOpts(),
    targetDB:     p.targetDB || 'export',
    format:       document.getElementById('sqlFormat').value,
    insertType:   p.insertType,
    batchSize:    +document.getElementById('batchSize').value,
    includeDrops: document.getElementById('inclDrops').checked,
    zip:          document.getElementById('gzipExport').checked,
    schemaOnly:   document.getElementById('schemaExport').checked,
  });
  const r    = await fetch('<?= $nuConfig['baseUrl'] ?? '' ?>/api/appcloner.php?action=export_sql',{
    method:'POST', headers:{'Content-Type':'application/json'}, body
  });
  const blob = await r.blob();
  const cd   = r.headers.get('Content-Disposition') || '';
  const fn   = (cd.match(/filename="([^"]+)"/) || [])[1] || 'export.sql';
  const a    = document.createElement('a');
  a.href     = URL.createObjectURL(blob);
  a.download = fn;
  a.click();
}
</script>
</body>
</html>
