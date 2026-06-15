-- =============================================================================
-- install_menus_seed.sql
-- NuBuilder Next — nu_menus table + default sidebar seed
--
-- Safe to run on a fresh install OR an existing database.
--   • CREATE TABLE uses IF NOT EXISTS — no data loss if table already exists.
--   • INSERT uses INSERT IGNORE — skips rows where menu_label+menu_type
--     already exists (unique key), so re-running is harmless.
--
-- Run via:  mysql -u<user> -p <db> < install_menus_seed.sql
-- Or paste into phpMyAdmin / run via migrate.php
-- =============================================================================

-- ---------------------------------------------------------------------------
-- 1. TABLE DEFINITION
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `nu_menus` (
  `menu_id`        INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `menu_label`     VARCHAR(120)     NOT NULL DEFAULT '',
  `menu_type`      ENUM(
                     'form',
                     'report',
                     'query',
                     'url',
                     'group',
                     'divider'
                   )                NOT NULL DEFAULT 'form',
  `menu_target`    VARCHAR(255)     NOT NULL DEFAULT '',
  -- For type=form|report|query  → the object code/slug passed to NuApp.loadModule()
  -- For type=url                → the full URL
  -- For type=group|divider      → ignored (leave blank)

  `menu_parent_id` INT UNSIGNED     NOT NULL DEFAULT 0,
  -- 0 = top-level item.  Points to menu_id of a 'group' row for children.
  -- Max ONE level of nesting (enforced by MenuRenderer + api/menus.php).

  `menu_order`     SMALLINT         NOT NULL DEFAULT 0,
  -- Lower numbers appear first.  Items with equal order sort by menu_id ASC.

  `menu_roles`     VARCHAR(500)     NOT NULL DEFAULT '',
  -- Comma-separated role codes, e.g. 'admin,manager'
  -- EMPTY = visible to ALL authenticated users.
  -- 'globeadmin' and 'admin' always bypass this check regardless.

  `menu_active`    TINYINT(1)       NOT NULL DEFAULT 1,
  -- 0 = hidden from sidebar (but record kept for reference)

  `menu_icon`      VARCHAR(60)      NOT NULL DEFAULT 'default',
  -- Key into NuMenuRenderer::$icons map.
  -- Built-in keys: dashboard, forms, reports, queries, menus, users,
  --                files, workflow, calendar, ai, link, password,
  --                roles, audit, shield, alert, copy, inspector,
  --                divider, group, default

  `created_at`     TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                    ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`menu_id`),

  -- Prevent exact duplicate items being seeded twice on re-run
  UNIQUE KEY `uq_menu_label_type_target` (`menu_label`(80), `menu_type`, `menu_target`(80))

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Sidebar navigation items — managed via Menus module';


-- ---------------------------------------------------------------------------
-- 2. SEED — Main navigation group
-- ---------------------------------------------------------------------------
-- Group header: "Main"
INSERT IGNORE INTO `nu_menus`
  (menu_label, menu_type, menu_target, menu_parent_id, menu_order, menu_roles, menu_active, menu_icon)
VALUES
  ('Main', 'group', '', 0, 10, '', 1, 'group');

-- Grab the group id for use in child rows
SET @main_group = LAST_INSERT_ID();

-- If the INSERT was ignored (row existed), fetch the real id
SELECT @main_group := menu_id
FROM   nu_menus
WHERE  menu_label = 'Main'
  AND  menu_type  = 'group'
  AND  menu_target = ''
LIMIT 1;

-- Children of "Main" group
INSERT IGNORE INTO `nu_menus`
  (menu_label, menu_type, menu_target, menu_parent_id, menu_order, menu_roles, menu_active, menu_icon)
