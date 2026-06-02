-- ============================================================
-- nub5-dev Email Forms Integration Migration
-- Run AFTER install_email.sql
-- Adds per-form email notification config columns to nu_forms
-- ============================================================

ALTER TABLE `nu_forms`
  ADD COLUMN IF NOT EXISTS `form_email_notify`    TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Enable email notification on save (0=off, 1=on)',
  ADD COLUMN IF NOT EXISTS `form_email_notify_on` ENUM('new','all') NOT NULL DEFAULT 'new'
    COMMENT 'Send notification: new records only, or all saves',
  ADD COLUMN IF NOT EXISTS `form_email_to`        VARCHAR(500) DEFAULT NULL
    COMMENT 'Notification recipient email(s), comma-separated',
  ADD COLUMN IF NOT EXISTS `form_email_template`  VARCHAR(100) DEFAULT 'form_submission'
    COMMENT 'Email template slug to use (from nu_email_templates)';
