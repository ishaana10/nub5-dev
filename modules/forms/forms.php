<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/core/module_bootstrap.php';
// $auth, $nuConfig available. Session open under 'nu5sess'.

$db    = NuDatabase::getInstance();
$forms = $db->fetchAll("SELECT * FROM nu_forms WHERE form_active = 1 ORDER BY form_id DESC");

// Fetch all tables in the current DB for the "existing table" dropdown
$pdo         = $db->getPdo();
$tablesStmt  = $pdo->query('SHOW TABLES');
$allTables   = $tablesStmt ? $tablesStmt->fetchAll(PDO::FETCH_COLUMN) : [];
// Filter out system tables
$userTables  = array_values(array_filter($allTables, fn($t) => !in_array($t, [
    'nu_users','nu_roles','nu_permissions','nu_role_permissions',
    'nu_api_tokens','nu_api_usage','nu_audit_log','nu_files',
    'nu_forms','nu_reports','nu_queries','nu_menus'
], true)));

// Group forms by type
$formsByType = ['main' => [], 'subform' => [], 'popup' => [], 'report' => []];
foreach ($forms as $f) {
    $t = $f['form_type'] ?? 'main';
    if (!isset($formsByType[$t])) $formsByType[$t] = [];
    $formsByType[$t][] = $f;
}
?>

<style>
/* ── Form Builder Styles ─────────────────────────────────────── */
.nb-builder-wrap { display:flex; gap:20px; min-height:520px; }

/* Toolbox */
.nb-toolbox { width:180px; flex-shrink:0; }
.nb-toolbox-title { font-size:11px; font-weight:700; letter-spacing:.08em; text-transform:uppercase; color:var(--text-secondary); margin-bottom:10px; }
.nb-tools-group { margin-bottom:14px; }
.nb-tools-group-label { font-size:10px; font-weight:600; letter-spacing:.06em; text-transform:uppercase; color:var(--text-tertiary); margin-bottom:6px; padding-left:2px; }
.nb-tool {
  display:flex; align-items:center; gap:7px; padding:7px 10px;
  border-radius:8px; cursor:grab; font-size:12px; font-weight:500;
  color:var(--text-primary); border:1px solid var(--border-color);
  background:var(--bg-surface); margin-bottom:4px;
  transition:background .15s, border-color .15s, box-shadow .15s;
  user-select:none;
}
.nb-tool:hover { background:var(--bg-elevated); border-color:var(--color-primary); box-shadow:0 0 0 2px color-mix(in oklch,var(--color-primary) 18%,transparent); }
.nb-tool svg { flex-shrink:0; color:var(--text-secondary); }
.nb-tool.dragging { opacity:.4; }

/* Canvas */
.nb-canvas-wrap { flex:1; display:flex; flex-direction:column; }
.nb-canvas-title { font-size:11px; font-weight:700; letter-spacing:.08em; text-transform:uppercase; color:var(--text-secondary); margin-bottom:10px; }
.nb-canvas {
  flex:1; min-height:380px; border:2px dashed var(--border-color);
  border-radius:12px; padding:12px; background:var(--bg-elevated);
  transition:border-color .2s;
}
.nb-canvas.drag-over { border-color:var(--color-primary); background:color-mix(in oklch,var(--color-primary) 5%,var(--bg-elevated)); }
.nb-canvas-empty { text-align:center; padding:60px 20px; color:var(--text-tertiary); font-size:13px; pointer-events:none; }

/* Canvas field card */
.nb-cfield {
  border:1px solid var(--border-color); border-radius:10px;
  background:var(--bg-surface); margin-bottom:8px;
  transition:border-color .15s, box-shadow .15s;
}
.nb-cfield:hover { border-color:var(--color-primary); }
.nb-cfield.drag-source { opacity:.35; }
.nb-cfield-header {
  display:flex; align-items:center; gap:8px; padding:9px 12px;
  cursor:pointer;
}
.nb-cfield-drag { cursor:grab; color:var(--text-tertiary); font-size:16px; line-height:1; flex-shrink:0; }
.nb-cfield-drag:active { cursor:grabbing; }
.nb-cfield-type-badge {
  font-size:10px; font-weight:600; letter-spacing:.04em; text-transform:uppercase;
  padding:2px 7px; border-radius:20px;
  background:color-mix(in oklch,var(--color-primary) 12%,transparent);
  color:var(--color-primary);
}
.nb-cfield-label { flex:1; font-size:13px; font-weight:500; }
.nb-cfield-actions { display:flex; gap:4px; }
.nb-cfield-btn {
  padding:3px 7px; border-radius:6px; font-size:11px; font-weight:500;
  border:1px solid var(--border-color); background:none; cursor:pointer;
  color:var(--text-secondary); transition:all .15s;
}
.nb-cfield-btn:hover { background:var(--bg-elevated); border-color:var(--color-primary); color:var(--color-primary); }
.nb-cfield-btn.del:hover { background:#fee; border-color:#e55; color:#c33; }

/* Expand/collapse panel */
.nb-cfield-body { display:none; padding:12px; border-top:1px solid var(--border-color); }
.nb-cfield-body.open { display:block; }

/* Inline label+input grid inside field body */
.nb-fp-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(180px,1fr)); gap:10px; }
.nb-fp { display:flex; flex-direction:column; gap:4px; }
.nb-fp label { font-size:11px; font-weight:600; color:var(--text-secondary); }
.nb-fp input,.nb-fp select,.nb-fp textarea { font-size:12px; }
.nb-fp-full { grid-column:1/-1; }
.nb-fp-check { display:flex; align-items:center; gap:6px; font-size:12px; font-weight:500; color:var(--text-primary); }

