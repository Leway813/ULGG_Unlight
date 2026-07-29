-- Dependencies for secure UNLIGHT ID binding.
--
-- Preflight policy:
--   * Existing duplicate (username, region) bindings are never deleted or merged.
--   * The migration stops with SQLSTATE 45000 before creating the unique key.
--   * Existing game_user.region definitions, including production ENUM types,
--     are intentionally left unchanged.

DELIMITER $$

DROP PROCEDURE IF EXISTS `create_ulid_binding_dependencies`$$

CREATE PROCEDURE `create_ulid_binding_dependencies`()
BEGIN
    DECLARE v_count INT DEFAULT 0;
    DECLARE v_required_columns INT DEFAULT 0;
    DECLARE v_has_equivalent_unique INT DEFAULT 0;
    DECLARE v_has_named_index INT DEFAULT 0;
    DECLARE v_arena_columns INT DEFAULT 0;
    DECLARE v_has_arena_index INT DEFAULT 0;

    SELECT COUNT(*)
      INTO v_count
      FROM information_schema.tables
     WHERE table_schema = DATABASE()
       AND table_name = 'game_user'
       AND table_type = 'BASE TABLE';

    IF v_count <> 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Required table game_user does not exist';
    END IF;

    /*
     * Binding data. Existing columns are preserved so this migration can be
     * rerun safely against a production schema.
     */
    SELECT COUNT(*)
      INTO v_count
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'game_user'
       AND column_name = 'friend_code';

    IF v_count = 0 THEN
        ALTER TABLE `game_user`
            ADD COLUMN `friend_code`
                VARCHAR(64)
                CHARACTER SET utf8mb4
                COLLATE utf8mb4_unicode_ci
                NULL
                AFTER `username`;
    END IF;

    SELECT COUNT(*)
      INTO v_count
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'game_user'
       AND column_name = 'verify_image';

    IF v_count = 0 THEN
        ALTER TABLE `game_user`
            ADD COLUMN `verify_image`
                VARCHAR(128)
                CHARACTER SET ascii
                COLLATE ascii_bin
                NULL
                COMMENT 'Opaque key for a private ULID verification image'
                AFTER `friend_code`;
    END IF;

    SELECT COUNT(*)
      INTO v_count
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'game_user'
       AND column_name = 'email';

    IF v_count = 0 THEN
        ALTER TABLE `game_user`
            ADD COLUMN `email`
                VARCHAR(254)
                CHARACTER SET utf8mb4
                COLLATE utf8mb4_unicode_ci
                NULL
                AFTER `verify_image`;
    END IF;

    /*
     * username and region are owned by the base/auth schema. Verify their
     * presence without changing either column's production type or collation.
     */
    SELECT COUNT(*)
      INTO v_required_columns
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'game_user'
       AND column_name IN ('username', 'region');

    IF v_required_columns <> 2 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'game_user.username and game_user.region are required';
    END IF;

    /*
     * Duplicate preflight. This is intentionally executed before any unique
     * key is added and never changes application data.
     */
    SELECT COUNT(*)
      INTO v_count
      FROM (
          SELECT `username`, `region`
            FROM `game_user`
           GROUP BY `username`, `region`
          HAVING COUNT(*) > 1
      ) AS duplicate_bindings;

    IF v_count > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT =
                'Duplicate game_user username/region bindings require manual repair';
    END IF;

    /*
     * A unique username index is stricter than the required pair and therefore
     * also satisfies binding uniqueness. Otherwise add the composite key.
     */
    SELECT COUNT(*)
      INTO v_has_equivalent_unique
      FROM (
          SELECT
              index_name,
              MAX(non_unique) AS non_unique,
              GROUP_CONCAT(
                  column_name
                  ORDER BY seq_in_index
                  SEPARATOR ','
              ) AS indexed_columns
            FROM information_schema.statistics
           WHERE table_schema = DATABASE()
             AND table_name = 'game_user'
           GROUP BY index_name
          HAVING non_unique = 0
             AND indexed_columns IN (
                 'username',
                 'username,region',
                 'region,username'
             )
      ) AS equivalent_unique_indexes;

    IF v_has_equivalent_unique = 0 THEN
        SELECT COUNT(*)
          INTO v_has_named_index
          FROM information_schema.statistics
         WHERE table_schema = DATABASE()
           AND table_name = 'game_user'
           AND index_name = 'uq_game_user_ulid_region';

        IF v_has_named_index > 0 THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT =
                    'Index uq_game_user_ulid_region exists with an incompatible definition';
        END IF;

        ALTER TABLE `game_user`
            ADD UNIQUE KEY `uq_game_user_ulid_region`
                (`username`, `region`);
    END IF;

    /*
     * arena_unlight.region belongs to the ingestion schema and is not created
     * or modified here. When it exists, add indexes supporting the advisory
     * name_p1/name_p2 + region lookup used by the binding page.
     */
    SELECT COUNT(*)
      INTO v_arena_columns
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'arena_unlight'
       AND column_name IN ('region', 'name_p1', 'name_p2');

    IF v_arena_columns = 3 THEN
        SELECT COUNT(*)
          INTO v_has_arena_index
          FROM (
              SELECT
                  index_name,
                  GROUP_CONCAT(
                      column_name
                      ORDER BY seq_in_index
                      SEPARATOR ','
                  ) AS indexed_columns
                FROM information_schema.statistics
               WHERE table_schema = DATABASE()
                 AND table_name = 'arena_unlight'
               GROUP BY index_name
              HAVING indexed_columns = 'region,name_p1'
                  OR indexed_columns LIKE 'region,name_p1,%'
          ) AS arena_name_p1_indexes;

        IF v_has_arena_index = 0 THEN
            SELECT COUNT(*)
              INTO v_has_named_index
              FROM information_schema.statistics
             WHERE table_schema = DATABASE()
               AND table_name = 'arena_unlight'
               AND index_name = 'idx_arena_unlight_region_name_p1';

            IF v_has_named_index > 0 THEN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT =
                        'Index idx_arena_unlight_region_name_p1 has an incompatible definition';
            END IF;

            ALTER TABLE `arena_unlight`
                ADD KEY `idx_arena_unlight_region_name_p1`
                    (`region`, `name_p1`);
        END IF;

        SELECT COUNT(*)
          INTO v_has_arena_index
          FROM (
              SELECT
                  index_name,
                  GROUP_CONCAT(
                      column_name
                      ORDER BY seq_in_index
                      SEPARATOR ','
                  ) AS indexed_columns
                FROM information_schema.statistics
               WHERE table_schema = DATABASE()
                 AND table_name = 'arena_unlight'
               GROUP BY index_name
              HAVING indexed_columns = 'region,name_p2'
                  OR indexed_columns LIKE 'region,name_p2,%'
          ) AS arena_name_p2_indexes;

        IF v_has_arena_index = 0 THEN
            SELECT COUNT(*)
              INTO v_has_named_index
              FROM information_schema.statistics
             WHERE table_schema = DATABASE()
               AND table_name = 'arena_unlight'
               AND index_name = 'idx_arena_unlight_region_name_p2';

            IF v_has_named_index > 0 THEN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT =
                        'Index idx_arena_unlight_region_name_p2 has an incompatible definition';
            END IF;

            ALTER TABLE `arena_unlight`
                ADD KEY `idx_arena_unlight_region_name_p2`
                    (`region`, `name_p2`);
        END IF;
    END IF;
END$$

CALL `create_ulid_binding_dependencies`()$$

DROP PROCEDURE IF EXISTS `create_ulid_binding_dependencies`$$

DELIMITER ;
