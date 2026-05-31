-- nuBuilder Next - Database Schema Installer
-- Compatible with MySQL 5.7+ / MariaDB 10.3+
-- Last updated: 2026-06-01 — includes all browse, builder, and display-mode columns

-- ─── USERS ───────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS nu_users (
    usr_id INT AUTO_INCREMENT PRIMARY KEY,
    usr_username VARCHAR(50) NOT NULL UNIQUE,
    usr_password VARCHAR(255) NOT NULL,
    usr_email VARCHAR(100),
    usr_role VARCHAR(30) DEFAULT 'user',
    usr_active TINYINT(1) DEFAULT 1,
    usr_2fa_secret VARCHAR(32),
    usr_failed_attempts INT DEFAULT 0,
    usr_last_attempt DATETIME,
    usr_created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    usr_updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ─── ROLES ───────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS nu_roles (
    role_id INT AUTO_INCREMENT PRIMARY KEY,
    role_code VARCHAR(30) NOT NULL UNIQUE,
    role_name VARCHAR(50) NOT NULL,
    role_description TEXT,
    role_active TINYINT(1) DEFAULT 1,
    role_created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ─── PERMISSIONS ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS nu_permissions (
    perm_id INT AUTO_INCREMENT PRIMARY KEY,
    perm_code VARCHAR(50) NOT NULL UNIQUE,
    perm_name VARCHAR(50) NOT NULL,
    perm_category VARCHAR(30),
    perm_description TEXT
) ENGINE=InnoDB;

-- ─── ROLE-PERMISSION MAPPING ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS nu_role_permissions (
    rp_id INT AUTO_INCREMENT PRIMARY KEY,
    rp_role_id INT NOT NULL,
    rp_perm_id INT NOT NULL,
    UNIQUE KEY unique_role_perm (rp_role_id, rp_perm_id),
    FOREIGN KEY (rp_role_id) REFERENCES nu_roles(role_id) ON DELETE CASCADE,
    FOREIGN KEY (rp_perm_id) REFERENCES nu_permissions(perm_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ─── API TOKENS ───────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS nu_api_tokens (
    token_id INT AUTO_INCREMENT PRIMARY KEY,
    token_key VARCHAR(64) NOT NULL UNIQUE,
    token_user_id INT NOT NULL,
    token_name VARCHAR(50),
    token_active TINYINT(1) DEFAULT 1,
    token_expires DATETIME,
    token_last_used DATETIME,
    token_created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (token_user_id) REFERENCES nu_users(usr_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ─── API USAGE (rate limiting) ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS nu_api_usage (
    usage_id INT AUTO_INCREMENT PRIMARY KEY,
    usage_user_id INT NOT NULL,
    usage_hour DATETIME NOT NULL,
    usage_count INT DEFAULT 1,
    UNIQUE KEY unique_user_hour (usage_user_id, usage_hour)
) ENGINE=InnoDB;

-- ─── AUDIT LOG ────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS nu_audit_log (
    audit_id INT AUTO_INCREMENT PRIMARY KEY,
    audit_action VARCHAR(30) NOT NULL,
    audit_table VARCHAR(50) NOT NULL,
    audit_record_id INT,
    audit_old_data JSON,
    audit_new_data JSON,
    audit_user_id INT,
    audit_username VARCHAR(50),
    audit_ip VARCHAR(45),
    audit_user_agent VARCHAR(255),
    audit_timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_action (audit_action),
    INDEX idx_table (audit_table),
    INDEX idx_user (audit_user_id),
    INDEX idx_timestamp (audit_timestamp)
) ENGINE=InnoDB;

-- ─── FILE UPLOADS ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS nu_files (
    file_id INT AUTO_INCREMENT PRIMARY KEY,
    file_name VARCHAR(255) NOT NULL,
    file_original_name VARCHAR(255),
    file_mime_type VARCHAR(100),
    file_size INT,
    file_path VARCHAR(500),
    file_table VARCHAR(50),
    file_record_id INT,
    file_field VARCHAR(50),
    file_uploaded_by INT,
    file_uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_table_record (file_table, file_record_id)
) ENGINE=InnoDB;

-- ─── FORMS (metadata-driven builder) ─────────────────────────────────────────
-- Includes all browse, builder, JS/PHP hooks, and display-mode columns
CREATE TABLE IF NOT EXISTS nu_forms (
    form_id          INT AUTO_INCREMENT PRIMARY KEY,
    form_code        VARCHAR(50)  NOT NULL UNIQUE,
    form_name        VARCHAR(100) NOT NULL,
    form_table       VARCHAR(50),
    form_description TEXT,
    form_layout      JSON,
    form_settings    JSON,
    form_active      TINYINT(1)   DEFAULT 1,

    -- JS / PHP hooks
    form_custom_js      TEXT,
    form_js_before_save TEXT,
    form_js_after_save  TEXT,
    form_custom_php     TEXT,
    form_custom_css     TEXT,

    -- Browse / list view settings
    browse_sql                TEXT,
    browse_columns            VARCHAR(500),
    browse_search_enabled     TINYINT(1)   DEFAULT 0,
    browse_search_placeholder VARCHAR(255),
    browse_search_fields      VARCHAR(500),
    browse_page_size          INT          DEFAULT 20,
    browse_default_sort       VARCHAR(255),
    browse_display_mode       VARCHAR(20)  DEFAULT 'inline',

    form_created_by  INT,
    form_created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    form_updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ─── REPORTS ──────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS nu_reports (
    report_id INT AUTO_INCREMENT PRIMARY KEY,
    report_code VARCHAR(50) NOT NULL UNIQUE,
    report_name VARCHAR(100) NOT NULL,
    report_type ENUM('table','chart','summary') DEFAULT 'table',
    report_sql TEXT,
    report_columns JSON,
    report_filters JSON,
    report_settings JSON,
    report_active TINYINT(1) DEFAULT 1,
    report_created_by INT,
    report_created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    report_updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ─── QUERIES ──────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS nu_queries (
    query_id INT AUTO_INCREMENT PRIMARY KEY,
    query_code VARCHAR(50) NOT NULL UNIQUE,
    query_name VARCHAR(100) NOT NULL,
    query_sql TEXT NOT NULL,
    query_description TEXT,
    query_parameters JSON,
    query_active TINYINT(1) DEFAULT 1,
    query_created_by INT,
    query_created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    query_updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ─── MENU BUILDER ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS nu_menus (
    menu_id INT AUTO_INCREMENT PRIMARY KEY,
    menu_parent_id INT DEFAULT 0,
    menu_code VARCHAR(50) NOT NULL,
    menu_label VARCHAR(100) NOT NULL,
    menu_type ENUM('form','report','query','url','divider') DEFAULT 'form',
    menu_target VARCHAR(100),
    menu_icon VARCHAR(50),
    menu_order INT DEFAULT 0,
    menu_active TINYINT(1) DEFAULT 1,
    menu_role_access JSON,
    menu_created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ─── SEED DATA ────────────────────────────────────────────────────────────────
INSERT INTO nu_roles (role_code, role_name, role_description) VALUES
('globeadmin', 'Global Admin', 'Full system access'),
('admin', 'Admin', 'Administrative access'),
('user', 'User', 'Standard user access'),
('viewer', 'Viewer', 'Read-only access');

INSERT INTO nu_permissions (perm_code, perm_name, perm_category) VALUES
('users.view',    'View Users',           'Users'),
('users.create',  'Create Users',         'Users'),
('users.edit',    'Edit Users',           'Users'),
('users.delete',  'Delete Users',         'Users'),
('roles.view',    'View Roles',           'Roles'),
('roles.manage',  'Manage Roles',         'Roles'),
('forms.view',    'View Forms',           'Forms'),
('forms.build',   'Build Forms',          'Forms'),
('reports.view',  'View Reports',         'Reports'),
('reports.build', 'Build Reports',        'Reports'),
('queries.view',  'View Queries',         'Queries'),
('queries.build', 'Build Queries',        'Queries'),
('audit.view',    'View Audit Trail',     'Audit'),
('files.view',    'View Files',           'Files'),
('files.upload',  'Upload Files',         'Files'),
('api.view',      'View API Tokens',      'API'),
('api.manage',    'Manage API Tokens',    'API'),
('system.config', 'System Configuration', 'System');

INSERT INTO nu_role_permissions (rp_role_id, rp_perm_id)
SELECT r.role_id, p.perm_id FROM nu_roles r CROSS JOIN nu_permissions p
WHERE r.role_code = 'globeadmin';

INSERT INTO nu_role_permissions (rp_role_id, rp_perm_id)
SELECT r.role_id, p.perm_id FROM nu_roles r CROSS JOIN nu_permissions p
WHERE r.role_code = 'admin';

-- Default password: password (change immediately after first login)
INSERT INTO nu_users (usr_username, usr_password, usr_email, usr_role, usr_active) VALUES
('globeadmin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin@nubuilder.local', 'globeadmin', 1);

INSERT INTO nu_menus (menu_parent_id, menu_code, menu_label, menu_type, menu_target, menu_icon, menu_order) VALUES
(0, 'dashboard', 'Dashboard',  'form', 'dashboard', 'layout',    1),
(0, 'forms',     'Forms',      'form', 'forms',     'file-text', 2),
(0, 'reports',   'Reports',    'form', 'reports',   'pie-chart', 3),
(0, 'queries',   'Queries',    'form', 'queries',   'database',  4),
(0, 'users',     'Users',      'form', 'users',     'users',     10),
(0, 'roles',     'Roles',      'form', 'roles',     'shield',    11),
(0, 'audit',     'Audit Trail','form', 'audit',     'clipboard', 12),
(0, 'files',     'Files',      'form', 'files',     'paperclip', 13);

-- ─── MIGRATION: run this block on EXISTING installs to add missing columns ────
-- Safe to run even if columns already exist (uses IF NOT EXISTS style via PROCEDURE)
-- Or just run the ALTER TABLE statements below manually in phpMyAdmin.
--
-- ALTER TABLE nu_forms ADD COLUMN IF NOT EXISTS form_custom_js      TEXT          AFTER form_active;
-- ALTER TABLE nu_forms ADD COLUMN IF NOT EXISTS form_js_before_save TEXT          AFTER form_custom_js;
-- ALTER TABLE nu_forms ADD COLUMN IF NOT EXISTS form_js_after_save  TEXT          AFTER form_js_before_save;
-- ALTER TABLE nu_forms ADD COLUMN IF NOT EXISTS form_custom_php     TEXT          AFTER form_js_after_save;
-- ALTER TABLE nu_forms ADD COLUMN IF NOT EXISTS form_custom_css     TEXT          AFTER form_custom_php;
-- ALTER TABLE nu_forms ADD COLUMN IF NOT EXISTS browse_sql          TEXT          AFTER form_custom_css;
-- ALTER TABLE nu_forms ADD COLUMN IF NOT EXISTS browse_columns      VARCHAR(500)  AFTER browse_sql;
-- ALTER TABLE nu_forms ADD COLUMN IF NOT EXISTS browse_search_enabled     TINYINT(1) DEFAULT 0 AFTER browse_columns;
-- ALTER TABLE nu_forms ADD COLUMN IF NOT EXISTS browse_search_placeholder VARCHAR(255) AFTER browse_search_enabled;
-- ALTER TABLE nu_forms ADD COLUMN IF NOT EXISTS browse_search_fields      VARCHAR(500) AFTER browse_search_placeholder;
-- ALTER TABLE nu_forms ADD COLUMN IF NOT EXISTS browse_page_size    INT DEFAULT 20 AFTER browse_search_fields;
-- ALTER TABLE nu_forms ADD COLUMN IF NOT EXISTS browse_default_sort VARCHAR(255)  AFTER browse_page_size;
-- ALTER TABLE nu_forms ADD COLUMN IF NOT EXISTS browse_display_mode VARCHAR(20) DEFAULT 'inline' AFTER browse_default_sort;
