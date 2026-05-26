<?php
require_once '../../config.php';
require_once '../../core/Database.php';
require_once '../../core/Auth.php';

$auth = new NuAuth();
if (!$auth->checkAuth()) exit('Unauthorized');
if (!$auth->hasPermission('roles.view')) exit('Access denied');

$db = NuDatabase::getInstance();
$roles = $db->fetchAll("SELECT * FROM nu_roles ORDER BY role_name");
$permissions = $db->fetchAll("SELECT * FROM nu_permissions ORDER BY perm_category, perm_name");
?>

<div class="nu-roles">
    <div class="nu-card">
        <div class="nu-card-header">
            <h3 class="nu-card-title">Roles & Permissions</h3>
        </div>
        <div class="nu-table-wrap">
            <table class="nu-table">
                <thead>
                    <tr>
                        <th>Role</th>
                        <th>Description</th>
                        <th>Permissions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($roles as $role): 
                        $rolePerms = $db->fetchAll(
                            "SELECT p.perm_name, p.perm_category FROM nu_permissions p
                             JOIN nu_role_permissions rp ON p.perm_id = rp.rp_perm_id
                             WHERE rp.rp_role_id = :rid",
                            [':rid' => $role['role_id']]
                        );
                    ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($role['role_name']); ?></strong></td>
                        <td><?php echo htmlspecialchars($role['role_description'] ?? '-'); ?></td>
                        <td>
                            <?php foreach ($rolePerms as $rp): ?>
                            <span class="nu-status nu-status-active" style="margin:2px;"><?php echo htmlspecialchars($rp['perm_category'] . ': ' . $rp['perm_name']); ?></span>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
