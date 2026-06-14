-- ============================================================
-- nuBuilder Next — Customisable Dashboard Widget System
-- Run once.  Safe to re-run (IF NOT EXISTS / INSERT IGNORE).
-- ============================================================

-- ── 1. Widget definitions ─────────────────────────────────────
-- Each row is one widget on a user's (or role's) dashboard.
CREATE TABLE IF NOT EXISTS nu_dashboard_widgets (
    widget_id          INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    widget_user_id     INT UNSIGNED     DEFAULT NULL,   -- NULL = role-level default
    widget_role        VARCHAR(60)      DEFAULT NULL,   -- role this layout belongs to (NULL = personal)
    widget_type        VARCHAR(30)      NOT NULL,       -- stat|chart_bar|chart_pie|chart_line|table|list|progress|custom
    widget_title       VARCHAR(120)     NOT NULL DEFAULT '',
    widget_icon        VARCHAR(60)      DEFAULT NULL,   -- optional icon key
    widget_config      JSON             NOT NULL,       -- type-specific config (see docs below)
    widget_width       TINYINT UNSIGNED NOT NULL DEFAULT 1,  -- 1-4 grid columns
    widget_height      TINYINT UNSIGNED NOT NULL DEFAULT 1,  -- 1-3 row spans
    widget_position    SMALLINT         NOT NULL DEFAULT 0,  -- sort order (drag-reorder updates this)
    widget_active      TINYINT(1)       NOT NULL DEFAULT 1,
    widget_created_at  TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    widget_updated_at  TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (widget_id),
    INDEX idx_user    (widget_user_id, widget_active),
    INDEX idx_role    (widget_role,    widget_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. Layout presets (role-level defaults) ───────────────────
-- Admins design a default layout per role; users may override.
CREATE TABLE IF NOT EXISTS nu_dashboard_layouts (
    layout_id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    layout_role       VARCHAR(60)  NOT NULL,   -- e.g. 'user', 'manager', 'supervisor'
    layout_name       VARCHAR(120) NOT NULL DEFAULT 'Default Layout',
    layout_is_default TINYINT(1)   NOT NULL DEFAULT 1,
    layout_created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (layout_id),
    UNIQUE KEY uq_role (layout_role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3. Seed: example widgets for the 'user' role ─────────────
-- These are role-level defaults (widget_user_id = NULL, widget_role = 'user').
INSERT IGNORE INTO nu_dashboard_widgets
    (widget_user_id, widget_role, widget_type, widget_title, widget_icon, widget_config, widget_width, widget_height, widget_position)
VALUES
-- KPI: available forms
(NULL, 'user', 'stat', 'Available Forms', 'forms',
 '{"source":"query","sql":"SELECT COUNT(*) as value FROM nu_forms WHERE form_active=1","color":"primary"}',
 1, 1, 10),
-- KPI: available reports
(NULL, 'user', 'stat', 'Available Reports', 'reports',
 '{"source":"query","sql":"SELECT COUNT(*) as value FROM nu_reports WHERE report_active=1","color":"success"}',
 1, 1, 20),
-- Quick links list
(NULL, 'user', 'list', 'Quick Links', NULL,
 '{"items":[{"label":"Open Forms","module":"forms"},{"label":"Open Reports","module":"reports"},{"label":"Calendar","module":"calendar"},{"label":"Change Password","module":"password"}]}',
 2, 1, 30),
-- My recent activity table
(NULL, 'user', 'table', 'My Recent Activity', NULL,
 '{"source":"query","sql":"SELECT audit_action AS Action, audit_table AS Area, DATE_FORMAT(audit_timestamp,\'%b %e %l:%i %p\') AS Time FROM nu_audit_log WHERE audit_user_id={{user_id}} ORDER BY audit_timestamp DESC LIMIT 6","columns":["Action","Area","Time"]}',
 4, 2, 40);

-- Seed: example widgets for 'manager' role
INSERT IGNORE INTO nu_dashboard_widgets
    (widget_user_id, widget_role, widget_type, widget_title, widget_icon, widget_config, widget_width, widget_height, widget_position)
VALUES
(NULL, 'manager', 'stat', 'Total Users', 'users',
 '{"source":"query","sql":"SELECT COUNT(*) as value FROM nu_users","color":"primary"}',
 1, 1, 10),
(NULL, 'manager', 'stat', 'Active Forms', 'forms',
 '{"source":"query","sql":"SELECT COUNT(*) as value FROM nu_forms WHERE form_active=1","color":"success"}',
 1, 1, 20),
(NULL, 'manager', 'chart_bar', 'Activity This Week', NULL,
 '{"source":"query","sql":"SELECT DATE(audit_timestamp) AS label, COUNT(*) AS value FROM nu_audit_log WHERE audit_timestamp >= DATE_SUB(CURDATE(),INTERVAL 7 DAY) GROUP BY DATE(audit_timestamp) ORDER BY label"}',
 2, 2, 30),
(NULL, 'manager', 'chart_pie', 'Actions Breakdown', NULL,
 '{"source":"query","sql":"SELECT audit_action AS label, COUNT(*) AS value FROM nu_audit_log WHERE audit_timestamp >= DATE_SUB(CURDATE(),INTERVAL 30 DAY) GROUP BY audit_action ORDER BY value DESC LIMIT 6"}',
 2, 2, 40);

-- Layout presets
INSERT IGNORE INTO nu_dashboard_layouts (layout_role, layout_name) VALUES
('user',       'Default User Layout'),
('manager',    'Manager Layout'),
('supervisor', 'Supervisor Layout'),
('admin',      'Admin Layout');
