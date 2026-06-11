<?php
declare(strict_types=1);
/**
 * WorkflowEngine
 * Core engine for nub5-dev workflow module.
 * Handles starting, advancing, rejecting and cancelling workflow instances.
 */
class WorkflowEngine
{
    private NuDatabase $db;

    public function __construct()
    {
        $this->db = NuDatabase::getInstance();
    }

    // ── Start a new workflow instance ──────────────────────────────────────────
    public function start(int $wfId, int $userId, ?string $table = null, ?string $recordId = null, array $meta = []): int
    {
        $startStage = $this->db->fetchOne(
            'SELECT * FROM nu_workflow_stages
              WHERE wfs_wf_id = :wid AND wfs_is_start = 1
              ORDER BY wfs_order ASC LIMIT 1',
            [':wid' => $wfId]
        );
        if (!$startStage) {
            throw new RuntimeException('Workflow has no start stage defined.');
        }

        $instanceId = $this->db->insert('nu_workflow_instances', [
            'wfi_wf_id'        => $wfId,
            'wfi_stage_id'     => $startStage['wfs_id'],
            'wfi_record_table' => $table,
            'wfi_record_id'    => $recordId,
            'wfi_status'       => 'active',
            'wfi_started_by'   => $userId,
            'wfi_meta'         => $meta ? json_encode($meta) : null,
        ]);

        $this->logHistory($instanceId, null, (int)$startStage['wfs_id'], 'start', $userId, 'Workflow started');
        return $instanceId;
    }

    // ── Advance to next stage via a transition ─────────────────────────────────
    public function advance(int $instanceId, int $transitionId, int $userId, string $comment = ''): bool
    {
        $instance = $this->getInstance($instanceId);
        if (!$instance || $instance['wfi_status'] !== 'active') {
            throw new RuntimeException('Instance not found or not active.');
        }

        $transition = $this->db->fetchOne(
            'SELECT * FROM nu_workflow_transitions WHERE wft_id = :tid AND wft_from_id = :from',
            [':tid' => $transitionId, ':from' => $instance['wfi_stage_id']]
        );
        if (!$transition) {
            throw new RuntimeException('Invalid transition for current stage.');
        }

        $toStage = $this->db->fetchOne(
            'SELECT * FROM nu_workflow_stages WHERE wfs_id = :id',
            [':id' => $transition['wft_to_id']]
        );

        $isEnd     = (bool)($toStage['wfs_is_end'] ?? false);
        $newStatus = $isEnd ? 'completed' : 'active';

        $this->db->update(
            'nu_workflow_instances',
            [
                'wfi_stage_id'     => $transition['wft_to_id'],
                'wfi_status'       => $newStatus,
                'wfi_completed_at' => $isEnd ? date('Y-m-d H:i:s') : null,
            ],
            'wfi_id = :id',
            [':id' => $instanceId]
        );

        $this->logHistory($instanceId, (int)$instance['wfi_stage_id'], (int)$transition['wft_to_id'], $transition['wft_action'], $userId, $comment);
        return true;
    }

    // ── Reject / cancel ────────────────────────────────────────────────────────
    public function reject(int $instanceId, int $userId, string $comment = ''): bool
    {
        return $this->terminate($instanceId, 'rejected', $userId, $comment);
    }

    public function cancel(int $instanceId, int $userId, string $comment = ''): bool
    {
        return $this->terminate($instanceId, 'cancelled', $userId, $comment);
    }

    private function terminate(int $instanceId, string $status, int $userId, string $comment): bool
    {
        $instance = $this->getInstance($instanceId);
        if (!$instance || $instance['wfi_status'] !== 'active') {
            throw new RuntimeException('Instance not found or not active.');
        }
        $this->db->update(
            'nu_workflow_instances',
            ['wfi_status' => $status, 'wfi_completed_at' => date('Y-m-d H:i:s')],
            'wfi_id = :id',
            [':id' => $instanceId]
        );
        $this->logHistory($instanceId, (int)$instance['wfi_stage_id'], (int)$instance['wfi_stage_id'], $status, $userId, $comment);
        return true;
    }

    // ── Helpers ────────────────────────────────────────────────────────────────
    public function getInstance(int $instanceId): ?array
    {
        return $this->db->fetchOne(
            'SELECT i.*, w.wf_name, w.wf_code, s.wfs_name AS stage_name, s.wfs_color AS stage_color
               FROM nu_workflow_instances i
               JOIN nu_workflows w ON w.wf_id = i.wfi_wf_id
               JOIN nu_workflow_stages s ON s.wfs_id = i.wfi_stage_id
              WHERE i.wfi_id = :id',
            [':id' => $instanceId]
        ) ?: null;
    }

    public function getHistory(int $instanceId): array
    {
        return $this->db->fetchAll(
            'SELECT h.*, u.usr_name AS actor_name,
                    fs.wfs_name AS from_stage, ts.wfs_name AS to_stage
               FROM nu_workflow_history h
               LEFT JOIN nu_users u  ON u.usr_id  = h.wfh_actor_id
               LEFT JOIN nu_workflow_stages fs ON fs.wfs_id = h.wfh_from_id
               LEFT JOIN nu_workflow_stages ts ON ts.wfs_id = h.wfh_to_id
              WHERE h.wfh_wfi_id = :id
              ORDER BY h.wfh_acted_at ASC',
            [':id' => $instanceId]
        );
    }

    public function getAvailableTransitions(int $instanceId): array
    {
        $instance = $this->getInstance($instanceId);
        if (!$instance) return [];
        return $this->db->fetchAll(
            'SELECT t.*, ts.wfs_name AS to_stage_name, ts.wfs_color AS to_stage_color
               FROM nu_workflow_transitions t
               JOIN nu_workflow_stages ts ON ts.wfs_id = t.wft_to_id
              WHERE t.wft_from_id = :sid
              ORDER BY t.wft_id ASC',
            [':sid' => $instance['wfi_stage_id']]
        );
    }

    private function logHistory(int $instanceId, ?int $fromId, int $toId, string $action, int $userId, string $comment): void
    {
        $this->db->insert('nu_workflow_history', [
            'wfh_wfi_id'   => $instanceId,
            'wfh_from_id'  => $fromId,
            'wfh_to_id'    => $toId,
            'wfh_action'   => $action,
            'wfh_actor_id' => $userId,
            'wfh_comment'  => $comment ?: null,
        ]);
    }
}
