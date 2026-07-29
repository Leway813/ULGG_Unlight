-- Repair legacy Steam Auth tables without deleting application data.
-- This migration intentionally preserves:
--   game_user.region ENUM('TW','JP')
--   game_user.steam_username VARCHAR(100)
--   user_remember_tokens.idx_user

DROP PROCEDURE IF EXISTS `repair_existing_steam_auth_tables`;

DELIMITER $$

CREATE PROCEDURE `repair_existing_steam_auth_tables`()
BEGIN
    DECLARE v_count BIGINT DEFAULT 0;
    DECLARE v_exact BIGINT DEFAULT 0;
    DECLARE v_parent_data_type VARCHAR(64);
    DECLARE v_child_data_type VARCHAR(64);
    DECLARE v_parent_column_type VARCHAR(255);
    DECLARE v_child_column_type VARCHAR(255);
    DECLARE v_parent_charset VARCHAR(64);
    DECLARE v_child_charset VARCHAR(64);
    DECLARE v_parent_collation VARCHAR(64);
    DECLARE v_child_collation VARCHAR(64);
    DECLARE v_parent_length BIGINT;
    DECLARE v_child_length BIGINT;
    DECLARE v_avatar_data_type VARCHAR(64);
    DECLARE v_avatar_length BIGINT;

    /*
     * Required legacy tables and columns must already exist. The companion
     * create migration remains responsible for creating a fresh schema.
     */
    SELECT COUNT(*)
      INTO v_count
      FROM information_schema.tables
     WHERE table_schema = DATABASE()
       AND table_name IN (
           'game_user',
           'user_remember_tokens',
           'steam_openid_nonce'
       );

    IF v_count <> 3 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Steam Auth repair requires all three existing tables';
    END IF;

    SELECT COUNT(*)
      INTO v_count
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND (
           (table_name = 'game_user'
               AND column_name IN ('id', 'steam_avatar_full'))
           OR
           (table_name = 'user_remember_tokens'
               AND column_name IN ('user_id', 'token', 'expired_at'))
           OR
           (table_name = 'steam_openid_nonce'
               AND column_name IN ('nonce', 'steam_id'))
       );

    IF v_count <> 7 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Steam Auth repair found missing required columns';
    END IF;

    /*
     * user_remember_tokens.token
     *
     * Reject unsafe legacy values instead of truncating, padding, or deleting
     * them. Valid remember tokens are 64-character hexadecimal bearer tokens.
     */
    SELECT COUNT(*)
      INTO v_count
      FROM `user_remember_tokens`
     WHERE `token` IS NULL
        OR CHAR_LENGTH(`token`) <> 64
        OR `token` NOT REGEXP '^[0-9A-Fa-f]{64}$';

    IF v_count > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Invalid remember tokens block CHAR(64) conversion';
    END IF;

    SELECT COUNT(*)
      INTO v_exact
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'user_remember_tokens'
       AND column_name = 'token'
       AND data_type = 'char'
       AND character_maximum_length = 64
       AND character_set_name = 'ascii'
       AND collation_name = 'ascii_bin'
       AND is_nullable = 'NO';

    IF v_exact = 0 THEN
        ALTER TABLE `user_remember_tokens`
            MODIFY COLUMN `token`
                CHAR(64)
                CHARACTER SET ascii
                COLLATE ascii_bin
                NOT NULL;
    END IF;

    SELECT COUNT(*)
      INTO v_count
      FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'user_remember_tokens'
       AND index_name = 'idx_token'
       AND non_unique = 1;

    IF v_count > 0 THEN
        ALTER TABLE `user_remember_tokens`
            DROP INDEX `idx_token`;
    END IF;

    /*
     * The expected unique index must be a single-column UNIQUE(token).
     * If an index with the expected name has a different definition, repair it.
     */
    SELECT COUNT(*)
      INTO v_count
      FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'user_remember_tokens'
       AND index_name = 'uq_user_remember_tokens_token';

    SELECT COUNT(*)
      INTO v_exact
      FROM (
          SELECT index_name
            FROM information_schema.statistics
           WHERE table_schema = DATABASE()
             AND table_name = 'user_remember_tokens'
             AND index_name = 'uq_user_remember_tokens_token'
           GROUP BY index_name
          HAVING MIN(non_unique) = 0
             AND COUNT(*) = 1
             AND MAX(
                 CASE
                     WHEN seq_in_index = 1 AND column_name = 'token'
                     THEN 1
                     ELSE 0
                 END
             ) = 1
      ) AS exact_token_index;

    IF v_count > 0 AND v_exact = 0 THEN
        ALTER TABLE `user_remember_tokens`
            DROP INDEX `uq_user_remember_tokens_token`;
    END IF;

    SELECT COUNT(*)
      INTO v_count
      FROM (
          SELECT `token`
            FROM `user_remember_tokens`
           GROUP BY `token`
          HAVING COUNT(*) > 1
      ) AS duplicate_tokens;

    IF v_count > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Duplicate remember tokens block UNIQUE index creation';
    END IF;

    SELECT COUNT(*)
      INTO v_exact
      FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'user_remember_tokens'
       AND index_name = 'uq_user_remember_tokens_token'
       AND non_unique = 0
       AND seq_in_index = 1
       AND column_name = 'token';

    IF v_exact = 0 THEN
        ALTER TABLE `user_remember_tokens`
            ADD UNIQUE KEY `uq_user_remember_tokens_token` (`token`);
    END IF;

    /*
     * user_remember_tokens.expired_at
     *
     * Any index whose first column is expired_at satisfies the query pattern.
     * A conflicting index with the expected name is repaired only when needed.
     */
    SELECT COUNT(*)
      INTO v_exact
      FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'user_remember_tokens'
       AND seq_in_index = 1
       AND column_name = 'expired_at';

    IF v_exact = 0 THEN
        SELECT COUNT(*)
          INTO v_count
          FROM information_schema.statistics
         WHERE table_schema = DATABASE()
           AND table_name = 'user_remember_tokens'
           AND index_name = 'idx_user_remember_tokens_expired_at';

        IF v_count > 0 THEN
            ALTER TABLE `user_remember_tokens`
                DROP INDEX `idx_user_remember_tokens_expired_at`;
        END IF;

        ALTER TABLE `user_remember_tokens`
            ADD KEY `idx_user_remember_tokens_expired_at` (`expired_at`);
    END IF;

    /*
     * user_remember_tokens.user_id foreign key compatibility.
     *
     * Integer types must have the same base type and signedness. Character
     * types additionally require equal length, character set, and collation.
     */
    SELECT
        data_type,
        column_type,
        character_set_name,
        collation_name,
        character_maximum_length
      INTO
        v_parent_data_type,
        v_parent_column_type,
        v_parent_charset,
        v_parent_collation,
        v_parent_length
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'game_user'
       AND column_name = 'id';

    SELECT
        data_type,
        column_type,
        character_set_name,
        collation_name,
        character_maximum_length
      INTO
        v_child_data_type,
        v_child_column_type,
        v_child_charset,
        v_child_collation,
        v_child_length
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'user_remember_tokens'
       AND column_name = 'user_id';

    IF
        v_parent_data_type <> v_child_data_type
        OR (
            (LOWER(v_parent_column_type) LIKE '% unsigned')
            <> (LOWER(v_child_column_type) LIKE '% unsigned')
        )
        OR (
            v_parent_data_type IN (
                'char',
                'varchar',
                'binary',
                'varbinary'
            )
            AND (
                NOT (v_parent_length <=> v_child_length)
                OR NOT (v_parent_charset <=> v_child_charset)
                OR NOT (v_parent_collation <=> v_child_collation)
            )
        )
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'game_user.id and remember user_id types are incompatible';
    END IF;

    SELECT COUNT(*)
      INTO v_count
      FROM `user_remember_tokens` AS remember
      LEFT JOIN `game_user` AS user
        ON user.`id` = remember.`user_id`
     WHERE remember.`user_id` IS NOT NULL
       AND user.`id` IS NULL;

    IF v_count > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Orphan remember-token rows block foreign key creation';
    END IF;

    /*
     * Repair a wrongly defined constraint with the expected name. This does
     * not touch differently named constraints or the existing idx_user index.
     */
    SELECT COUNT(*)
      INTO v_count
      FROM information_schema.table_constraints
     WHERE constraint_schema = DATABASE()
       AND table_name = 'user_remember_tokens'
       AND constraint_name = 'fk_user_remember_tokens_user'
       AND constraint_type = 'FOREIGN KEY';

    SELECT COUNT(*)
      INTO v_exact
      FROM information_schema.key_column_usage AS usage_info
      JOIN information_schema.referential_constraints AS ref_info
        ON ref_info.constraint_schema = usage_info.constraint_schema
       AND ref_info.table_name = usage_info.table_name
       AND ref_info.constraint_name = usage_info.constraint_name
     WHERE usage_info.constraint_schema = DATABASE()
       AND usage_info.table_name = 'user_remember_tokens'
       AND usage_info.constraint_name = 'fk_user_remember_tokens_user'
       AND usage_info.column_name = 'user_id'
       AND usage_info.referenced_table_name = 'game_user'
       AND usage_info.referenced_column_name = 'id'
       AND ref_info.delete_rule = 'CASCADE'
       AND (
           SELECT COUNT(*)
             FROM information_schema.key_column_usage AS constraint_columns
            WHERE constraint_columns.constraint_schema =
                    usage_info.constraint_schema
              AND constraint_columns.table_name = usage_info.table_name
              AND constraint_columns.constraint_name =
                    usage_info.constraint_name
       ) = 1;

    IF v_count > 0 AND v_exact = 0 THEN
        ALTER TABLE `user_remember_tokens`
            DROP FOREIGN KEY `fk_user_remember_tokens_user`;
    END IF;

    SELECT COUNT(*)
      INTO v_exact
      FROM information_schema.table_constraints
     WHERE constraint_schema = DATABASE()
       AND table_name = 'user_remember_tokens'
       AND constraint_name = 'fk_user_remember_tokens_user'
       AND constraint_type = 'FOREIGN KEY';

    IF v_exact = 0 THEN
        ALTER TABLE `user_remember_tokens`
            ADD CONSTRAINT `fk_user_remember_tokens_user`
            FOREIGN KEY (`user_id`)
            REFERENCES `game_user` (`id`)
            ON DELETE CASCADE;
    END IF;

    /*
     * steam_openid_nonce columns.
     * Reject NULL or oversized values instead of silently truncating them.
     */
    SELECT COUNT(*)
      INTO v_count
      FROM `steam_openid_nonce`
     WHERE `nonce` IS NULL
        OR CHAR_LENGTH(`nonce`) > 255;

    IF v_count > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Invalid nonce rows block VARCHAR(255) conversion';
    END IF;

    SELECT COUNT(*)
      INTO v_exact
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'steam_openid_nonce'
       AND column_name = 'nonce'
       AND data_type = 'varchar'
       AND character_maximum_length = 255
       AND character_set_name = 'ascii'
       AND collation_name = 'ascii_bin'
       AND is_nullable = 'NO';

    IF v_exact = 0 THEN
        ALTER TABLE `steam_openid_nonce`
            MODIFY COLUMN `nonce`
                VARCHAR(255)
                CHARACTER SET ascii
                COLLATE ascii_bin
                NOT NULL;
    END IF;

    SELECT COUNT(*)
      INTO v_count
      FROM `steam_openid_nonce`
     WHERE `steam_id` IS NULL
        OR CHAR_LENGTH(`steam_id`) > 64;

    IF v_count > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Invalid Steam IDs block VARCHAR(64) conversion';
    END IF;

    SELECT COUNT(*)
      INTO v_exact
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'steam_openid_nonce'
       AND column_name = 'steam_id'
       AND data_type = 'varchar'
       AND character_maximum_length = 64
       AND character_set_name = 'ascii'
       AND collation_name = 'ascii_bin'
       AND is_nullable = 'NO';

    IF v_exact = 0 THEN
        ALTER TABLE `steam_openid_nonce`
            MODIFY COLUMN `steam_id`
                VARCHAR(64)
                CHARACTER SET ascii
                COLLATE ascii_bin
                NOT NULL;
    END IF;

    /*
     * game_user.steam_avatar_full
     *
     * Only widen a legacy VARCHAR that is shorter than the production length.
     * region, steam_username, and idx_user are intentionally untouched.
     */
    SELECT
        data_type,
        character_maximum_length
      INTO
        v_avatar_data_type,
        v_avatar_length
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'game_user'
       AND column_name = 'steam_avatar_full';

    IF
        v_avatar_data_type = 'varchar'
        AND v_avatar_length < 2048
    THEN
        ALTER TABLE `game_user`
            MODIFY COLUMN `steam_avatar_full` VARCHAR(2048) NULL;
    END IF;
END$$

CALL `repair_existing_steam_auth_tables`()$$
DROP PROCEDURE `repair_existing_steam_auth_tables`$$

DELIMITER ;
