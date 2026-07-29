

-- Table structure for table `arena`

DROP TABLE IF EXISTS `arena`;
CREATE TABLE `arena` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `e1` text NOT NULL,
  `e2` text NOT NULL,
  `e3` text NOT NULL,
  `e4` text NOT NULL,
  `e5` text NOT NULL,
  `u1` text NOT NULL,
  `u2` text NOT NULL,
  `u3` text NOT NULL,
  `u4` text NOT NULL,
  `u5` text NOT NULL,
  `sequence` text NOT NULL,
  `win` varchar(10) DEFAULT '0',
  `lose` varchar(10) DEFAULT '0',
  `tie` varchar(45) DEFAULT NULL,
  `update` varchar(45) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Table structure for table `arena_unlight`

DROP TABLE IF EXISTS `arena_unlight`;
CREATE TABLE `arena_unlight` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `room_id` varchar(45) DEFAULT NULL,
  `cost` int(11) DEFAULT NULL COMMENT 'Quickmatch 的 cost 值',
  `stage` int(11) DEFAULT NULL,
  `e1` int(11) NOT NULL,
  `e2` int(11) NOT NULL,
  `e3` int(11) NOT NULL,
  `u1` int(11) NOT NULL,
  `u2` int(11) NOT NULL,
  `u3` int(11) NOT NULL,
  `win` int(11) DEFAULT '0',
  `lose` int(11) DEFAULT '0',
  `tie` int(11) DEFAULT '0',
  `update_time` datetime DEFAULT CURRENT_TIMESTAMP,
  `username` varchar(45) NOT NULL,
  `name_p1` varchar(45) DEFAULT NULL,
  `bp_p1` int(11) DEFAULT NULL,
  `win_p1` int(11) DEFAULT NULL,
  `draw_p1` int(11) DEFAULT NULL,
  `lose_p1` int(11) DEFAULT NULL,
  `name_p2` varchar(45) DEFAULT NULL,
  `bp_p2` int(11) DEFAULT NULL,
  `win_p2` int(11) DEFAULT NULL,
  `draw_p2` int(11) DEFAULT NULL,
  `lose_p2` int(11) DEFAULT NULL,
  `ack1` int(11) DEFAULT '0',
  `ack2` int(11) DEFAULT '0',
  `is_read` tinyint(1) NOT NULL DEFAULT '0',
  `w1` int(11) DEFAULT NULL,
  `w2` int(11) DEFAULT NULL,
  `w3` int(11) DEFAULT NULL,
  `eventindex1` varchar(255) DEFAULT NULL,
  `v1` int(11) DEFAULT NULL,
  `v2` int(11) DEFAULT NULL,
  `v3` int(11) DEFAULT NULL,
  `eventindex2` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `room_id_UNIQUE` (`room_id`),
  KEY `idx_e1` (`e1`),
  KEY `idx_e2` (`e2`),
  KEY `idx_e3` (`e3`),
  KEY `idx_u1` (`u1`),
  KEY `idx_u2` (`u2`),
  KEY `idx_u3` (`u3`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Table structure for table `character`

DROP TABLE IF EXISTS `character`;
CREATE TABLE `character` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `adj` varchar(45) DEFAULT NULL,
  `name` varchar(45) DEFAULT NULL,
  `goodat` varchar(45) DEFAULT NULL,
  `type` varchar(45) DEFAULT NULL,
  `ico` varchar(45) DEFAULT NULL,
  `used` int(11) DEFAULT '0',
  `showtime` varchar(45) DEFAULT NULL,
  `onstage` int(11) DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Table structure for table `cost_unlight`

DROP TABLE IF EXISTS `cost_unlight`;
CREATE TABLE `cost_unlight` (
  `ID` int(11) NOT NULL,
  `name` text,
  `L1` int(11) DEFAULT NULL,
  `L2` int(11) DEFAULT NULL,
  `L3` int(11) DEFAULT NULL,
  `L4` int(11) DEFAULT NULL,
  `L5` int(11) DEFAULT NULL,
  `R1` int(11) DEFAULT NULL,
  `R2` int(11) DEFAULT NULL,
  `R3` int(11) DEFAULT NULL,
  `R4` int(11) DEFAULT NULL,
  `R5` int(11) DEFAULT NULL,
  `on_stage` int(11) DEFAULT NULL,
  PRIMARY KEY (`ID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Table structure for table `game_user`

DROP TABLE IF EXISTS `game_user`;
CREATE TABLE `game_user` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `nickname` varchar(45) DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `ack` int(11) DEFAULT '0',
  `update_time` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `apply` int(11) DEFAULT NULL,
  `show_private` int(11) DEFAULT NULL,
  `steamID` varchar(64) DEFAULT NULL,
  `black_list` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username_UNIQUE` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Table structure for table `pokemon`

DROP TABLE IF EXISTS `pokemon`;
CREATE TABLE `pokemon` (
  `id` varchar(45) NOT NULL,
  `ico` varchar(45) DEFAULT NULL,
  `name` varchar(45) DEFAULT NULL,
  `level` varchar(45) DEFAULT NULL,
  `attribute` varchar(45) DEFAULT NULL,
  `HP` int(11) DEFAULT NULL,
  `weakness` varchar(45) DEFAULT NULL,
  `retreat` int(11) DEFAULT NULL,
  `series` varchar(45) DEFAULT NULL,
  `skill1_atk` int(11) DEFAULT NULL,
  `skill1_eng` int(11) DEFAULT NULL,
  `skill2_atk` int(11) DEFAULT NULL,
  `skill2_eng` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Table structure for table `quickmatch_monthly_cost`

DROP TABLE IF EXISTS `quickmatch_monthly_cost`;
CREATE TABLE `quickmatch_monthly_cost` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `chennel` varchar(45) NOT NULL,
  `month_date` datetime NOT NULL COMMENT 'YYYY-MM-DD HH:MM:SS（月結時間）',
  `cost1` int(11) NOT NULL COMMENT '分段1 (COST55) 三角色總費用',
  `cost2` int(11) NOT NULL COMMENT '分段2 (COST61) 三角色總費用',
  `cost3` int(11) NOT NULL COMMENT '分段3 (COST73) 三角色總費用',
  `cost4` int(11) NOT NULL COMMENT '分段4 (COST90+) 三角色總費用',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='每月 Quickmatch 四分段費用總表';

-- Table structure for table `ranking_bp`

DROP TABLE IF EXISTS `ranking_bp`;
CREATE TABLE `ranking_bp` (
  `ts` datetime NOT NULL COMMENT '資料時間戳',
  `rank_num` int(11) NOT NULL COMMENT '第 N 名（1~100）',
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `level` int(11) DEFAULT NULL,
  `bp` int(11) DEFAULT NULL,
  `win_ranked` int(11) DEFAULT NULL,
  `lose_ranked` int(11) DEFAULT NULL,
  `draw_ranked` int(11) DEFAULT NULL,
  PRIMARY KEY (`ts`,`rank_num`),
  KEY `ts` (`ts`),
  KEY `bp` (`bp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table structure for table `ranking_bp_JP`

DROP TABLE IF EXISTS `ranking_bp_JP`;
CREATE TABLE `ranking_bp_JP` (
  `ts` datetime NOT NULL COMMENT '資料時間戳',
  `rank_num` int(11) NOT NULL COMMENT '第 N 名（1~100）',
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `level` int(11) DEFAULT NULL,
  `bp` int(11) DEFAULT NULL,
  `win_ranked` int(11) DEFAULT NULL,
  `lose_ranked` int(11) DEFAULT NULL,
  `draw_ranked` int(11) DEFAULT NULL,
  PRIMARY KEY (`ts`,`rank_num`),
  KEY `ts` (`ts`),
  KEY `bp` (`bp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table structure for table `ranking_bp_JP_history`

DROP TABLE IF EXISTS `ranking_bp_JP_history`;
CREATE TABLE `ranking_bp_JP_history` (
  `ts` datetime NOT NULL COMMENT '資料時間戳',
  `rank_num` int(11) NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `level` int(11) DEFAULT NULL COMMENT '玩家等級',
  `bp` int(11) DEFAULT NULL COMMENT 'Battle Points',
  `win_ranked` int(11) DEFAULT NULL COMMENT '排名賽勝場',
  `lose_ranked` int(11) DEFAULT NULL COMMENT '排名賽敗場',
  `draw_ranked` int(11) DEFAULT NULL COMMENT '排名賽平手',
  PRIMARY KEY (`ts`,`rank_num`),
  KEY `ts` (`ts`),
  KEY `bp` (`bp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table structure for table `ranking_bp_history`

DROP TABLE IF EXISTS `ranking_bp_history`;
CREATE TABLE `ranking_bp_history` (
  `ts` datetime NOT NULL COMMENT '資料時間戳',
  `rank_num` int(11) NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `level` int(11) DEFAULT NULL COMMENT '玩家等級',
  `bp` int(11) DEFAULT NULL COMMENT 'Battle Points',
  `win_ranked` int(11) DEFAULT NULL COMMENT '排名賽勝場',
  `lose_ranked` int(11) DEFAULT NULL COMMENT '排名賽敗場',
  `draw_ranked` int(11) DEFAULT NULL COMMENT '排名賽平手',
  PRIMARY KEY (`ts`,`rank_num`),
  KEY `ts` (`ts`),
  KEY `bp` (`bp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table structure for table `ranking_qp`

DROP TABLE IF EXISTS `ranking_qp`;
CREATE TABLE `ranking_qp` (
  `ts` datetime NOT NULL COMMENT '資料時間戳',
  `rank_num` int(11) NOT NULL COMMENT '第 N 名（1~100）',
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `level` int(11) DEFAULT NULL,
  `qp` int(11) DEFAULT NULL,
  PRIMARY KEY (`ts`,`rank_num`),
  KEY `ts` (`ts`),
  KEY `qp` (`qp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Table structure for table `ranking_qp_JP`

DROP TABLE IF EXISTS `ranking_qp_JP`;
CREATE TABLE `ranking_qp_JP` (
  `ts` datetime NOT NULL COMMENT '資料時間戳',
  `rank_num` int(11) NOT NULL COMMENT '第 N 名（1~100）',
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `level` int(11) DEFAULT NULL,
  `qp` int(11) DEFAULT NULL,
  PRIMARY KEY (`ts`,`rank_num`),
  KEY `ts` (`ts`),
  KEY `qp` (`qp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Table structure for table `ranking_qp_JP_history`

DROP TABLE IF EXISTS `ranking_qp_JP_history`;
CREATE TABLE `ranking_qp_JP_history` (
  `ts` datetime NOT NULL COMMENT '資料時間戳記',
  `rank_num` int(10) unsigned NOT NULL COMMENT '自動編號',
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '玩家名稱',
  `level` int(11) DEFAULT NULL COMMENT '玩家等級',
  `qp` int(11) DEFAULT NULL COMMENT '任務點數 (QuestPoint)',
  PRIMARY KEY (`ts`,`rank_num`),
  KEY `idx_ts` (`ts`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='儲存 ranking_qp.json 中的 QP 排行及其時間戳記';

-- Table structure for table `ranking_qp_history`

DROP TABLE IF EXISTS `ranking_qp_history`;
CREATE TABLE `ranking_qp_history` (
  `ts` datetime NOT NULL COMMENT '資料時間戳記',
  `rank_num` int(10) unsigned NOT NULL COMMENT '自動編號',
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '玩家名稱',
  `level` int(11) DEFAULT NULL COMMENT '玩家等級',
  `qp` int(11) DEFAULT NULL COMMENT '任務點數 (QuestPoint)',
  PRIMARY KEY (`ts`,`rank_num`),
  KEY `idx_ts` (`ts`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='儲存 ranking_qp.json 中的 QP 排行及其時間戳記';

-- Temporary view structure for view `use_rate_unlight`

DROP TABLE IF EXISTS `use_rate_unlight`;
/*!50001 DROP VIEW IF EXISTS `use_rate_unlight`*/;
/*!50001 CREATE VIEW `use_rate_unlight` AS SELECT 
 1 AS `id`,
 1 AS `ico`,
 1 AS `name`,
 1 AS `level`,
 1 AS `count_ttl`,
 1 AS `win`,
 1 AS `lose`,
 1 AS `rate`*/;

-- Temporary view structure for view `ranking_my`

DROP TABLE IF EXISTS `ranking_my`;
/*!50001 DROP VIEW IF EXISTS `ranking_my`*/;
/*!50001 CREATE VIEW `ranking_my` AS SELECT 
 1 AS `u1`,
 1 AS `u2`,
 1 AS `u3`,
 1 AS `u4`,
 1 AS `u5`,
 1 AS `win`,
 1 AS `lose`,
 1 AS `rate`*/;

-- Temporary view structure for view `best_combined_unlight`

DROP TABLE IF EXISTS `best_combined_unlight`;
/*!50001 DROP VIEW IF EXISTS `best_combined_unlight`*/;
/*!50001 CREATE VIEW `best_combined_unlight` AS SELECT 
 1 AS `char1`,
 1 AS `char2`,
 1 AS `total_wins`,
 1 AS `total_loses`,
 1 AS `total_games`,
 1 AS `win_rate`,
 1 AS `update_time`,
 1 AS `username`*/;

-- Temporary view structure for view `arena_statistic`

DROP TABLE IF EXISTS `arena_statistic`;
/*!50001 DROP VIEW IF EXISTS `arena_statistic`*/;
/*!50001 CREATE VIEW `arena_statistic` AS SELECT 
 1 AS `e1`,
 1 AS `e2`,
 1 AS `e3`,
 1 AS `e4`,
 1 AS `e5`,
 1 AS `u1`,
 1 AS `u2`,
 1 AS `u3`,
 1 AS `u4`,
 1 AS `u5`,
 1 AS `win`,
 1 AS `lose`,
 1 AS `rate`*/;

-- Temporary view structure for view `best_3v3_teams_unlight`

DROP TABLE IF EXISTS `best_3v3_teams_unlight`;
/*!50001 DROP VIEW IF EXISTS `best_3v3_teams_unlight`*/;
/*!50001 CREATE VIEW `best_3v3_teams_unlight` AS SELECT 
 1 AS `char1`,
 1 AS `char2`,
 1 AS `char3`,
 1 AS `total_wins`,
 1 AS `total_loses`,
 1 AS `total_games`,
 1 AS `win_rate`,
 1 AS `update_time`,
 1 AS `username`*/;

-- Temporary view structure for view `use_rate1`

DROP TABLE IF EXISTS `use_rate1`;
/*!50001 DROP VIEW IF EXISTS `use_rate1`*/;
/*!50001 CREATE VIEW `use_rate1` AS SELECT 
 1 AS `e1`,
 1 AS `ico`,
 1 AS `count_ttl`*/;

-- Temporary view structure for view `ranking`

DROP TABLE IF EXISTS `ranking`;
/*!50001 DROP VIEW IF EXISTS `ranking`*/;
/*!50001 CREATE VIEW `ranking` AS SELECT 
 1 AS `e1`,
 1 AS `e2`,
 1 AS `e3`,
 1 AS `e4`,
 1 AS `e5`,
 1 AS `win`,
 1 AS `lose`,
 1 AS `rate`*/;

-- Temporary view structure for view `arena_statistic_unlight`

DROP TABLE IF EXISTS `arena_statistic_unlight`;
/*!50001 DROP VIEW IF EXISTS `arena_statistic_unlight`*/;
/*!50001 CREATE VIEW `arena_statistic_unlight` AS SELECT 
 1 AS `e1`,
 1 AS `e2`,
 1 AS `e3`,
 1 AS `u1`,
 1 AS `u2`,
 1 AS `u3`,
 1 AS `win`,
 1 AS `lose`,
 1 AS `update_time`,
 1 AS `last_username`,
 1 AS `last_username_bp`,
 1 AS `last_enemy`,
 1 AS `last_enemy_bp`*/;

-- Temporary view structure for view `view_char_usage_unlight`

DROP TABLE IF EXISTS `view_char_usage_unlight`;
/*!50001 DROP VIEW IF EXISTS `view_char_usage_unlight`*/;
/*!50001 CREATE VIEW `view_char_usage_unlight` AS SELECT 
 1 AS `id`,
 1 AS `ico`,
 1 AS `name`,
 1 AS `level`,
 1 AS `count_ttl`,
 1 AS `win`,
 1 AS `lose`,
 1 AS `rate`*/;

-- Temporary view structure for view `rank_all`

DROP TABLE IF EXISTS `rank_all`;
/*!50001 DROP VIEW IF EXISTS `rank_all`*/;
/*!50001 CREATE VIEW `rank_all` AS SELECT 
 1 AS `u1`,
 1 AS `u2`,
 1 AS `u3`,
 1 AS `u4`,
 1 AS `u5`,
 1 AS `win`,
 1 AS `lose`,
 1 AS `rate`*/;

-- Final view structure for view `use_rate_unlight`

/*!50001 DROP VIEW IF EXISTS `use_rate_unlight`*/;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=CURRENT_USER SQL SECURITY DEFINER */
/*!50001 VIEW `use_rate_unlight` AS select `char_usage`.`char_id` AS `id`,`b`.`ico` AS `ico`,`b`.`name` AS `name`,`b`.`level` AS `level`,sum(`char_usage`.`count_num`) AS `count_ttl`,sum(`char_usage`.`win`) AS `win`,sum(`char_usage`.`lose`) AS `lose`,round(((sum(`char_usage`.`win`) / nullif((sum(`char_usage`.`win`) + sum(`char_usage`.`lose`)),0)) * 100),1) AS `rate` from ((select `arena_unlight`.`e1` AS `char_id`,count(0) AS `count_num`,sum(`arena_unlight`.`lose`) AS `win`,sum(`arena_unlight`.`win`) AS `lose` from `arena_unlight` group by `arena_unlight`.`e1` union all select `arena_unlight`.`e2` AS `char_id`,count(0) AS `count_num`,sum(`arena_unlight`.`lose`) AS `win`,sum(`arena_unlight`.`win`) AS `lose` from `arena_unlight` group by `arena_unlight`.`e2` union all select `arena_unlight`.`e3` AS `char_id`,count(0) AS `count_num`,sum(`arena_unlight`.`lose`) AS `win`,sum(`arena_unlight`.`win`) AS `lose` from `arena_unlight` group by `arena_unlight`.`e3` union all select `arena_unlight`.`u1` AS `char_id`,count(0) AS `count_num`,sum(`arena_unlight`.`win`) AS `win`,sum(`arena_unlight`.`lose`) AS `lose` from `arena_unlight` group by `arena_unlight`.`u1` union all select `arena_unlight`.`u2` AS `char_id`,count(0) AS `count_num`,sum(`arena_unlight`.`win`) AS `win`,sum(`arena_unlight`.`lose`) AS `lose` from `arena_unlight` group by `arena_unlight`.`u2` union all select `arena_unlight`.`u3` AS `char_id`,count(0) AS `count_num`,sum(`arena_unlight`.`win`) AS `win`,sum(`arena_unlight`.`lose`) AS `lose` from `arena_unlight` group by `arena_unlight`.`u3`) `char_usage` left join `unlight` `b` on((`char_usage`.`char_id` = `b`.`id`))) where (`char_usage`.`char_id` is not null) group by `char_usage`.`char_id` order by `count_ttl` desc,`rate` desc */;

-- Final view structure for view `ranking_my`

/*!50001 DROP VIEW IF EXISTS `ranking_my`*/;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=CURRENT_USER SQL SECURITY DEFINER */
/*!50001 VIEW `ranking_my` AS select `arena`.`u1` AS `u1`,`arena`.`u2` AS `u2`,`arena`.`u3` AS `u3`,`arena`.`u4` AS `u4`,`arena`.`u5` AS `u5`,sum(`arena`.`win`) AS `win`,sum(`arena`.`lose`) AS `lose`,round(((sum(`arena`.`win`) / (sum(`arena`.`lose`) + sum(`arena`.`win`))) * 100),0) AS `rate` from `arena` group by `arena`.`u1`,`arena`.`u2`,`arena`.`u3`,`arena`.`u4`,`arena`.`u5` order by `rate` desc */;

-- Final view structure for view `best_combined_unlight`

/*!50001 DROP VIEW IF EXISTS `best_combined_unlight`*/;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=CURRENT_USER SQL SECURITY DEFINER */
/*!50001 VIEW `best_combined_unlight` AS select `combined`.`char1` AS `char1`,`combined`.`char2` AS `char2`,sum(`combined`.`win`) AS `total_wins`,sum(`combined`.`lose`) AS `total_loses`,sum((`combined`.`win` + `combined`.`lose`)) AS `total_games`,coalesce(((sum(`combined`.`win`) / nullif(sum((`combined`.`win` + `combined`.`lose`)),0)) * 100),0) AS `win_rate`,max(`combined`.`update_time`) AS `update_time`,max(`combined`.`username`) AS `username` from (select `arena_unlight`.`e1` AS `char1`,`arena_unlight`.`e2` AS `char2`,`arena_unlight`.`lose` AS `win`,`arena_unlight`.`win` AS `lose`,`arena_unlight`.`update_time` AS `update_time`,`arena_unlight`.`username` AS `username` from `arena_unlight` where ((`arena_unlight`.`ack1` = 1) and (`arena_unlight`.`ack2` = 1)) union all select `arena_unlight`.`e1` AS `char1`,`arena_unlight`.`e3` AS `char2`,`arena_unlight`.`lose` AS `win`,`arena_unlight`.`win` AS `lose`,`arena_unlight`.`update_time` AS `update_time`,`arena_unlight`.`username` AS `username` from `arena_unlight` where ((`arena_unlight`.`ack1` = 1) and (`arena_unlight`.`ack2` = 1)) union all select `arena_unlight`.`e2` AS `char1`,`arena_unlight`.`e3` AS `char2`,`arena_unlight`.`lose` AS `win`,`arena_unlight`.`win` AS `lose`,`arena_unlight`.`update_time` AS `update_time`,`arena_unlight`.`username` AS `username` from `arena_unlight` where ((`arena_unlight`.`ack1` = 1) and (`arena_unlight`.`ack2` = 1)) union all select `arena_unlight`.`u1` AS `char1`,`arena_unlight`.`u2` AS `char2`,`arena_unlight`.`win` AS `win`,`arena_unlight`.`lose` AS `lose`,`arena_unlight`.`update_time` AS `update_time`,`arena_unlight`.`username` AS `username` from `arena_unlight` where ((`arena_unlight`.`ack1` = 1) and (`arena_unlight`.`ack2` = 1)) union all select `arena_unlight`.`u1` AS `char1`,`arena_unlight`.`u3` AS `char2`,`arena_unlight`.`win` AS `win`,`arena_unlight`.`lose` AS `lose`,`arena_unlight`.`update_time` AS `update_time`,`arena_unlight`.`username` AS `username` from `arena_unlight` where ((`arena_unlight`.`ack1` = 1) and (`arena_unlight`.`ack2` = 1)) union all select `arena_unlight`.`u2` AS `char1`,`arena_unlight`.`u3` AS `char2`,`arena_unlight`.`win` AS `win`,`arena_unlight`.`lose` AS `lose`,`arena_unlight`.`update_time` AS `update_time`,`arena_unlight`.`username` AS `username` from `arena_unlight` where ((`arena_unlight`.`ack1` = 1) and (`arena_unlight`.`ack2` = 1))) `combined` where ((`combined`.`char1` is not null) and (`combined`.`char2` is not null)) group by `combined`.`char1`,`combined`.`char2` order by `total_games` desc */;

-- Final view structure for view `arena_statistic`

/*!50001 DROP VIEW IF EXISTS `arena_statistic`*/;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=CURRENT_USER SQL SECURITY DEFINER */
/*!50001 VIEW `arena_statistic` AS select `arena`.`e1` AS `e1`,`arena`.`e2` AS `e2`,`arena`.`e3` AS `e3`,`arena`.`e4` AS `e4`,`arena`.`e5` AS `e5`,`arena`.`u1` AS `u1`,`arena`.`u2` AS `u2`,`arena`.`u3` AS `u3`,`arena`.`u4` AS `u4`,`arena`.`u5` AS `u5`,sum(`arena`.`win`) AS `win`,sum(`arena`.`lose`) AS `lose`,round(((sum(`arena`.`win`) / (sum(`arena`.`win`) + sum(`arena`.`lose`))) * 100),0) AS `rate` from `arena` group by `arena`.`e1`,`arena`.`e2`,`arena`.`e3`,`arena`.`e4`,`arena`.`e5`,`arena`.`u1`,`arena`.`u2`,`arena`.`u3`,`arena`.`u4`,`arena`.`u5` order by `rate` desc */;

-- Final view structure for view `best_3v3_teams_unlight`

/*!50001 DROP VIEW IF EXISTS `best_3v3_teams_unlight`*/;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=CURRENT_USER SQL SECURITY DEFINER */
/*!50001 VIEW `best_3v3_teams_unlight` AS select `combined`.`char1` AS `char1`,`combined`.`char2` AS `char2`,`combined`.`char3` AS `char3`,sum(`combined`.`win`) AS `total_wins`,sum(`combined`.`lose`) AS `total_loses`,sum((`combined`.`win` + `combined`.`lose`)) AS `total_games`,coalesce(((sum(`combined`.`win`) / nullif(sum((`combined`.`win` + `combined`.`lose`)),0)) * 100),0) AS `win_rate`,max(`combined`.`update_time`) AS `update_time`,substring_index(group_concat(`combined`.`username` order by `combined`.`update_time` DESC separator ','),',',1) AS `username` from (select `arena_unlight`.`e1` AS `char1`,`arena_unlight`.`e2` AS `char2`,`arena_unlight`.`e3` AS `char3`,`arena_unlight`.`lose` AS `win`,`arena_unlight`.`win` AS `lose`,`arena_unlight`.`update_time` AS `update_time`,`arena_unlight`.`username` AS `username` from `arena_unlight` where ((`arena_unlight`.`ack1` = 1) and (`arena_unlight`.`ack2` = 1)) union all select `arena_unlight`.`u1` AS `char1`,`arena_unlight`.`u2` AS `char2`,`arena_unlight`.`u3` AS `char3`,`arena_unlight`.`win` AS `win`,`arena_unlight`.`lose` AS `lose`,`arena_unlight`.`update_time` AS `update_time`,`arena_unlight`.`username` AS `username` from `arena_unlight` where ((`arena_unlight`.`ack1` = 1) and (`arena_unlight`.`ack2` = 1))) `combined` group by `combined`.`char1`,`combined`.`char2`,`combined`.`char3` order by `total_games` desc */;

-- Final view structure for view `use_rate1`

/*!50001 DROP VIEW IF EXISTS `use_rate1`*/;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=CURRENT_USER SQL SECURITY DEFINER */
/*!50001 VIEW `use_rate1` AS select `a`.`e1` AS `e1`,`b`.`ico` AS `ico`,`a`.`count_ttl` AS `count_ttl` from ((select `aa`.`e1` AS `e1`,sum(`aa`.`count_num`) AS `count_ttl` from (select `arena`.`e1` AS `e1`,count(`arena`.`e1`) AS `count_num` from `arena` group by `arena`.`e1` union select `arena`.`e2` AS `e2`,count(`arena`.`e2`) AS `count(e2)` from `arena` group by `arena`.`e2` union select `arena`.`e3` AS `e3`,count(`arena`.`e3`) AS `count(e3)` from `arena` group by `arena`.`e3` union select `arena`.`e4` AS `e4`,count(`arena`.`e4`) AS `count(e4)` from `arena` group by `arena`.`e4` union select `arena`.`e5` AS `e5`,count(`arena`.`e5`) AS `count(e5)` from `arena` group by `arena`.`e5` union select `arena`.`u1` AS `u1`,count(`arena`.`u1`) AS `count(u1)` from `arena` group by `arena`.`u1` union select `arena`.`u2` AS `u2`,count(`arena`.`u2`) AS `count(u2)` from `arena` group by `arena`.`u2` union select `arena`.`u3` AS `u3`,count(`arena`.`u3`) AS `count(u3)` from `arena` group by `arena`.`u3` union select `arena`.`u4` AS `u4`,count(`arena`.`u4`) AS `count(u4)` from `arena` group by `arena`.`u4` union select `arena`.`u5` AS `u5`,count(`arena`.`u5`) AS `count(u5)` from `arena` group by `arena`.`u5`) `aa` group by `aa`.`e1`) `a` left join `pokemon` `b` on((`a`.`e1` = `b`.`id`))) where (`a`.`e1` <> '') order by `a`.`e1` */;

-- Final view structure for view `ranking`

/*!50001 DROP VIEW IF EXISTS `ranking`*/;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=CURRENT_USER SQL SECURITY DEFINER */
/*!50001 VIEW `ranking` AS select `arena`.`e1` AS `e1`,`arena`.`e2` AS `e2`,`arena`.`e3` AS `e3`,`arena`.`e4` AS `e4`,`arena`.`e5` AS `e5`,sum(`arena`.`lose`) AS `win`,sum(`arena`.`win`) AS `lose`,round(((sum(`arena`.`lose`) / (sum(`arena`.`lose`) + sum(`arena`.`win`))) * 100),0) AS `rate` from `arena` group by `arena`.`e1`,`arena`.`e2`,`arena`.`e3`,`arena`.`e4`,`arena`.`e5` order by `rate` desc */;

-- Final view structure for view `arena_statistic_unlight`

/*!50001 DROP VIEW IF EXISTS `arena_statistic_unlight`*/;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=CURRENT_USER SQL SECURITY DEFINER */
/*!50001 VIEW `arena_statistic_unlight` AS select `combined`.`e1` AS `e1`,`combined`.`e2` AS `e2`,`combined`.`e3` AS `e3`,`combined`.`u1` AS `u1`,`combined`.`u2` AS `u2`,`combined`.`u3` AS `u3`,sum(`combined`.`win`) AS `win`,sum(`combined`.`lose`) AS `lose`,max(`combined`.`update_time`) AS `update_time`,any_value(`combined`.`username`) AS `last_username`,any_value(`combined`.`username_bp`) AS `last_username_bp`,any_value(`combined`.`enemy`) AS `last_enemy`,any_value(`combined`.`enemy_bp`) AS `last_enemy_bp` from (select `arena_unlight`.`e1` AS `e1`,`arena_unlight`.`e2` AS `e2`,`arena_unlight`.`e3` AS `e3`,`arena_unlight`.`u1` AS `u1`,`arena_unlight`.`u2` AS `u2`,`arena_unlight`.`u3` AS `u3`,`arena_unlight`.`win` AS `win`,`arena_unlight`.`lose` AS `lose`,`arena_unlight`.`update_time` AS `update_time`,`arena_unlight`.`name_p1` AS `username`,`arena_unlight`.`bp_p1` AS `username_bp`,`arena_unlight`.`name_p2` AS `enemy`,`arena_unlight`.`bp_p2` AS `enemy_bp` from `arena_unlight` where ((`arena_unlight`.`ack1` = 1) and (`arena_unlight`.`ack2` = 1)) union all select `arena_unlight`.`u1` AS `e1`,`arena_unlight`.`u2` AS `e2`,`arena_unlight`.`u3` AS `e3`,`arena_unlight`.`e1` AS `u1`,`arena_unlight`.`e2` AS `u2`,`arena_unlight`.`e3` AS `u3`,`arena_unlight`.`lose` AS `win`,`arena_unlight`.`win` AS `lose`,`arena_unlight`.`update_time` AS `update_time`,`arena_unlight`.`name_p2` AS `username`,`arena_unlight`.`bp_p2` AS `username_bp`,`arena_unlight`.`name_p1` AS `enemy`,`arena_unlight`.`bp_p1` AS `enemy_bp` from `arena_unlight` where ((`arena_unlight`.`ack1` = 1) and (`arena_unlight`.`ack2` = 1))) `combined` group by `combined`.`e1`,`combined`.`e2`,`combined`.`e3`,`combined`.`u1`,`combined`.`u2`,`combined`.`u3` */;

-- Final view structure for view `view_char_usage_unlight`

/*!50001 DROP VIEW IF EXISTS `view_char_usage_unlight`*/;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=CURRENT_USER SQL SECURITY DEFINER */
/*!50001 VIEW `view_char_usage_unlight` AS select `char_usage`.`char_id` AS `id`,`b`.`ico` AS `ico`,`b`.`name` AS `name`,`b`.`level` AS `level`,sum(`char_usage`.`count_num`) AS `count_ttl`,sum(`char_usage`.`win`) AS `win`,sum(`char_usage`.`lose`) AS `lose`,round(((sum(`char_usage`.`win`) / nullif(sum((`char_usage`.`win` + `char_usage`.`lose`)),0)) * 100),1) AS `rate` from ((select `arena_unlight`.`e1` AS `char_id`,count(0) AS `count_num`,sum(`arena_unlight`.`lose`) AS `win`,sum(`arena_unlight`.`win`) AS `lose`,`arena_unlight`.`update_time` AS `update_time` from `arena_unlight` group by `arena_unlight`.`e1`,`arena_unlight`.`update_time` union all select `arena_unlight`.`e2` AS `char_id`,count(0) AS `count_num`,sum(`arena_unlight`.`lose`) AS `win`,sum(`arena_unlight`.`win`) AS `lose`,`arena_unlight`.`update_time` AS `update_time` from `arena_unlight` group by `arena_unlight`.`e2`,`arena_unlight`.`update_time` union all select `arena_unlight`.`e3` AS `char_id`,count(0) AS `count_num`,sum(`arena_unlight`.`lose`) AS `win`,sum(`arena_unlight`.`win`) AS `lose`,`arena_unlight`.`update_time` AS `update_time` from `arena_unlight` group by `arena_unlight`.`e3`,`arena_unlight`.`update_time` union all select `arena_unlight`.`u1` AS `char_id`,count(0) AS `count_num`,sum(`arena_unlight`.`win`) AS `win`,sum(`arena_unlight`.`lose`) AS `lose`,`arena_unlight`.`update_time` AS `update_time` from `arena_unlight` group by `arena_unlight`.`u1`,`arena_unlight`.`update_time` union all select `arena_unlight`.`u2` AS `char_id`,count(0) AS `count_num`,sum(`arena_unlight`.`win`) AS `win`,sum(`arena_unlight`.`lose`) AS `lose`,`arena_unlight`.`update_time` AS `update_time` from `arena_unlight` group by `arena_unlight`.`u2`,`arena_unlight`.`update_time` union all select `arena_unlight`.`u3` AS `char_id`,count(0) AS `count_num`,sum(`arena_unlight`.`win`) AS `win`,sum(`arena_unlight`.`lose`) AS `lose`,`arena_unlight`.`update_time` AS `update_time` from `arena_unlight` group by `arena_unlight`.`u3`,`arena_unlight`.`update_time`) `char_usage` left join `unlight` `b` on((`char_usage`.`char_id` = `b`.`id`))) where (`char_usage`.`char_id` is not null) group by `char_usage`.`char_id` order by `count_ttl` desc,`rate` desc */;

-- Final view structure for view `rank_all`

/*!50001 DROP VIEW IF EXISTS `rank_all`*/;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=CURRENT_USER SQL SECURITY DEFINER */
/*!50001 VIEW `rank_all` AS select `aa`.`u1` AS `u1`,`aa`.`u2` AS `u2`,`aa`.`u3` AS `u3`,`aa`.`u4` AS `u4`,`aa`.`u5` AS `u5`,sum(`aa`.`win`) AS `win`,sum(`aa`.`lose`) AS `lose`,round(((sum(`aa`.`win`) / (sum(`aa`.`win`) + sum(`aa`.`lose`))) * 100),0) AS `rate` from (select `ranking_my`.`u1` AS `u1`,`ranking_my`.`u2` AS `u2`,`ranking_my`.`u3` AS `u3`,`ranking_my`.`u4` AS `u4`,`ranking_my`.`u5` AS `u5`,`ranking_my`.`win` AS `win`,`ranking_my`.`lose` AS `lose`,`ranking_my`.`rate` AS `rate` from `ranking_my` union all select `ranking`.`e1` AS `e1`,`ranking`.`e2` AS `e2`,`ranking`.`e3` AS `e3`,`ranking`.`e4` AS `e4`,`ranking`.`e5` AS `e5`,`ranking`.`win` AS `win`,`ranking`.`lose` AS `lose`,`ranking`.`rate` AS `rate` from `ranking`) `aa` group by `aa`.`u1`,`aa`.`u2`,`aa`.`u3`,`aa`.`u4`,`aa`.`u5` order by `rate` desc */;

-- Table structure for table `unlight`

DROP TABLE IF EXISTS `unlight`;
CREATE TABLE `unlight` (
  `id` int(11) NOT NULL,
  `ico` varchar(45) DEFAULT NULL,
  `name` varchar(45) DEFAULT NULL,
  `level` varchar(45) DEFAULT NULL,
  `HP` int(11) DEFAULT NULL,
  `ATK` int(11) DEFAULT NULL,
  `DEF` int(11) DEFAULT NULL,
  `cost` int(11) NOT NULL DEFAULT '0',
  `sword` int(11) DEFAULT NULL,
  `gun` int(11) DEFAULT NULL,
  `shield` int(11) DEFAULT NULL,
  `move` int(11) DEFAULT NULL,
  `special` int(11) DEFAULT NULL,
  `description` varchar(100) DEFAULT NULL,
  `stand_ico` varchar(45) DEFAULT NULL,
  `ico_back` varchar(45) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Table structure for table `unlight_eventindex`

DROP TABLE IF EXISTS `unlight_eventindex`;
CREATE TABLE `unlight_eventindex` (
  `id` int(11) NOT NULL,
  `ico` text,
  `name` text,
  `cost` int(11) DEFAULT NULL,
  `sword` int(11) DEFAULT '0',
  `gun` int(11) DEFAULT '0',
  `shield` int(11) DEFAULT '0',
  `shift` int(11) DEFAULT '0',
  `special` int(11) DEFAULT '0',
  `description` text,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Table structure for table `unlight_weapon`

DROP TABLE IF EXISTS `unlight_weapon`;
CREATE TABLE `unlight_weapon` (
  `id` int(11) NOT NULL,
  `ico` text,
  `name` text,
  `cost` int(11) DEFAULT NULL,
  `atk_melee` int(11) DEFAULT NULL,
  `atk_ranged` int(11) DEFAULT NULL,
  `def_melee` int(11) DEFAULT NULL,
  `def_ranged` int(11) DEFAULT NULL,
  `description` text,
  `char` text,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Table structure for table `visitors`

DROP TABLE IF EXISTS `visitors`;
CREATE TABLE `visitors` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `ip` varchar(45) NOT NULL,
  `user_agent` text NOT NULL,
  `page` varchar(255) NOT NULL,
  `search_term` varchar(255) DEFAULT NULL,
  `character_name` varchar(255) DEFAULT NULL,
  `visited_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `visited_at` (`visited_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