/* Form-level tabs */
.nb-tabs { display:flex; gap:4px; border-bottom:1px solid var(--border-color); margin-bottom:16px; }
.nb-tab {
  padding:8px 16px; border-radius:8px 8px 0 0; font-size:12px; font-weight:600;
  cursor:pointer; border:1px solid transparent; border-bottom:none;
  color:var(--text-secondary); background:none; transition:all .15s;
  position:relative; top:1px;
}
.nb-tab:hover { color:var(--text-primary); background:var(--bg-elevated); }
.nb-tab.active { color:var(--color-primary); background:var(--bg-surface); border-color:var(--border-color); border-bottom-color:var(--bg-surface); }
.nb-tab-panel { display:none; }
.nb-tab-panel.active { display:block; }

/* Form card meta */
.nu-form-meta { font-size:12px; color:var(--text-tertiary); }

/* ── Compact meta row inside form list cards ── */
.nu-form-meta-row {
  display:flex; flex-wrap:wrap; align-items:center;
  gap:4px 12px; margin-bottom:10px;
}
.nu-form-meta-row .nu-form-meta {
  display:flex; align-items:center; gap:4px; margin-bottom:0;
  white-space:nowrap;
}
.nu-form-meta-row .nu-meta-sep {
  color:var(--border-color); font-size:10px; user-select:none;
}

/* ── Forms list grid — multi-column compact cards ── */
#formsGrid {
  display:grid;
  grid-template-columns:repeat(auto-fill, minmax(280px, 1fr));
  gap:12px;
}
#formsGrid .nu-card {
  padding:14px 16px;
  display:flex;
  flex-direction:column;
}
#formsGrid .nu-card-header {
  margin-bottom:8px;
}
#formsGrid .nu-card > div:last-child {
  margin-top:auto;
}

/* Drag ghost */
.nb-drag-ghost {
  position:fixed; pointer-events:none; z-index:9999;
  background:var(--bg-surface); border:1px solid var(--color-primary);
  border-radius:8px; padding:8px 14px; font-size:12px; opacity:.85;
  box-shadow:0 4px 20px rgba(0,0,0,.15);
}

/* Save/cancel bar */
.nb-save-bar {
  display:flex !important;
  justify-content:flex-end;
  align-items:center;
  gap:8px;
  margin-top:20px;
  padding-top:16px;
  border-top:1px solid var(--border-color);
}

/* Browse display mode cards */
.nb-display-modes { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:16px; }
.nb-display-mode-card {
  flex:1; min-width:140px; border:2px solid var(--border-color);
  border-radius:10px; padding:14px 12px; cursor:pointer;
  background:var(--bg-surface); transition:all .15s; text-align:center;
}
.nb-display-mode-card:hover { border-color:var(--color-primary); background:var(--bg-elevated); }
.nb-display-mode-card.selected {
  border-color:var(--color-primary);
  background:color-mix(in oklch,var(--color-primary) 8%,var(--bg-surface));
}
.nb-display-mode-card input[type=radio] { display:none; }
.nb-display-mode-icon { font-size:22px; margin-bottom:6px; }
.nb-display-mode-label { font-size:12px; font-weight:700; color:var(--text-primary); margin-bottom:3px; }
.nb-display-mode-desc { font-size:11px; color:var(--text-tertiary); line-height:1.4; }

/* PK type toggle */
.nb-pk-cards { display:flex; gap:10px; }
.nb-pk-card {
  flex:1; border:2px solid var(--border-color); border-radius:10px;
  padding:12px 14px; cursor:pointer; background:var(--bg-surface);
  transition:all .15s;
}
.nb-pk-card:hover { border-color:var(--color-primary); background:var(--bg-elevated); }
.nb-pk-card.selected {
  border-color:var(--color-primary);
  background:color-mix(in oklch,var(--color-primary) 8%,var(--bg-surface));
}
.nb-pk-card input[type=radio] { display:none; }
.nb-pk-card-title { font-size:12px; font-weight:700; color:var(--text-primary); margin-bottom:3px; }
.nb-pk-card-code  { font-size:11px; font-family:monospace; color:var(--color-primary); margin-bottom:4px; }
.nb-pk-card-desc  { font-size:11px; color:var(--text-tertiary); line-height:1.4; }

