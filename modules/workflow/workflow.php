<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/core/module_bootstrap.php';

$db        = NuDatabase::getInstance();
$workflows = $db->fetchAll(
    'SELECT w.*,
            (SELECT COUNT(*) FROM nu_workflow_stages s WHERE s.wfs_wf_id = w.wf_id) AS stage_count,
            (SELECT COUNT(*) FROM nu_workflow_instances i WHERE i.wfi_wf_id = w.wf_id AND i.wfi_status = "active") AS active_instances,
            (SELECT COUNT(*) FROM nu_workflow_instances i WHERE i.wfi_wf_id = w.wf_id) AS total_instances
       FROM nu_workflows w
      ORDER BY w.wf_active DESC, w.wf_updated_at DESC'
);
?>

<div class="nu-workflow-module">

<!-- ══ TOP HEADER ══════════════════════════════════════════════════════════════ -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;flex-wrap:wrap;gap:12px;">
  <div>
    <h2 style="font-size:20px;font-weight:600;margin:0 0 2px;">Workflow Builder</h2>
    <p style="color:var(--text-secondary);font-size:13px;margin:0;">Design approval flows, stage pipelines and automated transitions</p>
  </div>
  <div style="display:flex;gap:8px;align-items:center;">
    <div id="wfSearch" style="position:relative;">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="position:absolute;left:9px;top:50%;transform:translateY(-50%);color:var(--text-tertiary);"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      <input type="text" class="nu-input" id="wfSearchInput" placeholder="Search workflows…" oninput="WF.search(this.value)" style="padding-left:30px;width:200px;font-size:13px;">
    </div>
    <?php if ($auth->hasPermission('workflow', 'create')): ?>
    <button class="nu-btn nu-btn-primary" onclick="WF.openNew()">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-2px;margin-right:4px;"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      New Workflow
    </button>
    <?php endif; ?>
  </div>
</div>

<!-- ══ STATS ROW ═══════════════════════════════════════════════════════════════ -->
<?php
$totalWf      = count($workflows);
$activeWf     = count(array_filter($workflows, fn($w) => $w['wf_active']));
$totalActive  = array_sum(array_column($workflows, 'active_instances'));
$totalRun     = array_sum(array_column($workflows, 'total_instances'));
?>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px;margin-bottom:24px;">
  <?php foreach ([
    ['Workflows',         $totalWf,     '#6366f1', 'M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5'],
    ['Active',            $activeWf,    '#22c55e', 'M22 11.08V12a10 10 0 1 1-5.93-9.14 M22 4L12 14.01l-3-3'],
    ['Running Instances', $totalActive, '#f59e0b', 'M13 10V3L4 14h7v7l9-11h-7z'],
    ['Total Runs',        $totalRun,    '#06b6d4', 'M12 20h9 M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z'],
  ] as [$label, $val, $color, $icon]): ?>
  <div class="nu-card" style="padding:16px;">
    <div style="display:flex;align-items:center;gap:10px;">
      <div style="width:36px;height:36px;border-radius:8px;background:<?= $color ?>18;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="<?= $color ?>" stroke-width="2"><path d="<?= $icon ?>"/></svg>
      </div>
      <div>
        <div style="font-size:22px;font-weight:700;line-height:1;"><?= $val ?></div>
        <div style="font-size:11px;color:var(--text-tertiary);margin-top:2px;"><?= $label ?></div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- ══ WORKFLOW CARDS ══════════════════════════════════════════════════════════ -->
