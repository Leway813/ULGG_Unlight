<?php

/**
 * _analysis_base.php
 * 共用分析頁 Context（不輸出 HTML）
 */
function buildUrl(string $path, array $params): string
{
  return $path . '?' . http_build_query($params);
}
// ===============================
// 分析期間（days）
// ===============================
$DAYS = 90; // default

if (isset($_GET['days'])) {
  $d = (int)$_GET['days'];
  if (in_array($d, [14, 30, 90], true)) {
    $DAYS = $d;
  }
}

// ===============================
// COST 區間定義（統一用這一份）
// ===============================

function buildCostCondition(string $costKey): array
{
  switch ($costKey) {
    case 'C1':
      return ['sql' => 'm.cost BETWEEN 50 AND 59', 'label' => '50–59'];
    case 'C2':
      return ['sql' => 'm.cost BETWEEN 60 AND 69', 'label' => '60–69'];
    case 'C3':
      return ['sql' => 'm.cost BETWEEN 70 AND 80', 'label' => '70–80'];
    case 'C4':
      return ['sql' => 'm.cost >= 90',           'label' => '90+'];
    default:
      return ['sql' => '1=1',                     'label' => '全部'];
  }
}
function getBestCombosIncludingCharacter(
  PDO $pdo,
  int $charId,
  int $days,
  float $baseWinRate,
  string $costSql,      // ✅ 直接吃 SQL
  int $limit = 5,
  int $minMatches = 10
): array {

  $sql = "SELECT
      m.leader_id,
      m.back1_id AS mate_a,
      m.back2_id AS mate_b,
      COUNT(*) AS matches,
      ROUND(AVG(m.is_win) * 100, 1) AS win_rate,

      ROUND(
        (AVG(m.is_win) * 100 - :base_wr)
        * LOG(COUNT(*) + 1)
        * (COUNT(*) / (COUNT(*) + 30))
        * LOG(COUNT(*) + 1),
        2
      ) AS score,

      ul1.name  AS leader_name,
      ul1.level AS leader_lv,
      ul1.ico   AS leader_ico,
      ul2.name  AS mate_a_name,
      ul2.level AS mate_a_lv,
      ul2.ico   AS mate_a_ico,
      ul3.name  AS mate_b_name,
      ul3.level AS mate_b_lv,
      ul3.ico   AS mate_b_ico

    FROM arena_player_match_result m
    JOIN unlight ul1 ON ul1.id = m.leader_id
    JOIN unlight ul2 ON ul2.id = m.back1_id
    JOIN unlight ul3 ON ul3.id = m.back2_id

    WHERE
      :cid IN (m.leader_id, m.back1_id, m.back2_id)
      AND m.update_time >= DATE_SUB(NOW(), INTERVAL :days DAY)
      AND {$costSql}

    GROUP BY
      m.leader_id, m.back1_id, m.back2_id

    HAVING
      COUNT(*) >= :min_matches
      AND (AVG(m.is_win) * 100 - :base_wr) > 0

    ORDER BY score DESC
    LIMIT :limit
  ";

  $stmt = $pdo->prepare($sql);
  $stmt->bindValue(':cid', $charId, PDO::PARAM_INT);
  $stmt->bindValue(':days', $days, PDO::PARAM_INT);
  $stmt->bindValue(':base_wr', $baseWinRate, PDO::PARAM_STR);
  $stmt->bindValue(':min_matches', $minMatches, PDO::PARAM_INT);
  $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
  $stmt->execute();

  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
  if (!$rows) return [];

  return array_map(static function ($r) {
    return [
      'leader' => [
        'id'   => (int)$r['leader_id'],
        'name' => $r['leader_name'],
        'lv'   => $r['leader_lv'],
        'ico'  => $r['leader_ico'],
      ],
      'mates' => [
        [
          'id'   => (int)$r['mate_a'],
          'name' => $r['mate_a_name'],
          'lv'   => $r['mate_a_lv'],
          'ico'  => $r['mate_a_ico'],
        ],
        [
          'id'   => (int)$r['mate_b'],
          'name' => $r['mate_b_name'],
          'lv'   => $r['mate_b_lv'],
          'ico'  => $r['mate_b_ico'],
        ],
      ],
      'matches'  => (int)$r['matches'],
      'win_rate' => (float)$r['win_rate'],
      'score'    => (float)$r['score'],
    ];
  }, $rows);
}
function getBestTeammates(
  PDO $pdo,
  int $charId,
  int $days,
  float $baseWinRate,
  string $costSql,
  int $limit = 5,
  int $minMatches = 10
): array {

  $sql = "
    SELECT
      ul.id    AS char_id,
      ul.name  AS name,
      ul.level AS lv,
      ul.ico   AS ico,

      COUNT(*) AS matches,
      ROUND(AVG(m.is_win) * 100, 1) AS win_rate,

      ROUND(
        (AVG(m.is_win) * 100 - :base_wr)
        * LOG(COUNT(*) + 1)
        * (COUNT(*) / (COUNT(*) + 30)),
        2
      ) AS score

    FROM arena_player_match_result m
    JOIN unlight ul
      ON ul.id IN (m.leader_id, m.back1_id, m.back2_id)

    WHERE
      :cid IN (m.leader_id, m.back1_id, m.back2_id)
      AND ul.id <> :cid
      AND m.update_time >= DATE_SUB(NOW(), INTERVAL :days DAY)
      AND {$costSql}

    GROUP BY ul.id

    HAVING
      COUNT(*) >= :min_matches
      AND (AVG(m.is_win) * 100 - :base_wr) > 0

    ORDER BY score DESC
    LIMIT :limit
  ";

  $stmt = $pdo->prepare($sql);
  $stmt->bindValue(':cid', $charId, PDO::PARAM_INT);
  $stmt->bindValue(':days', $days, PDO::PARAM_INT);
  $stmt->bindValue(':base_wr', $baseWinRate, PDO::PARAM_STR);
  $stmt->bindValue(':min_matches', $minMatches, PDO::PARAM_INT);
  $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
  $stmt->execute();

  return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
function getTopPlayersByCharacterWithBP(
  PDO $pdo,
  int $charId,
  int $days,
  float $baseWinRate,
  string $costSql,
  int $limit = 10,
  int $minMatches = 20
): array {

  $sql = "
WITH
bp_tw AS (
  SELECT name, rank_num
  FROM ranking_bp_TW
  WHERE ts = (SELECT MAX(ts) FROM ranking_bp_TW)
),
bp_jp AS (
  SELECT name, rank_num
  FROM ranking_bp_JP
  WHERE ts = (SELECT MAX(ts) FROM ranking_bp_JP)
)

SELECT
  m.player_name,
  COUNT(*) AS matches,
  ROUND(AVG(m.is_win) * 100, 1) AS win_rate,

  ROUND(
    (AVG(m.is_win) * 100 - :base_wr)
    * LOG(COUNT(*) + 1)
    * (COUNT(*) / (COUNT(*) + 30)),
    2
  ) AS score,

  COALESCE(bp_tw.rank_num, bp_jp.rank_num) AS bp_rank,

  CASE
    WHEN bp_tw.rank_num IS NOT NULL THEN 'TW'
    WHEN bp_jp.rank_num IS NOT NULL THEN 'JP'
    ELSE NULL
  END AS region

FROM arena_player_match_result m
LEFT JOIN bp_tw ON bp_tw.name = m.player_name
LEFT JOIN bp_jp ON bp_jp.name = m.player_name

WHERE
  :cid IN (m.leader_id, m.back1_id, m.back2_id)
  AND m.is_win IS NOT NULL
  AND m.update_time >= DATE_SUB(NOW(), INTERVAL :days DAY)
  AND {$costSql}

GROUP BY m.player_name

HAVING
  COUNT(*) >= :min_matches
  AND (AVG(m.is_win) * 100 - :base_wr) > 0

ORDER BY score DESC
LIMIT :limit
";

  $stmt = $pdo->prepare($sql);
  $stmt->bindValue(':cid', $charId, PDO::PARAM_INT);
  $stmt->bindValue(':days', $days, PDO::PARAM_INT);
  $stmt->bindValue(':base_wr', $baseWinRate, PDO::PARAM_STR);
  $stmt->bindValue(':min_matches', $minMatches, PDO::PARAM_INT);
  $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
  $stmt->execute();

  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

  return array_map(static function ($r) {
    return [
      'player'   => $r['player_name'],
      'matches'  => (int)$r['matches'],
      'win_rate' => (float)$r['win_rate'],
      'score'    => (float)$r['score'],
      'bp_rank'  => $r['bp_rank'] !== null ? (int)$r['bp_rank'] : null,
      'region'   => $r['region'], // ✅ 關鍵
    ];
  }, $rows);
}
function regionIcon($region)
{
  switch ($region) {
    case 'TW':
      return '<i class="fab fa-steam"></i>';

    case 'JP':
      return '<span class="server-icon dmm">D</span>';

    case 'CR':
      return '<span class="region-globe">☯</span>'; //🌐

    default:
      return '';
  }
}

// 統一提供 metaDays（未來可擴充）
$metaDays = $DAYS;

// SQL 用條件
$sqlMetaWhere = '';
$sqlMetaParam = [];

if ($metaDays !== null) {
  $sqlMetaWhere = ' AND update_time >= NOW() - INTERVAL ? DAY ';
  $sqlMetaParam[] = $metaDays;
}

// ===============================
// 伺服器（server）
// ===============================
$server = strtoupper($_GET['server'] ?? $_SESSION['server'] ?? 'ALL');
if (!in_array($server, ['ALL', 'STEAM', 'DMM'], true)) {
  $server = 'ALL';
}
$_SESSION['server'] = $server;

function getCharacterBaseWinRate(
  PDO $pdo,
  int $charId,
  int $days,
  string $costSql
): float {

  $sql = "
    SELECT
      ROUND(AVG(m.is_win) * 100, 2) AS base_wr
    FROM arena_player_match_result m
    WHERE
      :cid IN (m.leader_id, m.back1_id, m.back2_id)
      AND m.is_win IS NOT NULL
      AND m.update_time >= DATE_SUB(NOW(), INTERVAL :days DAY)
      AND {$costSql}
  ";

  $stmt = $pdo->prepare($sql);
  $stmt->bindValue(':cid', $charId, PDO::PARAM_INT);
  $stmt->bindValue(':days', $days, PDO::PARAM_INT);
  $stmt->execute();

  $wr = $stmt->fetchColumn();
  return $wr !== null ? (float)$wr : 50.0;
}

// 之後可加：rank / mode / meta tag...
function getCharacterEventUsage(
  PDO $pdo,
  int $charId,
  int $days,
  string $costSql,
  float $baseWinRate,
  int $limit = 15
): array {

  $sql = "
SELECT
  e.id   AS event_id,
  e.name,
  e.ico,

  COUNT(*) AS matches,
  ROUND(AVG(m.is_win) * 100, 1) AS win_rate,

  ROUND(
    50 + 50 * (
      (
        EXP(
          2 * (
            SIGN(AVG(m.is_win) * 100 - :base_wr)
            * SQRT(ABS(AVG(m.is_win) * 100 - :base_wr))
            * LOG(COUNT(*) + 1)
            * (COUNT(*) / (COUNT(*) + 80))
          ) / 6
        ) - 1
      ) / (
        EXP(
          2 * (
            SIGN(AVG(m.is_win) * 100 - :base_wr)
            * SQRT(ABS(AVG(m.is_win) * 100 - :base_wr))
            * LOG(COUNT(*) + 1)
            * (COUNT(*) / (COUNT(*) + 80))
          ) / 6
        ) + 1
      )
    ),
    1
  ) AS score

FROM arena_player_match_result m
JOIN arena_unlight a
  ON a.id = m.match_id
JOIN arena_deck_event d
  ON d.match_id = m.match_id
 AND d.side = m.side
JOIN unlight_eventindex e
  ON e.id = d.event_id1

WHERE
  :cid IN (m.leader_id, m.back1_id, m.back2_id)
  AND m.update_time >= DATE_SUB(NOW(), INTERVAL :days DAY)
  AND {$costSql}

GROUP BY e.id
HAVING COUNT(*) >= 30
ORDER BY score DESC
LIMIT :limit
";

  $stmt = $pdo->prepare($sql);
  $stmt->bindValue(':cid', $charId, PDO::PARAM_INT);
  $stmt->bindValue(':days', $days, PDO::PARAM_INT);
  $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
  $stmt->bindValue(':base_wr', $baseWinRate, PDO::PARAM_STR);
  $stmt->execute();

  return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getTeamBuildEventRanking(
  PDO $pdo,
  string $teamKey,
  int $days,
  string $costSql,
  float $baseWinRate,
  int $limit = 15,
  int $minMatches = 1
): array {

  $sql = "
SELECT
  e.id   AS event_id,
  e.name,
  e.ico,

  COUNT(*) AS matches,
  ROUND(AVG(m.is_win) * 100, 1) AS win_rate,

  ROUND(
    50 + 50 * (
      (
        EXP(
          2 * (
            SIGN(AVG(m.is_win) * 100 - :base_wr)
            * SQRT(ABS(AVG(m.is_win) * 100 - :base_wr))
            * LOG(COUNT(*) + 1)
            * (COUNT(*) / (COUNT(*) + 80))
          ) / 6
        ) - 1
      ) / (
        EXP(
          2 * (
            SIGN(AVG(m.is_win) * 100 - :base_wr)
            * SQRT(ABS(AVG(m.is_win) * 100 - :base_wr))
            * LOG(COUNT(*) + 1)
            * (COUNT(*) / (COUNT(*) + 80))
          ) / 6
        ) + 1
      )
    ),
    1
  ) AS score

FROM arena_player_match_result m
JOIN arena_deck_event d
  ON d.match_id = m.match_id
 AND d.side = m.side
JOIN unlight_eventindex e
  ON e.id = d.event_id1

WHERE
  m.team_key = :team_key
  AND m.is_win IS NOT NULL
  AND m.update_time >= DATE_SUB(NOW(), INTERVAL :days DAY)
  AND {$costSql}

GROUP BY e.id
HAVING COUNT(*) >= :min_matches
ORDER BY score DESC
LIMIT :limit
";

  $stmt = $pdo->prepare($sql);
  $stmt->bindValue(':team_key', $teamKey, PDO::PARAM_STR);
  $stmt->bindValue(':days', $days, PDO::PARAM_INT);
  $stmt->bindValue(':base_wr', $baseWinRate, PDO::PARAM_STR);
  $stmt->bindValue(':min_matches', $minMatches, PDO::PARAM_INT);
  $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
  $stmt->execute();

  return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}


function getTeamEventDelta(
  PDO $pdo,
  string $teamKey,
  int $days,
  int $limit = 10,
  int $minMatches = 1
): array {

  $sql = "
WITH base AS (
  SELECT DISTINCT
    m.match_id,
    m.side,
    m.is_win,
    d.event_id1 AS event_id
  FROM arena_player_match_result m
  JOIN arena_deck_event d
    ON d.match_id = m.match_id
   AND d.side = m.side
  WHERE
    m.team_key = :team_key
    AND m.is_win IS NOT NULL
    AND m.update_time >= DATE_SUB(NOW(), INTERVAL :days DAY)
),
event_match AS (
  SELECT
    event_id,
    match_id,
    is_win
  FROM base
),
event_stat AS (
  SELECT
    event_id,

    COUNT(DISTINCT match_id)                             AS with_cnt,
    SUM(is_win)                                          AS with_win,

    (
      SELECT COUNT(DISTINCT b2.match_id)
      FROM event_match b2
      WHERE b2.match_id NOT IN (
        SELECT match_id FROM event_match b3
        WHERE b3.event_id = em.event_id
      )
    )                                                    AS without_cnt,

    (
      SELECT SUM(is_win)
      FROM event_match b2
      WHERE b2.match_id NOT IN (
        SELECT match_id FROM event_match b3
        WHERE b3.event_id = em.event_id
      )
    )                                                    AS without_win

  FROM event_match em
  GROUP BY event_id
)
SELECT
  e.id   AS event_id,
  e.name,
  e.ico,

  ROUND(with_win / NULLIF(with_cnt,0) * 100, 1)        AS win_with,
  ROUND(without_win / NULLIF(without_cnt,0) * 100, 1) AS win_without,

  ROUND(
    (with_win / NULLIF(with_cnt,0)
   - without_win / NULLIF(without_cnt,0)) * 100,
    1
  ) AS delta,

  with_cnt AS matches

FROM event_stat s
JOIN unlight_eventindex e ON e.id = s.event_id

WHERE
  with_cnt >= :min_matches
  AND without_cnt >= :min_matches

ORDER BY delta DESC
LIMIT :limit
";

  $stmt = $pdo->prepare($sql);
  $stmt->bindValue(':team_key', $teamKey, PDO::PARAM_STR);
  $stmt->bindValue(':days', $days, PDO::PARAM_INT);
  $stmt->bindValue(':min_matches', $minMatches, PDO::PARAM_INT);
  $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
  $stmt->execute();

  return $stmt->fetchAll(PDO::FETCH_ASSOC);
}




function getCharacterEventDelta(
  PDO $pdo,
  int $charId,
  int $days,
  string $costSql,
  int $limit = 10,
  int $minMatches = 20
): array {

  $sql = "
WITH base AS (
  SELECT
    m.match_id,
    m.side,
    m.is_win,
    d.event_id1 AS main_event_id
  FROM arena_player_match_result m
  JOIN arena_unlight a
    ON a.id = m.match_id
  JOIN arena_deck_event d
    ON d.match_id = m.match_id
   AND d.side = m.side
  WHERE
    :cid IN (m.leader_id, m.back1_id, m.back2_id)
    AND m.update_time >= DATE_SUB(NOW(), INTERVAL :days DAY)
    AND {$costSql}
),
event_stat AS (
  SELECT
    main_event_id AS event_id,

    COUNT(*)                                   AS with_cnt,
    SUM(is_win)                                AS with_win,

    (
      SELECT COUNT(*) FROM base b2
      WHERE b2.main_event_id <> b1.main_event_id
    ) AS without_cnt,

    (
      SELECT SUM(is_win) FROM base b2
      WHERE b2.main_event_id <> b1.main_event_id
    ) AS without_win

  FROM base b1
  GROUP BY main_event_id
)
SELECT
  e.id   AS event_id,
  e.name,
  e.ico,

  ROUND(with_win / NULLIF(with_cnt,0) * 100, 1)        AS win_with,
  ROUND(without_win / NULLIF(without_cnt,0) * 100, 1) AS win_without,

  ROUND(
    (with_win / NULLIF(with_cnt,0)
   - without_win / NULLIF(without_cnt,0)) * 100,
    1
  ) AS delta,

  with_cnt AS matches

FROM event_stat s
JOIN unlight_eventindex e ON e.id = s.event_id

WHERE
  with_cnt >= :min_matches
  AND without_cnt >= :min_matches

ORDER BY delta DESC
LIMIT :limit
";

  $stmt = $pdo->prepare($sql);
  $stmt->bindValue(':cid', $charId, PDO::PARAM_INT);
  $stmt->bindValue(':days', $days, PDO::PARAM_INT);
  $stmt->bindValue(':min_matches', $minMatches, PDO::PARAM_INT);
  $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
  $stmt->execute();

  return $stmt->fetchAll(PDO::FETCH_ASSOC);
}



/* 隊伍事件卡構築 */
function getBestTeamEventBuilds(
  PDO $pdo,
  int $charId,
  int $days,
  string $costSql,
  int $limit = 5,
  int $minMatches = 10
): array {

  $sql = "SELECT
    m.leader_id,
    LEAST(m.back1_id, m.back2_id)     AS back_a,
    GREATEST(m.back1_id, m.back2_id)  AS back_b,

    COUNT(*) AS matches,
    ROUND(AVG(m.is_win) * 100, 1) AS win_rate,

    JSON_ARRAYAGG(
      CASE
        WHEN m.side = 'P1' THEN a.eventindex1
        ELSE a.eventindex2
      END
    ) AS events_json

  FROM arena_player_match_result m
  JOIN arena_unlight a ON a.id = m.match_id

  WHERE
    :cid IN (m.leader_id, m.back1_id, m.back2_id)
    AND m.update_time >= DATE_SUB(NOW(), INTERVAL :days DAY)
    AND {$costSql}

  GROUP BY
    m.leader_id,
    back_a,
    back_b

  HAVING COUNT(*) >= :min_matches

  ORDER BY win_rate DESC, matches DESC
  LIMIT :limit
  ";

  $stmt = $pdo->prepare($sql);
  $stmt->bindValue(':cid', $charId, PDO::PARAM_INT);
  $stmt->bindValue(':days', $days, PDO::PARAM_INT);
  $stmt->bindValue(':min_matches', $minMatches, PDO::PARAM_INT);
  $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
  $stmt->execute();

  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
  if (!$rows) return [];

  $result = [];

  foreach ($rows as $r) {

    $matches = (int)$r['matches'];

    // ① 拿出事件卡統計（你原本的 helper）
    $events = extractTopEventsFromEventsJson(
      $pdo,
      $r['events_json']
    );

    if (!$events || $matches <= 0) {
      continue;
    }

    // ✅ 新版：平均每場 ≥ 1 張
    $coreEvents = array_filter($events, function ($e) use ($matches) {
      if (empty($e['count']) || $matches <= 0) return false;

      return ($e['count'] / $matches) >= 3;
    });

    // ③ 至少要 2 張核心事件卡，才算構築
    if (count($coreEvents) < 2) {
      continue;
    }

    // ④ OK → 放入結果
    $result[] = [
      'chars' => [
        ...getCharacterBasic($pdo, (int)$r['leader_id']),
        ...getCharacterBasic($pdo, (int)$r['back_a']),
        ...getCharacterBasic($pdo, (int)$r['back_b']),
      ],
      'events'   => array_values($coreEvents), // reindex
      'matches'  => $matches,
      'win_rate' => (float)$r['win_rate'],
    ];
  }

  return $result;
}

/**
 * 從 events_json 中萃取「隊伍常用事件卡」
 * 回傳：已排序的事件卡資料（含出現率）
 */
function extractTopEventsFromEventsJson(
  PDO $pdo,
  ?string $eventsJson,
  int $limit = 8
): array {

  // ① 完全沒資料（理論上不該發生，但防呆）
  if (!$eventsJson) {
    return [];
  }

  $raw = json_decode($eventsJson, true);
  if (!is_array($raw)) {
    return [];
  }

  // ② 檢查是否為 [null, null, null ...]（你現在遇到的）
  $hasAnyValue = false;
  foreach ($raw as $item) {
    if ($item !== null && $item !== 'null') {
      $hasAnyValue = true;
      break;
    }
  }

  if (!$hasAnyValue) {
    return [[
      'id'    => null,
      'name'  => '事件卡資料未記錄',
      'ico'   => null,
      'count' => 0,
      'type'  => 'no-data'
    ]];
  }

  // ③ 正常展開事件卡
  $flat = [];

  foreach ($raw as $item) {

    // item 是 JSON 字串（最常見）
    if (is_string($item)) {
      $decoded = json_decode($item, true);
      if (is_array($decoded)) {
        foreach ($decoded as $eid) {
          if ($eid !== null) {
            $flat[] = (int)$eid;
          }
        }
      }
      continue;
    }

    // item 已經是 array
    if (is_array($item)) {
      foreach ($item as $eid) {
        if ($eid !== null) {
          $flat[] = (int)$eid;
        }
      }
    }
  }

  // ④ 有資料結構，但實際上沒有任何事件卡
  if (empty($flat)) {
    return [[
      'id'    => null,
      'name'  => '無常用事件卡',
      'ico'   => null,
      'count' => 0,
      'type'  => 'empty'
    ]];
  }

  // ⑤ 統計
  $counts = array_count_values($flat);
  arsort($counts);

  $topIds = array_slice(array_keys($counts), 0, $limit);
  if (empty($topIds)) {
    return [];
  }

  $in = implode(',', array_fill(0, count($topIds), '?'));
  $stmt = $pdo->prepare("
    SELECT id, name, ico
    FROM unlight_eventindex
    WHERE id IN ($in)
  ");
  $stmt->execute($topIds);

  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $result = [];
  foreach ($rows as $r) {
    $id = (int)$r['id'];
    $result[] = [
      'id'    => $id,
      'name'  => $r['name'],
      'ico'   => $r['ico'],
      'count' => $counts[$id] ?? 0,
      'type'  => 'normal'
    ];
  }

  usort($result, fn($a, $b) => $b['count'] <=> $a['count']);

  return $result;
}




/**
 * 取得角色基本資料（給分析用）
 */
function getCharacterBasic(PDO $pdo, int $charId): array
{
  static $cache = [];

  if (isset($cache[$charId])) {
    return $cache[$charId];
  }

  $stmt = $pdo->prepare("
    SELECT
      id,
      name,
      level AS lv,
      ico
    FROM unlight
    WHERE id = :id
    LIMIT 1
  ");
  $stmt->bindValue(':id', $charId, PDO::PARAM_INT);
  $stmt->execute();

  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$row) {
    return [];
  }

  return $cache[$charId] = [[
    'id'   => (int)$row['id'],
    'name' => $row['name'],
    'lv'   => $row['lv'],
    'ico'  => $row['ico'],
  ]];
}

/**
 * 將 eventindex JSON 轉為事件卡資料陣列
 */
function getEventsByEventIndex(PDO $pdo, string $json): array
{
  $ids = json_decode($json, true);
  if (!is_array($ids) || empty($ids)) {
    return [];
  }

  // 去重，避免同卡顯示一堆 badge
  $ids = array_values(array_unique(array_map('intval', $ids)));

  $in = implode(',', array_fill(0, count($ids), '?'));

  $stmt = $pdo->prepare("
    SELECT
      id,
      name,
      ico
    FROM unlight_eventindex
    WHERE id IN ($in)
    ORDER BY id
  ");
  $stmt->execute($ids);

  return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getCharacterTopMainEventFast(
  PDO $pdo,
  int $charId,
  int $days,
  string $costSql
): ?array {

  $sql = "
    SELECT
      e.id,
      e.name,
      e.ico,
      COUNT(*) AS cnt,
      ROUND(AVG(m.is_win) * 100, 1) AS win_rate
    FROM arena_deck_event d
    JOIN arena_player_match_result m
      ON m.match_id = d.match_id
     AND m.side = d.side
    JOIN unlight_eventindex e
      ON e.id = d.event_id1
    WHERE
      d.event_id1 IS NOT NULL
      AND :cid IN (m.leader_id, m.back1_id, m.back2_id)
      AND m.update_time >= DATE_SUB(NOW(), INTERVAL :days DAY)
      AND {$costSql}
    GROUP BY e.id
    ORDER BY cnt DESC
    LIMIT 1
  ";

  $stmt = $pdo->prepare($sql);
  $stmt->bindValue(':cid', $charId, PDO::PARAM_INT);
  $stmt->bindValue(':days', $days, PDO::PARAM_INT);
  $stmt->execute();

  return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function resolveCardLevelLabel(int $level, int $rarity): string
{
  if ($rarity <= 5) {
    return 'L' . $level;
  }
  return 'R' . ($rarity - 5);
}

function getWorstEnemyCombos(
  PDO $pdo,
  int $charId,
  int $days,
  ?array $costRange,
  int $limit = 5,
  int $minMatches = 3
): array {

  $costSql = '';
  if ($costRange) {
    $costSql = 'AND me.cost BETWEEN :cost_min AND :cost_max';
  }

  $sql = "SELECT
  enemy.leader_id                                      AS enemy_leader,
  LEAST(enemy.back1_id, enemy.back2_id)                AS enemy_back_a,
  GREATEST(enemy.back1_id, enemy.back2_id)             AS enemy_back_b,

  COUNT(*)                                             AS matches,

  -- 我方
  SUM(me.is_win = 1)                                   AS my_win,
  SUM(me.is_win = 0)                                   AS my_lose,
  ROUND(AVG(me.is_win) * 100, 1)                        AS my_win_rate,

  -- 敵方勝率
  ROUND((1 - AVG(me.is_win)) * 100, 1)                  AS enemy_win_rate,

  -- ⭐ 加權風險分數（核心）
  ROUND(
    ((1 - AVG(me.is_win)) * 100)
    * LOG10(COUNT(*) + 1)
    * LEAST(1, COUNT(*) / 5),   -- 🔧 30 → 10
    2
  ) AS risk_score,

  ul1.name  AS leader_name,
  ul1.level AS leader_lv,
  ul1.ico   AS leader_ico,

  ul2.name  AS mate_a_name,
  ul2.level AS mate_a_lv,
  ul2.ico   AS mate_a_ico,

  ul3.name  AS mate_b_name,
  ul3.level AS mate_b_lv,
  ul3.ico   AS mate_b_ico

  FROM arena_player_match_result me
  JOIN arena_player_match_result enemy
    ON enemy.match_id = me.match_id
  AND enemy.side <> me.side

  JOIN unlight ul1 ON ul1.id = enemy.leader_id
  JOIN unlight ul2 ON ul2.id = enemy.back1_id
  JOIN unlight ul3 ON ul3.id = enemy.back2_id

  WHERE
    :cid IN (me.leader_id, me.back1_id, me.back2_id)
    AND :cid NOT IN (enemy.leader_id, enemy.back1_id, enemy.back2_id)
    AND me.is_win IS NOT NULL
    AND me.update_time >= DATE_SUB(NOW(), INTERVAL :days DAY)
    {$costSql}

  GROUP BY
    enemy_leader,
    enemy_back_a,
    enemy_back_b

  HAVING COUNT(*) >= :min_matches
  AND (1 - AVG(me.is_win)) >= 0.4

  -- 🔥 真正的排序依據
  ORDER BY risk_score DESC

  LIMIT :limit
  ";

  $stmt = $pdo->prepare($sql);

  $stmt->bindValue(':cid', $charId, PDO::PARAM_INT);
  $stmt->bindValue(':days', $days, PDO::PARAM_INT);
  $stmt->bindValue(':min_matches', $minMatches, PDO::PARAM_INT);
  $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);

  if ($costRange) {
    $stmt->bindValue(':cost_min', $costRange[0], PDO::PARAM_INT);
    $stmt->bindValue(':cost_max', $costRange[1], PDO::PARAM_INT);
  }

  $stmt->execute();
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

  return array_map(static function ($r) {
    return [
      'leader' => [
        'id'   => (int)$r['enemy_leader'],
        'name' => $r['leader_name'],
        'lv'   => $r['leader_lv'],
        'ico'  => $r['leader_ico'],
      ],
      'mates' => [
        [
          'id'   => (int)$r['enemy_back_a'],
          'name' => $r['mate_a_name'],
          'lv'   => $r['mate_a_lv'],
          'ico'  => $r['mate_a_ico'],
        ],
        [
          'id'   => (int)$r['enemy_back_b'],
          'name' => $r['mate_b_name'],
          'lv'   => $r['mate_b_lv'],
          'ico'  => $r['mate_b_ico'],
        ],
      ],
      'matches'        => (int)$r['matches'],
      'my_win'         => (int)$r['my_win'],
      'my_lose'        => (int)$r['my_lose'],
      'my_win_rate'    => (float)$r['my_win_rate'],
      'enemy_win_rate' => (float)$r['enemy_win_rate'],
      'risk_score'     => (float)$r['risk_score'],
    ];
  }, $rows);
}
function getWorstEnemyCombosByTeam(
  PDO $pdo,
  string $teamKey,
  int $days,
  ?array $costRange,
  int $limit = 5,
  int $minMatches = 3
): array {

  $costSql = '';
  if ($costRange) {
    $costSql = 'AND me.cost BETWEEN :cost_min AND :cost_max';
  }

  $sql = "
    SELECT
      enemy.leader_id                                      AS enemy_leader,
      LEAST(enemy.back1_id, enemy.back2_id)                AS enemy_back_a,
      GREATEST(enemy.back1_id, enemy.back2_id)             AS enemy_back_b,

      COUNT(*)                                             AS matches,

      -- 我方
      SUM(me.is_win = 1)                                   AS my_win,
      SUM(me.is_win = 0)                                   AS my_lose,
      ROUND(AVG(me.is_win) * 100, 1)                        AS my_win_rate,

      -- 敵方勝率
      ROUND((1 - AVG(me.is_win)) * 100, 1)                  AS enemy_win_rate,

      -- ⭐ 風險分數（與單人版一致）
      ROUND(
        ((1 - AVG(me.is_win)) * 100)
        * LOG10(COUNT(*) + 1)
        * LEAST(1, COUNT(*) / 5),
        2
      ) AS risk_score,

      ul1.name  AS leader_name,
      ul1.level AS leader_lv,
      ul1.ico   AS leader_ico,

      ul2.name  AS mate_a_name,
      ul2.level AS mate_a_lv,
      ul2.ico   AS mate_a_ico,

      ul3.name  AS mate_b_name,
      ul3.level AS mate_b_lv,
      ul3.ico   AS mate_b_ico

    FROM arena_player_match_result me
    JOIN arena_player_match_result enemy
      ON enemy.match_id = me.match_id
    AND enemy.side <> me.side

    JOIN unlight ul1 ON ul1.id = enemy.leader_id
    JOIN unlight ul2 ON ul2.id = enemy.back1_id
    JOIN unlight ul3 ON ul3.id = enemy.back2_id

    WHERE
      me.team_key = :team_key
      AND me.is_win IS NOT NULL
      AND me.update_time >= DATE_SUB(NOW(), INTERVAL :days DAY)
      {$costSql}

    GROUP BY
      enemy_leader,
      enemy_back_a,
      enemy_back_b

    HAVING
      COUNT(*) >= :min_matches
       AND AVG(me.is_win) < 0.5

    ORDER BY risk_score DESC
    LIMIT :limit
    ";

  $stmt = $pdo->prepare($sql);

  $stmt->bindValue(':team_key', $teamKey);
  $stmt->bindValue(':days', $days, PDO::PARAM_INT);
  $stmt->bindValue(':min_matches', $minMatches, PDO::PARAM_INT);
  $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);

  if ($costRange) {
    $stmt->bindValue(':cost_min', $costRange[0], PDO::PARAM_INT);
    $stmt->bindValue(':cost_max', $costRange[1], PDO::PARAM_INT);
  }

  $stmt->execute();
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

  return array_map(static function ($r) {
    return [
      'leader' => [
        'id'   => (int)$r['enemy_leader'],
        'name' => $r['leader_name'],
        'lv'   => $r['leader_lv'],
        'ico'  => $r['leader_ico'],
      ],
      'mates' => [
        [
          'id'   => (int)$r['enemy_back_a'],
          'name' => $r['mate_a_name'],
          'lv'   => $r['mate_a_lv'],
          'ico'  => $r['mate_a_ico'],
        ],
        [
          'id'   => (int)$r['enemy_back_b'],
          'name' => $r['mate_b_name'],
          'lv'   => $r['mate_b_lv'],
          'ico'  => $r['mate_b_ico'],
        ],
      ],
      'matches'        => (int)$r['matches'],
      'my_win'         => (int)$r['my_win'],
      'my_lose'        => (int)$r['my_lose'],
      'my_win_rate'    => (float)$r['my_win_rate'],
      'enemy_win_rate' => (float)$r['enemy_win_rate'],
      'risk_score'     => (float)$r['risk_score'],
    ];
  }, $rows);
}

function getCounterCharacters(
  PDO $pdo,
  int $charId,
  int $days,
  ?array $costRange,   // 這版先保留介面，相容舊呼叫
  int $limit = 5,
  int $minMatches = 20
): array {

  $costSql = '';
  if ($costRange && isset($costRange[0], $costRange[1])) {
    $costSql = ' AND cost BETWEEN :cost_min AND :cost_max ';
  }

  $sql = "SELECT
  t.char_id,
  ul.name,
  ul.level,
  ul.ico,
  COUNT(*) AS matches,
  SUM(t.win) AS win,
  SUM(t.lose) AS lose,
  SUM(t.tie) AS tie,

  -- 勝率（%）
  ROUND(SUM(t.win) / COUNT(*) * 100, 1) AS enemy_win,

  -- ⭐ 綜合分數（越高越危險）
  ROUND(
    (SUM(t.win) / COUNT(*) * 100)
    * LOG10(COUNT(*) + 1)
    * LEAST(1, COUNT(*) / 20), -- 🔧 30 → 20
    2
  ) AS score

  FROM (
    -- 🟥 charId 在敵方 → 看我方 u1/u2/u3（勝負不變）
    SELECT u1 AS char_id, win, lose, tie
    FROM arena_unlight
    WHERE (win + lose + tie = 1)
      AND :cid IN (e1, e2, e3)
      AND :cid NOT IN (u1, u2, u3)      -- ⭐ 對方不含自己
      AND update_time >= DATE_SUB(NOW(), INTERVAL :days DAY)
      $costSql

    UNION ALL
    SELECT u2 AS char_id, win, lose, tie
    FROM arena_unlight
    WHERE (win + lose + tie = 1)
      AND :cid IN (e1, e2, e3)
      AND :cid NOT IN (u1, u2, u3)
      AND update_time >= DATE_SUB(NOW(), INTERVAL :days DAY)
      $costSql

    UNION ALL
    SELECT u3 AS char_id, win, lose, tie
    FROM arena_unlight
    WHERE (win + lose + tie = 1)
      AND :cid IN (e1, e2, e3)
      AND :cid NOT IN (u1, u2, u3)
      AND update_time >= DATE_SUB(NOW(), INTERVAL :days DAY)
      $costSql

    -- 🟦 charId 在我方 → 看敵方 e1/e2/e3（勝負反轉）
    UNION ALL
    SELECT e1 AS char_id, lose AS win, win AS lose, tie
    FROM arena_unlight
    WHERE (win + lose + tie = 1)
      AND :cid IN (u1, u2, u3)
      AND :cid NOT IN (e1, e2, e3)      -- ⭐ 對方不含自己
      AND update_time >= DATE_SUB(NOW(), INTERVAL :days DAY)
      $costSql

    UNION ALL
    SELECT e2 AS char_id, lose AS win, win AS lose, tie
    FROM arena_unlight
    WHERE (win + lose + tie = 1)
      AND :cid IN (u1, u2, u3)
      AND :cid NOT IN (e1, e2, e3)
      AND update_time >= DATE_SUB(NOW(), INTERVAL :days DAY)
      $costSql

    UNION ALL
    SELECT e3 AS char_id, lose AS win, win AS lose, tie
    FROM arena_unlight
    WHERE (win + lose + tie = 1)
      AND :cid IN (u1, u2, u3)
      AND :cid NOT IN (e1, e2, e3)
      AND update_time >= DATE_SUB(NOW(), INTERVAL :days DAY)
      $costSql
  ) t

  JOIN unlight ul
    ON ul.id = t.char_id

  -- 保險：結果本身不顯示自己
  WHERE t.char_id <> :cid

  GROUP BY t.char_id
  HAVING COUNT(*) >= :min_matches
  AND (SUM(t.win) / COUNT(*)) >= 0.4
  ORDER BY score DESC
  LIMIT :limit";


  $stmt = $pdo->prepare($sql);
  $stmt->bindValue(':cid', $charId, PDO::PARAM_INT);
  $stmt->bindValue(':days', $days, PDO::PARAM_INT);
  $stmt->bindValue(':min_matches', $minMatches, PDO::PARAM_INT);
  $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
  if ($costRange && isset($costRange[0], $costRange[1])) {
    $stmt->bindValue(':cost_min', $costRange[0], PDO::PARAM_INT);
    $stmt->bindValue(':cost_max', $costRange[1], PDO::PARAM_INT);
  }
  $stmt->execute();

  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

  return array_map(static function ($r) {
    return [
      'char_id'   => (int)$r['char_id'],
      'name'      => $r['name'],
      'level'     => $r['level'],
      'ico'       => $r['ico'],
      'matches'   => (int)$r['matches'],
      'win'       => (int)$r['win'],
      'lose'      => (int)$r['lose'],
      'tie'       => (int)$r['tie'],
      'enemy_win' => (float)$r['enemy_win'],
      'score'     => (float)$r['score'],
    ];
  }, $rows);
}


// ===============================
// _analysis_base.php SLOT / PHASE / RANGE 定義
// ===============================

const UL_SLOT_LABEL = [
  0 => '劍',
  1 => '槍',
  2 => '盾',
  3 => '移',
  4 => '特',
  5 => '花',
  6 => '黑',
  7 => '無',
];

const UL_PHASE_LABEL = [
  0 => 'ATK',
  1 => 'DEF',
  2 => 'MOV',
];

const UL_RANGE_LABEL = [
  '0' => '近',
  '1' => '中',
  '2' => '遠',
];

const UL_REQUIRE_TYPE_LABEL = [
  0 => '劍',
  1 => '槍',
  2 => '盾',
  3 => '移',
  4 => '特',
  5 => '任意',
  '0,1' => '[劍,槍]',
  '0,1,2' => '[劍,槍,盾]',
];

const UL_REQUIRE_NUM_LABEL = [
  0 => '至少',
  1 => '等於',
  2 => '至多',
];

const UL_REQUIRE_TYPE_CLASS = [
  0 => 'req-type-sword',   // 劍
  1 => 'req-type-gun',     // 槍
  2 => 'req-type-shield',  // 盾
  3 => 'req-type-move',    // 移
  4 => 'req-type-spec',    // 特
  5 => 'req-type-any',     // 任意
  '0,1' => 'req-type-sword-gun', // ⭐ 劍 / 槍
  '0,1,2' => 'req-type-sword-gun', // ⭐ 劍 / 槍/盾
];



/* require_json 解析器 */
function ul_format_skill_require(?string $json): ?string
{
  if (!$json) return null;

  $data = json_decode($json, true);
  if (!$data) return null;

  if (isset($data['require'])) {
    $requires = $data['require'];
  } elseif (isset($data['cost'])) {
    $requires = $data['cost'];
  } elseif (is_array($data)) {
    $requires = $data;
  } else {
    return null;
  }

  if (empty($requires)) return null;

  $parts = [];

  foreach ($requires as $r) {
    if (!isset($r['type'], $r['quantity'])) continue;

    $qty = (int)$r['quantity'];
    $num = (int)($r['num'] ?? 0);

    // ---------- type key 正規化 ----------
    $typeRaw = $r['type'];
    if (is_array($typeRaw)) {
      sort($typeRaw);
      $typeKey = implode(',', $typeRaw);
    } else {
      $typeKey = (string)(int)$typeRaw;
    }

    $cls = UL_REQUIRE_TYPE_CLASS[$typeKey];
    $typeLabel = UL_REQUIRE_TYPE_LABEL[$typeKey];
    $opLabel   = UL_REQUIRE_NUM_LABEL[$num];

    // ---------- Tooltip 文字 ----------
    $tooltip = sprintf(
      '%s %s %d',
      $typeLabel,
      $opLabel,
      $qty
    );

    // ---------- 顯示符號 ----------
    $opIcon = match ($num) {
      1 => '=',
      2 => '↓',
      default => '↑',
    };

    $parts[] = sprintf(
      '<span class="req-chip %s" title="%s">
         <span class="req-num">%d</span>
         <span class="req-op">%s</span>
       </span>',
      $cls,
      htmlspecialchars($tooltip, ENT_QUOTES),
      $qty,
      $opIcon
    );
  }

  return implode('', $parts);
}





function ul_format_phase(?int $phase): ?string
{
  return UL_PHASE_LABEL[$phase] ?? null;
}
$stages = [
  -1    => '全部',
  0    => '雷城',
  1    => '誘森',
  2    => '垃圾',
  3    => '冰封',
  4    => '人魂',
  5    => '盡村',
  6    => '風暴',
  7    => '峰亥盧',
  8    => '魔都',
  9    => '狂山',
  10   => '魔女山谷',
  11   => '隨機',
  12   => '烏波斯黑湖',
];

/**
 * 計算隊伍 COST 懲罰
 *
 * 規則：
 * - 任兩角 COST 差 > 6  → +5
 * - 任兩角 COST 差 > 13 → 再 +5
 *
 * @param int[] $costs [c1, c2, c3]
 * @return array {
 *   punish: int,
 *   total_cost: int,
 *   detail: array
 * }
 */
function calcTeamCostPunish(array $costs): array
{
  // 過濾未選角色
  $costs = array_values(array_filter($costs, fn($v) => $v > 0));

  $baseTotal = array_sum($costs);
  $punish = 0;
  $detail = [];

  $n = count($costs);
  if ($n < 2) {
    return [
      'punish' => 0,
      'total_cost' => $baseTotal,
      'detail' => []
    ];
  }

  for ($i = 0; $i < $n; $i++) {
    for ($j = $i + 1; $j < $n; $j++) {

      $diff = abs($costs[$i] - $costs[$j]);
      $pairPunish = 0;

      if ($diff > 6)  $pairPunish += 5;
      if ($diff > 13) $pairPunish += 5;

      if ($pairPunish > 0) {
        $punish += $pairPunish;
        $detail[] = [
          'a' => $costs[$i],
          'b' => $costs[$j],
          'diff' => $diff,
          'punish' => $pairPunish
        ];
      }
    }
  }

  return [
    'punish' => $punish,
    'total_cost' => $baseTotal + $punish,
    'detail' => $detail
  ];
}
function maskBp(?int $bp): string
{
  if (!$bp || $bp <= 0) {
    return 'BP ?';
  }

  // 轉成字串
  $s = (string)$bp;

  // 長度 < 3 直接遮
  if (strlen($s) < 3) {
    return substr($s, 0, 1) . 'XX';
  }

  // 保留前兩碼，其餘補 X
  return substr($s, 0, 2) . str_repeat('X', strlen($s) - 2);
}

/**
 * 取得「隊伍最佳事件卡（含 ico）」
 */
function getBestTeamEventUsage(
  PDO $pdo,
  string $teamKey,
  int $days,
  float $baseWinRate,
  int $limit = 5,
  int $minMatches = 5
): array {

  $sql = "
SELECT
  e.id   AS event_id,
  e.name,
  e.ico,

  COUNT(*) AS matches,
  ROUND(AVG(m.is_win) * 100, 1) AS win_rate,

  ROUND(
    (AVG(m.is_win) * 100 - :base_wr)
    * LOG(COUNT(*) + 1)
    * (COUNT(*) / (COUNT(*) + 50)),
    2
  ) AS score

FROM arena_player_match_result m
JOIN arena_unlight a
  ON a.id = m.match_id
JOIN arena_deck_event d
  ON d.match_id = m.match_id
 AND d.side = m.side
JOIN unlight_eventindex e
  ON e.id = d.event_id1

WHERE
  m.team_key = :team_key
  AND m.is_win IS NOT NULL
  AND m.update_time >= DATE_SUB(NOW(), INTERVAL :days DAY)

GROUP BY e.id

HAVING
  COUNT(*) >= :min_matches
  AND (AVG(m.is_win) * 100 - :base_wr) > 0

ORDER BY score DESC
LIMIT :limit
";

  $stmt = $pdo->prepare($sql);
  $stmt->bindValue(':team_key', $teamKey);
  $stmt->bindValue(':days', $days, PDO::PARAM_INT);
  $stmt->bindValue(':base_wr', $baseWinRate, PDO::PARAM_STR);
  $stmt->bindValue(':min_matches', $minMatches, PDO::PARAM_INT);
  $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
  $stmt->execute();

  return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function minMatchesByBp(int $bpMin): int
{
  return match (true) {
    $bpMin >= 1700 => 10,
    $bpMin >= 1600 => 20,
    default        => 30,
  };
}

/**
 * Strength Rank 專用
 * 依 BP 區間 × Cost 區間，取得最強牌組
 */
function getTopTeamBuildsByBpAndCost(
  PDO $pdo,
  int $bpMin,
  int $days,
  string $costSql,
  int $limit = 3
): array {

  $sql = "
SELECT
  m.team_key,
  m.leader_id,
  m.back1_id,
  m.back2_id,

  COUNT(*) AS matches,
  ROUND(AVG(m.is_win) * 100, 1) AS win_rate,

  -- ⭐ Strength Rank Score（不過濾，只排序）
  ROUND(
    (AVG(m.is_win) * 100 - 45)
    * LOG(COUNT(*) + 1)
    * POW((COUNT(*) / (COUNT(*) + 15)), 0.75)
    * CASE
        WHEN COUNT(*) < 20 THEN (COUNT(*) / 20)
        ELSE 1
      END,
    2
  ) AS score

FROM arena_player_match_result m

WHERE
  m.player_bp >= :bp_min
  AND m.is_win IS NOT NULL
  AND m.update_time >= DATE_SUB(NOW(), INTERVAL :days DAY)
  AND {$costSql}

GROUP BY
  m.team_key,
  m.leader_id,
  m.back1_id,
  m.back2_id

  HAVING COUNT(*) >= 2

ORDER BY
  score DESC

LIMIT :limit
";

  $stmt = $pdo->prepare($sql);
  $stmt->bindValue(':bp_min', $bpMin, PDO::PARAM_INT);
  $stmt->bindValue(':days', $days, PDO::PARAM_INT);
  $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
  $stmt->execute();

  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
  if (!$rows) return [];

  $result = [];

  foreach ($rows as $r) {
    $chars = [
      ...getCharacterBasic($pdo, (int)$r['leader_id']),
      ...getCharacterBasic($pdo, (int)$r['back1_id']),
      ...getCharacterBasic($pdo, (int)$r['back2_id']),
    ];

    $result[] = [
      'team_key' => $r['team_key'],
      'matches'  => (int)$r['matches'],
      'win_rate' => (float)$r['win_rate'],
      'score'    => (float)$r['score'],
      'chars'    => $chars,
    ];
  }

  return $result;
}

function tierByScore(float $score, int $matches, int $bpMin): array
{
  if ($bpMin >= 1700) {
    if ($score >= 24 && $matches >= 12) return ['S', 'tier-s'];
    if ($score >= 16 && $matches >= 8) return ['A', 'tier-a'];
    if ($score >= 8 && $matches >= 5) return ['B', 'tier-b'];
    return ['C', 'tier-c'];
  }

  if ($bpMin >= 1600) {
    if ($score >= 30 && $matches >= 28) return ['S', 'tier-s'];
    if ($score >= 20 && $matches >= 14) return ['A', 'tier-a'];
    if ($score >= 10 && $matches >= 7) return ['B', 'tier-b'];
    return ['C', 'tier-c'];
  }

  if ($bpMin >= 1500) {
    if ($score >= 45 && $matches >= 40) return ['S', 'tier-s'];
    if ($score >= 30 && $matches >= 15) return ['A', 'tier-a'];
    if ($score >= 15 && $matches >= 8) return ['B', 'tier-b'];
    return ['C', 'tier-c'];
  }

  if ($score >= 45 && $matches >= 40) return ['S', 'tier-s'];
  if ($score >= 30 && $matches >= 15) return ['A', 'tier-a'];
  if ($score >= 15 && $matches >= 8) return ['B', 'tier-b'];
  return ['C', 'tier-c'];
}
