-- ============================================================
-- nuBuilder 5 - App Cloner Module Registration
-- ============================================================
-- NOTE: The nub5 sidebar menu is hardcoded in index.php, not
-- driven by nu_menus at runtime. The nav item has already been
-- added directly to index.php (inside the $isAdmin block).
--
-- This SQL file only registers the form record in nu_forms so
-- the module is traceable in the system. No nu_menus row is
-- needed for the sidebar to show the link.
-- ============================================================

-- ─── 1. Register in nu_forms ────────────────────────────────────────────────
INSERT IGNORE INTO `nu_forms` (
    `form_code`,
    `form_name`,
    `form_table`,
    `form_description`,
    `form_layout`,
    `form_settings`,
    `form_active`,
    `form_pk_type`,
    `form_table_mode`
) VALUES (
    'appcloner',
    'App Cloner',
    NULL,
    'Clone or export the nuBuilder 5 application database and files.',
    NULL,
    JSON_OBJECT(
        'type',          'custom',
        'custom_module', 'appcloner'
    ),
    1,
    'autoincrement',
    'existing'
);

-- ─── 2. Optionally register in nu_menus (informational — sidebar is hardcoded)
--        Only needed if you later build a dynamic menu renderer.
INSERT IGNORE INTO `nu_menus` (
    `menu_parent_id`,
    `menu_code`,
    `menu_label`,
    `menu_type`,
    `menu_target`,
    `menu_icon`,
    `menu_order`,
    `menu_active`,
    `menu_role_access`
) VALUES (
    0,
    'appcloner',
    'App Cloner',
    'form',
    'appcloner',
    'copy',
    99,
    1,
    JSON_ARRAY('globeadmin', 'admin')
);

-- ─── Notes ──────────────────────────────────────────────────────────────────
-- The App Cloner nav link is rendered in index.php inside the <?php if ($isAdmin) ?>
-- block under "Admin Tools". It calls NuApp.loadModule('appcloner').
-- Make sure your web server can write to the /temp/ directory.
--
-- To remove:
--   DELETE FROM nu_forms WHERE form_code = 'appcloner';
--   DELETE FROM nu_menus  WHERE menu_code = 'appcloner';
-- Then remove the nav <a> block from index.php.

SELECT 'App Cloner registered. The nav link is live in index.php for globeadmin/admin roles.' AS result;
