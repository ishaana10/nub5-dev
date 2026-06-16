-- ============================================================
-- Migration 004: Dynamic role + per-form permission tables
-- ============================================================

-- 1. Roles table (if not already exists)
CREATE TABLE IF NOT EXISTS `nu_roles` (
    `role_id`          INT UNSIGN