<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/core/module_bootstrap.php';

$db    = NuDatabase::getInstance();
$menus = $db->fetchAll("SELECT * FROM nu_menus WHERE menu_active = 1 ORDER BY menu_parent_id ASC, menu_order ASC");

$forms = $db->fetchAll("SELECT form_code, form_name, form_type FROM nu_forms WHERE form_active = 1 ORDER BY form_name");

$menuMap = [];
foreach ($menus as $m) {
    $pid = (int)($m['menu_parent_id'] ?? 0);
    $menuMap[$pid][] = $m;
}

// All built-in icon keys from MenuRenderer
$builtinIcons = [
  'dashboard','forms','file-text','reports','pie-chart','queries','database',
  'menus','users','roles','shield','audit','clipboard','files','paperclip',
  'workflow','calendar','ai','link','password','lock','alert','copy',
  'inspector','layout','group','default'
];
?>

<style>
.nb-menu-list { display:flex; flex-direction:column; gap:4px; }

.nb-menu-item {
  display:flex; align-items:center; gap:8px;
  padding:8px 12px; border-radius:8px;
  border:1px solid var(--border-color);
  background:var(--bg-surface);
  transition:border-color .15s, background .15s;
  cursor:default;
}
.nb-menu-item:hover { border-color:color-mix(in oklch,var(--color-primary) 40%,var(--border-color)); background:var(--bg-elevated); }
.nb-menu-item.is-child { margin-left:28px; background:color-mix(in oklch,var(--bg-elevated) 60%,var(--bg-surface)); }
.nb-menu-item.is-divider { opacity:.6; }

.nb-menu-drag-handle { cursor:grab; color:var(--text-tertiary); font-size:15px; padding:0 2px; user-select:none; flex-shrink:0; }
.nb-menu-drag-handle:active { cursor:grabbing; }

.nb-menu-icon-preview {
  width:28px; height:28px; border-radius:6px; flex-shrink:0;
  background:color-mix(in oklch,var(--color-primary) 10%,transparent);
  color:var(--color-primary);
  display:flex; align-items:center; justify-content:center;
  font-size:14px;
}