/* Table mode toggle */
.nb-tmode-cards { display:flex; gap:10px; margin-bottom:12px; }
.nb-tmode-card {
  flex:1; border:2px solid var(--border-color); border-radius:10px;
  padding:12px 14px; cursor:pointer; background:var(--bg-surface);
  transition:all .15s;
}
.nb-tmode-card:hover { border-color:var(--color-primary); background:var(--bg-elevated); }
.nb-tmode-card.selected {
  border-color:var(--color-primary);
  background:color-mix(in oklch,var(--color-primary) 8%,var(--bg-surface));
}
.nb-tmode-card input[type=radio] { display:none; }
.nb-tmode-card-title { font-size:12px; font-weight:700; color:var(--text-primary); margin-bottom:3px; }
.nb-tmode-card-desc  { font-size:11px; color:var(--text-tertiary); line-height:1.4; }

/* Form type selector */
.nb-ftype-cards { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:16px; }
.nb-ftype-card {
  flex:1; min-width:120px; border:2px solid var(--border-color); border-radius:10px;
  padding:12px 10px; cursor:pointer; background:var(--bg-surface);
  transition:all .15s; text-align:center;
}
.nb-ftype-card:hover { border-color:var(--color-primary); background:var(--bg-elevated); }
.nb-ftype-card.selected {
  border-color:var(--color-primary);
  background:color-mix(in oklch,var(--color-primary) 8%,var(--bg-surface));
}
.nb-ftype-card input[type=radio] { display:none; }
.nb-ftype-icon  { font-size:20px; margin-bottom:4px; }
.nb-ftype-label { font-size:12px; font-weight:700; color:var(--text-primary); margin-bottom:2px; }
.nb-ftype-desc  { font-size:10px; color:var(--text-tertiary); line-height:1.3; }

