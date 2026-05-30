<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/core/module_bootstrap.php';

$db        = NuDatabase::getInstance();
$tables    = $db->fetchAll("SHOW TABLES");
$tableList = [];
foreach ($tables as $t) {
    $vals        = array_values($t);
    $tableList[] = $vals[0];
}
?>

<div class="nu-import-export">
    <div class="nu-grid">
        <div class="nu-card">
            <div class="nu-card-header">
                <h3 class="nu-card-title">Export Data</h3>
            </div>
            <div class="nu-modal-body">
                <div class="nu-field">
                    <label>Select Table</label>
                    <select class="nu-input" id="exportTable">
                        <option value="">-- Choose table --</option>
                        <?php foreach ($tableList as $t): ?>
                        <option value="<?php echo htmlspecialchars($t); ?>"><?php echo htmlspecialchars($t); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="nu-field">
                    <label>Format</label>
                    <select class="nu-input" id="exportFormat">
                        <option value="csv">CSV</option>
                        <option value="json">JSON</option>
                    </select>
                </div>
                <button class="nu-btn nu-btn-primary" onclick="exportData()" style="margin-top:8px;">Download Export</button>
            </div>
        </div>

        <div class="nu-card">
            <div class="nu-card-header">
                <h3 class="nu-card-title">Import Data</h3>
            </div>
            <div class="nu-modal-body">
                <div class="nu-field">
                    <label>Select Table</label>
                    <select class="nu-input" id="importTable">
                        <option value="">-- Choose table --</option>
                        <?php foreach ($tableList as $t): ?>
                        <option value="<?php echo htmlspecialchars($t); ?>"><?php echo htmlspecialchars($t); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="nu-field">
                    <label>CSV File</label>
                    <input type="file" class="nu-input" id="importFile" accept=".csv">
                </div>
                <button class="nu-btn nu-btn-primary" onclick="importData()" style="margin-top:8px;">Import CSV</button>
            </div>
        </div>
    </div>
</div>
