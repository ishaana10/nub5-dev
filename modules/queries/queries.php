<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/core/module_bootstrap.php';

$db      = NuDatabase::getInstance();
$queries = $db->fetchAll("SELECT * FROM nu_queries WHERE query_active = 1 ORDER BY query_id DESC");
?>

<div class="nu-queries">
    <div class="nu-card-header" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
        <h3 class="nu-card-title">Queries</h3>
        <?php if ($auth->hasPermission('queries', 'create')): ?>
        <button class="nu-btn nu-btn-primary" onclick="openQueryBuilder()">+ New Query</button>
        <?php endif; ?>
    </div>
    <div class="nu-grid">
        <?php foreach ($queries as $q): ?>
        <div class="nu-card">
            <div class="nu-card-header">
                <h4 class="nu-card-title"><?php echo htmlspecialchars($q['query_name']); ?></h4>
                <span class="nu-badge"><?php echo htmlspecialchars($q['query_code']); ?></span>
            </div>
            <p style="color:var(--text-secondary);font-size:13px;margin-bottom:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                <?php echo htmlspecialchars(substr($q['query_sql'], 0, 60)) . (strlen($q['query_sql']) > 60 ? '...' : ''); ?>
            </p>
            <div style="display:flex;gap:8px;">
                <button class="nu-btn nu-btn-primary nu-btn-sm" onclick="runQuery(<?php echo $q['query_id']; ?>)">Run</button>
                <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="editQuery(<?php echo $q['query_id']; ?>)">Edit</button>
                <button class="nu-btn nu-btn-danger nu-btn-sm" onclick="deleteQuery(<?php echo $q['query_id']; ?>, '<?php echo htmlspecialchars($q['query_name']); ?>')">Delete</button>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if (empty($queries)): ?>
        <div class="nu-card" style="grid-column:1/-1;text-align:center;padding:40px;">
            <p style="color:var(--text-tertiary);">No queries yet. Click "New Query" to create one.</p>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="nu-card" id="queryBuilderCard" style="display:none;margin-top:24px;">
    <div class="nu-card-header">
        <h3 class="nu-card-title" id="queryBuilderTitle">New Query</h3>
        <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="document.getElementById('queryBuilderCard').style.display='none'">Close</button>
    </div>
    <div class="nu-modal-body">
        <input type="hidden" id="editQueryId" value="">
        <div class="nu-field">
            <label>Query Name</label>
            <input type="text" class="nu-input" id="queryName" placeholder="Monthly Sales Report">
        </div>
        <div class="nu-field">
            <label>SQL Statement</label>
            <textarea class="nu-input" id="querySql" rows="6" placeholder="SELECT * FROM orders WHERE status = 'active' ORDER BY created_at DESC"></textarea>
        </div>
        <div class="nu-field">
            <label>Parameters (JSON)</label>
            <textarea class="nu-input" id="queryParams" rows="2" placeholder='{"status": "active"}'></textarea>
        </div>
        <div class="nu-field">
            <label style="display:flex;align-items:center;gap:8px;">
                <input type="checkbox" id="queryActive" checked> Active
            </label>
        </div>
    </div>
    <div class="nu-modal-footer" style="display:flex;justify-content:flex-end;gap:8px;margin-top:16px;">
        <button class="nu-btn nu-btn-ghost" onclick="document.getElementById('queryBuilderCard').style.display='none'">Cancel</button>
        <button class="nu-btn nu-btn-primary" onclick="saveQuery()">Save Query</button>
    </div>
</div>

<div class="nu-card" id="queryResultsCard" style="display:none;margin-top:24px;">
    <div class="nu-card-header" style="display:flex;justify-content:space-between;align-items:center;">
        <h3 class="nu-card-title">Query Results</h3>
        <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="document.getElementById('queryResultsCard').style.display='none'">Close</button>
    </div>
    <div id="queryResultsContent"></div>
</div>
