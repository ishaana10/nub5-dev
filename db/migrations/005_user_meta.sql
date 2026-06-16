-- ============================================================
-- Migration 005: User meta table for custom user fields
-- Compatible with MySQL 5.7+ / MariaDB 10.3+
-- ============================================================

CREATE TABLE IF NOT EXISTS `nu_user_meta` (
    `umeta_id`      INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `umeta_user_id` INT           NOT NULL,
    `umeta_key`     VARCHAR(80)   NOT NULL
                        COMMENT 'Matches key defined in config.user_fields.php',
    `umeta_value`   VARCHAR(500)  NOT NULL DEFAULT '',
    PRIMARY KEY (`umeta_id`),
    UNIQUE KEY `uq_user_meta` (`umeta_user_id`, `umeta_key`),
    KEY `idx_umeta_key` (`umeta_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Stores arbitrary key/value meta for each user (e.g. station, department, region).';
