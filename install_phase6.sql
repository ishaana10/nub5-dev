-- ============================================================
-- nub5-dev — Phase 6: Workflow Module
-- Compatible with MySQL 5.7+ / MariaDB 10.3+
-- Safe to run multiple times (CREATE TABLE IF NOT EXISTS)
-- ============================================================

-- ─── 1. WORKFLOW DEFINITIONS ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `nu_workflows` (
    `wf_id`          INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    `wf_code`        VARCHAR(64)    NOT NULL,
    `wf_name`        VARCHAR(128)   NOT NULL,
    `wf_description` TEXT,
    `wf_form_code`   VARCHAR(64)    DEFAULT NULL COMMENT 'optional: bind to a form',
    `wf_active`      TINYINT(1)     NOT NULL DEFAULT 1,
    `wf_created_by`  INT UNSIGNED   DEFAULT NULL,
    `wf_created_at`  DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `wf_updated_at`  DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`wf_id`),
    UNIQUE KEY `uq_wf_code` (`wf_code`),
    KEY `idx_wf_active` (`wf_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── 2. STAGES ────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `nu_workflow_stages` (
    `wfs_id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `wfs_wf_id`       INT UNSIGNED  NOT NULL,
    `wfs_code`        VARCHAR(64)   NOT NULL,
    `wfs_name`        VARCHAR(128)  NOT NULL,
    `wfs_description` TEXT,
    `wfs_color`       VARCHAR(16)   NOT NULL DEFAULT '#6366f1',
    `wfs_is_start`    TINYINT(1)    NOT NULL DEFAULT 0,
    `wfs_is_end`      TINYINT(1)    NOT NULL DEFAULT 0,
    `wfs_order`       SMALLINT      NOT NULL DEFAULT 0,
    `wfs_sla_hours`   SMALLINT      DEFAULT NULL COMMENT 'SLA in hours, NULL = no limit',
    `wfs_role`        VARCHAR(64)   DEFAULT NULL COMMENT 'role that acts on this stage',
    `wfs_created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`wfs_id`),
    KEY `idx_wfs_wf_id` (`wfs_wf_id`),
    KEY `idx_wfs_order` (`wfs_wf_id`, `wfs_order`),
    CONSTRAINT `fk_wfs_wf` FOREIGN KEY (`wfs_wf_id`) REFERENCES `nu_workflows` (`wf_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── 3. TRANSITIONS ───────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `nu_workflow_transitions` (
    `wft_id`        INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `wft_wf_id`     INT UNSIGNED  NOT NULL,
    `wft_from_id`   INT UNSIGNED  NOT NULL,
    `wft_to_id`     INT UNSIGNED  NOT NULL,
    `wft_action`    VARCHAR(64)   NOT NULL DEFAULT 'advance' COMMENT 'advance|reject|escalate|return',
    `wft_label`     VARCHAR(128)  NOT NULL DEFAULT 'Advance',
    `wft_condition` TEXT          DEFAULT NULL COMMENT 'optional JSON condition',
    `wft_created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`wft_id`),
    KEY `idx_wft_wf`   (`wft_wf_id`),
    KEY `idx_wft_from` (`wft_from_id`),
    CONSTRAINT `fk_wft_wf`   FOREIGN KEY (`wft_wf_id`)   REFERENCES `nu_workflows`       (`wf_id`)  ON DELETE CASCADE,
    CONSTRAINT `fk_wft_from` FOREIGN KEY (`wft_from_id`) REFERENCES `nu_workflow_stages` (`wfs_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_wft_to`   FOREIGN KEY (`wft_to_id`)   REFERENCES `nu_workflow_stages` (`wfs_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── 4. INSTANCES (running workflow per record) ────────────────────────────────
CREATE TABLE IF NOT EXISTS `nu_workflow_instances` (
    `wfi_id`           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `wfi_wf_id`        INT UNSIGNED  NOT NULL,
    `wfi_stage_id`     INT UNSIGNED  NOT NULL COMMENT 'current stage',
    `wfi_record_table` VARCHAR(64)   DEFAULT NULL,
    `wfi_record_id`    VARCHAR(64)   DEFAULT NULL,
    `wfi_status`       ENUM('active','completed','rejected','cancelled') NOT NULL DEFAULT 'active',
    `wfi_started_by`   INT UNSIGNED  DEFAULT NULL,
    `wfi_started_at`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `wfi_completed_at` DATETIME      DEFAULT NULL,
    `wfi_meta`         JSON          DEFAULT NULL,
    PRIMARY KEY (`wfi_id`),
    KEY `idx_wfi_wf`     (`wfi_wf_id`),
    KEY `idx_wfi_stage`  (`wfi_stage_id`),
    KEY `idx_wfi_status` (`wfi_status`),
    KEY `idx_wfi_record` (`wfi_record_table`, `wfi_record_id`),
    CONSTRAINT `fk_wfi_wf`    FOREIGN KEY (`wfi_wf_id`)    REFERENCES `nu_workflows`       (`wf_id`)  ON DELETE CASCADE,
    CONSTRAINT `fk_wfi_stage` FOREIGN KEY (`wfi_stage_id`) REFERENCES `nu_workflow_stages` (`wfs_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── 5. HISTORY / AUDIT LOG ───────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `nu_workflow_history` (
    `wfh_id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `wfh_wfi_id`     INT UNSIGNED  NOT NULL,
    `wfh_from_id`    INT UNSIGNED  DEFAULT NULL,
    `wfh_to_id`      INT UNSIGNED  NOT NULL,
    `wfh_action`     VARCHAR(64)   NOT NULL,
    `wfh_actor_id`   INT UNSIGNED  DEFAULT NULL,
    `wfh_comment`    TEXT          DEFAULT NULL,
    `wfh_acted_at`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`wfh_id`),
    KEY `idx_wfh_wfi`   (`wfh_wfi_id`),
    KEY `idx_wfh_actor` (`wfh_actor_id`),
    CONSTRAINT `fk_wfh_wfi` FOREIGN KEY (`wfh_wfi_id`) REFERENCES `nu_workflow_instances` (`wfi_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 'Phase 6 — Workflow module schema installed.' AS status;
