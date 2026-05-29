<?php
require_once '../../config.php';
require_once '../../core/Database.php';
require_once '../../core/Auth.php';

$auth = new NuAuth();
if (!$auth->checkAuth()) exit('Unauthorized');

$db = NuDatabase::getInstance();
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

/* Save/cancel bar — always visible at bottom of builder card */
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
          $layout = @json_decode($f['form_layout'] ?? '[]', true);
          $fieldCount = is_array($layout) ? count($layout) : 0;
        ?>
        <p class="nu-form-meta" style="margin-bottom:12px;"><?= $fieldCount ?> field<?= $fieldCount !== 1 ? 's' : '' ?></p>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
          <button class="nu-btn nu-btn-primary nu-btn-sm" onclick="previewForm('<?= htmlspecialchars($f['form_code']) ?>')">Preview</button>
          <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="nbFormBuilder.edit(<?= (int)$f['form_id'] ?>)">Edit</button>
          <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="browseForm('<?= htmlspecialchars($f['form_code']) ?>')">Browse</button>
          <button class="nu-btn nu-btn-danger nu-btn-sm" onclick="deleteForm(<?= (int)$f['form_id'] ?>,'<?= htmlspecialchars($f['form_name'],ENT_QUOTES) ?>')">Delete</button>
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
      <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="nbFormBuilder.close()">✕ Close</button>
    </div>

    <!-- Form identity row -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:20px;">
      <div class="nu-field">
        <label>Form Name <span style="color:var(--color-danger);">*</span></label>
        <input type="text" class="nu-input" id="builderFormName" placeholder="e.g. Customer Order Form">
      </div>
      <div class="nu-field">
        <label>Database Table</label>
        <input type="text" class="nu-input" id="builderFormTable" placeholder="e.g. customers">
      </div>
    </div>

    <!-- Tabs -->
    <div class="nb-tabs" id="nbTabsRow">
      <button class="nb-tab active" data-panel="panelFields"  onclick="nbFormBuilder.switchTab(this)">🧩 Fields</button>
      <button class="nb-tab"        data-panel="panelBrowse"  onclick="nbFormBuilder.switchTab(this)">📋 Browse</button>
      <button class="nb-tab"        data-panel="panelEvents"  onclick="nbFormBuilder.switchTab(this)">⚡ Events</button>
      <button class="nb-tab"        data-panel="panelCode"    onclick="nbFormBuilder.switchTab(this)">🎨 CSS / PHP</button>
    </div>

    <!-- ── Tab: Fields ─────────────────────────────────────────── -->
    <div class="nb-tab-panel active" id="panelFields">
      <div class="nb-builder-wrap">

        <!-- Toolbox -->
        <div class="nb-toolbox">
          <div class="nb-toolbox-title">Field Types</div>

          <div class="nb-tools-group">
            <div class="nb-tools-group-label">Basic</div>
            <div class="nb-tool" draggable="true" data-type="text"><?= icon('type') ?> Text</div>
            <div class="nb-tool" draggable="true" data-type="email"><?= icon('mail') ?> Email</div>
            <div class="nb-tool" draggable="true" data-type="number"><?= icon('hash') ?> Number</div>
            <div class="nb-tool" draggable="true" data-type="phone"><?= icon('phone') ?> Phone</div>
            <div class="nb-tool" draggable="true" data-type="url"><?= icon('link') ?> URL</div>
            <div class="nb-tool" draggable="true" data-type="password"><?= icon('lock') ?> Password</div>
            <div class="nb-tool" draggable="true" data-type="textarea"><?= icon('align-left') ?> Textarea</div>
          </div>

          <div class="nb-tools-group">
            <div class="nb-tools-group-label">Date &amp; Time</div>
            <div class="nb-tool" draggable="true" data-type="date"><?= icon('calendar') ?> Date</div>
            <div class="nb-tool" draggable="true" data-type="time"><?= icon('clock') ?> Time</div>
            <div class="nb-tool" draggable="true" data-type="datetime"><?= icon('clock') ?> Date+Time</div>
          </div>

          <div class="nb-tools-group">
            <div class="nb-tools-group-label">Choice</div>
            <div class="nb-tool" draggable="true" data-type="select"><?= icon('chevron-down') ?> Select</div>
            <div class="nb-tool" draggable="true" data-type="radio"><?= icon('circle') ?> Radio</div>
            <div class="nb-tool" draggable="true" data-type="checkbox"><?= icon('check-square') ?> Checkbox</div>
            <div class="nb-tool" draggable="true" data-type="checkbox_group"><?= icon('list') ?> Checkbox Group</div>
          </div>

          <div class="nb-tools-group">
            <div class="nb-tools-group-label">Advanced</div>
            <div class="nb-tool" draggable="true" data-type="lookup"><?= icon('search') ?> Lookup</div>
            <div class="nb-tool" draggable="true" data-type="file"><?= icon('paperclip') ?> File Upload</div>
            <div class="nb-tool" draggable="true" data-type="color"><?= icon('droplet') ?> Color</div>
            <div class="nb-tool" draggable="true" data-type="range"><?= icon('sliders') ?> Range</div>
            <div class="nb-tool" draggable="true" data-type="hidden"><?= icon('eye-off') ?> Hidden</div>
            <div class="nb-tool" draggable="true" data-type="calculated"><?= icon('zap') ?> Calculated</div>
            <div class="nb-tool" draggable="true" data-type="subform"><?= icon('layers') ?> Subform</div>
          </div>

          <div class="nb-tools-group">
            <div class="nb-tools-group-label">Layout</div>
            <div class="nb-tool" draggable="true" data-type="html"><?= icon('code') ?> HTML Block</div>
            <div class="nb-tool" draggable="true" data-type="divider"><?= icon('minus') ?> Divider</div>
            <div class="nb-tool" draggable="true" data-type="button"><?= icon('square') ?> Button</div>
          </div>
        </div>

        <!-- Canvas -->
        <div class="nb-canvas-wrap">
          <div class="nb-canvas-title">Form Canvas — drag to reorder</div>
          <div class="nb-canvas" id="formCanvas">
            <div class="nb-canvas-empty" id="canvasEmpty">⬆ Drag or click a field type to add it here</div>
          </div>
        </div>

      </div><!-- /builder-wrap -->
    </div><!-- /panelFields -->

    <!-- ── Tab: Browse ─────────────────────────────────────────── -->
    <div class="nb-tab-panel" id="panelBrowse">
      <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;">

        <div class="nu-field" style="grid-column:1/-1;">
          <label style="display:flex;align-items:center;gap:8px;">
            <input type="checkbox" id="formBrowseSearchEnabled"> Enable Search Bar
          </label>
        </div>

        <div class="nu-field">
          <label>Search Placeholder</label>
          <input type="text" id="formBrowseSearchPlaceholder" class="nu-input" placeholder="Search records...">
        </div>

        <div class="nu-field">
          <label>Search Fields <span class="nu-form-meta">(comma-separated column names)</span></label>
          <input type="text" id="formBrowseSearchFields" class="nu-input" placeholder="name,email,status">
        </div>

        <div class="nu-field">
          <label>Page Size</label>
          <input type="number" id="formBrowsePageSize" class="nu-input" value="20" min="1">
        </div>

        <div class="nu-field">
          <label>Default Sort</label>
          <input type="text" id="formBrowseDefaultSort" class="nu-input" placeholder="created_at DESC">
        </div>

        <div class="nu-field" style="grid-column:1/-1;">
          <label>Browse SQL <span class="nu-form-meta">(overrides auto-query; use :search for search term)</span></label>
          <textarea id="formBrowseSql" class="nu-input" rows="5" placeholder="SELECT id, name, email FROM customers WHERE active=1"></textarea>
        </div>

        <div class="nu-field" style="grid-column:1/-1;">
          <label>Browse Columns JSON <span class="nu-form-meta">(overrides auto-columns)</span></label>
          <textarea id="formBrowseColumns" class="nu-input" rows="5" placeholder='[{"name":"status","label":"Status"},{"name":"email","label":"Email"}]'></textarea>
        </div>

      </div>
    </div><!-- /panelBrowse -->

    <!-- ── Tab: Events ─────────────────────────────────────────── -->
    <div class="nb-tab-panel" id="panelEvents">
      <div style="display:grid;gap:14px;">

        <div class="nu-field">
          <label>JS — On Load <span class="nu-form-meta">(runs when form opens; <code>nu</code> = nuForm object)</span></label>
          <textarea id="formCustomJs" class="nu-input" rows="7" placeholder="// nu.setValue('status','active');"></textarea>
        </div>

        <div class="nu-field">
          <label>JS — Before Save <span class="nu-form-meta">(return false to cancel)</span></label>
          <textarea id="formJsBeforeSave" class="nu-input" rows="5" placeholder="// if(!nu.getValue('name')){ NuApp.toast('Name required','error'); return false; }"></textarea>
        </div>

        <div class="nu-field">
          <label>JS — After Save</label>
          <textarea id="formJsAfterSave" class="nu-input" rows="5" placeholder="// NuApp.toast('Record saved!');"></textarea>
        </div>

        <div class="nu-field">
          <label>PHP — Before Save <span class="nu-form-meta">(server-side; <code>$record</code> array available)</span></label>
          <textarea id="formCustomPhp" class="nu-input" rows="7" placeholder="// $record['slug'] = strtolower(str_replace(' ','-',$record['name']));"></textarea>
        </div>

      </div>
    </div><!-- /panelEvents -->

    <!-- ── Tab: CSS / PHP ──────────────────────────────────────── -->
    <div class="nb-tab-panel" id="panelCode">
      <div style="display:grid;gap:14px;">
        <div class="nu-field">
          <label>Custom CSS <span class="nu-form-meta">(scoped to this form)</span></label>
          <textarea id="formCustomCss" class="nu-input" rows="10" placeholder=".nu-generated-form .my-class { color: red; }"></textarea>
        </div>
      </div>
    </div><!-- /panelCode -->

    <!-- ── Save / Cancel bar — always rendered, never hidden ── -->
    <div class="nb-save-bar">
      <span id="builderSaveStatus" style="font-size:12px;color:var(--text-secondary);"></span>
      <button class="nu-btn nu-btn-ghost" onclick="nbFormBuilder.close()">Cancel</button>
      <button class="nu-btn nu-btn-primary" onclick="nbFormBuilder.save()">💾 Save Form</button>
    </div>

  </div><!-- /formBuilderCard -->

