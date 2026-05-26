<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once '../../config.php';
require_once '../../core/Database.php';
require_once '../../core/Auth.php';
require_once '../../core/ReportRenderer.php';

$auth = new NuAuth();
if (!$auth->checkAuth()) {
    http_response_code(401);
    echo '<p style="color:red;">Unauthorized</p>';
    exit;
}

$db = NuDatabase::getInstance();
$view = $_GET['view'] ?? 'list';
$reportCode = $_GET['code'] ?? '';
?>

<?php if ($view === 'list'): ?>
<div style="padding:20px;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
        <h2 style="margin:0;font-size:20px;font-weight:600;">Reports</h2>
        <button class="nu-btn nu-btn-primary" onclick="openReportBuilder()">+ New Report</button>
    </div>
    
    <?php 
    try {
        $reports = $db->fetchAll("SELECT * FROM nu_reports WHERE report_active = 1 ORDER BY report_name");
    } catch (Exception $e) {
        echo '<p style="color:red;">Error loading reports: ' . htmlspecialchars($e->getMessage()) . '</p>';
        $reports = [];
    }
    ?>
    
    <div style="background:var(--bg-elevated);border:1px solid var(--border-color);border-radius:12px;overflow:hidden;">
        <table style="width:100%;border-collapse:collapse;">
            <thead>
                <tr style="background:var(--bg-color);border-bottom:1px solid var(--border-color);">
                    <th style="padding:12px 16px;text-align:left;font-size:13px;font-weight:600;">Code</th>
                    <th style="padding:12px 16px;text-align:left;font-size:13px;font-weight:600;">Name</th>
                    <th style="padding:12px 16px;text-align:left;font-size:13px;font-weight:600;">Type</th>
                    <th style="padding:12px 16px;text-align:left;font-size:13px;font-weight:600;">Created</th>
                    <th style="padding:12px 16px;text-align:left;font-size:13px;font-weight:600;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($reports as $r): ?>
                <tr style="border-bottom:1px solid var(--border-color);">
                    <td style="padding:12px 16px;"><code style="background:var(--bg-color);padding:2px 6px;border-radius:4px;font-size:12px;"><?php echo htmlspecialchars($r['report_code']); ?></code></td>
                    <td style="padding:12px 16px;"><?php echo htmlspecialchars($r['report_name']); ?></td>
                    <td style="padding:12px 16px;"><span style="background:var(--success);color:#fff;padding:2px 8px;border-radius:4px;font-size:12px;"><?php echo ucfirst($r['report_type']); ?></span></td>
                    <td style="padding:12px 16px;font-size:13px;color:var(--text-secondary);"><?php echo date('M j, Y', strtotime($r['report_created_at'])); ?></td>
                    <td style="padding:12px 16px;">
                        <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="runReport('<?php echo $r['report_code']; ?>')">Run</button>
                        <button class="nu-btn nu-btn-ghost nu-btn-sm" onclick="editReport('<?php echo $r['report_id']; ?>')">Edit</button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($reports)): ?>
                <tr>
                    <td colspan="5" style="padding:40px;text-align:center;color:var(--text-secondary);">No reports yet. Build your first report.</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Report Builder -->
    <div id="reportBuilderCard" style="display:none;margin-top:24px;background:var(--bg-elevated);border:1px solid var(--border-color);border-radius:12px;padding:20px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
            <h3 style="margin:0;">Report Builder</h3>
            <button class="nu-btn nu-btn-primary nu-btn-sm" onclick="saveReport()">Save Report</button>
        </div>
        <div style="display:flex;flex-direction:column;gap:16px;">
            <div>
                <label style="display:block;font-size:13px;margin-bottom:4px;">Report Name</label>
                <input type="text" class="nu-input" id="reportName" placeholder="Sales Summary" style="width:100%;">
            </div>
            <div>
                <label style="display:block;font-size:13px;margin-bottom:4px;">Report Type</label>
                <select class="nu-input" id="reportType" style="width:100%;">
                    <option value="table">Table</option>
                    <option value="chart">Chart</option>
                    <option value="summary">Summary</option>
                </select>
            </div>
            <div>
                <label style="display:block;font-size:13px;margin-bottom:4px;">SQL Query (SELECT only)</label>
                <textarea class="nu-input" id="reportSql" rows="6" placeholder="SELECT status, COUNT(*) as total FROM orders GROUP BY status" style="width:100%;"></textarea>
            </div>
            <div>
                <label style="display:block;font-size:13px;margin-bottom:4px;">Columns JSON</label>
                <textarea class="nu-input" id="reportColumns" rows="3" placeholder='[{"field":"status","label":"Status"}]' style="width:100%;"></textarea>
            </div>
        </div>
    </div>

    <div id="reportOutput"></div>
</div>
<?php endif; ?>