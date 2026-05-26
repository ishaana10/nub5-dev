<?php
require_once '../config.php';
require_once '../core/Database.php';
require_once '../core/Auth.php';
require_once '../core/Audit.php';

header('Content-Type: application/json');

$auth = new NuAuth();
if (!$auth->checkAuth()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? '';
$db = NuDatabase::getInstance();

switch ($action) {
    case 'start':
        $code = $_GET['code'] ?? '';
        $wf = $db->fetchOne("SELECT * FROM nu_workflows WHERE wf_code = :code", [':code' => $code]);
        if (!$wf) {
            echo json_encode(['success' => false, 'error' => 'Workflow not found']);
            exit;
        }

        $instanceId = $db->insert('nu_workflow_instances', [
            'wfi_wf_id' => $wf['wf_id'],
            'wfi_status' => 'pending',
            'wfi_current_step' => 1,
            'wfi_started_by' => $_SESSION['nu_user_id']
        ]);

        $audit = new NuAudit();
        $audit->log('workflow_start', 'nu_workflow_instances', $instanceId);

        echo json_encode(['success' => true, 'instance_id' => $instanceId]);
        break;

    case 'approve':
        $instanceId = $_GET['id'] ?? 0;
        $decision = $_GET['decision'] ?? '';
        $data = json_decode(file_get_contents('php://input'), true);
        $comment = $data['comment'] ?? '';

        $instance = $db->fetchOne("SELECT * FROM nu_workflow_instances WHERE wfi_id = :id", [':id' => $instanceId]);
        if (!$instance) {
            echo json_encode(['success' => false, 'error' => 'Instance not found']);
            exit;
        }

        // Get current step
        $step = $db->fetchOne("SELECT * FROM nu_workflow_steps WHERE wfs_wf_id = :wf AND wfs_step_order = :order", 
            [':wf' => $instance['wfi_wf_id'], ':order' => $instance['wfi_current_step']]);

        // Record approval
        $db->insert('nu_workflow_approvals', [
            'wfa_wfi_id' => $instanceId,
            'wfa_step_id' => $step['wfs_id'] ?? 0,
            'wfa_approver_id' => $_SESSION['nu_user_id'],
            'wfa_action' => $decision,
            'wfa_comment' => $comment
        ]);

        // Check if more steps
        $nextStep = $db->fetchOne("SELECT * FROM nu_workflow_steps WHERE wfs_wf_id = :wf AND wfs_step_order = :order", 
            [':wf' => instance['wfi_wf_id'], ':order' => $instance['wfi_current_step'] + 1]);

        if ($decision === 'rejected') {
            $db->update('nu_workflow_instances', ['wfi_status' => 'rejected', 'wfi_completed_at' => date('Y-m-d H:i:s')], 'wfi_id = :id', [':id' => $instanceId]);
        } elseif ($nextStep) {
            $db->update('nu_workflow_instances', ['wfi_current_step' => $instance['wfi_current_step'] + 1], 'wfi_id = :id', [':id' => $instanceId]);
        } else {
            $db->update('nu_workflow_instances', ['wfi_status' => 'approved', 'wfi_completed_at' => date('Y-m-d H:i:s')], 'wfi_id = :id', [':id' => $instanceId]);
        }

        $audit = new NuAudit();
        $audit->log('workflow_' . $decision, 'nu_workflow_instances', $instanceId);

        // Send email notification
        require_once '../core/Emailer.php';
        $emailer = new NuEmailer();
        $emailer->notifyWorkflow($instanceId, $decision, $comment);

        echo json_encode(['success' => true]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
}
?>
