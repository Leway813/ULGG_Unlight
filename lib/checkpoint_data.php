<?php

/**
 * UL.GG Yearly Checkpoint Data Provider
 * PAGE 1 ~ PAGE 5
 */

function getCheckpointData(PDO $pdo, string $player, int $year): array
{
    return [
        'overview'     => getCheckpointOverview($pdo, $player, $year),      // PAGE 1
        'core_chars'   => getCheckpointCoreCharacters($pdo, $player, $year), // PAGE 2
        'top_teams'    => getCheckpointTopTeams($pdo, $player, $year),      // PAGE 3
        'opponents'    => getCheckpointTopOpponents($pdo, $player, $year),  // PAGE 4
        'style'        => getCheckpointStyleProfile($pdo, $player, $year),  // PAGE 5
    ];
}

# =========================================================
# PAGE 1｜年度對戰概覽
# =========================================================
function getCheckpointOverview(PDO $pdo, string $player, int $year): array
{
    $sql = "
    SELECT
      COUNT(*) AS total_matches,
      SUM(is_win = 1) AS win_cnt,
      SUM(is_win = 0) AS lose_cnt,
      SUM(is_win IS NULL) AS tie_cnt,
      COUNT(DISTINCT DATE_FORMAT(update_time, '%Y-%m')) AS active_months
    FROM v_arena_player_match_result
    WHERE player_name = :player
      AND YEAR(update_time) = :year
  ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'player' => $player,
        'year'   => $year
    ]);

    $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $total = (int)($r['total_matches'] ?? 0);
    $win   = (int)($r['win_cnt'] ?? 0);

    return [
        'total'         => $total,
        'win'           => $win,
        'lose'          => (int)($r['lose_cnt'] ?? 0),
        'tie'           => (int)($r['tie_cnt'] ?? 0),
        'win_rate'      => $total > 0 ? round($win / $total * 100, 1) : 0,
        'active_months' => (int)($r['active_months'] ?? 0),
    ];
}

# =========================================================
# PAGE 2｜核心角色（出場最多）
# =========================================================
function getCheckpointCoreCharacters(PDO $pdo, string $player, int $year, int $limit = 4): array
{
    $sql = "
    SELECT
      u.id AS char_id,
      u.name AS char_name,
      u.ico,
      COUNT(*) AS usage_cnt,
      SUM(v.is_win = 1) AS win_cnt
    FROM v_arena_player_match_result v
    JOIN unlight u ON u.id = v.leader_id
    WHERE v.player_name = :player
      AND YEAR(v.update_time) = :year
    GROUP BY u.id, u.name, u.ico
    ORDER BY usage_cnt DESC
    LIMIT {$limit}
  ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'player' => $player,
        'year'   => $year
    ]);

    return array_map(function ($r) {
        $rate = $r['usage_cnt'] > 0
            ? round($r['win_cnt'] / $r['usage_cnt'] * 100, 1)
            : 0;

        return [
            'char_id'   => (int)$r['char_id'],
            'name'      => $r['char_name'],
            'ico'       => $r['ico'],
            'usage'     => (int)$r['usage_cnt'],
            'win_rate'  => $rate,
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

# =========================================================
# PAGE 3｜最常使用隊伍組合
# =========================================================
function getCheckpointTopTeams(PDO $pdo, string $player, int $year, int $limit = 2): array
{
    $sql = "
    SELECT
      v.leader_id,
      v.back1_id,
      v.back2_id,
      COUNT(*) AS cnt,
      SUM(v.is_win = 1) AS win_cnt
    FROM v_arena_player_match_result v
    WHERE v.player_name = :player
      AND YEAR(v.update_time) = :year
    GROUP BY v.leader_id, v.back1_id, v.back2_id
    HAVING cnt >= 3
    ORDER BY cnt DESC
    LIMIT {$limit}
  ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'player' => $player,
        'year'   => $year
    ]);

    $teams = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {

        $ids = [
            (int)$r['leader_id'],
            (int)$r['back1_id'],
            (int)$r['back2_id']
        ];

        $in = implode(',', $ids);

        $charRows = $pdo->query("
      SELECT id, name, ico
      FROM unlight
      WHERE id IN ({$in})
    ")->fetchAll(PDO::FETCH_ASSOC);

        $charMap = [];
        foreach ($charRows as $c) {
            $charMap[(int)$c['id']] = $c;
        }

        $teams[] = [
            'leader' => $charMap[$ids[0]] ?? null,
            'back1'  => $charMap[$ids[1]] ?? null,
            'back2'  => $charMap[$ids[2]] ?? null,
            'matches' => (int)$r['cnt'],
            'win_rate' => round($r['win_cnt'] / $r['cnt'] * 100, 1),
        ];
    }

    return $teams;
}



# =========================================================
# PAGE 4｜最常遇到的對手（敵方 Leader）
# =========================================================
function getCheckpointTopOpponents(PDO $pdo, string $player, int $year, int $limit = 3): array
{
    $sql = "
    SELECT
      opponent,
      COUNT(*) AS cnt
    FROM (
      SELECT
        CASE
          WHEN name_p1 = :player THEN name_p2
          ELSE name_p1
        END AS opponent
      FROM arena_unlight
      WHERE (name_p1 = :player OR name_p2 = :player)
        AND YEAR(update_time) = :year
        AND ack1 = 1
        AND ack2 = 1
    ) t
    WHERE opponent IS NOT NULL
    GROUP BY opponent
    ORDER BY cnt DESC
    LIMIT {$limit}
  ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'player' => $player,
        'year'   => $year
    ]);

    return array_map(fn($r) => [
        'name'  => $r['opponent'],
        'count' => (int)$r['cnt'],
    ], $stmt->fetchAll(PDO::FETCH_ASSOC));
}





# =========================================================
# PAGE 5｜對戰風格輪廓
# =========================================================
function getCheckpointStyleProfile(PDO $pdo, string $player, int $year): array
{
  // 平均 COST
  $sqlCost = "
    SELECT AVG(cost) AS avg_cost
    FROM v_arena_player_match_result
    WHERE player_name = :player
      AND YEAR(update_time) = :year
      AND cost IS NOT NULL
  ";
  $stmt = $pdo->prepare($sqlCost);
  $stmt->execute([
    'player' => $player,
    'year'   => $year
  ]);
  $avgCost = round((float)$stmt->fetchColumn(), 1);

  // 常見地圖 + 次數
  $sqlStage = "
    SELECT stage, COUNT(*) AS cnt
    FROM arena_unlight
    WHERE (name_p1 = :player OR name_p2 = :player)
      AND YEAR(update_time) = :year
      AND stage IS NOT NULL
    GROUP BY stage
    ORDER BY cnt DESC
    LIMIT 1
  ";
  $stmt = $pdo->prepare($sqlStage);
  $stmt->execute([
    'player' => $player,
    'year'   => $year
  ]);
  $stage = $stmt->fetch(PDO::FETCH_ASSOC);

  return [
    'avg_cost'     => $avgCost,
    'fav_stage'    => $stage['stage'] ?? null,
    'fav_stage_cnt'=> $stage['cnt'] ?? 0,
  ];
}