</div><!-- /nu-forms -->

<?php
function icon($name) {
  $icons = [
    'type'        => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="4 7 4 4 20 4 20 7"/><line x1="9" y1="20" x2="15" y2="20"/><line x1="12" y1="4" x2="12" y2="20"/></svg>',
    'mail'        => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>',
    'hash'        => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="4" y1="9" x2="20" y2="9"/><line x1="4" y1="15" x2="20" y2="15"/><line x1="10" y1="3" x2="8" y2="21"/><line x1="16" y1="3" x2="14" y2="21"/></svg>',
    'phone'       => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12 19.79 19.79 0 0 1 1.61 3.44 2 2 0 0 1 3.6 1h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.6a16 16 0 0 0 6.29 6.29l.96-.96a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>',
    'link'        => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>',
    'lock'        => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>',
    'align-left'  => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="17" y1="10" x2="3" y2="10"/><line x1="21" y1="6" x2="3" y2="6"/><line x1="21" y1="14" x2="3" y2="14"/><line x1="17" y1="18" x2="3" y2="18"/></svg>',
    'calendar'    => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>',
    'clock'       => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
    'chevron-down'=> '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>',
    'circle'      => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/></svg>',
    'check-square'=> '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>',
    'list'        => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>',
    'search'      => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>',
    'paperclip'   => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>',
    'droplet'     => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2.69l5.66 5.66a8 8 0 1 1-11.31 0z"/></svg>',
    'sliders'     => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/></svg>',
    'eye-off'     => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>',
    'zap'         => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>',
    'layers'      => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/></svg>',
    'code'        => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>',
    'minus'       => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"/></svg>',
    'square'      => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/></svg>',
  ];
  return $icons[$name] ?? '';
}
?>