<div id="wfCards" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px;">
  <?php if (empty($workflows)): ?>
  <div class="nu-card" style="grid-column:1/-1;text-align:center;padding:64px 24px;">
    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" style="color:var(--text-tertiary);margin:0 auto 16px;"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
    <p style="font-size:15px;font-weight:500;margin-bottom:4px;">No workflows yet</p>
    <p style="color:var(--text-tertiary);font-size:13px;margin-bottom:20px;">Create your first workflow to start building approval flows and pipelines.</p>
    <button class="nu-btn nu-btn-primary" onclick="WF.openNew()">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-2px;margin-right:4px;"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      Create First Workflow
    </button>
  </div>
  <?php else: ?>
  <?php foreach ($workflows as $wf): ?>
  <div class="nu-card wf-card" data-id="<?= $wf['wf_id'] ?>" data-name="<?= htmlspecialchars(strtolower($wf['wf_name'])) ?>" style="cursor:pointer;transition:box-shadow .15s,transform .15s;" onclick="WF.openDetail(<?= $wf['wf_id'] ?>)" onmouseenter="this.style.boxShadow='0 4px 20px rgba(0,0,0,.15)';this.style.transform='translateY(-1px)'" onmouseleave="this.style.boxShadow='';this.style.transform=''">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:12px;">
      <div style="flex:1;min-width:0;">
        <h4 style="font-size:15px;font-weight:600;margin:0 0 3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($wf['wf_name']) ?></h4>
        <span class="nu-badge nu-badge-secondary" style="font-size:11px;"><?= htmlspecialchars($wf['wf_code']) ?></span>
      </div>
      <span class="nu-badge <?= $wf['wf_active'] ? 'nu-badge-success' : 'nu-badge-muted' ?>" style="margin-left:8px;flex-shrink:0;"><?= $wf['wf_active'] ? 'Active' : 'Inactive' ?></span>
    </div>
    <?php if ($wf['wf_description']): ?>
    <p style="font-size:12px;color:var(--text-secondary);margin-bottom:12px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;"><?= htmlspecialchars($wf['wf_description']) ?></p>
    <?php endif; ?>
    <div style="display:flex;gap:16px;font-size:12px;color:var(--text-tertiary);margin-bottom:14px;">
      <span><b style="color:var(--text-primary);font-weight:600;"><?= $wf['stage_count'] ?></b> stages</span>
      <span><b style="color:var(--color-warning,#f59e0b);font-weight:600;"><?= $wf['active_instances'] ?></b> running</span>
      <span><b style="color:var(--text-secondary);font-weight:600;"><?= $wf['total_instances'] ?></b> total</span>
      <?php if ($wf['wf_form_code']): ?>
      <span>📋 <?= htmlspecialchars($wf['wf_form_code']) ?></span>
      <?php endif; ?>
    </div>
    <div style="display:flex;gap:6px;" onclick="event.stopPropagation();">
      <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="WF.openDetail(<?= $wf['wf_id'] ?>)">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:3px;"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
        Edit
      </button>
      <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="WF.openInstances(<?= $wf['wf_id'] ?>, '<?= htmlspecialchars($wf['wf_name'], ENT_QUOTES) ?>')">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:3px;"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
        Instances
      </button>
      <?php if ($auth->hasPermission('workflow', 'delete')): ?>
      <button class="nu-btn nu-btn-danger nu-btn-sm" onclick="WF.delete(<?= $wf['wf_id'] ?>, '<?= htmlspecialchars($wf['wf_name'], ENT_QUOTES) ?>')">
        Delete
      </button>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════════
     DETAIL DRAWER  (slide in from right)
═══════════════════════════════════════════════════════════════════════════════ -->
<div id="wfDrawerOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:800;" onclick="WF.closeDrawer()"></div>