/* Form type badge in list */
.nb-type-badge {
  display:inline-block; font-size:10px; font-weight:700; letter-spacing:.04em;
  text-transform:uppercase; padding:2px 8px; border-radius:20px;
  vertical-align:middle;
}
.nb-type-badge.main    { background:color-mix(in oklch,var(--color-primary) 12%,transparent); color:var(--color-primary); }
.nb-type-badge.subform { background:color-mix(in oklch,#f59e0b 15%,transparent); color:#b45309; }
.nb-type-badge.popup   { background:color-mix(in oklch,#8b5cf6 15%,transparent); color:#6d28d9; }
.nb-type-badge.report  { background:color-mix(in oklch,#10b981 15%,transparent); color:#047857; }

/* Forms list filter tabs */
.nb-filter-tabs { display:flex; gap:6px; margin-bottom:16px; flex-wrap:wrap; }
.nb-filter-tab {
  padding:5px 14px; border-radius:20px; font-size:12px; font-weight:600;
  border:1px solid var(--border-color); background:var(--bg-surface);
  cursor:pointer; transition:all .15s; color:var(--text-secondary);
}
.nb-filter-tab:hover { border-color:var(--color-primary); color:var(--color-primary); }
.nb-filter-tab.active { background:var(--color-primary); border-color:var(--color-primary); color:#fff; }
</style>

<div class="nu-forms">

  <!-- ── Forms list ────────────────────────────────────────────── -->
  <div id="formsListSection">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
      <h3 class="nu-card-title">Forms</h3>
      <?php if ($auth->hasPermission('forms','create')): ?>
      <button class="nu-btn nu-btn-primary" onclick="nbFormBuilder.open()">+ New Form</button>
      <?php endif; ?>
    </div>

    <!-- Filter tabs -->
    <div class="nb-filter-tabs" id="nbFilterTabs">
      <button class="nb-filter-tab active" data-filter="all"     onclick="nbFilterForms('all',this)">All (<?= count($forms) ?>)</button>
      <button class="nb-filter-tab"        data-filter="main"    onclick="nbFilterForms('main',this)">⊞ Main (<?= count($formsByType['main']) ?>)</button>
      <button class="nb-filter-tab"        data-filter="subform" onclick="nbFilterForms('subform',this)">⊟ Subforms (<?= count($formsByType['subform']) ?>)</button>
      <button class="nb-filter-tab"        data-filter="popup"   onclick="nbFilterForms('popup',this)">▣ Popups (<?= count($formsByType['popup']) ?>)</button>
      <button class="nb-filter-tab"        data-filter="report"  onclick="nbFilterForms('report',this)">📊 Reports (<?= count($formsByType['report']) ?>)</button>
    </div>

    <div class="nu-grid" id="formsGrid">
      <?php foreach ($forms as $f): ?>
      <?php
        $layout     = @json_decode($f['form_layout'] ?? '[]', true);
        $fieldCount = is_array($layout) ? count($layout) : 0;
        $formLabel  = htmlspecialchars($f['form_name'], ENT_QUOTES);
        $formCode   = htmlspecialchars($f['form_code'], ENT_QUOTES);
        $browseMode = $f['browse_display_mode'] ?? 'inline';
        $pkType     = $f['form_pk_type']   ?? 'autoincrement';
        $tMode      = $f['form_table_mode'] ?? 'new';
        $fType      = $f['form_type']       ?? 'main';
        $typeLabels = ['main'=>'Main','subform'=>'Subform','popup'=>'Popup','report'=>'Report'];
        $typeLabel  = $typeLabels[$fType] ?? ucfirst($fType);
        $isMain     = in_array($fType, ['main','popup']);
        $pkLabel    = $pkType === 'uuid' ? 'UUID' : 'Auto-int';
      ?>
      <div class="nu-card" data-form-type="<?= htmlspecialchars($fType, ENT_QUOTES) ?>">
        <div class="nu-card-header" style="gap:8px;flex-wrap:wrap;">
          <h4 class="nu-card-title" style="flex:1;"><?= htmlspecialchars($f['form_name']) ?></h4>
          <span class="nb-type-badge <?= htmlspecialchars($fType) ?>"><?= $typeLabel ?></span>
          <span class="nu-badge"><?= htmlspecialchars($f['form_code']) ?></span>
        </div>

        <!-- Compact inline meta row -->
        <div class="nu-form-meta-row">
          <span class="nu-form-meta">
            🗄 <?= $f['form_table'] ? '<code>'.htmlspecialchars($f['form_table']).'</code>' : '<em style="color:var(--text-tertiary)">no table</em>' ?>
          </span>
          <span class="nu-meta-sep">·</span>
          <span class="nu-form-meta">
            📋 <strong style="color:var(--text-primary);"><?= $fieldCount ?></strong> field<?= $fieldCount !== 1 ? 's' : '' ?>
          </span>
          <span class="nu-meta-sep">·</span>
          <span class="nu-form-meta">
            🔑 <span style="font-weight:600;color:var(--color-primary);"><?= $pkLabel ?></span>
          </span>
          <?php if ($isMain): ?>
          <span class="nu-meta-sep">·</span>
          <span class="nu-form-meta">
            👁 <span style="font-weight:600;color:var(--color-primary);"><?= htmlspecialchars(ucfirst($browseMode)) ?></span>
          </span>
          <?php endif; ?>
        </div>

        <div style="display:flex;gap:8px;flex-wrap:wrap;">
          <?php if ($isMain): ?>
          <button class="nu-btn nu-btn-primary nu-btn-sm" onclick="window.previewForm && previewForm('<?= $formCode ?>','<?= $formLabel ?>')">⊞ Preview</button>
          <button class="nu-btn nu-btn-ghost nu-btn-sm"   onclick="window.browseForm  && browseForm('<?= $formCode ?>',1,'','<?= $formLabel ?>','<?= htmlspecialchars($browseMode,ENT_QUOTES) ?>')">⊟ Browse</button>
          <?php endif; ?>
          <button class="nu-btn nu-btn-ghost nu-btn-sm"   onclick="nbFormBuilder.edit(<?= (int)$f['form_id'] ?>)">✎ Edit</button>
          <button class="nu-btn nu-btn-danger nu-btn-sm"  onclick="window.deleteForm && deleteForm(<?= (int)$f['form_id'] ?>,'<?= $formLabel ?>')">Delete</button>
        </div>
      </div>
      <?php endforeach; ?>

      <?php if (empty($forms)): ?>
      <div class="nu-card" style="grid-column:1/-1;text-align:center;padding:48px;">
        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="color:var(--text-tertiary);margin:0 auto 12px;"><rect x="3" y="3" width="18" height="18" rx="3"/><path d="M9 9h6M9 12h6M9 15h4"/></svg>
        <p style="color:var(--text-tertiary);margin-bottom:12px;">No forms yet. Click "New Form" to create one.</p>
        <button class="nu-btn nu-btn-primary" onclick="nbFormBuilder.open()">+ New Form</button>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── Form Builder ──────────────────────────────────────────── -->
  <div class="nu-card" id="formBuilderCard" style="display:none;margin-top:24px;">
    <input type="hidden" id="editFormId" value="">

    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
      <h3 class="nu-card-title" id="builderTitle">New Form</h3>
      <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="nbFormBuilder.close()">✕ Cancel</button>
    </div>

    <!-- ── Form Type selector ── -->
    <div style="margin-bottom:16px;">
      <label class="nu-label" style="margin-bottom:10px;display:block;">Form Type</label>
      <div class="nb-ftype-cards" id="nbFormTypeCards">
        <label class="nb-ftype-card selected" onclick="nbFormBuilder.selectFormType('main',this)">
          <input type="radio" name="formType" id="formTypeMain" value="main" checked>
          <div class="nb-ftype-icon">⊞</div>
          <div class="nb-ftype-label">Main Form</div>
          <div class="nb-ftype-desc">Standalone — appears in menu &amp; navigation</div>
        </label>
        <label class="nb-ftype-card" onclick="nbFormBuilder.selectFormType('subform',this)">
          <input type="radio" name="formType" id="formTypeSubform" value="subform">
          <div class="nb-ftype-icon">⊟</div>
          <div class="nb-ftype-label">Subform</div>
          <div class="nb-ftype-desc">Embedded inside a main form, linked by FK</div>
        </label>
        <label class="nb-ftype-card" onclick="nbFormBuilder.selectFormType('popup',this)">
          <input type="radio" name="formType" id="formTypePopup" value="popup">
          <div class="nb-ftype-icon">▣</div>
          <div class="nb-ftype-label">Popup</div>
          <div class="nb-ftype-desc">Opens in a modal for lookup / record selection</div>
        </label>
        <label class="nb-ftype-card" onclick="nbFormBuilder.selectFormType('report',this)">
          <input type="radio" name="formType" id="formTypeReport" value="report">
          <div class="nb-ftype-icon">📊</div>
          <div class="nb-ftype-label">Report</div>
          <div class="nb-ftype-desc">Read-only display form, no save button</div>
        </label>
      </div>
    </div>

    <!-- ── Top meta row: Name + Code ── -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px;">
      <div>
        <label class="nu-label">Form Name</label>
        <input type="text" id="builderFormName" class="nu-input" placeholder="e.g. Customer Registration">
      </div>
      <div>
        <label class="nu-label">Form Code <span style="font-weight:400;color:var(--text-tertiary);">(auto-generated if blank)</span></label>
        <input type="text" id="builderFormCode" class="nu-input" placeholder="e.g. customer_registration">
      </div>
    </div>

    <!-- ── Table Mode selector ── -->
    <div style="margin-bottom:16px;">
      <label class="nu-label" style="margin-bottom:10px;display:block;">Table</label>
      <div class="nb-tmode-cards" id="nbTableModeCards">
        <label class="nb-tmode-card selected" onclick="nbFormBuilder.selectTableMode('new',this)">
          <input type="radio" name="formTableMode" id="formTableModeNew" value="new" checked>
          <div class="nb-tmode-card-title">✦ Create new table</div>
          <div class="nb-tmode-card-desc">NuBuilder creates and manages the DB table automatically</div>
        </label>
        <label class="nb-tmode-card" onclick="nbFormBuilder.selectTableMode('existing',this)">
          <input type="radio" name="formTableMode" id="formTableModeExisting" value="existing">
          <div class="nb-tmode-card-title">⊞ Use existing table</div>
          <div class="nb-tmode-card-desc">Attach this form to a table that already exists in the database</div>
        </label>
      </div>

      <!-- New table name input (shown when mode = new) -->
      <div id="tableNewWrap">
        <label class="nu-label">DB Table Name</label>
        <input type="text" id="builderFormTable" class="nu-input" placeholder="e.g. customers">
      </div>

      <!-- Existing table dropdown (shown when mode = existing) -->
      <div id="tableExistingWrap" style="display:none;">
        <label class="nu-label">Select Existing Table</label>
        <select id="builderFormTableExisting" class="nu-input" onchange="document.getElementById('builderFormTable').value=this.value">
          <option value="">-- choose a table --</option>
          <?php foreach ($userTables as $tbl): ?>
          <option value="<?= htmlspecialchars($tbl, ENT_QUOTES) ?>"><?= htmlspecialchars($tbl) ?></option>
          <?php endforeach; ?>
        </select>
        <p style="font-size:11px;color:var(--text-tertiary);margin-top:6px;">NuBuilder will read this table's columns and <strong>not</strong> alter its structure unless you explicitly add new fields.</p>
      </div>
    </div>

    <!-- ── Primary Key type selector ── -->
    <div style="margin-bottom:20px;" id="pkTypeSection">
      <label class="nu-label" style="margin-bottom:10px;display:block;">Primary Key Type</label>
      <div class="nb-pk-cards" id="nbPkCards">
        <label class="nb-pk-card selected" onclick="nbFormBuilder.selectPkType('autoincrement',this)">
          <input type="radio" name="formPkType" id="formPkTypeAuto" value="autoincrement" checked>
          <div class="nb-pk-card-title">Auto-increment INT</div>
          <div class="nb-pk-card-code">id INT AUTO_INCREMENT</div>
          <div class="nb-pk-card-desc">Standard MySQL integer PK. Simple, fast, and compatible with everything.</div>
        </label>
        <label class="nb-pk-card" onclick="nbFormBuilder.selectPkType('uuid',this)">
          <input type="radio" name="formPkType" id="formPkTypeUuid" value="uuid">
          <div class="nb-pk-card-title">NuBuilder UUID</div>
          <div class="nb-pk-card-code">id VARCHAR(36) — nubuilder style</div>
          <div class="nb-pk-card-desc">Globally unique string ID. Best for migration, sync, and multi-system environments.</div>
        </label>
      </div>
    </div>

    <!-- Tabs -->
    <div class="nb-tabs" id="nbTabsRow">
      <button type="button" class="nb-tab active" data-panel="panelFields"  onclick="nbFormBuilder.switchTab(this)">Fields</button>
      <button type="button" class="nb-tab"        data-panel="panelBrowse"  onclick="nbFormBuilder.switchTab(this)" id="browseTab">Browse</button>
      <button type="button" class="nb-tab"        data-panel="panelEvents"  onclick="nbFormBuilder.switchTab(this)">Events / JS</button>
      <button type="button" class="nb-tab"        data-panel="panelPhpCss"  onclick="nbFormBuilder.switchTab(this)">PHP / CSS</button>
    </div>

    <!-- ── TAB: Fields ── -->
    <div class="nb-tab-panel active" id="panelFields">
      <div class="nb-builder-wrap">

        <!-- Toolbox -->
        <div class="nb-toolbox">
          <div class="nb-toolbox-title">Field Types</div>

          <div class="nb-tools-group">
            <div class="nb-tools-group-label">Basic</div>
            <div class="nb-tool" data-type="text"     draggable="true"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16M4 12h16M4 18h7"/></svg>Text</div>
            <div class="nb-tool" data-type="textarea" draggable="true"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M7 8h10M7 12h10M7 16h6"/></svg>Textarea</div>
            <div class="nb-tool" data-type="number"   draggable="true"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 8h2v8M15 8h2v8M9 8h-2M17 8h-2M9 16h-2M17 16h-2"/></svg>Number</div>
            <div class="nb-tool" data-type="email"    draggable="true"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m2 7 10 7 10-7"/></svg>Email</div>
            <div class="nb-tool" data-type="password" draggable="true"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>Password</div>
            <div class="nb-tool" data-type="date"     draggable="true"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>Date</div>
            <div class="nb-tool" data-type="datetime" draggable="true"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18M12 14v4l2 2"/></svg>DateTime</div>
            <div class="nb-tool" data-type="time"     draggable="true"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>Time</div>
            <div class="nb-tool" data-type="checkbox" draggable="true"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="m9 12 2 2 4-4"/></svg>Checkbox</div>
            <div class="nb-tool" data-type="file"     draggable="true"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>File</div>
          </div>

          <div class="nb-tools-group">
            <div class="nb-tools-group-label">Choice</div>
            <div class="nb-tool" data-type="select"         draggable="true"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9l6 6 6-6"/></svg>Select</div>
            <div class="nb-tool" data-type="radio"          draggable="true"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="3" fill="currentColor"/></svg>Radio</div>
            <div class="nb-tool" data-type="checkbox_group" draggable="true"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>Checkboxes</div>
          </div>

          <div class="nb-tools-group">
            <div class="nb-tools-group-label">Advanced</div>
            <div class="nb-tool" data-type="lookup"     draggable="true"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>Lookup</div>
            <div class="nb-tool" data-type="subform"    draggable="true"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>Subform</div>
            <div class="nb-tool" data-type="calculated" draggable="true"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 7h16M4 12h8M4 17h12"/></svg>Calculated</div>
            <div class="nb-tool" data-type="range"      draggable="true"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="8" cy="12" r="2"/><path d="M2 12h4M10 12h12"/></svg>Range</div>
            <div class="nb-tool" data-type="color"      draggable="true"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/></svg>Color</div>
          </div>

          <div class="nb-tools-group">
            <div class="nb-tools-group-label">Layout</div>
            <div class="nb-tool" data-type="html"    draggable="true"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18l-5-6 5-6M15 6l5 6-5 6"/></svg>HTML</div>
            <div class="nb-tool" data-type="divider" draggable="true"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"/></svg>Divider</div>
            <div class="nb-tool" data-type="button"  draggable="true"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="10" rx="2"/></svg>Button</div>
          </div>
        </div>

        <!-- Canvas -->
        <div class="nb-canvas-wrap">
          <div class="nb-canvas-title">Form Canvas</div>
          <div class="nb-canvas" id="formCanvas">
            <div class="nb-canvas-empty" id="canvasEmpty">&#x2B06; Drag or click a field type to add it here</div>
          </div>
        </div>

      </div>
    </div>

    <!-- ── TAB: Browse ── -->
    <div class="nb-tab-panel" id="panelBrowse">
      <div style="margin-bottom:12px;padding:10px 14px;background:color-mix(in oklch,#f59e0b 10%,var(--bg-surface));border:1px solid color-mix(in oklch,#f59e0b 30%,transparent);border-radius:8px;font-size:12px;color:#92400e;" id="browseNotApplicable" style="display:none;">
        ℹ️ Browse settings only apply to <strong>Main</strong> and <strong>Popup</strong> form types.
      </div>

      <!-- Browse Display Mode selector -->
      <div style="margin-bottom:20px;">
        <label class="nu-label" style="margin-bottom:10px;display:block;">Browse Display Mode</label>
        <div class="nb-display-modes" id="browseDisplayModes">
          <label class="nb-display-mode-card selected" onclick="nbFormBuilder.selectDisplayMode('inline',this)">
            <input type="radio" name="browseDisplayMode" id="browseDisplayModeInline" value="inline" checked>
            <div class="nb-display-mode-icon">▤</div>
            <div class="nb-display-mode-label">Inline</div>
            <div class="nb-display-mode-desc">Replaces content area with breadcrumb navigation</div>
          </label>
          <label class="nb-display-mode-card" onclick="nbFormBuilder.selectDisplayMode('modal',this)">
            <input type="radio" name="browseDisplayMode" id="browseDisplayModeModal" value="modal">
            <div class="nb-display-mode-icon">▣</div>
            <div class="nb-display-mode-label">Modal</div>
            <div class="nb-display-mode-desc">Opens browse in a resizable overlay modal</div>
          </label>
          <label class="nb-display-mode-card" onclick="nbFormBuilder.selectDisplayMode('fullpage',this)">
            <input type="radio" name="browseDisplayMode" id="browseDisplayModeFullpage" value="fullpage">
            <div class="nb-display-mode-icon">⛶</div>
            <div class="nb-display-mode-label">Full Page</div>
            <div class="nb-display-mode-desc">Hides sidebar, browse fills entire viewport</div>
          </label>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div style="grid-column:1/-1;">
          <label class="nu-label">Browse SQL <span style="font-weight:400;color:var(--text-tertiary);">(leave empty for auto SELECT *)</span></label>
          <textarea id="formBrowseSql" class="nu-input" rows="4" placeholder="SELECT id, name, email FROM customers WHERE active = 1"></textarea>
        </div>
        <div style="grid-column:1/-1;">
          <label class="nu-label">Browse Columns <span style="font-weight:400;color:var(--text-tertiary);">(comma-sep field names to show as columns)</span></label>
          <input type="text" id="formBrowseColumns" class="nu-input" placeholder="id, name, email, created_at">
        </div>
        <div>
          <label class="nu-label">Page Size</label>
          <input type="number" id="formBrowsePageSize" class="nu-input" value="20" min="1" max="500">
        </div>
        <div>
          <label class="nu-label">Default Sort <span style="font-weight:400;color:var(--text-tertiary);">(field ASC/DESC)</span></label>
          <input type="text" id="formBrowseDefaultSort" class="nu-input" placeholder="created_at DESC">
        </div>
        <div style="grid-column:1/-1;">
          <label class="nb-fp-check" style="margin-bottom:8px;">
            <input type="checkbox" id="formBrowseSearchEnabled"> Enable search bar
          </label>
        </div>
        <div>
          <label class="nu-label">Search Placeholder</label>
          <input type="text" id="formBrowseSearchPlaceholder" class="nu-input" placeholder="Search records...">
        </div>
        <div>
          <label class="nu-label">Search Fields <span style="font-weight:400;color:var(--text-tertiary);">(comma-sep)</span></label>
          <input type="text" id="formBrowseSearchFields" class="nu-input" placeholder="name, email">
        </div>
      </div>
    </div>

    <!-- ── TAB: Events / JS ── -->
    <div class="nb-tab-panel" id="panelEvents">
      <div style="display:grid;gap:12px;">
        <div>
          <label class="nu-label">JS On Load <span style="font-weight:400;color:var(--text-tertiary);">(runs after form renders — use <code>nu.getValue()</code> etc.)</span></label>
          <textarea id="formCustomJs" class="nu-input" rows="6" placeholder="// nu.hide('field_name');&#10;// nu.setValue('status', 'active');"></textarea>
        </div>
        <div>
          <label class="nu-label">JS Before Save</label>
          <textarea id="formJsBeforeSave" class="nu-input" rows="4" placeholder="// return false; to cancel save"></textarea>
        </div>
        <div>
          <label class="nu-label">JS After Save</label>
          <textarea id="formJsAfterSave" class="nu-input" rows="4" placeholder="// nu.toast('Saved!');"></textarea>
        </div>
      </div>
    </div>

    <!-- ── TAB: PHP / CSS ── -->
    <div class="nb-tab-panel" id="panelPhpCss">
      <div style="display:grid;gap:12px;">
        <div>
          <label class="nu-label">Custom PHP <span style="font-weight:400;color:var(--text-tertiary);">(runs server-side before render)</span></label>
          <textarea id="formCustomPhp" class="nu-input" rows="6" placeholder="// $data['status'] = 'active';"></textarea>
        </div>
        <div>
          <label class="nu-label">Custom CSS</label>
          <textarea id="formCustomCss" class="nu-input" rows="6" placeholder=".nu-generated-form { ... }"></textarea>
        </div>
      </div>
    </div>

    <!-- Save bar -->
    <div class="nb-save-bar">
      <button type="button" class="nu-btn nu-btn-ghost" onclick="nbFormBuilder.close()">Cancel</button>
      <button type="button" class="nu-btn nu-btn-primary" onclick="window.saveForm && saveForm()">&#x1F4BE; Save Form</button>
    </div>

  </div>

</div>

<script>
// ── Guard: only run once per page load to prevent re-declaration errors ──
if (!window._nbFormsModuleInit) {
  window._nbFormsModuleInit = true;

  // ── Form type filter ─────────────────────────────────────────────
  window.nbFilterForms = function(filter, btn) {
    document.querySelectorAll('.nb-filter-tab').forEach(b => b.classList.remove('active'));
    if (btn) btn.classList.add('active');
    document.querySelectorAll('#formsGrid .nu-card[data-form-type]').forEach(card => {
      const show = filter === 'all' || card.dataset.formType === filter;
      card.style.display = show ? '' : 'none';
    });
  };

  // ── Form type selector ───────────────────────────────────────────
  nbFormBuilder.selectFormType = function(type, card) {
    document.querySelectorAll('.nb-ftype-card').forEach(c => c.classList.remove('selected'));
    if (card) card.classList.add('selected');
    const radio = document.querySelector('input[name="formType"][value="'+type+'"]');
    if (radio) radio.checked = true;

    const browseTabEl = document.getElementById('browseTab');
    const browseNotice = document.getElementById('browseNotApplicable');
    const isBrowseable = type === 'main' || type === 'popup';
    if (browseTabEl) browseTabEl.style.opacity = isBrowseable ? '1' : '0.4';
    if (browseNotice) browseNotice.style.display = isBrowseable ? 'none' : 'block';
  };

  // ── Table mode toggle ────────────────────────────────────────────
  nbFormBuilder.selectTableMode = function(mode, card) {
    document.querySelectorAll('.nb-tmode-card').forEach(c => c.classList.remove('selected'));
    if (card) card.classList.add('selected');
    const radio = document.querySelector('input[name="formTableMode"][value="'+mode+'"]');
    if (radio) radio.checked = true;
    const isNew = mode === 'new';
    document.getElementById('tableNewWrap').style.display      = isNew ? '' : 'none';
    document.getElementById('tableExistingWrap').style.display = isNew ? 'none' : '';
    document.getElementById('nbPkCards').style.opacity        = isNew ? '1' : '0.4';
    document.getElementById('nbPkCards').style.pointerEvents  = isNew ? '' : 'none';
    if (!isNew) {
      const sel = document.getElementById('builderFormTableExisting');
      if (sel && sel.value) document.getElementById('builderFormTable').value = sel.value;
    }
  };

  // ── PK type toggle ───────────────────────────────────────────────
  nbFormBuilder.selectPkType = function(type, card) {
    document.querySelectorAll('.nb-pk-card').forEach(c => c.classList.remove('selected'));
    if (card) card.classList.add('selected');
    const radio = document.querySelector('input[name="formPkType"][value="'+type+'"]');
    if (radio) radio.checked = true;
  };

  // ── Extend open() to reset all fields ───────────────────────────
  const _nbOpen = nbFormBuilder.open.bind(nbFormBuilder);
  nbFormBuilder.open = function() {
    _nbOpen();
    nbFormBuilder.selectFormType('main', document.querySelector('.nb-ftype-card'));
    nbFormBuilder.selectTableMode('new', document.querySelector('.nb-tmode-card'));
    nbFormBuilder.selectPkType('autoincrement', document.querySelector('.nb-pk-card'));
    document.getElementById('builderFormCode').value = '';
  };

  // ── Restore form type on edit ────────────────────────────────────
  window._nbRestoreFormType = function(ftype) {
    const val = ftype || 'main';
    const radio = document.querySelector('input[name="formType"][value="'+val+'"]');
    if (radio) {
      const card = radio.closest('.nb-ftype-card');
      nbFormBuilder.selectFormType(val, card);
    }
  };

  // ── Patch saveForm to include pk_type, table_mode, form_type ────
  const _origSaveForm = window.saveForm;
  window.saveForm = function() {
    nbFormBuilder._pkType    = document.querySelector('input[name="formPkType"]:checked')?.value    || 'autoincrement';
    nbFormBuilder._tableMode = document.querySelector('input[name="formTableMode"]:checked')?.value || 'new';
    nbFormBuilder._formCode  = document.getElementById('builderFormCode').value.trim();
    nbFormBuilder._formType  = document.querySelector('input[name="formType"]:checked')?.value      || 'main';
    if (typeof _origSaveForm === 'function') _origSaveForm();
  };
}
</script>