<script>
/**
 * nbFormBuilder — global namespace, safe for innerHTML injection.
 * All onclick="nbFormBuilder.xxx()" calls reference this object directly.
 * No IIFE wrapping to avoid scope loss after innerHTML injection.
 */
window.nbFormBuilder = (function() {

  // ── private helpers ─────────────────────────────────────────
  function _esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;'); }
  function _val(obj, k, def) { return obj[k] !== undefined ? obj[k] : (def || ''); }
  function _chk(obj, k) { return obj[k] ? 'checked' : ''; }
  function _el(id) { return document.getElementById(id); }

  function _canvasEmpty() {
    var canvas = _el('formCanvas');
    var empty  = _el('canvasEmpty');
    if (!canvas || !empty) return;
    empty.style.display = canvas.querySelectorAll('.nb-cfield').length ? 'none' : 'block';
  }

  function _inp(cls, obj, k, ph, def) {
    return '<input type="text" class="nu-input ' + cls + '" value="' + _esc(_val(obj,k,def||'')) + '" placeholder="' + _esc(ph||'') + '">';
  }
  function _row(label, inner, full) {
    return '<div class="nb-fp' + (full?' nb-fp-full':'') + '"><label>' + label + '</label>' + inner + '</div>';
  }
  function _chkLbl(cls, obj, k, lbl) {
    return '<label class="nb-fp-check"><input type="checkbox" class="' + cls + '" ' + _chk(obj,k) + '> ' + lbl + '</label>';
  }

  // ── build per-field property panel HTML ─────────────────────
  function _fieldPanel(type, extra) {
    extra = extra || {};

    var widthSel = '<select class="nu-input nu-field-width">' +
      ['25%','33%','50%','66%','75%','100%'].map(function(w) {
        return '<option' + (_val(extra,'width','100%') === w ? ' selected' : '') + '>' + w + '</option>';
      }).join('') + '</select>';

    var html = '<div class="nb-fp-grid">' +
      _row('Label', '<input type="text" class="nu-input nu-builder-label" value="' + _esc(_val(extra,'label')) + '" placeholder="Field label">') +
      _row('Field Name (DB column)', '<input type="text" class="nu-input nu-builder-name" value="' + _esc(_val(extra,'name')) + '" placeholder="field_name">') +
      _row('Width', widthSel) +
      _row('Default Value',   _inp('nu-field-default',    extra, 'default_value',  'default value')) +
      _row('Placeholder',     _inp('nu-field-placeholder',extra, 'placeholder',    'hint text')) +
      _row('Help Text',       _inp('nu-field-help',       extra, 'help_text',      'shown under field')) +
      _row('CSS Class',       _inp('nu-field-cssclass',   extra, 'css_class',      'my-custom-class')) +
      _row('Tab',             _inp('nu-field-tab',        extra, 'tab',            'tab name')) +
      _row('Section',         _inp('nu-field-section',    extra, 'section',        'section heading')) +
      _row('Visibility Rule', _inp('nu-field-vis',        extra, 'visibility_rule','JS expression')) +
      _row('Readonly Rule',   _inp('nu-field-readonly',   extra, 'readonly_rule',  'JS expression')) +
      _row('JS On Change',    _inp('nu-field-onchange',   extra, 'js_onchange',    'JS code snippet')) +
      '<div class="nb-fp nb-fp-full" style="flex-direction:row;gap:16px;flex-wrap:wrap;align-items:center;">' +
        _chkLbl('nu-field-required', extra, 'required', 'Required') +
      '</div>';

    if (type === 'textarea') {
      html += _row('Rows', '<input type="number" class="nu-input nu-field-rows" value="' + _val(extra,'rows',3) + '" min="1" max="30">');
    }
    if (type === 'number' || type === 'range') {
      html += _row('Min',  _inp('nu-field-min',  extra, 'min',  ''));
      html += _row('Max',  _inp('nu-field-max',  extra, 'max',  ''));
      html += _row('Step', _inp('nu-field-step', extra, 'step', ''));
    }
    if (type === 'file') {
      html += _row('Accept', _inp('nu-field-accept', extra, 'accept', '.pdf,.jpg,.png'));
      html += '<div class="nb-fp">' + _chkLbl('nu-field-multiple-upload', extra, 'multiple_upload', 'Allow multiple files') + '</div>';
    }
    if (type === 'select' || type === 'radio' || type === 'checkbox_group') {
      var srcType = _val(extra, 'source_type', _val(extra,'sourcetype','static'));
      var opts    = (extra.options||[]).map(function(o){ return (o.value||'') + '|' + (o.label||o.value||''); }).join('\n');
      var sqlVal  = _val(extra, 'sql_source', _val(extra,'sqlsource',''));
      html += '<div class="nb-fp nb-fp-full"><label>Option Source</label>' +
        '<select class="nu-input nu-select-source-type" onchange="nbFormBuilder.toggleSelectSource(this)">' +
          '<option value="static"' + (srcType==='static'?' selected':'') + '>Static Options</option>' +
          '<option value="sql"'    + (srcType==='sql'   ?' selected':'') + '>SQL Query</option>' +
        '</select></div>' +
        '<div class="nb-fp nb-fp-full nu-static-block"' + (srcType!=='static'?' style="display:none"':'') + '>' +
          '<label>Options <span style="font-weight:400;">(value|label per line)</span></label>' +
          '<textarea class="nu-input nu-select-static" rows="4" placeholder="active|Active\npending|Pending">' + _esc(opts) + '</textarea>' +
        '</div>' +
        '<div class="nb-fp nb-fp-full nu-sql-block"' + (srcType!=='sql'?' style="display:none"':'') + '>' +
          '<label>SQL Query</label>' +
          '<textarea class="nu-input nu-select-sql" rows="3" placeholder="SELECT id, name FROM customers">' + _esc(sqlVal) + '</textarea>' +
        '</div>';
      if (type === 'select') {
        html += '<div class="nb-fp">' + _chkLbl('nu-field-multiple', extra, 'multiple',  'Multi-select') + '</div>';
        html += '<div class="nb-fp">' + _chkLbl('nu-field-select2',  extra, 'select2',   'Use Select2')  + '</div>';
      }
    }
    if (type === 'lookup') {
      var lk    = extra.lookup || {};
      var lkSrc = lk.table ? lk.table + '.' + (lk.display_column||lk.displaycolumn||'name') : '';
      html += '<div class="nb-fp nb-fp-full"><label>Source (table.column)</label>' +
        '<input type="text" class="nu-input nu-lookup-source" value="' + _esc(lkSrc) + '" placeholder="customers.name"></div>' +
        _row('ID Column',    '<input type="text" class="nu-input nu-lookup-id"     value="' + _esc(lk.id_column||lk.idcolumn||'id') + '" placeholder="id">')    +
        _row('Filter SQL',   '<input type="text" class="nu-input nu-lookup-filter" value="' + _esc(lk.filter||'')                  + '" placeholder="active=1">') +
        '<div class="nb-fp nb-fp-full"><label>Extra Mapping (src:field, comma-sep)</label>' +
        '<input type="text" class="nu-input nu-lookup-extra" value="' + _esc(lk.extra||'') + '" placeholder="dept_id:department"></div>';
    }
    if (type === 'subform') {
      var sf  = extra.subform || {};
      var sfv = sf.form_code ? sf.form_code + '.' + (sf.fk_field||'') : '';
      html += '<div class="nb-fp nb-fp-full"><label>Config (form_code.fk_field)</label>' +
        '<input type="text" class="nu-input nu-subform-config" value="' + _esc(sfv) + '" placeholder="order_items.order_id"></div>' +
        '<div class="nb-fp"><label>View</label>' +
        '<select class="nu-input nu-subform-view">' +
          '<option value="grid"' + ((sf.view||'grid')==='grid'?' selected':'') + '>Grid</option>' +
          '<option value="form"' + (sf.view==='form'?' selected':'')           + '>Form</option>' +
        '</select></div>';
    }
    if (type === 'calculated') {
      html += '<div class="nb-fp nb-fp-full"><label>Expression</label>' +
        '<input type="text" class="nu-input nu-calc-expression" value="' + _esc(_val(extra,'calculated')) + '" placeholder="getValue(\'qty\') * getValue(\'price\')"></div>';
    }
    if (type === 'html') {
      html += '<div class="nb-fp nb-fp-full"><label>HTML Content</label>' +
        '<textarea class="nu-input nu-html-content" rows="4" placeholder="<strong>Section header</strong>">' + _esc(_val(extra,'html_content')) + '</textarea></div>';
    }
    if (type === 'button') {
      html += _row('Button Action', _inp('nu-field-button-action', extra, 'button_action', 'JS / procedure code'));
      html += _row('Legend',        _inp('nu-field-legend',        extra, 'legend',         ''));
    }

    html += '</div>'; // close nb-fp-grid
    return html;
  }

  // ── drag: toolbox → canvas ────────────────────────────────
  var _dragTool  = null;
  var _dragField = null;

  function _initToolbox() {
    document.querySelectorAll('#panelFields .nb-tool').forEach(function(tool) {
      // remove and re-add to clear stale listeners
      var t = tool.cloneNode(true);
      tool.parentNode.replaceChild(t, tool);
      t.addEventListener('dragstart', function(e) {
        _dragTool = t.dataset.type;
        t.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'copy';
      });
      t.addEventListener('dragend', function() { t.classList.remove('dragging'); });
      t.addEventListener('click',   function() { _addField(t.dataset.type); });
    });
  }

  function _initCanvasDrop() {
    var canvas = _el('formCanvas');
    if (!canvas) return;
    canvas.addEventListener('dragover', function(e) {
      e.preventDefault();
      canvas.classList.add('drag-over');
    });
    canvas.addEventListener('dragleave', function() { canvas.classList.remove('drag-over'); });
    canvas.addEventListener('drop', function(e) {
      e.preventDefault();
      canvas.classList.remove('drag-over');
      if (_dragTool) { _addField(_dragTool); _dragTool = null; }
    });
  }

  function _makeDraggable(el) {
    el.setAttribute('draggable','true');
    el.addEventListener('dragstart', function(e) {
      _dragField = el; el.classList.add('drag-source'); e.dataTransfer.effectAllowed = 'move';
    });
    el.addEventListener('dragend', function() {
      el.classList.remove('drag-source');
      document.querySelectorAll('.nb-cfield').forEach(function(f){ f.style.outline=''; });
      _dragField = null;
    });
    el.addEventListener('dragover', function(e) {
      if (!_dragField || _dragField === el) return;
      e.preventDefault();
      var r = el.getBoundingClientRect();
      var canvas = _el('formCanvas');
      if (e.clientY > r.top + r.height/2) canvas.insertBefore(_dragField, el.nextSibling);
      else canvas.insertBefore(_dragField, el);
    });
  }

  // ── add field to canvas ────────────────────────────────────
  function _addField(type, label, name, required, extraData) {
    var canvas = _el('formCanvas');
    if (!canvas) return;
    var extra = extraData || {};
    if (!label) label = type.charAt(0).toUpperCase() + type.slice(1).replace(/_/g,' ') + ' Field';
    if (!name)  name  = type + '_' + Date.now();
    extra.label    = extra.label    !== undefined ? extra.label    : label;
    extra.name     = extra.name     !== undefined ? extra.name     : name;
    extra.required = extra.required !== undefined ? extra.required : (required || false);

    var card = document.createElement('div');
    card.className    = 'nb-cfield nu-builder-field';
    card.dataset.type = type;

    // Store flat properties for saveForm()
    card.dataset.width          = extra.width          || '100%';
    card.dataset.default        = extra.default_value  || '';
    card.dataset.placeholder    = extra.placeholder    || '';
    card.dataset.help           = extra.help_text      || '';
    card.dataset.cssClass       = extra.css_class      || '';
    card.dataset.sortOrder      = extra.sort_order     || '';
    card.dataset.rows           = extra.rows           || '3';
    card.dataset.min            = extra.min            || '';
    card.dataset.max            = extra.max            || '';
    card.dataset.step           = extra.step           || '';
    card.dataset.accept         = extra.accept         || '';
    card.dataset.multipleUpload = extra.multiple_upload ? '1' : '0';
    card.dataset.legend         = extra.legend         || '';
    card.dataset.select2        = extra.select2 ? '1' : '0';
    card.dataset.tab            = extra.tab            || '';
    card.dataset.section        = extra.section        || '';
    card.dataset.visibilityRule = extra.visibility_rule || '';
    card.dataset.readonlyRule   = extra.readonly_rule   || '';
    card.dataset.css            = extra.css            || '';
    card.dataset.onchange       = extra.js_onchange    || '';
    card.dataset.htmlContent    = extra.html_content   || '';
    card.dataset.buttonAction   = extra.button_action  || '';

    var typeLabel = type.replace(/_/g,' ');
    card.innerHTML = '<div class="nb-cfield-header" onclick="nbFormBuilder.toggleField(this)">' +
      '<span class="nb-cfield-drag" title="Drag to reorder" onclick="event.stopPropagation()">&#x2807;</span>' +
      '<span class="nb-cfield-type-badge">' + typeLabel + '</span>' +
      '<span class="nb-cfield-label">' + _esc(extra.label) + '</span>' +
      '<div class="nb-cfield-actions">' +
        '<button type="button" class="nb-cfield-btn" onclick="event.stopPropagation();nbFormBuilder.toggleField(this.closest(\'.nb-cfield\').querySelector(\'.nb-cfield-header\'))">&#x2699;</button>' +
        '<button type="button" class="nb-cfield-btn del" onclick="event.stopPropagation();this.closest(\'.nb-cfield\').remove();nbFormBuilder._canvasEmpty();">&#x2715;</button>' +
      '</div></div>' +
      '<div class="nb-cfield-body">' + _fieldPanel(type, extra) + '</div>';

    canvas.appendChild(card);
    _canvasEmpty();
    _makeDraggable(card);

    // live label update
    var lInput = card.querySelector('.nu-builder-label');
    if (lInput) {
      lInput.addEventListener('input', function() {
        card.querySelector('.nb-cfield-label').textContent = lInput.value || '(no label)';
      });
    }

    // auto-expand first field
    if (canvas.querySelectorAll('.nb-cfield').length === 1) {
      card.querySelector('.nb-cfield-body').classList.add('open');
    }
  }

  // ── public API ─────────────────────────────────────────────
  return {
    _canvasEmpty: _canvasEmpty,

    switchTab: function(btn) {
      document.querySelectorAll('#nbTabsRow .nb-tab').forEach(function(t){ t.classList.remove('active'); });
      document.querySelectorAll('#formBuilderCard .nb-tab-panel').forEach(function(p){ p.classList.remove('active'); });
      btn.classList.add('active');
      var panel = document.getElementById(btn.dataset.panel);
      if (panel) panel.classList.add('active');
    },

    toggleField: function(header) {
      var body = header.closest('.nb-cfield').querySelector('.nb-cfield-body');
      if (body) body.classList.toggle('open');
    },

    toggleSelectSource: function(sel) {
      var card = sel.closest('.nb-cfield-body');
      if (!card) return;
      card.querySelector('.nu-static-block').style.display = sel.value === 'static' ? '' : 'none';
      card.querySelector('.nu-sql-block').style.display    = sel.value === 'sql'    ? '' : 'none';
    },

    addField: function(type, label, name, required, extraData) {
      _addField(type, label, name, required, extraData);
    },

    open: function() {
      var card = _el('formBuilderCard');
      if (!card) return;
      _el('editFormId').value          = '';
      _el('builderTitle').textContent  = 'New Form';
      _el('builderFormName').value     = '';
      _el('builderFormTable').value    = '';
      _el('formCanvas').innerHTML = '<div class="nb-canvas-empty" id="canvasEmpty">&#x2B06; Drag or click a field type to add it here</div>';

      ['formCustomJs','formJsBeforeSave','formJsAfterSave','formCustomPhp',
       'formCustomCss','formBrowseSql','formBrowseColumns',
       'formBrowseSearchPlaceholder','formBrowseSearchFields','formBrowseDefaultSort'
      ].forEach(function(id){ var e=_el(id); if(e) e.value=''; });
      var chk = _el('formBrowseSearchEnabled'); if (chk) chk.checked = false;
      var ps  = _el('formBrowsePageSize');      if (ps)  ps.value   = '20';

      // Switch to Fields tab
      var firstTab = document.querySelector('#nbTabsRow .nb-tab');
      if (firstTab) this.switchTab(firstTab);

      card.style.display = 'block';
      card.scrollIntoView({ behavior:'smooth' });
      _initToolbox();
      _initCanvasDrop();
    },

    close: function() {
      var card = _el('formBuilderCard');
      if (card) card.style.display = 'none';
    },

    save: function() {
      // Delegate to global saveForm() which reads the canvas
      if (typeof window.saveForm === 'function') window.saveForm();
    },

    edit: async function(id) {
      try {
        var json = await NuApp.apiJson(
          'api/crud.php?table=nu_forms&id=' + encodeURIComponent(id),
          { credentials:'same-origin' }
        );
        var form = json.data || json.record;
        if (!json.success || !form) { NuApp.toast('Could not load form','error'); return; }

        nbFormBuilder.open();

        _el('editFormId').value          = id;
        _el('builderTitle').textContent  = 'Edit Form';
        _el('builderFormName').value     = form.form_name  || '';
        _el('builderFormTable').value    = form.form_table || '';

        function sv(id, v){ var e=_el(id); if(e) e.value = v||''; }
        function sc(id, v){ var e=_el(id); if(e) e.checked = parseInt(v||0)===1; }

        sv('formCustomJs',               form.form_custom_js);
        sv('formJsBeforeSave',           form.form_js_before_save);
        sv('formJsAfterSave',            form.form_js_after_save);
        sv('formCustomPhp',              form.form_custom_php);
        sv('formCustomCss',              form.form_custom_css);
        sv('formBrowseSql',              form.browse_sql);
        sv('formBrowseColumns',          form.browse_columns);
        sv('formBrowseSearchPlaceholder',form.browse_search_placeholder);
        sv('formBrowseSearchFields',     form.browse_search_fields);
        sv('formBrowsePageSize',         form.browse_page_size || '20');
        sv('formBrowseDefaultSort',      form.browse_default_sort);
        sc('formBrowseSearchEnabled',    form.browse_search_enabled);

        // rebuild canvas
        _el('formCanvas').innerHTML = '<div class="nb-canvas-empty" id="canvasEmpty" style="display:none;"></div>';
        try {
          var layout = JSON.parse(form.form_layout || '[]');
          if (Array.isArray(layout) && layout.length) {
            layout.forEach(function(f) {
              _addField(f.type||'text', f.label, f.name, !!f.required, f);
            });
          } else {
            _el('formCanvas').innerHTML = '<div class="nb-canvas-empty" id="canvasEmpty">&#x2B06; Drag or click a field type to add it here</div>';
          }
        } catch(e) { console.error('layout parse error', e); }

        _canvasEmpty();
        _el('formBuilderCard').scrollIntoView({ behavior:'smooth' });
      } catch(e) {
        console.error('edit error', e);
        NuApp.toast('Error: ' + e.message, 'error');
      }
    }
  };

})();

// ── backward compat shims so nubuilder-next.js calls still work ──
window.openFormBuilder = function() { nbFormBuilder.open(); };
window.nbCloseBuilder  = function() { nbFormBuilder.close(); };
window.nbSwitchTab     = function(btn) { nbFormBuilder.switchTab(btn); };
window.nbToggleField   = function(h)   { nbFormBuilder.toggleField(h); };
window.addFieldToCanvas = function(type, label, name, req, extra) { nbFormBuilder.addField(type, label, name, req, extra); };
window.canvasEmpty     = function() { nbFormBuilder._canvasEmpty(); };
window.editForm        = function(id) { nbFormBuilder.edit(id); };

// ── Init toolbox/canvas once DOM is ready ─────────────────────
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', function() {
    nbFormBuilder.open && nbFormBuilder._initToolboxOnce && nbFormBuilder._initToolboxOnce();
  });
}
</script>