<div id="wfDrawer" style="
  display:none;position:fixed;top:0;right:0;bottom:0;
  width:min(780px,100vw);background:var(--bg-primary,#fff);
  box-shadow:-4px 0 32px rgba(0,0,0,.2);z-index:801;
  overflow-y:auto;transform:translateX(100%);
  transition:transform .25s cubic-bezier(.16,1,.3,1);
">

  <!-- Drawer header -->
  <div style="position:sticky;top:0;background:var(--bg-primary,#fff);border-bottom:1px solid var(--border-color);z-index:10;padding:16px 20px;display:flex;justify-content:space-between;align-items:center;">
    <div>
      <h3 id="wfDrawerTitle" style="font-size:16px;font-weight:600;margin:0 0 2px;">Workflow</h3>
      <span id="wfDrawerCode" style="font-size:12px;color:var(--text-tertiary);font-family:monospace;"></span>
    </div>
    <div style="display:flex;gap:8px;align-items:center;">
      <button class="nu-btn nu-btn-primary nu-btn-sm" onclick="WF.saveAll()">Save</button>
      <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="WF.closeDrawer()">✕</button>
    </div>
  </div>

  <div style="padding:20px;">

    <!-- ── Workflow meta ────────────────────────────────────────────────────── -->
    <div class="nu-card" style="margin-bottom:20px;">
      <h4 style="font-size:13px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--text-tertiary);margin:0 0 14px;">Workflow Details</h4>
      <input type="hidden" id="wfEditId">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
        <div class="nu-field" style="margin:0;">
          <label>Name <span style="color:var(--color-danger)">*</span></label>
          <input type="text" class="nu-input" id="wfName" placeholder="Leave Approval" oninput="WF.autoCode()">
        </div>
        <div class="nu-field" style="margin:0;">
          <label>Code</label>
          <input type="text" class="nu-input" id="wfCode" placeholder="leave_approval" style="font-family:monospace;font-size:13px;">
        </div>
      </div>
      <div class="nu-field" style="margin-bottom:12px;">
        <label>Description</label>
        <input type="text" class="nu-input" id="wfDescription" placeholder="Optional description…">
      </div>
      <div style="display:grid;grid-template-columns:1fr auto;gap:12px;align-items:end;">
        <div class="nu-field" style="margin:0;">
          <label>Bound Form Code <span style="color:var(--text-tertiary);font-weight:400;">(optional)</span></label>
          <input type="text" class="nu-input" id="wfFormCode" placeholder="leave_form" style="font-family:monospace;font-size:13px;">
        </div>
        <div class="nu-field" style="margin:0;">
          <label>Active</label>
          <div style="padding-top:8px;"><input type="checkbox" id="wfActive" checked style="width:16px;height:16px;"></div>
        </div>
      </div>
    </div>

    <!-- ── Stage Pipeline Visual ─────────────────────────────────────────────── -->
    <div class="nu-card" style="margin-bottom:20px;">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
        <h4 style="font-size:13px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--text-tertiary);margin:0;">Stages</h4>
        <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="WF.addStage()">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:3px;"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Add Stage
        </button>
      </div>

      <!-- Pipeline visual -->
      <div id="wfPipeline" style="display:flex;align-items:center;gap:0;overflow-x:auto;padding-bottom:8px;margin-bottom:16px;min-height:64px;"></div>

      <!-- Stage list -->
      <div id="wfStageList"></div>
    </div>

    <!-- ── Transitions ───────────────────────────────────────────────────────── -->
    <div class="nu-card" style="margin-bottom:20px;">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
        <h4 style="font-size:13px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--text-tertiary);margin:0;">Transitions</h4>
        <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="WF.addTransition()">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:3px;"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Add Transition
        </button>
      </div>
      <div id="wfTransitionList">
        <p style="font-size:13px;color:var(--text-tertiary);text-align:center;padding:16px;">Save the workflow first, then add transitions.</p>
      </div>
    </div>

  </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════════
     INSTANCES PANEL
═══════════════════════════════════════════════════════════════════════════════ -->
<div id="wfInstancesPanel" style="display:none;margin-top:28px;">
  <div class="nu-card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
      <div>
        <h3 class="nu-card-title" id="wfInstancesTitle" style="margin:0;">Instances</h3>
        <span id="wfInstancesMeta" style="font-size:12px;color:var(--text-secondary);"></span>
      </div>
      <div style="display:flex;gap:8px;">
        <select class="nu-input" id="wfInstancesFilter" onchange="WF.filterInstances()" style="font-size:13px;padding:5px 10px;">
          <option value="">All statuses</option>
          <option value="active">Active</option>
          <option value="completed">Completed</option>
          <option value="rejected">Rejected</option>
          <option value="cancelled">Cancelled</option>
        </select>
        <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="document.getElementById('wfInstancesPanel').style.display='none'">Close</button>
      </div>
    </div>
    <div id="wfInstancesContent"></div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════════
     HISTORY TIMELINE MODAL
═══════════════════════════════════════════════════════════════════════════════ -->
<div id="wfHistoryModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:900;align-items:center;justify-content:center;">
  <div style="background:var(--bg-primary,#fff);border-radius:12px;width:min(580px,94vw);max-height:85vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.3);">
    <div style="padding:20px 24px;border-bottom:1px solid var(--border-color);display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;background:var(--bg-primary,#fff);z-index:1;">
      <div>
        <h3 id="wfHistoryTitle" style="font-size:15px;font-weight:600;margin:0 0 2px;">Instance History</h3>
        <span id="wfHistorySub" style="font-size:12px;color:var(--text-tertiary);"></span>
      </div>
      <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="WF.closeHistory()">✕</button>
    </div>
    <!-- current stage badge -->
    <div id="wfHistoryStage" style="padding:12px 24px;border-bottom:1px solid var(--border-color);display:flex;align-items:center;gap:8px;"></div>
    <!-- transition actions -->
    <div id="wfHistoryActions" style="padding:12px 24px;border-bottom:1px solid var(--border-color);display:none;"></div>
    <!-- timeline -->
    <div id="wfHistoryTimeline" style="padding:20px 24px;"></div>
  </div>
</div>

</div><!-- /.nu-workflow-module -->

<style>
.wf-stage-pill {
  display:inline-flex;align-items:center;gap:6px;
  padding:7px 14px;border-radius:99px;
  font-size:12px;font-weight:600;white-space:nowrap;
  border:2px solid transparent;
  transition:box-shadow .12s;
}
.wf-stage-pill.start { border-style:dashed; }
.wf-stage-pill.end   { border-style:solid;  }
.wf-arrow {
  width:32px;height:2px;background:var(--border-color);flex-shrink:0;position:relative;
}
.wf-arrow::after {
  content:'';position:absolute;right:-1px;top:-4px;
  border:5px solid transparent;border-left-color:var(--border-color);
}
.wf-timeline-item { display:flex;gap:14px;padding-bottom:20px;position:relative; }
.wf-timeline-item::before {
  content:'';position:absolute;left:15px;top:28px;bottom:0;width:2px;
  background:var(--border-color);
}
.wf-timeline-item:last-child::before { display:none; }
.wf-timeline-dot {
  width:32px;height:32px;border-radius:50%;display:flex;align-items:center;
  justify-content:center;flex-shrink:0;font-size:13px;
}
</style>

<script>
/* =============================================================================
   WF — Workflow module controller
   All fetch() paths use root-relative api/workflow.php (AJAX-loaded into index.php)
   ============================================================================= */
const WF = (() => {
  const $ = id => document.getElementById(id);
  const esc = s => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');

  function toast(msg, type = 'info') {
    if (typeof nuToast === 'function') { nuToast(msg, type); return; }
    console.log('[WF]', type, msg);
  }

  // ── State ────────────────────────────────────────────────────────────────────
  let _stages      = [];  // stages for current drawer
  let _transitions = [];  // transitions for current drawer
  let _stageCount  = 0;
  let _transCount  = 0;
  let _currentWfId = null;
  let _instancesWfId = null;

  // ── Search ───────────────────────────────────────────────────────────────────
  function search(val) {
    const q = val.toLowerCase();
    document.querySelectorAll('.wf-card').forEach(el => {
      const name = el.dataset.name || '';
      el.style.display = name.includes(q) ? '' : 'none';
    });
  }

  // ── Open blank new workflow ──────────────────────────────────────────────────
  function openNew() {
    _resetDrawer();
    $('wfDrawerTitle').textContent = 'New Workflow';
    $('wfDrawerCode').textContent  = '';
    _showDrawer();
  }

  // ── Open existing workflow detail ────────────────────────────────────────────
  async function openDetail(id) {
    _resetDrawer();
    try {
      const r = await fetch(`api/workflow.php?action=get&id=${id}`);
      const d = await r.json();
      if (!d.success) { toast(d.error, 'error'); return; }

      const wf = d.workflow;
      _currentWfId = parseInt(wf.wf_id);
      $('wfEditId').value       = wf.wf_id;
      $('wfName').value         = wf.wf_name;
      $('wfCode').value         = wf.wf_code;
      $('wfDescription').value  = wf.wf_description || '';
      $('wfFormCode').value     = wf.wf_form_code   || '';
      $('wfActive').checked     = wf.wf_active == 1;
      $('wfDrawerTitle').textContent = wf.wf_name;
      $('wfDrawerCode').textContent  = wf.wf_code;

      _stages      = d.stages      || [];
      _transitions = d.transitions || [];
      _renderStages();
      _renderTransitions();
    } catch (e) {
      toast('Failed to load workflow', 'error');
    }
    _showDrawer();
  }

  // ── Save entire workflow (meta + all pending stage/transition saves) ──────────
  async function saveAll() {
    const name = $('wfName').value.trim();
    if (!name) { toast('Workflow name is required', 'error'); return; }

    const payload = {
      wf_id:          $('wfEditId').value || null,
      wf_name:        name,
      wf_code:        $('wfCode').value.trim() || null,
      wf_description: $('wfDescription').value.trim(),
      wf_form_code:   $('wfFormCode').value.trim() || null,
      wf_active:      $('wfActive').checked ? 1 : 0,
    };

    try {
      const r = await fetch('api/workflow.php?action=save', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });
      const d = await r.json();
      if (!d.success) { toast(d.error, 'error'); return; }
      _currentWfId = d.wf_id;
      $('wfEditId').value = d.wf_id;
      toast('Workflow saved', 'success');
      // Save any unsaved stages
      const unsaved = document.querySelectorAll('.wf-stage-row[data-new="1"]');
      for (const row of unsaved) {
        await _saveStageRow(row, d.wf_id);
      }
      setTimeout(() => location.reload(), 700);
    } catch (e) {
      toast('Save failed: ' + e.message, 'error');
    }
  }

  // ── Delete workflow ──────────────────────────────────────────────────────────
  async function del(id, name) {
    if (!confirm(`Delete workflow "${name}"? This will also remove all stages, transitions, and history.`)) return;
    try {
      const r = await fetch(`api/workflow.php?action=delete&id=${id}`);
      const d = await r.json();
      if (!d.success) { toast(d.error, 'error'); return; }
      toast('Workflow deleted', 'success');
      setTimeout(() => location.reload(), 600);
    } catch (e) {
      toast('Delete failed', 'error');
    }
  }

  // ── Stage management ─────────────────────────────────────────────────────────
  function addStage() {
    const id = 'new_' + (++_stageCount);
    const colors = ['#6366f1','#22c55e','#f59e0b','#06b6d4','#ec4899','#8b5cf6','#14b8a6','#f97316'];
    const color  = colors[(_stageCount - 1) % colors.length];
    const stage  = { wfs_id: id, wfs_name: '', wfs_code: '', wfs_color: color, wfs_is_start: _stages.length === 0 ? 1 : 0, wfs_is_end: 0, wfs_order: _stages.length, wfs_sla_hours: '', wfs_role: '' };
    _stages.push(stage);
    _renderStages();
    setTimeout(() => {
      const inputs = document.querySelectorAll('.wf-stage-row input[data-field="name"]');
      if (inputs.length) inputs[inputs.length - 1].focus();
    }, 50);
  }

  function _renderStages() {
    const pipeline = $('wfPipeline');
    if (_stages.length === 0) {
      pipeline.innerHTML = '<span style="font-size:12px;color:var(--text-tertiary);padding:8px;">No stages yet — click "Add Stage" to start.</span>';
    } else {
      pipeline.innerHTML = _stages.map((s, i) => {
        const isStart = s.wfs_is_start == 1;
        const isEnd   = s.wfs_is_end   == 1;
        const name    = s.wfs_name || 'Stage ' + (i + 1);
        const col     = s.wfs_color || '#6366f1';
        const cls     = isStart ? 'start' : isEnd ? 'end' : '';
        const arrow   = i < _stages.length - 1 ? '<div class="wf-arrow"></div>' : '';
        return `<div class="wf-stage-pill ${cls}" style="background:${col}18;color:${col};border-color:${col}44;" title="${esc(name)}">
          ${isStart ? '▶ ' : isEnd ? '■ ' : ''}${esc(name)}
        </div>${arrow}`;
      }).join('');
    }

    const list = $('wfStageList');
    if (_stages.length === 0) { list.innerHTML = ''; return; }
    list.innerHTML = _stages.map((s, i) => {
      const isNew = String(s.wfs_id).startsWith('new_');
      return `
      <div class="wf-stage-row" data-idx="${i}" data-new="${isNew ? 1 : 0}" data-id="${esc(s.wfs_id)}"
           style="display:grid;grid-template-columns:16px auto 1fr 90px 80px 70px 50px 32px;gap:8px;align-items:center;
                  padding:8px;border-radius:6px;margin-bottom:6px;background:var(--bg-secondary);">
        <span style="color:var(--text-tertiary);cursor:grab;user-select:none;font-size:14px;">⠿</span>
        <input type="color" value="${esc(s.wfs_color)}" data-field="color" onchange="WF._patchStage(${i},'color',this.value)" style="width:28px;height:28px;border:none;border-radius:6px;cursor:pointer;padding:1px;background:none;">
        <input type="text" class="nu-input" value="${esc(s.wfs_name)}" data-field="name" placeholder="Stage name" oninput="WF._patchStage(${i},'name',this.value)" style="font-size:13px;padding:5px 8px;">
        <input type="text" class="nu-input" value="${esc(s.wfs_role || '')}" data-field="role" placeholder="Role" oninput="WF._patchStage(${i},'role',this.value)" style="font-size:12px;padding:5px 8px;" title="Role that acts on this stage">
        <input type="number" class="nu-input" value="${esc(s.wfs_sla_hours || '')}" data-field="sla" placeholder="SLA hrs" oninput="WF._patchStage(${i},'sla',this.value)" style="font-size:12px;padding:5px 8px;" title="SLA in hours">
        <label style="font-size:11px;color:var(--text-tertiary);white-space:nowrap;cursor:pointer;display:flex;align-items:center;gap:3px;">
          <input type="checkbox" ${s.wfs_is_start == 1 ? 'checked' : ''} onchange="WF._patchStage(${i},'is_start',this.checked?1:0)" style="width:13px;height:13px;"> Start
        </label>
        <label style="font-size:11px;color:var(--text-tertiary);white-space:nowrap;cursor:pointer;display:flex;align-items:center;gap:3px;">
          <input type="checkbox" ${s.wfs_is_end == 1 ? 'checked' : ''} onchange="WF._patchStage(${i},'is_end',this.checked?1:0)" style="width:13px;height:13px;"> End
        </label>
        <button class="nu-btn nu-btn-danger nu-btn-sm" onclick="WF._deleteStage(${i})" style="padding:4px 7px;">×</button>
      </div>`;
    }).join('');
  }

  function _patchStage(idx, field, val) {
    if (!_stages[idx]) return;
    const map = { color:'wfs_color', name:'wfs_name', role:'wfs_role', sla:'wfs_sla_hours', is_start:'wfs_is_start', is_end:'wfs_is_end' };
    if (map[field]) _stages[idx][map[field]] = val;
    if (field === 'name') {
      if (!_stages[idx].wfs_code || _stages[idx].wfs_code === '') {
        _stages[idx].wfs_code = val.toLowerCase().replace(/[^a-z0-9]+/g, '_');
      }
    }
    _renderStages();
  }

  async function _deleteStage(idx) {
    const stage = _stages[idx];
    if (!stage) return;
    const isNew = String(stage.wfs_id).startsWith('new_');
    if (!isNew) {
      if (!confirm('Delete stage "' + (stage.wfs_name || 'this stage') + '"? Any transitions using it will also be removed.')) return;
      try {
        const r = await fetch(`api/workflow.php?action=delete_stage&id=${stage.wfs_id}`);
        const d = await r.json();
        if (!d.success) { toast(d.error, 'error'); return; }
      } catch (e) { toast('Delete failed', 'error'); return; }
    }
    _stages.splice(idx, 1);
    _renderStages();
    if (_currentWfId) _reloadTransitions(_currentWfId);
  }

  async function _saveStageRow(row, wfId) {
    const idx    = parseInt(row.dataset.idx);
    const stage  = _stages[idx];
    if (!stage || !stage.wfs_name.trim()) return;
    const payload = {
      wfs_wf_id:      wfId,
      wfs_id:         String(stage.wfs_id).startsWith('new_') ? null : stage.wfs_id,
      wfs_name:       stage.wfs_name,
      wfs_code:       stage.wfs_code || stage.wfs_name.toLowerCase().replace(/[^a-z0-9]+/g,'_'),
      wfs_color:      stage.wfs_color,
      wfs_is_start:   stage.wfs_is_start,
      wfs_is_end:     stage.wfs_is_end,
      wfs_order:      idx,
      wfs_sla_hours:  stage.wfs_sla_hours || null,
      wfs_role:       stage.wfs_role || null,
    };
    try {
      const r = await fetch('api/workflow.php?action=save_stage', {
        method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(payload)
      });
      await r.json();
    } catch (e) { /* best effort */ }
  }

  // ── Transition management ────────────────────────────────────────────────────
  function addTransition() {
    if (!_currentWfId) { toast('Save the workflow first before adding transitions.', 'error'); return; }
    if (_stages.length < 2) { toast('You need at least 2 stages to create a transition.', 'error'); return; }
    const id = 'new_' + (++_transCount);
    _transitions.push({ wft_id: id, wft_wf_id: _currentWfId, wft_from_id: '', wft_to_id: '', wft_action: 'advance', wft_label: 'Advance', from_name: '', to_name: '' });
    _renderTransitions();
  }

  async function _reloadTransitions(wfId) {
    try {
      const r = await fetch(`api/workflow.php?action=get&id=${wfId}`);
      const d = await r.json();
      if (d.success) { _transitions = d.transitions || []; _renderTransitions(); }
    } catch (e) {}
  }

  function _renderTransitions() {
    const list = $('wfTransitionList');
    if (_stages.length < 2) {
      list.innerHTML = '<p style="font-size:13px;color:var(--text-tertiary);text-align:center;padding:16px;">Add at least 2 stages first.</p>';
      return;
    }
    if (_transitions.length === 0) {
      list.innerHTML = '<p style="font-size:13px;color:var(--text-tertiary);text-align:center;padding:16px;">No transitions yet. Click "Add Transition" to connect stages.</p>';
      return;
    }
    const stageOptions = _stages.map(s => `<option value="${esc(s.wfs_id)}">${esc(s.wfs_name || 'Unnamed')}</option>`).join('');
    list.innerHTML = _transitions.map((t, i) => {
      const isNew = String(t.wft_id).startsWith('new_');
      return `
      <div class="wf-trans-row" data-idx="${i}" data-new="${isNew?1:0}" data-id="${esc(t.wft_id)}"
           style="display:grid;grid-template-columns:1fr 80px 1fr 90px 32px;gap:8px;align-items:center;
                  padding:8px;border-radius:6px;margin-bottom:6px;background:var(--bg-secondary);">
        <select class="nu-input" data-field="from" onchange="WF._patchTrans(${i},'from',this.value)" style="font-size:12px;padding:5px 8px;">
          <option value="">From stage…</option>${stageOptions.replace(
            `value="${esc(t.wft_from_id)}"`, `value="${esc(t.wft_from_id)}" selected`)}
        </select>
        <select class="nu-input" data-field="action" onchange="WF._patchTrans(${i},'action',this.value)" style="font-size:12px;padding:5px 8px;">
          <option value="advance"  ${t.wft_action==='advance' ?'selected':''}>→ Advance</option>
          <option value="reject"   ${t.wft_action==='reject'  ?'selected':''}>✕ Reject</option>
          <option value="return"   ${t.wft_action==='return'  ?'selected':''}>↩ Return</option>
          <option value="escalate" ${t.wft_action==='escalate'?'selected':''}>↑ Escalate</option>
        </select>
        <select class="nu-input" data-field="to" onchange="WF._patchTrans(${i},'to',this.value)" style="font-size:12px;padding:5px 8px;">
          <option value="">To stage…</option>${stageOptions.replace(
            `value="${esc(t.wft_to_id)}"`, `value="${esc(t.wft_to_id)}" selected`)}
        </select>
        <input type="text" class="nu-input" value="${esc(t.wft_label)}" data-field="label"
               oninput="WF._patchTrans(${i},'label',this.value)"
               placeholder="Button label" style="font-size:12px;padding:5px 8px;">
        <button class="nu-btn nu-btn-danger nu-btn-sm" onclick="WF._deleteTrans(${i})" style="padding:4px 7px;">×</button>
      </div>`;
    }).join('');
  }

  function _patchTrans(idx, field, val) {
    if (!_transitions[idx]) return;
    const map = { from:'wft_from_id', to:'wft_to_id', action:'wft_action', label:'wft_label' };
    if (map[field]) _transitions[idx][map[field]] = val;
  }

  async function _deleteTrans(idx) {
    const t = _transitions[idx];
    if (!t) return;
    const isNew = String(t.wft_id).startsWith('new_');
    if (!isNew) {
      try {
        const r = await fetch(`api/workflow.php?action=delete_transition&id=${t.wft_id}`);
        const d = await r.json();
        if (!d.success) { toast(d.error, 'error'); return; }
      } catch (e) { toast('Delete failed', 'error'); return; }
    }
    _transitions.splice(idx, 1);
    _renderTransitions();
  }

  // ── Instances panel ──────────────────────────────────────────────────────────
  async function openInstances(wfId, wfName) {
    _instancesWfId = wfId;
    $('wfInstancesTitle').textContent = 'Instances — ' + wfName;
    $('wfInstancesPanel').style.display = 'block';
    $('wfInstancesPanel').scrollIntoView({ behavior: 'smooth', block: 'start' });
    await _loadInstances(wfId, '');
  }

  async function filterInstances() {
    if (!_instancesWfId) return;
    const status = $('wfInstancesFilter').value;
    await _loadInstances(_instancesWfId, status);
  }

  async function _loadInstances(wfId, status) {
    $('wfInstancesContent').innerHTML = '<p style="font-size:13px;color:var(--text-tertiary);padding:12px;">Loading…</p>';
    try {
      const r = await fetch(`api/workflow.php?action=instances&wf_id=${wfId}&status=${encodeURIComponent(status)}`);
      const d = await r.json();
      if (!d.success) { $('wfInstancesContent').innerHTML = `<p style="color:var(--color-danger)">${esc(d.error)}</p>`; return; }
      const rows = d.instances || [];
      $('wfInstancesMeta').textContent = rows.length + ' instance' + (rows.length !== 1 ? 's' : '');
      if (!rows.length) {
        $('wfInstancesContent').innerHTML = '<p style="font-size:13px;color:var(--text-tertiary);padding:12px 0;">No instances found.</p>';
        return;
      }
      const statusColors = { active:'#f59e0b', completed:'#22c55e', rejected:'#ef4444', cancelled:'#6b7280' };
      let html = '<table class="nu-table" style="font-size:13px;"><thead><tr><th>#</th><th>Stage</th><th>Status</th><th>Record</th><th>Started By</th><th>Started</th><th>Actions</th></tr></thead><tbody>';
      rows.forEach(inst => {
        const col = statusColors[inst.wfi_status] || '#888';
        const stageCol = inst.stage_color || '#6366f1';
        const recordInfo = inst.wfi_record_table ? `${esc(inst.wfi_record_table)} #${esc(inst.wfi_record_id)}` : '—';
        html += `<tr>
          <td style="font-weight:600;">#${esc(inst.wfi_id)}</td>
          <td><span style="background:${stageCol}18;color:${stageCol};padding:2px 8px;border-radius:99px;font-size:11px;font-weight:600;">${esc(inst.stage_name)}</span></td>
          <td><span style="background:${col}18;color:${col};padding:2px 8px;border-radius:99px;font-size:11px;font-weight:600;text-transform:capitalize;">${esc(inst.wfi_status)}</span></td>
          <td style="font-size:12px;color:var(--text-secondary);">${recordInfo}</td>
          <td style="font-size:12px;">${esc(inst.started_by_name || '—')}</td>
          <td style="font-size:11px;color:var(--text-tertiary);white-space:nowrap;">${esc((inst.wfi_started_at || '').substring(0,16))}</td>
          <td>
            <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="WF.openHistory(${inst.wfi_id})">Timeline</button>
          </td>
        </tr>`;
      });
      html += '</tbody></table>';
      $('wfInstancesContent').innerHTML = html;
    } catch (e) {
      $('wfInstancesContent').innerHTML = `<p style="color:var(--color-danger);font-size:13px;">${esc(e.message)}</p>`;
    }
  }

  // ── History / Timeline modal ─────────────────────────────────────────────────
  async function openHistory(instanceId) {
    $('wfHistoryModal').style.display = 'flex';
    $('wfHistoryTimeline').innerHTML  = '<p style="font-size:13px;color:var(--text-tertiary);">Loading…</p>';
    $('wfHistoryActions').style.display = 'none';
    $('wfHistoryStage').innerHTML = '';
    try {
      const r = await fetch(`api/workflow.php?action=history&instance_id=${instanceId}`);
      const d = await r.json();
      if (!d.success) { $('wfHistoryTimeline').innerHTML = `<p style="color:var(--color-danger);">${esc(d.error)}</p>`; return; }

      const inst = d.instance;
      $('wfHistoryTitle').textContent = `Instance #${inst.wfi_id} — ${inst.wf_name}`;
      $('wfHistorySub').textContent   = inst.wf_code;

      const stageCol = inst.stage_color || '#6366f1';
      $('wfHistoryStage').innerHTML = `
        <span style="font-size:12px;color:var(--text-tertiary);margin-right:6px;">Current stage:</span>
        <span style="background:${stageCol}18;color:${stageCol};padding:3px 12px;border-radius:99px;font-size:12px;font-weight:600;">${esc(inst.stage_name)}</span>
        <span style="margin-left:8px;font-size:12px;color:var(--text-tertiary);">${esc(inst.wfi_status)}</span>`;

      if (inst.wfi_status === 'active' && d.transitions && d.transitions.length > 0) {
        const actionColors = { advance:'nu-btn-primary', reject:'nu-btn-danger', return:'nu-btn-ghost', escalate:'nu-btn-ghost' };
        let actHtml = `<div style="font-size:12px;color:var(--text-tertiary);margin-bottom:8px;">Available actions:</div>
          <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:8px;">`;
        d.transitions.forEach(t => {
          const cls = actionColors[t.wft_action] || 'nu-btn-ghost';
          actHtml += `<button class="nu-btn ${cls} nu-btn-sm" onclick="WF.doTransition(${instanceId},${t.wft_id},'${esc(t.wft_label)}')">${esc(t.wft_label)}</button>`;
        });
        actHtml += `<button class="nu-btn nu-btn-danger nu-btn-sm" onclick="WF.doReject(${instanceId})">✕ Reject</button>`;
        actHtml += '</div>';
        actHtml += `<div style="display:flex;gap:8px;"><input type="text" class="nu-input" id="wfActionComment" placeholder="Comment (optional)" style="font-size:12px;padding:5px 10px;flex:1;"></div>`;
        $('wfHistoryActions').innerHTML = actHtml;
        $('wfHistoryActions').style.display = 'block';
      }

      const history = d.history || [];
      if (!history.length) {
        $('wfHistoryTimeline').innerHTML = '<p style="font-size:13px;color:var(--text-tertiary);">No history yet.</p>';
        return;
      }
      const actionIcons = { start:'▶', advance:'→', reject:'✕', cancel:'○', return:'↩', escalate:'↑', completed:'✓' };
      const actionColors2 = { start:'#6366f1', advance:'#22c55e', reject:'#ef4444', cancel:'#6b7280', return:'#f59e0b', escalate:'#06b6d4', completed:'#22c55e' };
      let html = '';
      history.forEach(h => {
        const col  = actionColors2[h.wfh_action] || '#888';
        const icon = actionIcons[h.wfh_action]   || '•';
        const time = (h.wfh_acted_at || '').substring(0, 16);
        const from = h.from_stage ? `<span style="color:var(--text-tertiary);">${esc(h.from_stage)}</span> → ` : '';
        html += `
        <div class="wf-timeline-item">
          <div class="wf-timeline-dot" style="background:${col}18;color:${col};">${icon}</div>
          <div style="flex:1;">
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
              <span style="font-size:13px;font-weight:600;text-transform:capitalize;">${esc(h.wfh_action)}</span>
              <span style="font-size:12px;color:var(--text-secondary);">${from}${esc(h.to_stage)}</span>
              <span style="font-size:11px;color:var(--text-tertiary);margin-left:auto;">${time}</span>
            </div>
            <div style="font-size:12px;color:var(--text-secondary);margin-top:2px;">
              By <b>${esc(h.actor_name || 'System')}</b>
              ${h.wfh_comment ? ' — <i>' + esc(h.wfh_comment) + '</i>' : ''}
            </div>
          </div>
        </div>`;
      });
      $('wfHistoryTimeline').innerHTML = html;
    } catch (e) {
      $('wfHistoryTimeline').innerHTML = `<p style="color:var(--color-danger);font-size:13px;">${esc(e.message)}</p>`;
    }
  }

  async function doTransition(instanceId, transitionId, label) {
    const comment = ($('wfActionComment')?.value || '').trim();
    if (!confirm(`Perform "${label}" on instance #${instanceId}?`)) return;
    try {
      const r = await fetch('api/workflow.php?action=advance', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ instance_id: instanceId, transition_id: transitionId, comment })
      });
      const d = await r.json();
      if (!d.success) { toast(d.error, 'error'); return; }
      toast('Transition applied', 'success');
      await openHistory(instanceId);
      if (_instancesWfId) await _loadInstances(_instancesWfId, $('wfInstancesFilter')?.value || '');
    } catch (e) { toast('Action failed', 'error'); }
  }

  async function doReject(instanceId) {
    const comment = ($('wfActionComment')?.value || '').trim();
    if (!confirm(`Reject instance #${instanceId}? This cannot be undone.`)) return;
    try {
      const r = await fetch('api/workflow.php?action=reject', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ instance_id: instanceId, comment })
      });
      const d = await r.json();
      if (!d.success) { toast(d.error, 'error'); return; }
      toast('Instance rejected', 'success');
      await openHistory(instanceId);
      if (_instancesWfId) await _loadInstances(_instancesWfId, $('wfInstancesFilter')?.value || '');
    } catch (e) { toast('Action failed', 'error'); }
  }

  function closeHistory() {
    $('wfHistoryModal').style.display = 'none';
  }

  // ── Drawer helpers ───────────────────────────────────────────────────────────
  function _showDrawer() {
    const overlay = $('wfDrawerOverlay');
    const drawer  = $('wfDrawer');
    overlay.style.display = 'block';
    drawer.style.display  = 'block';
    requestAnimationFrame(() => { drawer.style.transform = 'translateX(0)'; });
    document.body.style.overflow = 'hidden';
  }

  function closeDrawer() {
    const drawer  = $('wfDrawer');
    const overlay = $('wfDrawerOverlay');
    drawer.style.transform = 'translateX(100%)';
    setTimeout(() => {
      drawer.style.display  = 'none';
      overlay.style.display = 'none';
      document.body.style.overflow = '';
    }, 260);
  }

  function _resetDrawer() {
    $('wfEditId').value       = '';
    $('wfName').value         = '';
    $('wfCode').value         = '';
    $('wfDescription').value  = '';
    $('wfFormCode').value     = '';
    $('wfActive').checked     = true;
    _stages      = [];
    _transitions = [];
    _stageCount  = 0;
    _transCount  = 0;
    _currentWfId = null;
    _renderStages();
    _renderTransitions();
  }

  function autoCode() {
    if ($('wfEditId').value) return;
    const name = $('wfName').value;
    $('wfCode').value = name.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '');
    $('wfDrawerTitle').textContent = name || 'New Workflow';
  }

  // ── Public API ───────────────────────────────────────────────────────────────
  return {
    search, openNew, openDetail, saveAll,
    delete: del, closeDrawer, autoCode,
    addStage, _patchStage, _deleteStage, _saveStageRow,
    addTransition, _patchTrans, _deleteTrans,
    openInstances, filterInstances,
    openHistory, doTransition, doReject, closeHistory,
    _renderStages, _renderTransitions,
  };
})();

// Close history modal on overlay click
document.getElementById('wfHistoryModal')?.addEventListener('click', function(e) {
  if (e.target === this) WF.closeHistory();
});
</script>
