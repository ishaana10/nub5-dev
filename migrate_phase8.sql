-- ============================================================
-- migrate_phase8.sql
-- Dynamic Menu: per-view open mode + default view
-- Run once against your existing database.
-- ============================================================

-- 1. Add browse open-mode  (how the Browse/list view opens)
ALTER TABLE `nu_menus`
  ADD COLUMN `menu_browse_mode`  VARCHAR(10) NOT NULL DEFAULT 'inline'
  AFTER `menu_open_mode`;

-- 2. Add preview open-mode (how the Preview/read-only panel opens)
ALTER TABLE `nu_menus`
  ADD COLUMN `menu_preview_mode` VARCHAR(10) NOT NULL DEFAULT 'inline'
  AFTER `menu_browse_mode`;

-- 3. Add default view (which view loads when the user clicks the nav item)
ALTER TABLE `nu_menus`
  ADD COLUMN `menu_default_view` VARCHAR(10) NOT NULL DEFAULT 'browse'
  AFTER `menu_preview_mode`;

-- 4. Backfill existing rows from the old combined menu_open_mode string
--    Old format was  "display|view"  e.g. "inline|browse" or "popup|preview"
UPDATE `nu_menus`
SET
  `menu_browse_mode`  = CASE
      WHEN SUBSTRING_INDEX(`menu_open_mode`, '|', 1) IN ('inline','popup')
      THEN SUBSTRING_INDEX(`menu_open_mode`, '|', 1)
      ELSE 'inline'
    END,
  `menu_preview_mode` = 'inline',
  `menu_default_view` = CASE
      WHEN SUBSTRING_INDEX(`menu_open_mode`, '|', -1) IN ('browse','preview')
      THEN SUBSTRING_INDEX(`menu_open_mode`, '|', -1)
      ELSE 'browse'
    END
WHERE 1=1;

-- 5. Keep menu_open_mode for backwards-compat but mark deprecated
--    (we leave the column; MenuRenderer will use the new columns)
