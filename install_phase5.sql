-- nuBuilder Next — Phase 5: Reports module migration
-- Run on existing installs that were set up before the report_view_mode column was added
-- Safe to run multiple times (uses IF NOT EXISTS / MODIFY pattern)

-- Add report_view_mode column to nu_reports if missing
ALTER TABLE nu_reports
  ADD COLUMN IF NOT EXISTS report_view_mode VARCHAR(20) NOT NULL DEFAULT 'table'
  AFTER report_type;

-- Ensure all required columns exist
ALTER TABLE nu_reports
  ADD COLUMN IF NOT EXISTS report_filters JSON AFTER report_columns,
  ADD COLUMN IF NOT EXISTS report_settings JSON AFTER report_filters;

-- Add index on report_code for fast lookup
ALTER TABLE nu_reports
  ADD INDEX IF NOT EXISTS idx_report_code (report_code),
  ADD INDEX IF NOT EXISTS idx_report_active (report_active);
