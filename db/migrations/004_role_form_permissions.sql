-- ============================================================
-- Migration 004: Dynamic role + per-form permission tables
-- ============================================================

-- 1. Roles table (if not already exists)
CREATE TABLE IF NOT EXISTS `nu_roles` (
    `role_id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `role_code`        VARCHAR(80)     NOT NULL,
    `role_name`        VARCHAR(120)    NOT NULL,
    `role_description` VARCHAR(255)    NOT NULL DEFAULT '',
    `role_is_system`   TINYINT(1)      NOT NULL DEFAULT 0
                           COMMENT '1 = protected system role (e.g. globeadmin), cannot be deleted or renamed',
    PRIMARY KEY (`role_id`),
    UNIQUE KEY `uq_role_code` (`role_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Seed the globeadmin system role (safe to re-run)
INSERT IGNORE INTO `nu_roles` (`role_code`, `role_name`, `role_description`, `role_is_system`)
VALUES ('globeadmin', 'Globe Admin', 'System administrator — full access, cannot be deleted', 1);

-- 3. Add role_is_system column to existing installs that already have nu_roles
--    (ALTER IGNORE is safe; fails silently if column exists in older MySQL;
--     use IF NOT EXISTS syntax for MySQL 8+)
ALTER TABLE `nu_roles`
    ADD COLUMN IF NOT EXISTS `role_is_system` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT '1 = protected system role';

-- Ensure globeadmin is marked as system on existing data
UPDATE `nu_roles` SET `role_is_system` = 1 WHERE `role_code` = 'globeadmin';

-- 4. Per-form permission table
CREATE TABLE IF NOT EXISTS `nu_role_form_permissions` (
    `rfp_id`        INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `rfp_role_code` VARCHAR(80)   NOT NULL
                        COMMENT 'Matches nu_roles.role_code',
    `rfp_form_code` VARCHAR(120)  NOT NULL
                        COMMENT 'Matches nu_forms.form_code, or * for wildcard default',
    `rfp_can_view`   TINYINT(1)   NOT NULL DEFAULT 0,
    `rfp_can_add`    TINYINT(1)   NOT NULL DEFAULT 0,
    `rfp_can_edit`   TINYINT(1)   NOT NULL DEFAULT 0,
    `rfp_can_delete` TINYINT(1)   NOT NULL DEFAULT 0,
    `rfp_can_export` TINYINT(1)   NOT NULL DEFAULT 0,
    PRIMARY KEY (`rfp_id`),
    UNIQUE KEY `uq_role_form` (`rfp_role_code`, `rfp_form_code`),
    KEY `idx_rfp_role` (`rfp_role_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Per-role, per-form CRUD permissions. rfp_form_code=* is the wildcard default for a role.';
