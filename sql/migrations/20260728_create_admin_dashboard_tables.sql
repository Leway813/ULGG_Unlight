-- Admin dashboard storage for MySQL 8.0.
-- The visitors table already exists in database/schema.sql. The guarded
-- ALTER statements below add only columns required by the active loggers.

SET @ddl = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE `visitors` ADD COLUMN `is_bot` TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER `user_agent`',
    'SELECT 1'
  )
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'visitors'
    AND column_name = 'is_bot'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @ddl = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE `visitors` ADD COLUMN `bot_type` VARCHAR(64) NULL AFTER `is_bot`',
    'SELECT 1'
  )
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'visitors'
    AND column_name = 'bot_type'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @ddl = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE `visitors` ADD COLUMN `request_query` VARCHAR(1000) NULL AFTER `page`',
    'SELECT 1'
  )
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'visitors'
    AND column_name = 'request_query'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @ddl = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE `visitors` ADD COLUMN `character_id` INT UNSIGNED NULL AFTER `character_name`',
    'SELECT 1'
  )
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'visitors'
    AND column_name = 'character_id'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @ddl = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE `visitors` ADD COLUMN `referrer` TEXT NULL AFTER `character_id`',
    'SELECT 1'
  )
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'visitors'
    AND column_name = 'referrer'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @ddl = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE `visitors` ADD COLUMN `session_id` TEXT NULL AFTER `referrer`',
    'SELECT 1'
  )
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'visitors'
    AND column_name = 'session_id'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @ddl = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE `visitors` ADD COLUMN `visitor_type` VARCHAR(16) NOT NULL DEFAULT ''guest'' AFTER `session_id`',
    'SELECT 1'
  )
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'visitors'
    AND column_name = 'visitor_type'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @ddl = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE `visitors` ADD COLUMN `device` VARCHAR(32) NULL AFTER `visitor_type`',
    'SELECT 1'
  )
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'visitors'
    AND column_name = 'device'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @ddl = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE `visitors` ADD COLUMN `browser` VARCHAR(32) NULL AFTER `device`',
    'SELECT 1'
  )
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'visitors'
    AND column_name = 'browser'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @ddl = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE `visitors` ADD COLUMN `os` VARCHAR(32) NULL AFTER `browser`',
    'SELECT 1'
  )
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'visitors'
    AND column_name = 'os'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @ddl = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE `visitors` ADD COLUMN `response_ms` INT UNSIGNED NULL AFTER `os`',
    'SELECT 1'
  )
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'visitors'
    AND column_name = 'response_ms'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @ddl = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE `visitors` ADD COLUMN `country` VARCHAR(255) NULL AFTER `response_ms`',
    'SELECT 1'
  )
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'visitors'
    AND column_name = 'country'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @ddl = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE `visitors` ADD COLUMN `city` VARCHAR(255) NULL AFTER `country`',
    'SELECT 1'
  )
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'visitors'
    AND column_name = 'city'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS `admin_system_metrics` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `recorded_at` DATETIME(0) NOT NULL,
  `load_1` DECIMAL(10,2) NULL,
  `load_5` DECIMAL(10,2) NULL,
  `load_15` DECIMAL(10,2) NULL,
  `memory_used_mb` BIGINT UNSIGNED NULL,
  `memory_total_mb` BIGINT UNSIGNED NULL,
  `swap_used_mb` BIGINT UNSIGNED NULL,
  `disk_used_mb` BIGINT UNSIGNED NULL,
  `disk_total_mb` BIGINT UNSIGNED NULL,
  `mysql_connections` INT UNSIGNED NULL,
  PRIMARY KEY (`id`),
  KEY `idx_admin_system_metrics_recorded_at` (`recorded_at`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `admin_traffic_hourly` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `stat_hour` DATETIME(0) NOT NULL,
  `page` VARCHAR(255) NOT NULL,
  `human_pv` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `bot_pv` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `unique_visitors` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `sessions` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `avg_response_ms` INT UNSIGNED NULL,
  `max_response_ms` INT UNSIGNED NULL,
  `slow_1000_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `slow_3000_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_admin_traffic_hourly_hour_page` (`stat_hour`, `page`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `admin_watcher_heartbeat` (
  `watcher_key` VARCHAR(64) NOT NULL,
  `watcher_name` VARCHAR(128) NOT NULL,
  `region` VARCHAR(8) NOT NULL,
  `status` VARCHAR(16) NOT NULL,
  `pid` INT UNSIGNED NULL,
  `last_heartbeat_at` DATETIME(0) NOT NULL,
  `last_message_at` DATETIME(0) NULL,
  `last_data_at` DATETIME(0) NULL,
  `processed_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `error_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `last_error` TEXT NULL,
  `updated_at` TIMESTAMP(0) NOT NULL
    DEFAULT CURRENT_TIMESTAMP
    ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`watcher_key`),
  KEY `idx_admin_watcher_region_status` (`region`, `status`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `admin_error_summary` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `error_hash` CHAR(64) NOT NULL,
  `error_level` VARCHAR(64) NOT NULL,
  `message` TEXT NOT NULL,
  `file_path` VARCHAR(1024) NULL,
  `line_no` INT UNSIGNED NULL,
  `page` VARCHAR(255) NULL,
  `first_seen_at` DATETIME(0) NOT NULL,
  `last_seen_at` DATETIME(0) NOT NULL,
  `occurrence_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `resolved_at` DATETIME(0) NULL,
  `updated_at` TIMESTAMP(0) NOT NULL
    DEFAULT CURRENT_TIMESTAMP
    ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_admin_error_summary_hash` (`error_hash`),
  KEY `idx_admin_error_summary_open_recent`
    (`resolved_at`, `last_seen_at`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci;
