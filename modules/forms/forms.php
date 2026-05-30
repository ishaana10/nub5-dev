<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/core/module_bootstrap.php';
// $auth, $nuConfig available. Session open under 'nu5sess'.

$db    = NuDatabase::getInstance();
$forms = $db->fetchAll("SELECT * FROM nu_forms WHERE form_active = 1 ORDER BY form_id DESC");
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

/* Form card header meta row */
.nu-form-meta { font-size:12px; color:var(--text-tertiary); }

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
</style>

<div class="nu-forms">

  <!-- ── Forms list ────────────────────────────────────────────── -->
  <div id="formsListSection">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
      <h3 class="nu-card-title">Forms</h3>
      <?php if ($auth->hasPermission('forms','create')): ?>
      <button class="nu-btn nu-btn-primary" onclick="nbFormBuilder.open()">+ New Form</button>
      <?php endif; ?>
    </div>

    <div class="nu-grid">
      <?php foreach ($forms as $f): ?>
      <div class="nu-card">
        <div class="nu-card-header">
          <h4 class="nu-card-title"><?= htmlspecialchars($f['form_name']) ?></h4>
          <span class="nu-badge"><?= htmlspecialchars($f['form_code']) ?></span>
        </div>
        <p class="nu-form-meta" style="margin-bottom:4px;">Table: <?= $f['form_table'] ? '<code>'.htmlspecialchars($f['form_table']).'</code>' : '<em>none</em>' ?></p>
        <?php
          $layout     = @json_decode($f['form_layout'] ?? '[]', true);
          $fieldCount = is_array($layout) ? count($layout) : 0;
        ?>
        <p class="nu-form-meta" style="margin-bottom:12px;"><?= $fieldCount ?> field<?= $fieldCount !== 1 ? 's' : '' ?></p>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
          <button class="nu-btn nu-btn-primary nu-btn-sm"  onclick="previewForm('<?= htmlspecialchars($f['form_code']) ?>')">Preview</button>
          <button class="nu-btn nu-btn-ghost nu-btn-sm"    onclick="nbFormBuilder.edit(<?= (int)$f['form_id'] ?>)">Edit</button>
          <button class="nu-btn nu-btn-ghost nu-btn-sm"    onclick="browseForm('<?= htmlspecialchars($f['form_code']) ?>')">Browse</button>
          <button class="nu-btn nu-btn-danger nu-btn-sm"   onclick="deleteForm(<?= (int)$f['form_id'] ?>,'<?= htmlspecialchars($f['form_name'],ENT_QUOTES) ?>')">Delete</button>
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

    <!-- Top meta row -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px;">
      <div>
        <label class="nu-label">Form Name</label>
        <input type="text" id="builderFormName" class="nu-input" placeholder="e.g. Customer Registration">
      </div>
      <div>
        <label class="nu-label">DB Table</label>
        <input type="text" id="builderFormTable" class="nu-input" placeholder="e.g. customers">
      </div>
    </div>

    <!-- Tabs -->
    <div class="nb-tabs" id="nbTabsRow">
      <button type="button" class="nb-tab active" data-panel="panelFields"  onclick="nbFormBuilder.switchTab(this)">Fields</button>
      <button type="button" class="nb-tab"        data-panel="panelBrowse"  onclick="nbFormBuilder.switchTab(this)">Browse</button>
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
      <button type="button" class="nu-btn nu-btn-primary" onclick="saveForm()">&#x1F4BE; Save Form</button>
    </div>

  </div>

</div>