VALUES
  ('Dashboard',    'form', 'dashboard',    @main_group, 10, '', 1, 'dashboard'),
  ('Forms',        'form', 'forms',         @main_group, 20, '', 1, 'forms'),
  ('Reports',      'form', 'reports',       @main_group, 30, '', 1, 'reports'),
  ('Queries',      'form', 'queries',       @main_group, 40, '', 1, 'queries'),
  ('Calendar',     'form', 'calendar',      @main_group, 50, '', 1, 'calendar'),
  ('AI Assistant', 'form', 'ai',            @main_group, 60, '', 1, 'ai'),
  ('Integrations', 'form', 'integrations',  @main_group, 70, '', 1, 'link');


-- ---------------------------------------------------------------------------
-- 3. SEED — Admin Tools group
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO `nu_menus`
  (menu_label, menu_type, menu_target, menu_parent_id, menu_order, menu_roles, menu_active, menu_icon)
VALUES
  ('Admin Tools', 'group', '', 0, 80, 'globeadmin,admin', 1, 'group');

SET @admin_group = LAST_INSERT_ID();

SELECT @admin_group := menu_id
FROM   nu_menus
WHERE  menu_label = 'Admin Tools'
  AND  menu_type  = 'group'
  AND  menu_target = ''
LIMIT 1;

-- Children of "Admin Tools" group
INSERT IGNORE INTO `nu_menus`
  (menu_label,              menu_type, menu_target,      menu_parent_id,  menu_order, menu_roles,           menu_active, menu_icon)
VALUES
  ('Menus',                 'form', 'menus',             @admin_group,    10,  'globeadmin,admin',  1, 'menus'),
  ('Users',                 'form', 'users',             @admin_group,    20,  'globeadmin,admin',  1, 'users'),
  ('Roles',                 'form', 'roles',             @admin_group,    25,  'globeadmin,admin',  1, 'roles'),
  ('Audit Trail',           'form', 'audit',             @admin_group,    28,  'globeadmin,admin',  1, 'audit'),
  ('Files',                 'form', 'files',             @admin_group,    30,  'globeadmin,admin',  1, 'files'),
  ('Workflow',              'form', 'workflow',          @admin_group,    40,  'globeadmin,admin',  1, 'workflow'),
  ('DB & Server Inspector', 'form', 'inspector',         @admin_group,    50,  'globeadmin,admin',  1, 'inspector'),
  ('Error Log',             'form', 'errorlog',          @admin_group,    60,  'globeadmin,admin',  1, 'alert'),
  ('Password Policy',       'form', 'password_policy',   @admin_group,    70,  'globeadmin,admin',  1, 'shield'),
  ('App Cloner',            'form', 'appcloner',         @admin_group,    80,  'globeadmin,admin',  1, 'copy');


-- ---------------------------------------------------------------------------
-- 4. SEED — Personal section (every user)
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO `nu_menus`
  (menu_label, menu_type, menu_target, menu_parent_id, menu_order, menu_roles, menu_active, menu_icon)
VALUES
  ('Personal', 'group', '', 0, 90, '', 1, 'group');

SET @personal_group = LAST_INSERT_ID();

SELECT @personal_group := menu_id
FROM   nu_menus
WHERE  menu_label = 'Personal'
  AND  menu_type  = 'group'
  AND  menu_target = ''
LIMIT 1;

INSERT IGNORE INTO `nu_menus`
  (menu_label,       menu_type, menu_target, menu_parent_id,   menu_order, menu_roles, menu_active, menu_icon)
VALUES
  ('Change Password', 'form',  'password',  @personal_group,  10,  '',  1, 'password');


-- ---------------------------------------------------------------------------
-- 5. VERIFY (optional — comment out in production)
-- ---------------------------------------------------------------------------
-- SELECT menu_id, menu_label, menu_type, menu_target, menu_parent_id,
--        menu_order, menu_roles, menu_active, menu_icon
-- FROM   nu_menus
-- ORDER  BY menu_parent_id ASC, menu_order ASC;

-- =============================================================================
-- END OF FILE
-- =============================================================================
