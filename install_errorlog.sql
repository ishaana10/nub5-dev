-- nuBuilder Next — Error Log table
-- Run once to add the error logging system.
-- Safe to run multiple times (IF NOT EXISTS).

CREATE TABLE IF NOT EXISTS `nu_error_log` (
  `errlog_id`             INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `errlog_type`           ENUM('PHP','SQL','JS','APP') NOT NULL DEFAULT 'PHP' COMMENT 'Error source type',
  `errlog_severity`       ENUM('debug','info','warning','error','fatal') NOT NULL DEFAULT 'error',
  `errlog_message`        VARCHAR(2000)   NOT NULL DEFAULT '',
  `errlog_context`        TEXT            DEFAULT NULL COMMENT 'JSON: extra data, SQL, params, etc.',
  `errlog_trace`          TEXT            DEFAULT NULL COMMENT 'Stack trace or JS stack string',
  `errlog_file`           VARCHAR(500)    DEFAULT NULL COMMENT 'Source file (NU_ROOT stripped)',
  `errlog_line`           SMALLINT UNSIGNED DEFAULT NULL,
  `errlog_request_uri`    VARCHAR(500)    DEFAULT NULL,
  `errlog_request_method` VARCHAR(10)     DEFAULT NULL,
  `errlog_user_id`        INT UNSIGNED    DEFAULT NULL,
  `errlog_user_name`      VARCHAR(100)    DEFAULT NULL,
  `errlog_created_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`errlog_id`),
  KEY `idx_type`       (`errlog_type`),
  KEY `idx_severity`   (`errlog_severity`),
  KEY `idx_created`    (`errlog_created_at`),
  KEY `idx_type_sev`   (`errlog_type`, `errlog_severity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Centralised PHP / SQL / JS / APP error log';
