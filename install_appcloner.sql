-- ============================================================
-- nuBuilder 5 - App Cloner Module Registration
-- Run this once in phpMyAdmin after uploading the files.
-- ============================================================

-- Register the module as a menu item / form in zzzzsys_form
INSERT IGNORE INTO `zzzzsys_form` (
  `zzzzsys_form_id`,
  `zzzzsys_form_description`,
  `zzzzsys_form_type`,
  `zzzzsys_form_module`,
  `zzzzsys_form_order`
) VALUES (
  'nu_appcloner',
  'App Cloner',
  'custom',
  'appcloner',
  9999
);

-- Create temp directory placeholder (the PHP worker writes progress files here)
-- Make sure your web server has write permission to the /temp/ folder.
-- No SQL needed for the temp folder - just ensure it exists and is writable.

SELECT 'App Cloner module registered. Access it from the nuBuilder 5 menu.' AS msg;
