-- Steam authentication persistence required by api/auth/steam_callback.php.
-- Existing non-NULL game_user.steamID values must be unique before migration.

SET @column_exists = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'game_user'
      AND column_name = 'region'
);
SET @ddl = IF(
    @column_exists = 0,
    'ALTER TABLE `game_user` ADD COLUMN `region` VARCHAR(8) NOT NULL DEFAULT ''TW'' AFTER `apply`',
    'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @column_exists = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'game_user'
      AND column_name = 'steam_username'
);
SET @ddl = IF(
    @column_exists = 0,
    'ALTER TABLE `game_user` ADD COLUMN `steam_username` VARCHAR(255) NULL AFTER `steamID`',
    'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @column_exists = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'game_user'
      AND column_name = 'steam_avatar_full'
);
SET @ddl = IF(
    @column_exists = 0,
    'ALTER TABLE `game_user` ADD COLUMN `steam_avatar_full` VARCHAR(2048) NULL AFTER `steam_username`',
    'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @index_exists = (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'game_user'
      AND index_name = 'uq_game_user_steam_id'
);
SET @ddl = IF(
    @index_exists = 0,
    'ALTER TABLE `game_user` ADD UNIQUE KEY `uq_game_user_steam_id` (`steamID`)',
    'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS `steam_openid_nonce` (
    `nonce` VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `steam_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`nonce`),
    KEY `idx_steam_openid_nonce_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `user_remember_tokens` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT NOT NULL,
    `token` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `expired_at` DATETIME NOT NULL,
    `user_agent` TEXT NULL,
    `ip` VARCHAR(45) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_user_remember_tokens_token` (`token`),
    KEY `idx_user_remember_tokens_user_id` (`user_id`),
    KEY `idx_user_remember_tokens_expired_at` (`expired_at`),
    CONSTRAINT `fk_user_remember_tokens_user`
        FOREIGN KEY (`user_id`) REFERENCES `game_user` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