.nb-menu-label { flex:1; font-size:13px; font-weight:600; color:var(--text-primary); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.nb-menu-label-sub { font-size:11px; color:var(--text-tertiary); font-weight:400; margin-left:4px; }

.nb-menu-type-badge {
  font-size:9px; font-weight:700; letter-spacing:.04em; text-transform:uppercase;
  padding:2px 8px; border-radius:20px; flex-shrink:0;
}
.nb-menu-type-badge.form    { background:color-mix(in oklch,var(--color-primary) 12%,transparent); color:var(--color-primary); }
.nb-menu-type-badge.report  { background:color-mix(in oklch,#10b981 15%,transparent); color:#047857; }
.nb-menu-type-badge.query   { background:color-mix(in oklch,#8b5cf6 15%,transparent); color:#6d28d9; }
.nb-menu-type-badge.url     { background:color-mix(in oklch,#f59e0b 15%,transparent); color:#b45309; }
.nb-menu-type-badge.divider { background:var(--bg-elevated); color:var(--text-tertiary); }
.nb-menu-type-badge.group   { background:color-mix(in oklch,#3b82f6 15%,transparent); color:#1d4ed8; }

.nb-menu-order-badge { font-size:10px; color:var(--text-tertiary); font-weight:600; background:var(--bg-elevated); border:1px solid var(--border-color); padding:1px 6px; border-radius:4px; flex-shrink:0; }

.nb-menu-actions { display:flex; gap:4px; flex-shrink:0; }
.nb-menu-btn { padding:3px 8px; border-radius:5px; font-size:10px; font-weight:500; border:1px solid var(--border-color); background:none; cursor:pointer; color:var(--text-secondary); transition:all .15s; }
.nb-menu-btn:hover { background:var(--bg-elevated); border-color:var(--color-primary); color:var(--color-primary); }
.nb-menu-btn.del:hover { background:#fee; border-color:#e55; color:#c33; }

.nb-menu-item.drag-over { border-color:var(--color-primary); background:color-mix(in oklch,var(--color-primary) 6%,var(--bg-surface)); }

#menuBuilderCard .nb-fp-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:12px; }
#menuBuilderCard .nb-fp { display:flex; flex-direction:column; gap:4px; }
#menuBuilderCard .nb-fp label { font-size:11px; font-weight:600; color:var(--text-secondary); }
#menuBuilderCard .nb-fp-full { grid-column:1/-1; }

.nb-mtype-cards { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:0; }
/* Each card now carries data-type="form" etc for safe JS lookup */
.nb-mtype-card { flex:1; min-width:90px; border:2px solid var(--border-color); border-radius:10px; padding:10px 8px; cursor:pointer; background:var(--bg-surface); transition:all .15s; text-align:center; }
.nb-mtype-card:hover { border-color:var(--color-primary); background:var(--bg-elevated); }
.nb-mtype-card.selected { border-color:var(--color-primary); background:color-mix(in oklch,var(--color-primary) 8%,var(--bg-surface)); }
.nb-mtype-card input[type=radio] { display:none; }
.nb-mtype-icon  { font-size:18px; margin-bottom:4px; }
.nb-mtype-label { font-size:11px; font-weight:700; color:var(--text-primary); }

/* Icon picker tabs */
.nb-icon-tabs { display:flex; gap:0; border:1px solid var(--border-color); border-radius:8px; overflow:hidden; margin-bottom:8px; }
.nb-icon-tab { flex:1; padding:6px 10px; font-size:11px; font-weight:600; background:var(--bg-surface); border:none; cursor:pointer; color:var(--text-secondary); transition:all .15s; }
.nb-icon-tab.active { background:var(--color-primary); color:#fff; }
.nb-icon-tab-pane { display:none; }
.nb-icon-tab-pane.active { display:block; }

/* Built-in icon grid */
.nb-icon-grid {
  display:grid; grid-template-columns:repeat(auto-fill,minmax(72px,1fr)); gap:6px;
  max-height:200px; overflow-y:auto; padding:6px;
  border:1px solid var(--border-color); border-radius:8px;
  background:var(--bg-elevated);
}
.nb-icon-btn {
  display:flex; flex-direction:column; align-items:center; justify-content:center;
  gap:3px; padding:6px 4px; border-radius:6px; border:1.5px solid transparent;
  font-size:11px; cursor:pointer; transition:all .12s;
  background:var(--bg-surface); color:var(--text-secondary);
}
.nb-icon-btn svg { width:18px; height:18px; stroke:currentColor; fill:none; stroke-width:2; flex-shrink:0; }
.nb-icon-btn span { font-size:9px; text-align:center; line-height:1.1; word-break:break-all; }
.nb-icon-btn:hover { border-color:var(--color-primary); background:color-mix(in oklch,var(--color-primary) 8%,var(--bg-surface)); color:var(--color-primary); }
.nb-icon-btn.selected { border-color:var(--color-primary); background:color-mix(in oklch,var(--color-primary) 14%,var(--bg-surface)); color:var(--color-primary); }

/* Live preview */
.nb-menu-preview {
  display:flex; align-items:center; gap:10px;
  padding:10px 14px; border-radius:8px;
  background:var(--bg-elevated);
  border:1px solid var(--border-color);
  margin-bottom:16px;
}
.nb-menu-preview-icon { width:24px; height:24px; color:var(--color-primary); flex-shrink:0; }
.nb-menu-preview-icon svg { width:20px; height:20px; stroke:currentColor; fill:none; stroke-width:2; }
.nb-menu-preview-label { font-size:13px; font-weight:600; color:var(--text-primary); flex:1; }
.nb-menu-preview-badge { font-size:9px; font-weight:700; letter-spacing:.04em; text-transform:uppercase; padding:2px 8px; border-radius:20px; }

/* Group warning */
.nb-group-notice {
  display:none;
  background:color-mix(in oklch,#3b82f6 12%,transparent);
  border:1px solid color-mix(in oklch,#3b82f6 40%,transparent);
  border-radius:8px; padding:10px 14px;
  font-size:12px; color:#1d4ed8;
  margin-bottom:12px; line-height:1.5;
}

.nb-menu-save-bar { display:flex !important; justify-content:flex-end; align-items:center; gap:8px; margin-top:20px; padding-top:16px; border-top:1px solid var(--border-color); }

.nb-menu-empty { text-align:center; padding:56px 24px; color:var(--text-tertiary); font-size:13px; }
.nb-menu-empty svg { margin:0 auto 12px; color:var(--text-tertiary); }
</style>

<div class="nu-menus">

  <!-- ── Menu List ──────────────────────────────────────────────── -->
  <div id="menuListSection">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
      <h3 class="nu-card-title">Menu Builder</h3>
      <?php if ($auth->hasPermission('menus','create')): ?>
      <button class="nu-btn nu-btn-primary" onclick="nuMenuBuilder.open()">+ New Item</button>
      <?php endif; ?>
    </div>

    <div class="nu-card" style="padding:16px;">
      <div class="nb-menu-list" id="menuTree">
        <?php if (empty($menus)): ?>
        <div class="nb-menu-empty">
          <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
            <line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/>
          </svg>
          <p style="margin-bottom:12px;">No menu items yet.</p>
          <button class="nu-btn nu-btn-primary" onclick="nuMenuBuilder.open()">+ New Item</button>
        </div>
        <?php else: ?>
        <?php
        $topItems = $menuMap[0] ?? [];
        foreach ($topItems as $m):
          $mid    = (int)$m['menu_id'];
          $label  = htmlspecialchars($m['menu_label'], ENT_QUOTES);
          $type   = htmlspecialchars($m['menu_type']  ?? 'form', ENT_QUOTES);
          $target = htmlspecialchars($m['menu_target'] ?? '', ENT_QUOTES);
          $icon   = htmlspecialchars($m['menu_icon']   ?? 'default', ENT_QUOTES);
          $order  = (int)$m['menu_order'];
          $isDivider = $type === 'divider';
        ?>
        <div class="nb-menu-item<?= $isDivider ? ' is-divider' : '' ?>" data-id="<?= $mid ?>" draggable="true">
          <span class="nb-menu-drag-handle" title="Drag to reorder">&#9776;</span>
          <div class="nb-menu-icon-preview" title="icon: <?= $icon ?>">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <circle cx="12" cy="12" r="9"/>
            </svg>
          </div>
          <span class="nb-menu-label">
            <?= htmlspecialchars($m['menu_label']) ?>
            <?php if ($target): ?><span class="nb-menu-label-sub">&rarr; <?= $target ?></span><?php endif; ?>
            <?php if ($type === 'group'): ?><span class="nb-menu-label-sub" style="color:#1d4ed8;">(group &mdash; collapsible section)</span><?php endif; ?>
          </span>
          <span class="nb-menu-type-badge <?= $type ?>"><?= ucfirst($type) ?></span>
          <span class="nb-menu-order-badge"><?= $order ?></span>
          <div class="nb-menu-actions">
            <button class="nb-menu-btn" onclick="nuMenuBuilder.addChild(<?= $mid ?>, '<?= $label ?>')">+ Child</button>
            <button class="nb-menu-btn" onclick="nuMenuBuilder.edit(<?= $mid ?>)">&#x270E; Edit</button>
            <button class="nb-menu-btn del" onclick="nuMenuBuilder.del(<?= $mid ?>, '<?= $label ?>')">Delete</button>
          </div>
        </div>
        <?php
        $children = $menuMap[$mid] ?? [];
        foreach ($children as $c):
          $cid    = (int)$c['menu_id'];
          $clabel = htmlspecialchars($c['menu_label'], ENT_QUOTES);
          $ctype  = htmlspecialchars($c['menu_type']  ?? 'form', ENT_QUOTES);
          $ctarget= htmlspecialchars($c['menu_target'] ?? '', ENT_QUOTES);
          $cicon  = htmlspecialchars($c['menu_icon']   ?? 'default', ENT_QUOTES);
          $corder = (int)$c['menu_order'];
        ?>
        <div class="nb-menu-item is-child" data-id="<?= $cid ?>" data-parent="<?= $mid ?>" draggable="true">
          <span class="nb-menu-drag-handle" title="Drag to reorder">&#9776;</span>
          <div class="nb-menu-icon-preview" style="font-size:12px;" title="icon: <?= $cicon ?>">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <circle cx="12" cy="12" r="9"/>
            </svg>
          </div>
          <span class="nb-menu-label">
            <?= htmlspecialchars($c['menu_label']) ?>
            <?php if ($ctarget): ?><span class="nb-menu-label-sub">&rarr; <?= $ctarget ?></span><?php endif; ?>
          </span>
          <span class="nb-menu-type-badge <?= $ctype ?>"><?= ucfirst($ctype) ?></span>
          <span class="nb-menu-order-badge"><?= $corder ?></span>
          <div class="nb-menu-actions">
            <button class="nb-menu-btn" onclick="nuMenuBuilder.edit(<?= $cid ?>)">&#x270E; Edit</button>
            <button class="nb-menu-btn del" onclick="nuMenuBuilder.del(<?= $cid ?>, '<?= $clabel ?>')">Delete</button>
          </div>
        </div>
        <?php endforeach; ?>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>


  <!-- ── Builder Panel ──────────────────────────────────────────── -->
  <div class="nu-card" id="menuBuilderCard" style="display:none;margin-top:24px;">
    <input type="hidden" id="editMenuId" value="">
    <input type="hidden" id="editMenuParentId" value="0">
    <input type="hidden" id="editMenuIcon" value="default">

    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
      <h3 class="nu-card-title" id="menuBuilderTitle">New Menu Item</h3>
      <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="nuMenuBuilder.close()">&#x2715; Cancel</button>
    </div>

    <!-- Live preview -->
    <div class="nb-menu-preview" id="nbMenuPreview">
      <div class="nb-menu-preview-icon" id="nbPreviewIcon">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/></svg>
      </div>
      <span class="nb-menu-preview-label" id="nbPreviewLabel">Label</span>
      <span class="nb-menu-preview-badge" id="nbPreviewBadge" style="background:color-mix(in oklch,var(--color-primary) 12%,transparent);color:var(--color-primary);">Form</span>
      <span style="font-size:11px;color:var(--text-tertiary);margin-left:4px;">&#x2190; live preview</span>
    </div>

    <!-- Group notice -->
    <div class="nb-group-notice" id="nbGroupNotice">
      <strong>&#x1F4C2; Group item</strong> &mdash; A group is a <em>collapsible section header</em> in the sidebar.
      It does <strong>not</strong> navigate to any page. The <strong>Target field is intentionally hidden</strong> &mdash;
      add child items under this group to make them appear inside it.
    </div>

    <div style="margin-bottom:16px;">
      <label class="nu-label" style="margin-bottom:8px;display:block;">Item Type</label>
      <!-- data-type attribute used for safe JS lookup — no querySelector-on-onclick needed -->
      <div class="nb-mtype-cards" id="nbMenuTypeCards">
        <label class="nb-mtype-card selected" data-type="form" onclick="nuMenuBuilder.selectType('form',this)">
          <input type="radio" name="menuItemType" value="form" checked>
          <div class="nb-mtype-icon">&#x229E;</div><div class="nb-mtype-label">Form</div>
        </label>
        <label class="nb-mtype-card" data-type="report" onclick="nuMenuBuilder.selectType('report',this)">
          <input type="radio" name="menuItemType" value="report">
          <div class="nb-mtype-icon">&#x1F4CA;</div><div class="nb-mtype-label">Report</div>
        </label>
        <label class="nb-mtype-card" data-type="query" onclick="nuMenuBuilder.selectType('query',this)">
          <input type="radio" name="menuItemType" value="query">
          <div class="nb-mtype-icon">&#x1F50D;</div><div class="nb-mtype-label">Query</div>
        </label>
        <label class="nb-mtype-card" data-type="url" onclick="nuMenuBuilder.selectType('url',this)">
          <input type="radio" name="menuItemType" value="url">
          <div class="nb-mtype-icon">&#x1F517;</div><div class="nb-mtype-label">URL</div>
        </label>
        <label class="nb-mtype-card" data-type="group" onclick="nuMenuBuilder.selectType('group',this)">
          <input type="radio" name="menuItemType" value="group">
          <div class="nb-mtype-icon">&#x1F4C2;</div><div class="nb-mtype-label">Group</div>
        </label>
        <label class="nb-mtype-card" data-type="divider" onclick="nuMenuBuilder.selectType('divider',this)">
          <input type="radio" name="menuItemType" value="divider">
          <div class="nb-mtype-icon">&mdash;</div><div class="nb-mtype-label">Divider</div>
        </label>
      </div>
    </div>

    <div class="nb-fp-grid">

      <div class="nb-fp">
        <label>Label</label>
        <input type="text" id="menuLabel" class="nu-input" placeholder="e.g. Customers"
               oninput="nuMenuBuilder.updatePreview()">
      </div>

      <div class="nb-fp" id="menuTargetWrap">
        <label id="menuTargetLabel">Form</label>
        <select id="menuTargetSelect" class="nu-input">
          <option value="">-- select --</option>
          <?php foreach ($forms as $f): ?>
          <option value="<?= htmlspecialchars($f['form_code'], ENT_QUOTES) ?>"
                  data-type="<?= htmlspecialchars($f['form_type'] ?? 'form', ENT_QUOTES) ?>">
            <?= htmlspecialchars($f['form_name']) ?> (<?= htmlspecialchars($f['form_code']) ?>)
          </option>
          <?php endforeach; ?>
        </select>
        <input type="text" id="menuTargetUrl"  class="nu-input" placeholder="https://example.com" style="display:none;">
        <input type="text" id="menuTargetCode" class="nu-input" placeholder="query_code" style="display:none;">
      </div>

      <div class="nb-fp">
        <label>Parent Item <span style="font-weight:400;color:var(--text-tertiary);">(0&nbsp;=&nbsp;top level)</span></label>
        <select id="menuParent" class="nu-input">
          <option value="0">-- Top Level --</option>
          <?php
          foreach ($menuMap[0] ?? [] as $pm):
            if (($pm['menu_type'] ?? '') === 'divider') continue;
          ?>
          <option value="<?= (int)$pm['menu_id'] ?>">
            <?= htmlspecialchars($pm['menu_label']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="nb-fp">
        <label>Order</label>
        <input type="number" id="menuOrder" class="nu-input" value="0" min="0">
      </div>

      <div class="nb-fp">
        <label>Role Visibility <span style="font-weight:400;color:var(--text-tertiary);">(blank&nbsp;=&nbsp;all)</span></label>
        <input type="text" id="menuRoles" class="nu-input" placeholder="admin,manager">
      </div>

      <div class="nb-fp">
        <label style="display:flex;align-items:center;gap:6px;">
          <input type="checkbox" id="menuActive" checked> Active
        </label>
      </div>

      <!-- ── Icon Picker ── -->
      <div class="nb-fp nb-fp-full">
        <label>Icon</label>

        <div class="nb-icon-tabs">
          <button type="button" class="nb-icon-tab active" onclick="nuMenuBuilder.switchIconTab('builtin',this)">Built-in Icons</button>
          <button type="button" class="nb-icon-tab" onclick="nuMenuBuilder.switchIconTab('external',this)">External / URL</button>
          <button type="button" class="nb-icon-tab" onclick="nuMenuBuilder.switchIconTab('emoji',this)">Emoji / Custom</button>
        </div>

        <!-- Tab: built-in -->
        <div class="nb-icon-tab-pane active" id="nbIconPaneBuiltin">
          <div class="nb-icon-grid" id="iconGrid">
            <?php
            $iconSvgs = [
              'dashboard'  => '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>',
              'forms'      => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>',
              'file-text'  => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/>',
              'reports'    => '<path d="M21.21 15.89A10 10 0 1 1 8 2.83"/><path d="M22 12A10 10 0 0 0 12 2v10z"/>',
              'pie-chart'  => '<path d="M21.21 15.89A10 10 0 1 1 8 2.83"/><path d="M22 12A10 10 0 0 0 12 2v10z"/>',
              'queries'    => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/>',
              'database'   => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/>',
              'menus'      => '<line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/>',
              'users'      => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/>',
              'roles'      => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
              'shield'     => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/>',
              'audit'      => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>',
              'clipboard'  => '<path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/>',
              'files'      => '<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>',
              'paperclip'  => '<path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/>',
              'workflow'   => '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>',
              'calendar'   => '<rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
              'ai'         => '<path d="M12 2a10 10 0 1 0 10 10H12V2z"/><path d="M12 2a10 10 0 0 1 10 10"/>',
              'link'       => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
              'password'   => '<rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/><circle cx="12" cy="16" r="1" fill="currentColor"/>',
              'lock'       => '<rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
              'alert'      => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>',
              'copy'       => '<rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
              'layout'     => '<rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/>',
              'group'      => '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/>',
              'default'    => '<circle cx="12" cy="12" r="9"/>',
            ];
            foreach ($iconSvgs as $key => $svgBody):
            ?>
            <button type="button" class="nb-icon-btn<?= $key === 'default' ? ' selected' : '' ?>"
                    data-icon="<?= $key ?>"
                    onclick="nuMenuBuilder.selectIcon('<?= $key ?>', this)">
              <svg viewBox="0 0 24 24"><?= $svgBody ?></svg>
              <span><?= $key ?></span>
            </button>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Tab: external URL -->
        <div class="nb-icon-tab-pane" id="nbIconPaneExternal">
          <p style="font-size:11px;color:var(--text-tertiary);margin:0 0 6px;">Paste a URL to any <strong>.svg</strong>, <strong>.png</strong> or <strong>.ico</strong> image. It will be stored as-is and rendered as an &lt;img&gt; in the nav.</p>
          <input type="text" id="menuIconExtUrl" class="nu-input"
                 placeholder="https://cdn.example.com/icons/my-icon.svg"
                 oninput="nuMenuBuilder.setExternalIcon(this.value)">
          <div id="nbExtIconPreview" style="margin-top:8px;display:none;align-items:center;gap:8px;">
            <img id="nbExtIconImg" src="" style="width:28px;height:28px;object-fit:contain;" alt="icon preview">
            <span style="font-size:11px;color:var(--text-secondary);">Preview</span>
          </div>
        </div>

        <!-- Tab: emoji / custom text -->
        <div class="nb-icon-tab-pane" id="nbIconPaneEmoji">
          <p style="font-size:11px;color:var(--text-tertiary);margin:0 0 6px;">Type any emoji or short text. This is stored directly and rendered as the icon character in the nav.</p>
          <input type="text" id="menuIconCustom" class="nu-input" placeholder="e.g. &#x1F4CA; or &#x2605;"
                 oninput="nuMenuBuilder.setCustomIcon(this.value)">
        </div>

      </div><!-- end icon fp-full -->

    </div><!-- end nb-fp-grid -->

    <div class="nb-menu-save-bar">
      <button type="button" class="nu-btn nu-btn-ghost" onclick="nuMenuBuilder.close()">Cancel</button>
      <button type="button" class="nu-btn nu-btn-primary" onclick="nuMenuBuilder.save()">&#x1F4BE; Save Item</button>
    </div>
  </div>

</div>

<script>
if (!window._nbMenusModuleInit) {
  window._nbMenusModuleInit = true;

  var NU_MENUS_API = 'modules/menus/api/menus.php';

  var NB_ICON_SVGS = {
    'dashboard' :'<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>',
    'forms'     :'<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>',
    'file-text' :'<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/>',
    'reports'   :'<path d="M21.21 15.89A10 10 0 1 1 8 2.83"/><path d="M22 12A10 10 0 0 0 12 2v10z"/>',
    'pie-chart' :'<path d="M21.21 15.89A10 10 0 1 1 8 2.83"/><path d="M22 12A10 10 0 0 0 12 2v10z"/>',
    'queries'   :'<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/>',
    'database'  :'<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/>',
    'menus'     :'<line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/>',
    'users'     :'<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/>',
    'roles'     :'<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
    'shield'    :'<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/>',
    'audit'     :'<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>',
    'clipboard' :'<path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/>',
    'files'     :'<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>',
    'paperclip' :'<path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/>',
    'workflow'  :'<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>',
    'calendar'  :'<rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
    'ai'        :'<path d="M12 2a10 10 0 1 0 10 10H12V2z"/><path d="M12 2a10 10 0 0 1 10 10"/>',
    'link'      :'<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
    'password'  :'<rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/><circle cx="12" cy="16" r="1" fill="currentColor"/>',
    'lock'      :'<rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
    'alert'     :'<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>',
    'copy'      :'<rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
    'layout'    :'<rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/>',
    'group'     :'<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/>',
    'default'   :'<circle cx="12" cy="12" r="9"/>'
  };

  var NB_TYPE_COLORS = {
    form:'var(--color-primary)', report:'#047857', query:'#6d28d9',
    url:'#b45309', group:'#1d4ed8', divider:'#999'
  };
  var NB_TYPE_BG = {
    form:'color-mix(in oklch,var(--color-primary) 12%,transparent)',
    report:'color-mix(in oklch,#10b981 15%,transparent)',
    query:'color-mix(in oklch,#8b5cf6 15%,transparent)',
    url:'color-mix(in oklch,#f59e0b 15%,transparent)',
    group:'color-mix(in oklch,#3b82f6 15%,transparent)',
    divider:'var(--bg-elevated)'
  };

  window.nuMenuBuilder = {

    _currentIconMode: 'builtin',

    open: function(parentId, parentLabel) {
      document.getElementById('editMenuId').value       = '';
      document.getElementById('editMenuParentId').value = parentId || 0;
      document.getElementById('menuBuilderTitle').textContent =
        parentId ? ('New Item under \u201c' + (parentLabel || '#' + parentId) + '\u201d') : 'New Menu Item';
      document.getElementById('menuLabel').value    = '';
      document.getElementById('menuOrder').value    = 0;
      document.getElementById('menuRoles').value    = '';
      document.getElementById('menuActive').checked = true;
      document.getElementById('editMenuIcon').value = 'default';
      document.getElementById('menuIconCustom').value = '';
      document.getElementById('menuIconExtUrl').value  = '';
      document.getElementById('menuParent').value   = parentId || 0;
      document.getElementById('menuTargetSelect').value = '';
      document.getElementById('menuTargetUrl').value    = '';
      document.getElementById('menuTargetCode').value   = '';
      this.switchIconTab('builtin');
      this.selectType('form', document.querySelector('.nb-mtype-card[data-type="form"]'));
      document.querySelectorAll('.nb-icon-btn').forEach(function(b){ b.classList.remove('selected'); });
      var defBtn = document.querySelector('.nb-icon-btn[data-icon="default"]');
      if (defBtn) defBtn.classList.add('selected');
      document.getElementById('menuListSection').style.display = 'none';
      document.getElementById('menuBuilderCard').style.display = '';
      this.updatePreview();
    },

    addChild: function(parentId, parentLabel) { this.open(parentId, parentLabel); },

    close: function() {
      document.getElementById('menuBuilderCard').style.display  = 'none';
      document.getElementById('menuListSection').style.display  = '';
    },

    edit: function(id) {
      var self = this;
      fetch(NU_MENUS_API + '?action=get&id=' + id)
        .then(function(r) { return r.json(); })
        .then(function(d) {
          if (!d.success) { alert(d.message || 'Could not load item.'); return; }
          var m    = d.menu;
          var type = m.menu_type || 'form';
          var icon = m.menu_icon || 'default';

          document.getElementById('editMenuId').value       = m.menu_id;
          document.getElementById('editMenuParentId').value = m.menu_parent_id || 0;
          document.getElementById('menuBuilderTitle').textContent = 'Edit: ' + m.menu_label;
          document.getElementById('menuLabel').value    = m.menu_label  || '';
          document.getElementById('menuOrder').value    = m.menu_order  || 0;
          document.getElementById('menuRoles').value    = m.menu_role_access || '';
          document.getElementById('menuActive').checked = (m.menu_active == 1);
          document.getElementById('menuParent').value   = m.menu_parent_id || 0;

          // FIXED: use data-type attribute instead of broken onclick querySelector
          var typeCard = document.querySelector('.nb-mtype-card[data-type="' + type + '"]');
          self.selectType(type, typeCard);

          var target = m.menu_target || '';
          if (type === 'url')        document.getElementById('menuTargetUrl').value  = target;
          else if (type === 'query') document.getElementById('menuTargetCode').value = target;
          else                       document.getElementById('menuTargetSelect').value = target;

          document.getElementById('editMenuIcon').value = icon;
          var isExternal = icon.indexOf('http://') === 0 || icon.indexOf('https://') === 0;
          var isBuiltin  = !isExternal && (icon in NB_ICON_SVGS);
          if (isExternal) {
            self.switchIconTab('external');
            document.getElementById('menuIconExtUrl').value = icon;
            self.setExternalIcon(icon);
          } else if (isBuiltin) {
            self.switchIconTab('builtin');
            document.querySelectorAll('.nb-icon-btn').forEach(function(b){
              b.classList.toggle('selected', b.dataset.icon === icon);
            });
          } else {
            self.switchIconTab('emoji');
            document.getElementById('menuIconCustom').value = icon;
          }

          document.getElementById('menuListSection').style.display = 'none';
          document.getElementById('menuBuilderCard').style.display = '';
          self.updatePreview();
        })
        .catch(function(e) { console.error(e); alert('Network error loading menu item.'); });
    },

    switchIconTab: function(mode) {
      this._currentIconMode = mode;
      document.querySelectorAll('.nb-icon-tab').forEach(function(t){ t.classList.remove('active'); });
      document.querySelectorAll('.nb-icon-tab-pane').forEach(function(p){ p.classList.remove('active'); });
      var paneId = 'nbIconPane' + mode.charAt(0).toUpperCase() + mode.slice(1);
      var pane = document.getElementById(paneId);
      if (pane) pane.classList.add('active');
      var modes = ['builtin','external','emoji'];
      var idx = modes.indexOf(mode);
      var tabs = document.querySelectorAll('.nb-icon-tab');
      if (tabs[idx]) tabs[idx].classList.add('active');
    },

    selectType: function(type, card) {
      document.querySelectorAll('.nb-mtype-card').forEach(function(c){ c.classList.remove('selected'); });
      if (card) card.classList.add('selected');
      var radio = document.querySelector('input[name="menuItemType"][value="' + type + '"]');
      if (radio) radio.checked = true;

      var labelEl  = document.getElementById('menuTargetLabel');
      var selEl    = document.getElementById('menuTargetSelect');
      var urlEl    = document.getElementById('menuTargetUrl');
      var codeEl   = document.getElementById('menuTargetCode');
      var wrapEl   = document.getElementById('menuTargetWrap');
      var noticeEl = document.getElementById('nbGroupNotice');

      selEl.style.display  = 'none';
      urlEl.style.display  = 'none';
      codeEl.style.display = 'none';
      noticeEl.style.display = 'none';

      if (type === 'form' || type === 'report') {
        wrapEl.style.display = '';
        labelEl.textContent  = (type === 'form') ? 'Form' : 'Report';
        selEl.style.display  = '';
      } else if (type === 'query') {
        wrapEl.style.display = '';
        labelEl.textContent  = 'Query Code';
        codeEl.style.display = '';
      } else if (type === 'url') {
        wrapEl.style.display = '';
        labelEl.textContent  = 'URL';
        urlEl.style.display  = '';
      } else {
        wrapEl.style.display = 'none';
        if (type === 'group') noticeEl.style.display = '';
      }
      this.updatePreview();
    },

    selectIcon: function(icon, btn) {
      document.querySelectorAll('.nb-icon-btn').forEach(function(b){ b.classList.remove('selected'); });
      if (btn) btn.classList.add('selected');
      document.getElementById('editMenuIcon').value = icon;
      this.updatePreview();
    },

    setExternalIcon: function(val) {
      document.getElementById('editMenuIcon').value = val || 'default';
      var previewWrap = document.getElementById('nbExtIconPreview');
      var previewImg  = document.getElementById('nbExtIconImg');
      if (val && (val.indexOf('http://') === 0 || val.indexOf('https://') === 0)) {
        previewImg.src  = val;
        previewWrap.style.display = 'flex';
      } else {
        previewWrap.style.display = 'none';
      }
      this.updatePreview();
    },

    setCustomIcon: function(val) {
      document.getElementById('editMenuIcon').value = val || 'default';
      this.updatePreview();
    },

    updatePreview: function() {
      var icon  = document.getElementById('editMenuIcon').value || 'default';
      var label = document.getElementById('menuLabel').value || 'Label';
      var radio = document.querySelector('input[name="menuItemType"]:checked');
      var type  = radio ? radio.value : 'form';

      var iconEl  = document.getElementById('nbPreviewIcon');
      var labelEl = document.getElementById('nbPreviewLabel');
      var badgeEl = document.getElementById('nbPreviewBadge');

      labelEl.textContent = label;
      badgeEl.textContent = type.charAt(0).toUpperCase() + type.slice(1);
      badgeEl.style.background = NB_TYPE_BG[type]    || NB_TYPE_BG.form;
      badgeEl.style.color      = NB_TYPE_COLORS[type] || NB_TYPE_COLORS.form;

      var isExternal = icon.indexOf('http://') === 0 || icon.indexOf('https://') === 0;
      if (isExternal) {
        iconEl.innerHTML = '<img src="' + icon + '" style="width:20px;height:20px;object-fit:contain;">';
      } else if (NB_ICON_SVGS[icon]) {
        iconEl.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">' + NB_ICON_SVGS[icon] + '</svg>';
      } else {
        iconEl.innerHTML = '<span style="font-size:18px;line-height:1;">' + icon + '</span>';
      }
    },

    save: function() {
      var id     = document.getElementById('editMenuId').value.trim();
      var radio  = document.querySelector('input[name="menuItemType"]:checked');
      var type   = radio ? radio.value : 'form';
      var label  = document.getElementById('menuLabel').value.trim();
      var order  = parseInt(document.getElementById('menuOrder').value, 10) || 0;
      var parent = parseInt(document.getElementById('menuParent').value, 10) || 0;
      var roles  = document.getElementById('menuRoles').value.trim();
      var active = document.getElementById('menuActive').checked ? 1 : 0;
      var icon   = document.getElementById('editMenuIcon').value || 'default';

      var target = '';
      if (type === 'url')        target = document.getElementById('menuTargetUrl').value.trim();
      else if (type === 'query') target = document.getElementById('menuTargetCode').value.trim();
      else if (type === 'form' || type === 'report') target = document.getElementById('menuTargetSelect').value;

      if (!label && type !== 'divider') { document.getElementById('menuLabel').focus(); return; }

      var payload = { id:id, type:type, label:label, target:target,
                      parent:parent, order:order, roles:roles, active:active, icon:icon };
      fetch(NU_MENUS_API + '?action=' + (id ? 'update' : 'create'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      })
      .then(function(r){ return r.json(); })
      .then(function(d) {
        if (d.success) {
          if (window.NuApp) NuApp.loadModule('menus'); else location.reload();
        } else {
          alert(d.message || 'Save failed.');
        }
      })
      .catch(function(e){ console.error(e); alert('Network error.'); });
    },

    del: function(id, label) {
      if (!confirm('Delete \u201c' + label + '\u201d? Its child items will also be removed.')) return;
      fetch(NU_MENUS_API + '?action=delete', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: id })
      })
      .then(function(r){ return r.json(); })
      .then(function(d) {
        if (d.success) {
          if (window.NuApp) NuApp.loadModule('menus'); else location.reload();
        } else {
          alert(d.message || 'Delete failed.');
        }
      })
      .catch(function(e){ console.error(e); alert('Network error.'); });
    }
  };

  // ── Drag-to-reorder ────────────────────────────────────────────
  (function() {
    var dragging = null;
    document.querySelectorAll('.nb-menu-item[draggable]').forEach(function(el) {
      el.addEventListener('dragstart', function(e) {
        dragging = el; el.style.opacity = '.4';
        e.dataTransfer.effectAllowed = 'move';
      });
      el.addEventListener('dragend', function() {
        el.style.opacity = '';
        document.querySelectorAll('.nb-menu-item').forEach(function(r){ r.classList.remove('drag-over'); });
        dragging = null;
      });
      el.addEventListener('dragover',  function(e) { e.preventDefault(); if (el !== dragging) el.classList.add('drag-over'); });
      el.addEventListener('dragleave', function()  { el.classList.remove('drag-over'); });
      el.addEventListener('drop', function(e) {
        e.preventDefault(); el.classList.remove('drag-over');
        if (!dragging || dragging === el) return;
        var tree  = document.getElementById('menuTree');
        var nodes = Array.from(tree.children).filter(function(n){ return n.classList.contains('nb-menu-item'); });
        var fi = nodes.indexOf(dragging), ti = nodes.indexOf(el);
        if (fi < 0 || ti < 0) return;
        if (fi < ti) el.after(dragging); else el.before(dragging);
        var ordered = Array.from(tree.querySelectorAll('.nb-menu-item')).map(function(n, i){
          return { id: n.dataset.id, order: i };
        });
        fetch(NU_MENUS_API + '?action=reorder', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ items: ordered })
        }).catch(function(err){ console.warn('reorder:', err); });
      });
    });
  })();

}
</script>
