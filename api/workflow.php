<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/core/Database.php';
require_once dirname(__DIR__) . '/core/Auth.php';
require_once dirname(__DIR__) . '/core/Audit.php';
require_once dirname(__DIR__) . '/core/Workflow.php';

header('Content-Type: application/json');

$auth = NuAuth::getInstance();
if (!$auth->checkAuth()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$db     = NuDatabase::getInstance();
$audit  = new NuAudit();
$engine = new WorkflowEngine();
$userId = (int)($_SESSION['nu_user_id'] ?? 0);
$action = $_GET['action'] ?? '';
$body   = (array)(json_decode((string)file_get_contents('php://input'), true) ?? []);

try {
    switch ($action) {

        // ── LIST workflows ─────────────────────────────────────────────────────
        case 'list':
            $rows = $db->fetchAll(
                'SELECT w.*,
                        (SELECT COUNT(*) FROM nu_workflow_stages WHERE wfs_wf_id = w.wf_id) AS stage_count,
                        (SELECT COUNT(*) FROM nu_workflow_instances WHERE wfi_wf_id = w.wf_id AND wfi_status = "active") AS active_instances
                   FROM nu_workflows w
                  ORDER BY w.wf_updated_at DESC'
            );
            echo json_encode(['success' => true, 'workflows' => $rows]);
            break;

        // ── GET single workflow + its stages + transitions ──────────────────────
        case 'get':
            $id = (int)($_GET['id'] ?? 0);
            $wf = $db->fetchOne('SELECT * FROM nu_workflows WHERE wf_id = :id', [':id' => $id]);
            if (!$wf) { echo json_encode(['success' => false, 'error' => 'Not found']); break; }
            $stages = $db->fetchAll(
                'SELECT * FROM nu_workflow_stages WHERE wfs_wf_id = :id ORDER BY wfs_order ASC, wfs_id ASC',
                [':id' => $id]
            );
            $transitions = $db->fetchAll(
                'SELECT t.*, fs.wfs_name AS from_name, ts.wfs_name AS to_name
                   FROM nu_workflow_transitions t
                   JOIN nu_workflow_stages fs ON fs.wfs_id = t.wft_from_id
                   JOIN nu_workflow_stages ts ON ts.wfs_id = t.wft_to_id
                  WHERE t.wft_wf_id = :id
                  ORDER BY t.wft_id ASC',
                [':id' => $id]
            );
            echo json_encode(['success' => true, 'workflow' => $wf, 'stages' => $stages, 'transitions' => $transitions]);
            break;

        // ── SAVE workflow (create or update) ───────────────────────────────────
        case 'save':
            $name = trim((string)($body['wf_name'] ?? ''));
            if ($name === '') { echo json_encode(['success' => false, 'error' => 'Name required']); break; }
            $code = trim((string)($body['wf_code'] ?? ''));
            if ($code === '') {
                $code = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $name));
            }
            $data = [
                'wf_name'        => $name,
                'wf_code'        => $code,
                'wf_description' => trim((string)($body['wf_description'] ?? '')),
                'wf_form_code'   => trim((string)($body['wf_form_code'] ?? '')) ?: null,
                'wf_active'      => isset($body['wf_active']) ? (int)(bool)$body['wf_active'] : 1,
                'wf_created_by'  => $userId,
            ];
            $wfId = (int)($body['wf_id'] ?? 0);
            if ($wfId > 0) {
                unset($data['wf_created_by']);
                $db->update('nu_workflows', $data, 'wf_id = :id', [':id' => $wfId]);
                $audit->log('workflow_update', 'nu_workflows', $wfId);
                echo json_encode(['success' => true, 'wf_id' => $wfId]);
            } else {
                $wfId = $db->insert('nu_workflows', $data);
                $audit->log('workflow_create', 'nu_workflows', $wfId);
                echo json_encode(['success' => true, 'wf_id' => $wfId]);
            }
            break;

        // ── DELETE workflow ────────────────────────────────────────────────────
        case 'delete':
            $id = (int)($_GET['id'] ?? 0);
            $active = $db->fetchOne(
                'SELECT COUNT(*) AS n FROM nu_workflow_instances WHERE wfi_wf_id = :id AND wfi_status = "active"',
                [':id' => $id]
            );
            if ($active && (int)$active['n'] > 0) {
                echo json_encode(['success' => false, 'error' => 'Cannot delete: workflow has active instances.']);
                break;
            }
            $db->query('DELETE FROM nu_workflows WHERE wf_id = :id', [':id' => $id]);
            $audit->log('workflow_delete', 'nu_workflows', $id);
            echo json_encode(['success' => true]);
            break;

        // ── SAVE stage ─────────────────────────────────────────────────────────
        case 'save_stage':
            $wfId    = (int)($body['wfs_wf_id'] ?? 0);
            $stageId = (int)($body['wfs_id']    ?? 0);
            $name    = trim((string)($body['wfs_name'] ?? ''));
            if (!$wfId || $name === '') { echo json_encode(['success' => false, 'error' => 'wf_id and name required']); break; }
            $code = trim((string)($body['wfs_code'] ?? ''));
            if ($code === '') $code = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $name));
            $data = [
                'wfs_wf_id'       => $wfId,
                'wfs_code'        => $code,
                'wfs_name'        => $name,
                'wfs_description' => trim((string)($body['wfs_description'] ?? '')),
                'wfs_color'       => trim((string)($body['wfs_color'] ?? '#6366f1')),
                'wfs_is_start'    => (int)(bool)($body['wfs_is_start'] ?? 0),
                'wfs_is_end'      => (int)(bool)($body['wfs_is_end']   ?? 0),
                'wfs_order'       => (int)($body['wfs_order'] ?? 0),
                'wfs_sla_hours'   => ($body['wfs_sla_hours'] !== '' && $body['wfs_sla_hours'] !== null) ? (int)$body['wfs_sla_hours'] : null,
                'wfs_role'        => trim((string)($body['wfs_role'] ?? '')) ?: null,
            ];
            if ($stageId > 0) {
                $db->update('nu_workflow_stages', $data, 'wfs_id = :id', [':id' => $stageId]);
                echo json_encode(['success' => true, 'wfs_id' => $stageId]);
            } else {
                $newId = $db->insert('nu_workflow_stages', $data);
                echo json_encode(['success' => true, 'wfs_id' => $newId]);
            }
            break;

        // ── DELETE stage ───────────────────────────────────────────────────────
        case 'delete_stage':
            $id = (int)($_GET['id'] ?? 0);
            $db->query('DELETE FROM nu_workflow_stages WHERE wfs_id = :id', [':id' => $id]);
            echo json_encode(['success' => true]);
            break;

        // ── SAVE transition ────────────────────────────────────────────────────
        case 'save_transition':
            $wfId   = (int)($body['wft_wf_id']   ?? 0);
            $fromId = (int)($body['wft_from_id']  ?? 0);
            $toId   = (int)($body['wft_to_id']    ?? 0);
            $tId    = (int)($body['wft_id']       ?? 0);
            if (!$wfId || !$fromId || !$toId) { echo json_encode(['success' => false, 'error' => 'wf_id, from_id, to_id required']); break; }
            $data = [
                'wft_wf_id'   => $wfId,
                'wft_from_id' => $fromId,
                'wft_to_id'   => $toId,
                'wft_action'  => trim((string)($body['wft_action'] ?? 'advance')),
                'wft_label'   => trim((string)($body['wft_label']  ?? 'Advance')),
            ];
            if ($tId > 0) {
                $db->update('nu_workflow_transitions', $data, 'wft_id = :id', [':id' => $tId]);
                echo json_encode(['success' => true, 'wft_id' => $tId]);
            } else {
                $newId = $db->insert('nu_workflow_transitions', $data);
                echo json_encode(['success' => true, 'wft_id' => $newId]);
            }
            break;

        // ── DELETE transition ──────────────────────────────────────────────────
        case 'delete_transition':
            $id = (int)($_GET['id'] ?? 0);
            $db->query('DELETE FROM nu_workflow_transitions WHERE wft_id = :id', [':id' => $id]);
            echo json_encode(['success' => true]);
            break;

        // ── START instance ─────────────────────────────────────────────────────
        case 'start':
            $wfId     = (int)($body['wf_id'] ?? 0);
            $table    = trim((string)($body['record_table'] ?? '')) ?: null;
            $recordId = trim((string)($body['record_id']    ?? '')) ?: null;
            $meta     = (array)($body['meta'] ?? []);
            if (!$wfId) { echo json_encode(['success' => false, 'error' => 'wf_id required']); break; }
            $instanceId = $engine->start($wfId, $userId, $table, $recordId, $meta);
            $audit->log('workflow_instance_start', 'nu_workflow_instances', $instanceId);
            echo json_encode(['success' => true, 'instance_id' => $instanceId]);
            break;

        // ── ADVANCE instance ───────────────────────────────────────────────────
        case 'advance':
            $instanceId   = (int)($body['instance_id']   ?? 0);
            $transitionId = (int)($body['transition_id'] ?? 0);
            $comment      = trim((string)($body['comment'] ?? ''));
            $engine->advance($instanceId, $transitionId, $userId, $comment);
            $audit->log('workflow_advance', 'nu_workflow_instances', $instanceId);
            echo json_encode(['success' => true]);
            break;

        // ── REJECT instance ────────────────────────────────────────────────────
        case 'reject':
            $instanceId = (int)($body['instance_id'] ?? 0);
            $comment    = trim((string)($body['comment'] ?? ''));
            $engine->reject($instanceId, $userId, $comment);
            $audit->log('workflow_reject', 'nu_workflow_instances', $instanceId);
            echo json_encode(['success' => true]);
            break;

        // ── CANCEL instance ────────────────────────────────────────────────────
        case 'cancel':
            $instanceId = (int)($body['instance_id'] ?? 0);
            $comment    = trim((string)($body['comment'] ?? ''));
            $engine->cancel($instanceId, $userId, $comment);
            $audit->log('workflow_cancel', 'nu_workflow_instances', $instanceId);
            echo json_encode(['success' => true]);
            break;

        // ── LIST instances for a workflow ──────────────────────────────────────
        case 'instances':
            $wfId   = (int)($_GET['wf_id'] ?? 0);
            $status = trim((string)($_GET['status'] ?? ''));
            $sql    = 'SELECT i.*, s.wfs_name AS stage_name, s.wfs_color AS stage_color,
                              u.usr_name AS started_by_name
                         FROM nu_workflow_instances i
                         JOIN nu_workflow_stages s ON s.wfs_id = i.wfi_stage_id
                         LEFT JOIN nu_users u ON u.usr_id = i.wfi_started_by
                        WHERE i.wfi_wf_id = :wfid';
            $params = [':wfid' => $wfId];
            if ($status) { $sql .= ' AND i.wfi_status = :status'; $params[':status'] = $status; }
            $sql .= ' ORDER BY i.wfi_started_at DESC LIMIT 200';
            $rows = $db->fetchAll($sql, $params);
            echo json_encode(['success' => true, 'instances' => $rows]);
            break;

        // ── HISTORY for an instance ────────────────────────────────────────────
        case 'history':
            $instanceId  = (int)($_GET['instance_id'] ?? 0);
            $instance    = $engine->getInstance($instanceId);
            $history     = $engine->getHistory($instanceId);
            $transitions = $engine->getAvailableTransitions($instanceId);
            echo json_encode([
                'success'     => true,
                'instance'    => $instance,
                'history'     => $history,
                'transitions' => $transitions,
            ]);
            break;

        // ── Available transitions for instance ─────────────────────────────────
        case 'transitions':
            $instanceId = (int)($_GET['instance_id'] ?? 0);
            echo json_encode([
                'success'     => true,
                'transitions' => $engine->getAvailableTransitions($instanceId),
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Unknown action: ' . htmlspecialchars($action)]);
    }
} catch (Throwable $e) {
    error_log('[workflow.php] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
