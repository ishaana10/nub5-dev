-- nuBuilder Next - Phase 6 Migration: Password Changer Module
-- Run on EXISTING installs to add password policy & history support.
-- Safe to run multiple times (uses IF NOT EXISTS / ADD COLUMN IF NOT EXISTS).

-- ─── 1. Password policy table ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS nu_password_policy (
    policy_id                        INT         NOT NULL DEFAULT 1,
    policy_min_length                INT         NOT NULL DEFAULT 8,
    policy_require_uppercase         TINYINT(1)  NOT NULL DEFAULT 1,
    policy_require_lowercase          TINYINT(1)  NOT NULL DEFAULT 1,
    policy_require_number            TINYINT(1)  NOT NULL DEFAULT 1,
    policy_require_special           TINYINT(1)  NOT NULL DEFAULT 0,
    policy_disallow_username         TINYINT(1)  NOT NULL DEFAULT 1,
    policy_history_count             INT         NOT NULL DEFAULT 5,
    policy_expiry_days               INT         NOT NULL DEFAULT 0,
    policy_expiry_warning_days       INT         NOT NULL DEFAULT 7,
    policy_force_change_on_first_login TINYINT(1) NOT NULL DEFAULT 1,
    policy_updated_at                DATETIME    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (policy_id)
) ENGINE=InnoDB;

-- Seed default policy row (row 1 is always the active policy)
INSERT IGNORE INTO nu_password_policy (policy_id) VALUES (1);

-- ─── 2. Password history table ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS nu_password_history (
    ph_id          INT AUTO_INCREMENT PRIMARY KEY,
    ph_user_id     INT         NOT NULL,
    ph_hash        VARCHAR(255) NOT NULL,
    ph_created_at  DATETIME    DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ph_user (ph_user_id),
    FOREIGN KEY (ph_user_id) REFERENCES nu_users(usr_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ─── 3. Add columns to nu_users ──────────────────────────────────────────────
ALTER TABLE nu_users
    ADD COLUMN IF NOT EXISTS usr_password_changed_at DATETIME  DEFAULT NULL AFTER usr_last_attempt,
    ADD COLUMN IF NOT EXISTS usr_must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER usr_password_changed_at;

-- ─── 4. Seed password.change permission ──────────────────────────────────────
INSERT IGNORE INTO nu_permissions (perm_code, perm_name, perm_category)
VALUES ('password.change', 'Change Own Password', 'Users');

-- Grant password.change to all roles
INSERT IGNORE INTO nu_role_permissions (rp_role_id, rp_perm_id)
SELECT r.role_id, p.perm_id
FROM nu_roles r CROSS JOIN nu_permissions p
WHERE p.perm_code = 'password.change';

-- ─── 5. Add Password menu entries ───────────────────────────────────────────
INSERT IGNORE INTO nu_menus (menu_parent_id, menu_code, menu_label, menu_type, menu_target, menu_icon, menu_order)
VALUES
    (0, 'password',        'Password',        'form', 'password',        'lock',        9),
    (0, 'password_policy', 'Password Policy', 'form', 'password_policy', 'shield',      20);
