<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/core/module_bootstrap.php';

if (!$auth->hasPermission('files.view')) {
    http_response_code(403);
    exit('Access denied');
}

$db    = NuDatabase::getInstance();
$files = $db->fetchAll("SELECT * FROM nu_files ORDER BY file_uploaded_at DESC LIMIT 50");
?>

<div class="nu-files">
    <div class="nu-card">
        <div class="nu-card-header">
            <h3 class="nu-card-title">File Manager</h3>
            <?php if ($auth->hasPermission('files.upload')): ?>
            <form id="uploadForm" style="display:flex;gap:8px;align-items:center;">
                <input type="file" class="nu-input" id="fileInput" style="padding:6px 10px;font-size:13px;">
                <button type="submit" class="nu-btn nu-btn-primary nu-btn-sm">Upload</button>
            </form>
            <?php endif; ?>
        </div>
        <div class="nu-table-wrap">
            <table class="nu-table">
                <thead>
                    <tr><th>File</th><th>Type</th><th>Size</th><th>Uploaded</th><th>Actions</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($files as $f): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($f['file_original_name'] ?? $f['file_name']); ?></strong></td>
                        <td><?php echo htmlspecialchars($f['file_mime_type'] ?? '-'); ?></td>
                        <td><?php echo number_format($f['file_size'] / 1024, 1); ?> KB</td>
                        <td><?php echo date('M j, Y', strtotime($f['file_uploaded_at'])); ?></td>
                        <td>
                            <a href="uploads/<?php echo htmlspecialchars($f['file_name']); ?>" target="_blank" class="nu-btn nu-btn-ghost nu-btn-sm">View</a>
                            <button class="nu-btn nu-btn-danger nu-btn-sm" onclick="deleteFile(<?php echo $f['file_id']; ?>)">Delete</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($files)): ?>
                    <tr><td colspan="5" style="text-align:center;color:var(--text-tertiary);">No files uploaded yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
