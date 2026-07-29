<?php
// layout/base.php
require_once dirname(__DIR__)
  . '/includes/fortune_character_insight.php';

$pageTitleFull = $pageTitleFull ?? 'UL.GG 戰績網 | UNLIGHT 戰術研究中心';
$pageTitleText = $pageTitleText ?? 'UL.GG 戰績網';


if (!isset($activeMenu)) {
  $activeMenu = ''; // e.g. 'dashboard', 'ranking', 'queue', 'fight', 'statics'
}
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}
/*
 * 今日命運寫入用 CSRF Token。
 */
if (empty($_SESSION['fortune_csrf_token'])) {
  $_SESSION['fortune_csrf_token'] =
    bin2hex(random_bytes(32));
}

$fortuneCsrfToken =
  (string)$_SESSION['fortune_csrf_token'];

$currentUrl = $_SERVER['REQUEST_URI'] ?? '';

if (
  empty($_SESSION['steam_id']) &&
  preg_match('#^/pages/#', $currentUrl) &&
  !preg_match('#/(login|logout)#', $currentUrl)
) {
  $_SESSION['login_redirect'] = $currentUrl;
}

$username = $_SESSION['username'] ?? null;
/*
 * 今日命運測試模式
 *
 * 目前只有指定帳號可以：
 * 1. 不受一天一次限制
 * 2. 每次重新測試都換一組結果
 *
 * 功能確認後，直接改成：
 * $fortuneTestMode = false;
 */
$fortuneTestMode = false;
/* $fortuneTestMode = in_array(
  $username,
  ['way.lee', '咕嚕．挖2朵'],
  true
); */

$fortuneTestRoll = '';

if ($fortuneTestMode) {
  $fortuneTestRoll = trim(
    (string)($_GET['fortune_roll'] ?? '')
  );

  if ($fortuneTestRoll === '') {
    $fortuneTestRoll = bin2hex(random_bytes(6));
  }
}

// ===== 支付 QR Code（全站共用）=====
$paymentQR = [
  'jkopay' => null,
  'pxpay'  => null,
];

try {
  $sql = "
    SELECT pay_type, file_path
    FROM payment_qr
    WHERE is_active = 1
  ";
  $stmt = $db->query($sql);

  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $paymentQR[$row['pay_type']] = $row['file_path'];
  }
} catch (Throwable $e) {
  // ❌ 正式站台不 echo，避免影響前台
}


// ===== 今日命運（全站共用）=====
$fortunePool = [];
$fortuneCards = [];
$fortuneSelectedIndex = 0;
$fortuneSelectedCard = null;
$fortuneSelectedSkill = null;
$fortuneSelectedTags = [];
$fortuneCharacterProfile = null;
$fortuneCharacterBackground = '';
$fortuneCharacterInsight = '';
$fortuneCharacterHumor = '';
$fortuneSeasonalEasterEgg = null;
$fortuneDisplayInsight = '';
$fortuneDisplayInsightLabel = '啟示';
$fortuneDisplayInsightIsEgg = false;
$fortuneDisplayInsightIsHumor = false;
$fortuneDate = date('Y-m-d');
$fortuneUserSeed = 'fallback';
$fortuneDailySeed = $fortuneDate . '|fallback';
$fortuneCardImageBase = defined('IMG_BASE')
  ? IMG_BASE
  : '/assets/uploads/';
try {
  $fortuneSql = "
    SELECT
      u.id,
      u.chara_code,
      u.name,
      u.level,
      u.ico,
      u.filename,
      u.skill1_code,
      u.skill2_code,
      u.skill3_code,
      u.skill4_code
    FROM unlight u
    WHERE u.chara_code IS NOT NULL
      AND u.chara_code <> ''
      AND u.ico IS NOT NULL
      AND u.ico <> ''
      AND COALESCE(
        NULLIF(u.skill1_code, ''),
        NULLIF(u.skill2_code, ''),
        NULLIF(u.skill3_code, ''),
        NULLIF(u.skill4_code, '')
        ) IS NOT NULL

  ";

  $fortunePool = $db->query($fortuneSql)->fetchAll(PDO::FETCH_ASSOC);
  $fortuneUserSeed = '';

  if (!empty($_SESSION['steam_id'])) {
    // 登入玩家：跨瀏覽器、跨裝置仍是同一結果
    $fortuneUserSeed = 'steam:' . $_SESSION['steam_id'];
  } else {
    // 未登入玩家：依瀏覽器保存不同結果
    $fortuneCookieName = 'ulgg_fortune_seed';

    if (!empty($_COOKIE[$fortuneCookieName])) {
      $fortuneUserSeed = 'guest:' . $_COOKIE[$fortuneCookieName];
    } else {
      $newFortuneSeed = bin2hex(random_bytes(16));

      setcookie($fortuneCookieName, $newFortuneSeed, [
        'expires' => time() + 31536000,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
      ]);

      $fortuneUserSeed = 'guest:' . $newFortuneSeed;
    }
  }

  $fortuneDailySeed = $fortuneDate . '|' . $fortuneUserSeed;

  if ($fortuneTestMode) {
    $fortuneDailySeed .= '|test:' . $fortuneTestRoll;
  }

  // 以日期雜湊排序：同一天結果固定，隔天自動更換
  usort(
    $fortunePool,
    static function (array $a, array $b) use ($fortuneDailySeed): int {
      return strcmp(
        hash('sha256', $fortuneDailySeed . '|card|' . $a['id']),
        hash('sha256', $fortuneDailySeed . '|card|' . $b['id'])
      );
    }
  );

  $fortuneCards = array_slice($fortunePool, 0, 3);

  if (count($fortuneCards) === 3) {
    $fortuneSelectedIndex =
      hexdec(substr(
        hash('sha256', $fortuneDailySeed . '|selected'),
        0,
        2
      )) % 3;
    $fortuneSelectedCard = $fortuneCards[$fortuneSelectedIndex];
    /*
 * 角色背景啟示
 */
    $fortuneCharacterProfiles =
      fortuneLoadCharacterProfiles(
        dirname(__DIR__)
          . '/data/charaProfile.json'
      );

    $fortuneCharacterName = trim(
      (string)(
        $fortuneSelectedCard['name']
        ?? ''
      )
    );

    $fortuneCharacterProfile =
      fortuneFindCharacterProfile(
        $fortuneCharacterProfiles,
        $fortuneCharacterName
      );

    if ($fortuneCharacterProfile !== null) {
      $fortuneCharacterBackground =
        fortuneBuildCharacterBackground(
          $fortuneCharacterProfile
        );

      $fortuneCharacterInsight =
        fortuneBuildCharacterInsight(
          $fortuneCharacterProfile,
          $fortuneDailySeed
        );
      $fortuneCharacterHumor =
        fortuneBuildCharacterHumor(
          $fortuneCharacterProfile,
          $fortuneDailySeed
        );
    }
    $fortuneSeasonalEasterEgg =
      fortuneBuildSeasonalEasterEgg(
        $fortuneDailySeed
      );
    /*
 * 顯示規則：
 * 有節日／生活彩蛋時，覆蓋角色啟示。
 * 沒有彩蛋時，顯示角色啟示。
 */
    /*
 * 顯示優先序：
 * 啟示 < 角色吐槽 < 節日／生活彩蛋
 *
 * 最後只顯示其中一項。
 */
    $fortuneDisplayInsight =
      trim($fortuneCharacterInsight);

    $fortuneDisplayInsightLabel =
      '啟示';

    $fortuneDisplayInsightIsEgg =
      false;

    $fortuneDisplayInsightIsHumor =
      false;

    /*
 * 有角色吐槽時，覆蓋一般啟示。
 */
    if ($fortuneCharacterHumor !== '') {
      $fortuneDisplayInsight =
        trim($fortuneCharacterHumor);

      $fortuneDisplayInsightLabel =
        '角色吐槽';

      $fortuneDisplayInsightIsHumor =
        true;
    }

    /*
 * 有節日／生活彩蛋時，
 * 覆蓋啟示與角色吐槽。
 */
    if (
      is_array($fortuneSeasonalEasterEgg)
      && !empty($fortuneSeasonalEasterEgg['text'])
    ) {
      $fortuneDisplayInsight =
        trim(
          (string)$fortuneSeasonalEasterEgg['text']
        );

      $fortuneDisplayInsightLabel =
        trim(
          (string)(
            $fortuneSeasonalEasterEgg['label']
            ?? '今日彩蛋'
          )
        );

      $fortuneDisplayInsightIsEgg =
        true;

      $fortuneDisplayInsightIsHumor =
        false;
    }
    $cardLevel = strtoupper(
      trim((string)($fortuneSelectedCard['level'] ?? ''))
    );


    /*
 * 今日命運技能選擇規則
 *
 * 普通卡：
 * Lv1~Lv5 對應該等級最後解鎖技能
 *
 * 稀有卡：
 * R1~R5 對應該階段最具代表性的技能
 *
 * 例如：
 * R5 → EX4
 * R4 → EX3
 * R3 → EX2
 * R2 → EX1
 */

    $preferredSkillSlot = match ($cardLevel) {

      // 普通卡
      'L1' => 1,
      'L2' => 2,
      'L3' => 3,
      'L4' => 4,
      'L5' => 4,


      // 稀有卡
      'R1' => 1,
      'R2' => 1,
      'R3' => 2,
      'R4' => 3,
      'R5' => 4,


      default => 1,
    };


    $selectedSkillCode = trim(
      (string)(
        $fortuneSelectedCard['skill' . $preferredSkillSlot . '_code'] ?? ''
      )
    );


    /*
 * 防呆：
 * 如果該技能不存在，
 * 往前找可用技能
 */

    if ($selectedSkillCode === '') {

      for ($slot = 4; $slot >= 1; $slot--) {

        $fallbackCode = trim(
          (string)(
            $fortuneSelectedCard['skill' . $slot . '_code'] ?? ''
          )
        );

        if ($fallbackCode !== '') {
          $selectedSkillCode = $fallbackCode;
          break;
        }
      }
    }


    if ($selectedSkillCode !== '') {

      $stmt = $db->prepare("SELECT skill_code, name_tcn, info_tcn, phase, range_mask, require_json FROM unlight_skill WHERE skill_code = :skill_code LIMIT 1");
      $stmt->execute(['skill_code' => $selectedSkillCode]);
      $fortuneSelectedSkill = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

      $tagStmt = $db->prepare("
        SELECT
          st.tag,
          COALESCE(d.label_zh, st.tag) AS label_zh,
          d.dimension,
          d.tag_group,
          d.description
        FROM unlight_skill_tag st
        LEFT JOIN unlight_skill_tag_dictionary d ON d.tag = st.tag
        WHERE st.skill_code = :skill_code
        ORDER BY st.id
      ");
      $tagStmt->execute(['skill_code' => $selectedSkillCode]);
      $fortuneSelectedTags = $tagStmt->fetchAll(PDO::FETCH_ASSOC);
    }
  }
} catch (Throwable $e) {
  $fortuneCards = [];
  $fortuneSelectedCard = null;
  $fortuneSelectedSkill = null;
  $fortuneSelectedTags = [];

  $fortuneCharacterProfile = null;
  $fortuneCharacterBackground = '';
  $fortuneCharacterInsight = '';
  $fortuneCharacterHumor = '';
  $fortuneSeasonalEasterEgg = null;
  $fortuneDisplayInsight = '';
  $fortuneDisplayInsightLabel = '啟示';
  $fortuneDisplayInsightIsEgg = false;
  $fortuneDisplayInsightIsHumor = false;

  error_log(
    '[FORTUNE ERROR] '
      . $e->getMessage()
      . ' LINE:'
      . $e->getLine()
  );
}

/**
 * require_json type 對應：
 *
 * 0 劍   → 突破
 * 1 槍   → 洞察
 * 2 盾   → 守護
 * 3 移動 → 流轉
 * 4 特殊 → 奧秘
 * 5 任意 → 調和
 */
function ulggFortuneTypeName(int $type): string
{
  return match ($type) {
    0       => '突破籤',
    1       => '洞察籤',
    2       => '守護籤',
    3       => '流轉籤',
    4       => '奧秘籤',
    default => '調和籤',
  };
}

/**
 * 技能階段
 *
 * 目前依系統資料假設：
 * 0 攻擊、1 防禦、2 行動
 */
function ulggFortunePhaseInfo(mixed $phase): array
{
  $phase = is_numeric($phase)
    ? (int)$phase
    : trim((string)$phase);

  return match ($phase) {
    0, '0', 'attack' => [
      'key'   => 'attack',
      'label' => '攻擊階段',
    ],

    1, '1', 'defense' => [
      'key'   => 'defense',
      'label' => '防禦階段',
    ],

    2, '2', 'move', 'action' => [
      'key'   => 'action',
      'label' => '行動階段',
    ],

    default => [
      'key'   => 'unknown',
      'label' => '特殊階段',
    ],
  };
}

/**
 * 解析技能距離。
 *
 * range_mask 可能是：
 * "0"
 * "0,1"
 * "0,1,2"
 * 或陣列
 */
function ulggFortuneRangeInfo(mixed $rangeMask): array
{
  if (is_array($rangeMask)) {
    $values = $rangeMask;
  } else {
    $raw = trim((string)$rangeMask);

    $values = $raw === ''
      ? []
      : preg_split('/\s*,\s*/', $raw);
  }

  $values = array_values(
    array_unique(
      array_filter(
        array_map(
          static fn($value): int => (int)$value,
          $values
        ),
        static fn(int $value): bool =>
        in_array($value, [0, 1, 2], true)
      )
    )
  );

  sort($values, SORT_NUMERIC);

  $labels = [
    0 => '近距離',
    1 => '中距離',
    2 => '遠距離',
  ];

  $keys = [
    0 => 'near',
    1 => 'middle',
    2 => 'far',
  ];

  $rangeLabels = [];
  $rangeKeys = [];

  foreach ($values as $value) {
    $rangeLabels[] = $labels[$value];
    $rangeKeys[] = $keys[$value];
  }

  if (!$rangeLabels) {
    return [
      'values' => [],
      'keys'   => [],
      'labels' => [],
      'label'  => '不限距離',
    ];
  }

  return [
    'values' => $values,
    'keys'   => $rangeKeys,
    'labels' => $rangeLabels,
    'label'  => implode('・', $rangeLabels),
  ];
}
function ulggFortunePick(
  array $pool,
  string $seed,
  string $key
): string {
  $pool = array_values(
    array_filter(
      $pool,
      static fn($value): bool =>
      is_string($value) && trim($value) !== ''
    )
  );

  if (!$pool) {
    return '';
  }

  $hash = hash(
    'sha256',
    $seed . '|fortune-pick|' . $key
  );

  $index =
    hexdec(substr($hash, 0, 8))
    % count($pool);

  return $pool[$index];
}
function ulggFortuneAnalysis(
  ?array $skill,
  array $tags,
  string $date,
  string $fortuneDailySeed,
  string $level = ''
): array {
  /*
   * 判斷技能是否為 EX 技能。
   */
  $skillName = trim(
    (string)($skill['name_tcn'] ?? '')
  );

  $isExSkill = (bool)preg_match(
    '/^(?:Ex|Ｅｘ)\s*/iu',
    $skillName
  );

  /*
   * =====================================================
   * 一、解析命運原型
   * =====================================================
   */

  $typeScores = [
    0 => 0.0,
    1 => 0.0,
    2 => 0.0,
    3 => 0.0,
    4 => 0.0,
    5 => 0.0,
  ];

  if ($skill && !empty($skill['require_json'])) {
    $decoded = json_decode(
      (string)$skill['require_json'],
      true
    );

    if (is_array($decoded)) {
      foreach ($decoded as $requirement) {
        if (!is_array($requirement)) {
          continue;
        }

        $types = $requirement['type'] ?? 5;
        $types = is_array($types)
          ? $types
          : [$types];

        $quantity = max(
          0,
          (int)($requirement['quantity'] ?? 0)
        );

        $numberRule = (int)(
          $requirement['num'] ?? 0
        );

        /*
         * 0：至少
         * 1：等於
         * 2：至多
         */
        $factor = match ($numberRule) {
          1       => 1.20,
          2       => 0.60,
          default => 1.00,
        };

        foreach ($types as $type) {
          $type = (int)$type;

          if (!array_key_exists($type, $typeScores)) {
            continue;
          }

          $typeScores[$type] += max(
            0.5,
            $quantity * $factor
          );
        }
      }
    }
  }

  /*
   * 原型必須取最高分，
   * 不能再用數字最小的 type。
   */
  /*
 * 找出最高的需求分數。
 * 若多個原型同分，使用每日種子固定選出其中一個，
 * 避免永遠偏向數字較小的突破籤。
 */
  $maxTypeScore = max($typeScores);

  if ($maxTypeScore <= 0) {
    $fortuneArchetype = 5;
  } else {
    $topTypes = [];

    foreach ($typeScores as $type => $score) {
      if (abs($score - $maxTypeScore) < 0.00001) {
        $topTypes[] = (int)$type;
      }
    }

    if (count($topTypes) === 1) {
      $fortuneArchetype = $topTypes[0];
    } else {
      $tieHash = hexdec(
        substr(
          hash(
            'sha256',
            $fortuneDailySeed
              . '|archetype-tie|'
              . implode(',', $topTypes)
          ),
          0,
          8
        )
      );

      $fortuneArchetype =
        $topTypes[$tieHash % count($topTypes)];
    }
  }

  $mainLuck = ulggFortuneTypeName(
    $fortuneArchetype
  );

  /*
   * 保留所有出牌需求類型，
   * 供混合原型文案使用。
   */
  $typeList = [];

  foreach ($typeScores as $type => $score) {
    if ($score > 0) {
      $typeList[] = (int)$type;
    }
  }

  if (!$typeList) {
    $typeList = [5];
  }

  sort($typeList, SORT_NUMERIC);

  $typeKey = implode(',', $typeList);

  /*
   * =====================================================
   * 二、解析戰術階段與距離
   * =====================================================
   */

  $phaseInfo = ulggFortunePhaseInfo(
    $skill['phase'] ?? null
  );

  $rangeInfo = ulggFortuneRangeInfo(
    $skill['range_mask'] ?? ''
  );

  /*
   * =====================================================
   * 三、星等
   * =====================================================
   */

  $tagNames = array_values(
    array_unique(
      array_map(
        'strval',
        array_column($tags, 'tag')
      )
    )
  );

  $positiveTags = [
    'ATK+',
    'ATK↑',
    'DEF+',
    'DEF↑',
    'HP+',
    'MOV↑',
    '手牌+',
    '骰面⌈',
    '骰面★',
    '淨化',
    '再生',
    '傷害Ø',
    '不死',
    '不死Ⅱ',
    '聖痕',
    '成長',
  ];

  $cautionTags = [
    'HP-',
    '自壞',
    '中毒',
    '猛毒',
    '麻痺',
    '封印',
    '暈眩',
    '能力低下',
    '隨機',
    '效果?',
    '操想',
    '斷絕',
  ];

  $positiveCount = count(
    array_intersect($tagNames, $positiveTags)
  );

  $cautionCount = count(
    array_intersect($tagNames, $cautionTags)
  );




  /*
   * =====================================================
   * 四、六角能力＋獨立負荷
   * =====================================================
   */

  $aspectScores = [
    'mobility' => 0,
    'offense'  => 0,
    'guard'    => 0,
    'insight'  => 0,
    'resource' => 0,
    'change'   => 0,
    'pressure' => 0,
  ];

  $aspectLabels = [
    'mobility' => '機動力',
    'offense'  => '攻勢力',
    'guard'    => '守護力',
    'insight'  => '洞察力',
    'resource' => '資源力',
    'change'   => '變化力',
    'pressure' => '負荷值',
  ];

  /*
   * 技能標籤是能力值的主要來源。
   */
  $tagAspectMap = [

    /*
     * 攻勢
     */
    'ATK+'  => ['offense' => 2],
    'ATK↑'  => ['offense' => 3],
    'ATK×'  => ['offense' => 4, 'pressure' => 1],
    'ATK-'  => ['offense' => -2],
    'ATK↓'  => ['offense' => -3, 'pressure' => 1],
    'ATK÷'  => ['offense' => -2, 'pressure' => 1],
    'ATK='  => ['offense' => 1, 'insight' => 1],

    '直傷'  => ['offense' => 2],
    '傷害+' => ['offense' => 2],
    '傷害×' => ['offense' => 3, 'pressure' => 1],
    '傷害=' => ['offense' => 1, 'insight' => 2],
    '傷害↻' => ['change' => 2, 'insight' => 1],
    '殺戮'  => ['offense' => 4, 'pressure' => 2],
    '致死'  => ['offense' => 5, 'pressure' => 3],

    /*
     * 守護
     */
    'DEF+'   => ['guard' => 2],
    'DEF↑'   => ['guard' => 3],
    'DEF×'   => ['guard' => 4],
    'DEF-'   => ['guard' => -2],
    'DEF↓'   => ['guard' => -3, 'pressure' => 1],
    'DEF÷'   => ['guard' => -2, 'pressure' => 1],
    'DEF='   => ['guard' => 1, 'insight' => 1],

    'HP+'    => ['guard' => 2],
    'HP←'    => ['guard' => 2, 'resource' => 1],
    'HP>'    => ['guard' => 2],
    'HP='    => ['guard' => 1, 'insight' => 1],
    'HP-'    => ['guard' => -1, 'pressure' => 2],
    'HP÷'    => ['guard' => -2, 'pressure' => 2],
    'HP⇄'    => ['change' => 3],

    '傷害-'  => ['guard' => 2],
    '傷害÷'  => ['guard' => 3],
    '傷害Ø'  => ['guard' => 4],
    '傷害↪'  => ['guard' => 2, 'change' => 1],

    '再生'   => ['guard' => 3],
    '不死'   => ['guard' => 4, 'pressure' => 1],
    '不死Ⅱ' => ['guard' => 5, 'pressure' => 1],
    '淨化'   => ['guard' => 2, 'pressure' => -2],

    /*
     * 機動與距離控制
     */
    'MOV↑'  => ['mobility' => 3, 'change' => 1],
    'MOV↓'  => ['mobility' => -2, 'pressure' => 1],

    '距離+' => ['mobility' => 1, 'guard' => 1],
    '距離-' => ['mobility' => 1, 'offense' => 1],
    '距離=' => ['insight' => 1],
    '距離?' => ['mobility' => 1, 'change' => 3, 'pressure' => 1],
    '距離⇄' => ['mobility' => 2, 'change' => 2],

    '移動Ø' => ['mobility' => -3, 'pressure' => 2],

    /*
     * 洞察與精準判定
     */
    '條件='   => ['insight' => 2],
    '回合數=' => ['insight' => 2],
    '回合數+' => ['insight' => 1, 'change' => 1],
    '質數'    => ['insight' => 3],
    '標靶'    => ['insight' => 2, 'offense' => 1],
    '後手'    => ['insight' => 2, 'mobility' => -1],
    '骰面⌈'   => ['insight' => 2, 'change' => 1],

    /*
     * 資源
     */
    '手牌+'     => ['resource' => 2],
    '手牌←'     => ['resource' => 2, 'change' => 1],
    '手牌上限+' => ['resource' => 3],
    '事件卡'    => ['resource' => 2, 'change' => 1],
    '牌堆'      => ['resource' => 2],
    '墓地'      => ['resource' => 1],
    '武器'      => ['resource' => 1, 'offense' => 1],
    '狀態←'     => ['resource' => 1, 'change' => 2],

    '手牌-'     => ['resource' => -2, 'pressure' => 1],
    '手牌上限-' => ['resource' => -3, 'pressure' => 1],
    '出牌-'     => ['resource' => -2, 'pressure' => 1],
    '出牌Ø'     => ['resource' => -3, 'pressure' => 2],
    '出牌⇅'     => ['resource' => -1, 'change' => 2],

    /*
     * 變化與隨機
     */
    '角色⇄' => ['change' => 3],
    '狀態→' => ['change' => 2],
    '隨機'  => ['change' => 3, 'pressure' => 1],
    '效果?' => ['change' => 4, 'pressure' => 1],
    '擲骰+' => ['change' => 2, 'offense' => 1],
    '骰面★' => ['change' => 2, 'offense' => 1],
    '骰面☆' => ['change' => 2, 'pressure' => 1],

    /*
     * 成長與型態
     */
    '聖痕' => [
      'offense' => 2,
      'guard'   => 2,
    ],

    '臨界' => [
      'offense'  => 2,
      'guard'    => 1,
      'pressure' => 1,
    ],

    '成長' => [
      'offense'  => 1,
      'guard'    => 1,
      'resource' => 1,
    ],

    '棍術' => [
      'offense' => 2,
      'guard'   => 2,
    ],

    '混沌' => [
      'offense'  => 3,
      'guard'    => 2,
      'change'   => 2,
      'pressure' => 2,
    ],

    '狂戰士' => [
      'offense'  => 4,
      'pressure' => 4,
    ],

    /*
     * 負荷與控制
     */
    '中毒'     => ['pressure' => 2],
    '猛毒'     => ['pressure' => 4],
    '麻痺'     => ['mobility' => -3, 'pressure' => 3],
    '暈眩'     => ['mobility' => -2, 'pressure' => 3],
    '封印'     => ['resource' => -1, 'pressure' => 2],
    '咒縛'     => ['mobility' => -1, 'change' => -1, 'pressure' => 3],
    '詛咒'     => ['offense' => -1, 'pressure' => 3],
    '恐怖'     => ['offense' => -1, 'guard' => 1, 'pressure' => 2],

    '能力低下' => [
      'offense'  => -2,
      'guard'    => -2,
      'pressure' => 3,
    ],

    '操想'   => ['pressure' => 4],
    '斷絕'   => ['guard' => -2, 'pressure' => 3],
    '自壞'   => ['guard' => -2, 'pressure' => 5],
    '行動Ø'  => ['mobility' => -3, 'pressure' => 3],
    '行動⊘'  => ['mobility' => -4, 'pressure' => 4],
    '必殺技Ø' => ['resource' => -2, 'pressure' => 2],
    '必殺技⊘' => ['resource' => -3, 'pressure' => 3],

    '己方待機' => [
      'insight' => 1,
      'guard'   => 1,
    ],
    '異常狀態' => [
      'change'  => 1,
      'offense' => 1,
    ],
    '潛影' => [
      'mobility' => 1,
      'insight'  => 1,
      'change'   => 1,
    ],
    '全體' => [
      'change'  => 1,
      'offense' => 1,
    ],
    '陷阱' => [
      'insight' => 1,
      'change'  => 1,
    ],
  ];

  /*
 * 記錄尚未建立能力映射的 SKILL_TAG。
 * 正式功能不受影響，只供測試帳號檢查。
 */
  $unmappedTags = [];

  foreach ($tagNames as $tagName) {
    if (!isset($tagAspectMap[$tagName])) {
      $unmappedTags[] = $tagName;
      continue;
    }

    foreach (
      $tagAspectMap[$tagName]
      as $aspect => $value
    ) {
      if (!array_key_exists($aspect, $aspectScores)) {
        continue;
      }

      $aspectScores[$aspect] += (int)$value;
    }
  }

  $unmappedTags = array_values(
    array_unique($unmappedTags)
  );

  /*
   * =====================================================
   * 五、技能階段加成
   *
   * 階段是戰術節奏，只給少量加成。
   * =====================================================
   */

  $phaseAspectBonusMap = [
    'attack' => [
      'offense' => 2,
      'insight' => 1,
    ],

    'defense' => [
      'guard'   => 2,
      'insight' => 1,
    ],

    'action' => [
      'mobility' => 2,
      'change'   => 1,
    ],
  ];

  foreach (
    $phaseAspectBonusMap[$phaseInfo['key']] ?? []
    as $aspect => $value
  ) {
    $aspectScores[$aspect] += (int)$value;
  }

  /*
   * =====================================================
   * 六、距離適性加成
   *
   * 單距離：完整風格加成
   * 雙距離：各自取得一項主要加成
   * 全距離：以泛用與變化為主
   * =====================================================
   */

  $rangeValues = $rangeInfo['values'];

  if (count($rangeValues) === 1) {
    $rangeValue = $rangeValues[0];

    $singleRangeBonusMap = [
      0 => [
        'mobility' => 1,
        'offense'  => 1,
      ],

      1 => [
        'insight' => 1,
        'change'  => 1,
      ],

      2 => [
        'insight'  => 2,
        'resource' => 1,
      ],
    ];

    foreach (
      $singleRangeBonusMap[$rangeValue] ?? []
      as $aspect => $value
    ) {
      $aspectScores[$aspect] += (int)$value;
    }
  } elseif (count($rangeValues) === 2) {
    $doubleRangeMainBonusMap = [
      0 => ['offense' => 1],
      1 => ['insight' => 1],
      2 => ['resource' => 1],
    ];

    foreach ($rangeValues as $rangeValue) {
      foreach (
        $doubleRangeMainBonusMap[$rangeValue] ?? []
        as $aspect => $value
      ) {
        $aspectScores[$aspect] += (int)$value;
      }
    }

    $aspectScores['change'] += 1;
  } elseif (count($rangeValues) >= 3) {
    $aspectScores['change'] += 2;
    $aspectScores['mobility'] += 1;
  }

  /*
   * =====================================================
   * 七、角色等級強化最高能力
   * =====================================================
   */

  $positiveAspectKeys = [
    'mobility',
    'offense',
    'guard',
    'insight',
    'resource',
    'change',
  ];

  /*
 * 命運原型對應的主要能力。
 * 能力同分時優先選擇與命運原型一致的能力。
 */
  $archetypePreferredAspectMap = [
    0 => 'offense',
    1 => 'insight',
    2 => 'guard',
    3 => 'mobility',
    4 => 'resource',
    5 => 'change',
  ];

  $positiveAspectScores = [];

  foreach ($positiveAspectKeys as $aspectKey) {
    $positiveAspectScores[$aspectKey] =
      (int)$aspectScores[$aspectKey];
  }

  $maxAspectValue = max($positiveAspectScores);

  $topAspects = [];

  foreach ($positiveAspectScores as $aspectKey => $value) {
    if ($value === $maxAspectValue) {
      $topAspects[] = $aspectKey;
    }
  }

  $preferredAspect =
    $archetypePreferredAspectMap[$fortuneArchetype]
    ?? null;

  if (
    $preferredAspect !== null
    && in_array($preferredAspect, $topAspects, true)
  ) {
    $bestAspect = $preferredAspect;
  } elseif (count($topAspects) === 1) {
    $bestAspect = $topAspects[0];
  } else {
    $aspectTieHash = hexdec(
      substr(
        hash(
          'sha256',
          $fortuneDailySeed
            . '|aspect-before-level|'
            . implode(',', $topAspects)
        ),
        0,
        8
      )
    );

    $bestAspect =
      $topAspects[$aspectTieHash % count($topAspects)]
      ?? 'offense';
  }

  $levelBonusMap = [
    'L1' => 0,
    'L2' => 0,
    'L3' => 1,
    'L4' => 1,
    'L5' => 2,

    'R1' => 1,
    'R2' => 1,
    'R3' => 2,
    'R4' => 2,
    'R5' => 3,
  ];

  $levelBonus = (int)(
    $levelBonusMap[$level] ?? 0
  );

  if (
    isset($aspectScores[$bestAspect])
    && $aspectScores[$bestAspect] > 0
  ) {
    $aspectScores[$bestAspect] += $levelBonus;
  }

  /*
   * 每日值限制在 -9 ～ +9。
   */
  foreach ($aspectScores as $aspect => $value) {
    $aspectScores[$aspect] = max(
      -9,
      min(9, (int)$value)
    );
  }

  /*
   * 套用等級後重新找最高、最低能力。
   */
  $positiveAspectScores = [];

  foreach ($positiveAspectKeys as $aspectKey) {
    $positiveAspectScores[$aspectKey] =
      (int)$aspectScores[$aspectKey];
  }

  $maxAspectValue = max($positiveAspectScores);

  $topAspects = [];

  foreach ($positiveAspectScores as $aspectKey => $value) {
    if ($value === $maxAspectValue) {
      $topAspects[] = $aspectKey;
    }
  }

  $preferredAspect =
    $archetypePreferredAspectMap[$fortuneArchetype]
    ?? null;

  if (
    $preferredAspect !== null
    && in_array($preferredAspect, $topAspects, true)
  ) {
    $bestAspect = $preferredAspect;
  } elseif (count($topAspects) === 1) {
    $bestAspect = $topAspects[0];
  } else {
    $aspectTieHash = hexdec(
      substr(
        hash(
          'sha256',
          $fortuneDailySeed
            . '|aspect-after-level|'
            . implode(',', $topAspects)
        ),
        0,
        8
      )
    );

    $bestAspect =
      $topAspects[$aspectTieHash % count($topAspects)]
      ?? 'offense';
  }

  $bestValue = (int)(
    $positiveAspectScores[$bestAspect] ?? 0
  );

  $weakAspectScores = $positiveAspectScores;
  asort($weakAspectScores);

  $weakAspect = (string)(
    array_key_first($weakAspectScores)
    ?? 'resource'
  );

  $weakValue = (int)(
    $weakAspectScores[$weakAspect] ?? 0
  );

  $pressureValue = (int)(
    $aspectScores['pressure'] ?? 0
  );
  /*
 * =====================================================
 * 星級：代表今日整體力量品質
 *
 * 綜合考慮：
 * 1. 六角能力的正向總量
 * 2. 負向能力
 * 3. 負荷風險
 * 4. 正負面標籤
 * =====================================================
 */

  $positivePower = 0;
  $negativePower = 0;

  foreach ($positiveAspectKeys as $aspectKey) {
    $aspectValue = (int)$aspectScores[$aspectKey];

    if ($aspectValue > 0) {
      $positivePower += $aspectValue;
    } elseif ($aspectValue < 0) {
      $negativePower += abs($aspectValue);
    }
  }

  /*
 * 負荷不是完全扣除。
 * 高負荷代表力量可能很強，但需要付出代價，
 * 因此只扣除一部分。
 */
  $pressurePenalty = match (true) {
    $pressureValue >= 7 => 4,
    $pressureValue >= 5 => 3,
    $pressureValue >= 3 => 2,
    $pressureValue >= 1 => 1,
    default             => 0,
  };

  $tagAdjustment = 0;

  if ($positiveCount > $cautionCount) {
    $tagAdjustment += 1;
  }

  if ($cautionCount >= $positiveCount + 2) {
    $tagAdjustment -= 1;
  }

  $starScore =
    $positivePower
    - $negativePower
    - $pressurePenalty
    + $tagAdjustment;

  $stars = match (true) {
    $starScore >= 15 => 5,
    $starScore >= 10 => 4,
    $starScore >= 6  => 3,
    $starScore >= 2  => 2,
    default          => 1,
  };

  /*
 * 一星保留給真正高風險結果。
 */
  if (
    $stars === 1
    && $pressureValue < 7
    && $weakValue > -4
  ) {
    $stars = 2;
  }

  /*
   * 六角能力與負荷分開回傳。
   */
  $aspectDisplay = [];

  foreach ($positiveAspectKeys as $aspectKey) {
    $aspectDisplay[] = [
      'key'     => $aspectKey,
      'label'   => $aspectLabels[$aspectKey],
      'value'   => (int)$aspectScores[$aspectKey],
      'is_load' => false,
    ];
  }

  $pressureDisplay = [
    'key'     => 'pressure',
    'label'   => '負荷值',
    'value'   => $pressureValue,
    'is_load' => true,
  ];

  /*
   * =====================================================
   * 八、文案
   * =====================================================
   */

  $archetypeMessagePools = [
    0 => [
      '今天的核心能量偏向突破與執行。',
      '突破的力量正在聚集。',
      '今天較容易展現決斷與推進能力。',
    ],

    1 => [
      '今天的核心能量偏向觀察與判斷。',
      '洞察的力量正在增強。',
      '細節與資訊將成為今天的重要線索。',
    ],

    2 => [
      '今天的核心能量偏向穩定與守護。',
      '守護的力量正在形成。',
      '維持節奏與保存實力是今天的主題。',
    ],

    3 => [
      '今天的核心能量偏向流動與轉換。',
      '流轉的力量正在增強。',
      '局勢可能出現較多變化與調整空間。',
    ],

    4 => [
      '今天的核心能量偏向未知與特殊機會。',
      '奧秘的力量正在浮現。',
      '非典型線索可能帶來新的方向。',
    ],

    5 => [
      '今天的核心能量偏向平衡與調和。',
      '不同力量正在尋找平衡。',
      '今天的重點在於協調條件與節奏。',
    ],
  ];

  /* $phaseMessagePools = [
    'attack' => [
      '攻擊階段的節奏正在增強，今天適合掌握主動權。',
      '今日戰術傾向偏向攻擊，明確選定目標後宜果斷推進。',
      '攻擊階段象徵突破與執行，今天不宜讓重要行動停留在想法。',
    ],

    'defense' => [
      '防禦階段的節奏正在增強，今天適合穩定局勢並降低風險。',
      '今日戰術傾向偏向防禦，守住已有成果會帶來後續空間。',
      '防禦階段象徵承受與修復，今天先維持節奏會更加有利。',
    ],

    'action' => [
      '行動階段的節奏正在增強，今天適合移動、調整與重新配置。',
      '今日戰術傾向偏向行動，改變位置或處理順序可能帶來突破。',
      '行動階段象徵流動與選擇，今天適合替自己創造更多空間。',
    ],

    'unknown' => [
      '今天的戰術節奏較為特殊，適合視情況調整行動。',
    ],
  ]; */

  $rangeMessagePools = [
    'near' => [
      '處理事情宜直接明確。',
      '適合減少猶豫，儘早回應。',
      '今天宜正面處理眼前問題。',
    ],

    'middle' => [
      '宜在觀察與行動之間保留餘地。',
      '適合掌握節奏後再做選擇。',
      '今天宜維持攻守之間的平衡。',
    ],

    'far' => [
      '宜先觀察全局，再安排後續。',
      '適合先蒐集資訊，不必急於回應。',
      '今天宜從長期角度判斷局勢。',
    ],

    'multi' => [
      '今天可以保留兩種以上的應對方向。',
      '處理事情時不必過早排除其他方案。',
      '適合準備主要方案與備用方案。',
      '可先觀察局勢，再決定採取哪一種路線。',
      '今天適合在兩種選擇之間保留彈性。',
    ],

    'all' => [
      '今天可以從較完整的視角判斷局勢。',
      '不同條件之間具有較高的銜接空間。',
      '今天較容易兼顧眼前狀況與後續安排。',
      '適合先整理全局，再選擇最合適的切入點。',
      '多種條件可以同時納入考量。',
    ],

    'none' => [
      '處理方式可依實際情況決定。',
      '今天不需要預先限制自己的選擇。',
      '宜依現況選擇最適合的做法。',
    ],
  ];

  $rangePoolKey = match (count($rangeValues)) {
    0       => 'none',
    1       => $rangeInfo['keys'][0] ?? 'none',
    2       => 'multi',
    default => 'all',
  };

  $advantageMessagePools = [
    'mobility' => [
      '機動力提升至 %s，今天適合調整位置、順序與行動節奏。',
      '今日機動力獲得 %s 加成，改變方法可能比原地等待更有效。',
      '你的機動力為 %s，今天具備重新配置與快速應變的能力。',
    ],

    'offense' => [
      '攻勢力提升至 %s，明確選定目標後，突破能力會更加明顯。',
      '今日攻勢力獲得 %s 加成，適合將想法轉化為具體成果。',
      '你的攻勢力為 %s，今天具備主動創造局面的條件。',
    ],

    'guard' => [
      '守護力提升至 %s，穩定、防守與恢復會成為今天的重要優勢。',
      '今日守護力獲得 %s 加成，即使局勢改變，也較容易維持節奏。',
      '你的守護力為 %s，適合先鞏固現況，再逐步擴大成果。',
    ],

    'insight' => [
      '洞察力提升至 %s，今天更容易從資訊與細節中找到方向。',
      '今日洞察力獲得 %s 加成，確認條件後再行動會更有效率。',
      '你的洞察力為 %s，分析與判斷是今天的重要優勢。',
    ],

    'resource' => [
      '資源力提升至 %s，資訊、選項與可運用條件較為充足。',
      '今日資源力獲得 %s 加成，適合整理資訊與準備後續行動。',
      '你的資源力為 %s，善用已有條件會比單純冒進更有效率。',
    ],

    'change' => [
      '變化力提升至 %s，臨場應變與改變方法可能帶來突破。',
      '今日變化力達到 %s，新的發展與可能性會比平常更加明顯。',
      '你的變化力為 %s，今天適合保留彈性，不必過早鎖定唯一答案。',
    ],
  ];

  $exAdvantageMessagePools = [
    'mobility' => [
      '覺醒後的機動力達到 %s，今天更容易突破原有行動限制。',
      'EX 力量將機動力推升至 %s，重新配置與臨場切換會更加鮮明。',
      '機動力在強化後達到 %s，今天具備快速改變局勢的條件。',
    ],

    'offense' => [
      '覺醒後的攻勢力達到 %s，力量將更集中地指向明確目標。',
      'EX 力量將攻勢力推升至 %s，主動出擊的影響會更加明顯。',
      '攻勢力在強化後達到 %s，今天適合把握關鍵突破時機。',
    ],

    'guard' => [
      '覺醒後的守護力達到 %s，承受與穩定局勢的能力更加突出。',
      'EX 力量將守護力推升至 %s，今天更容易維持重要成果。',
      '守護力在強化後達到 %s，防守與恢復將形成更強支撐。',
    ],

    'insight' => [
      '覺醒後的洞察力達到 %s，隱藏條件與細節將更容易被察覺。',
      'EX 力量將洞察力推升至 %s，今天的判斷會更加敏銳。',
      '洞察力在強化後達到 %s，複雜局勢中更容易找到關鍵。',
    ],

    'resource' => [
      '覺醒後的資源力達到 %s，可運用的條件與選項更加充足。',
      'EX 力量將資源力推升至 %s，今天更適合整合手上的優勢。',
      '資源力在強化後達到 %s，準備與調度能力會更加突出。',
    ],

    'change' => [
      '覺醒後的變化力達到 %s，局勢可能出現超出預期的新方向。',
      'EX 力量將變化力推升至 %s，今天的可能性與轉換空間更加鮮明。',
      '變化力在強化後達到 %s，改變方法可能帶來關鍵突破。',
    ],
  ];
  $exModerateAdvantageMessagePools = [
    'mobility' => [
      'EX 特質使機動力來到 %s，今天更適合調整位置與節奏。',
    ],
    'offense' => [
      'EX 特質使攻勢力來到 %s，主動行動仍能創造一定空間。',
    ],
    'guard' => [
      'EX 特質使守護力來到 %s，今天可透過穩定應對降低風險。',
    ],
    'insight' => [
      'EX 特質使洞察力來到 %s，今天較容易察覺細節變化。',
    ],
    'resource' => [
      'EX 特質使資源力來到 %s，手上的條件仍有整理空間。',
    ],
    'change' => [
      'EX 特質使變化力來到 %s，局勢中仍存在新的可能。',
    ],
  ];

  $aspectTipPools = [
    'mobility' => [
      '先調整位置與節奏，再決定真正需要投入的方向。',
      '保持移動空間，會比立即固定做法更加有利。',
    ],

    'offense' => [
      '先鎖定最重要的目標，再集中力量突破。',
      '主動推進能創造機會，但不要同時分散在太多事情上。',
    ],

    'guard' => [
      '先穩住目前局勢，再尋找適合反擊或推進的時機。',
      '今天適合先保存成果，不必急著承擔額外風險。',
    ],

    'insight' => [
      '先確認資訊與條件，再投入真正重要的行動。',
      '判斷清楚後再出手，會比立即反應更有效率。',
    ],

    'resource' => [
      '先整理現有條件，再把資源投入最有價值的位置。',
      '保留可調度的資源，能讓後續選擇更加從容。',
    ],

    'change' => [
      '保留彈性與備案，變化中可能藏著新的出口。',
      '不必固守原計畫，適時改變方法更容易接近成果。',
    ],
  ];

  $baseMessage = ulggFortunePick(
    $archetypeMessagePools[$fortuneArchetype]
      ?? $archetypeMessagePools[5],
    $fortuneDailySeed,
    'archetype|' . $fortuneArchetype
  );

  /* $phaseMessage = ulggFortunePick(
    $phaseMessagePools[$phaseInfo['key']]
      ?? $phaseMessagePools['unknown'],
    $fortuneDailySeed,
    'phase|' . $phaseInfo['key']
  ); */

  $rangeMessage = ulggFortunePick(
    $rangeMessagePools[$rangePoolKey]
      ?? $rangeMessagePools['none'],
    $fortuneDailySeed,
    'range|' . $rangePoolKey
  );
  /*
 * 高負荷時，避免距離文案要求立即推進，
 * 改用較保守的節奏描述。
 */
  if ($pressureValue >= 5) {
    $highPressureRangePools = [
      '今天適合先確認承受範圍，再決定投入程度。',
      '局勢雖有推進空間，但不宜忽略自身負荷。',
      '今天宜控制行動節奏，避免因急於回應而擴大消耗。',
    ];

    $rangeMessage = ulggFortunePick(
      $highPressureRangePools,
      $fortuneDailySeed,
      'range-high-pressure|'
        . $rangePoolKey
        . '|'
        . $pressureValue
    );
  }

  $bestSignedValue =
    ($bestValue > 0 ? '+' : '')
    . $bestValue;

  /*
 * EX 技能依能力強度切換文案。
 */
  if ($isExSkill) {
    $activeAdvantagePools = $bestValue >= 5
      ? $exAdvantageMessagePools
      : $exModerateAdvantageMessagePools;
  } else {
    $activeAdvantagePools = $advantageMessagePools;
  }

  $advantageTemplate = ulggFortunePick(
    $activeAdvantagePools[$bestAspect]
      ?? $activeAdvantagePools['offense'],
    $fortuneDailySeed,
    'advantage|'
      . ($isExSkill
        ? ($bestValue >= 5 ? 'ex-strong' : 'ex-moderate')
        : 'normal')
      . '|'
      . $bestAspect
  );

  if ($bestValue > 0) {
    $advantageMessage = sprintf(
      $advantageTemplate,
      $bestSignedValue
    );
  } else {
    $advantageMessage =
      '今天的力量較為分散，適合先維持基本節奏並觀察變化。';
  }

  $archetypeCompatibleAspects = [
    0 => ['offense'],                     // 突破
    1 => ['insight', 'offense'],          // 洞察
    2 => ['guard', 'resource'],           // 守護
    3 => ['mobility', 'change'],          // 流轉
    4 => [
      'insight',
      'resource',
      'change',
      'mobility',
    ], // 奧秘
    5 => ['change', 'resource', 'guard'], // 調和
  ];



  $aspectTransitionMessage = '';

  $compatibleAspects =
    $archetypeCompatibleAspects[$fortuneArchetype]
    ?? [];

  if (
    !in_array($bestAspect, $compatibleAspects, true)
    && $bestValue >= 3
  ) {
    $aspectTransitionPools = [
      '今天的命運方向雖由%s引導，但真正突出的能力是%s。',
      '命運原型偏向%s，而今日實際優勢會透過%s展現。',
      '%s決定了今天的方向，%s則是你主要依靠的力量。',
    ];

    $aspectTransitionTemplate = ulggFortunePick(
      $aspectTransitionPools,
      $fortuneDailySeed,
      'aspect-transition|'
        . $fortuneArchetype
        . '|'
        . $bestAspect
    );

    $aspectTransitionMessage = sprintf(
      $aspectTransitionTemplate,
      str_replace('籤', '', $mainLuck),
      $aspectLabels[$bestAspect]
    );
  }



  /*
   * 負荷與弱點提醒。
   */
  $warningMessages = [];

  if ($pressureValue >= 5) {
    if ($isExSkill) {
      $warningMessages[] =
        '負荷值已達 +' . $pressureValue
        . '，覺醒力量正在放大效果，也同步提高了消耗。';
    } else {
      $warningMessages[] =
        '負荷值已達 +' . $pressureValue
        . '，今天的力量伴隨明顯壓力與消耗。';
    }
  } elseif ($pressureValue >= 3) {
    $warningMessages[] =
      '負荷值為 +' . $pressureValue
      . '，行動時要留意壓力、限制與資源消耗。';
  } elseif ($pressureValue < 0) {
    $warningMessages[] =
      '負荷值下降至 ' . $pressureValue
      . '，今天較容易排除干擾並恢復穩定。';
  }






  $tipPools = [
    0 => [
      '先鎖定最重要的目標，再集中力量突破。',
      '主動推進能創造機會，但不要同時分散在太多事情上。',
      '果斷跨出第一步，並替後續行動保留足夠資源。',
    ],

    1 => [
      '先確認資訊與條件，再投入真正重要的行動。',
      '觀察不是停滯，判斷清楚後再出手會更有效率。',
      '關鍵是辨認真正重要的線索。',
    ],

    2 => [
      '先守住自己的節奏，等條件成熟後再尋找突破。',
      '穩定累積比快速消耗更有利。',
      '先降低風險，再逐步擴大已有優勢。',
    ],

    3 => [
      '保留彈性與備案，變化中可能藏著新的出口。',
      '不必固守原計畫，適時改變方法更容易接近成果。',
      '先創造移動空間，再決定下一步的方向。',
    ],

    4 => [
      '留意非典型線索，但重要決定仍要再次確認。',
      '適合從不同角度理解問題。',
      '相信直覺提供的方向，同時替自己保留修正空間。',
    ],

    5 => [
      '先整理各項條件，再選擇最平衡的處理方式。',
      '不必急著控制所有結果，順勢調整會更加穩妥。',
      '在不同選項之間取得平衡，再決定投入方向。',
    ],
  ];

  /*
 * 原型與最高能力不相容時，
 * 今日戰術改由最高能力決定。
 */
  $isCrossAspect =
    !in_array($bestAspect, $compatibleAspects, true)
    && $bestValue >= 3;

  $lowPowerTipPools = [
    '先整理目前條件，不必急著做出明確取捨。',
    '今天適合維持基本節奏，再觀察局勢是否出現變化。',
    '力量尚未完全集中，先處理眼前最確定的部分即可。',
  ];

  $highRiskTipPools = [
    '優勢雖然明顯，但整體承受空間有限，今天宜控制投入程度。',
    '可以運用最突出的能力，但不要同時擴大其他戰線。',
    '力量集中在單一方向，推進時要替自己保留停止點與退路。',
  ];

  $isLowPowerResult =
    $bestValue <= 2;

  $isHighRiskResult =
    $stars <= 2
    && $pressureValue >= 3
    && $bestValue >= 3;

  if ($isLowPowerResult) {
    $activeTipPool = $lowPowerTipPools;
  } elseif ($isHighRiskResult) {
    $activeTipPool = $highRiskTipPools;
  } elseif ($isCrossAspect) {
    $activeTipPool =
      $aspectTipPools[$bestAspect]
      ?? $tipPools[5];
  } else {
    $activeTipPool =
      $tipPools[$fortuneArchetype]
      ?? $tipPools[5];
  }

  $tipMode = match (true) {
    $isLowPowerResult  => 'low-power',
    $isHighRiskResult  => 'high-risk',
    $isCrossAspect     => 'aspect',
    default            => 'archetype',
  };

  $tip = ulggFortunePick(
    $activeTipPool,
    $fortuneDailySeed,
    'tip|'
      . $tipMode
      . '|'
      . $fortuneArchetype
      . '|'
      . $bestAspect
  );

  /*
 * 今日戰術加入負荷與弱項修正，
 * 避免主文提醒高風險，下方卻仍要求全力推進。
 */
  /*
 * 極高負荷時直接覆蓋戰術，
 * 避免前句鼓勵推進、後句又要求停止。
 */
  if ($pressureValue >= 7) {
    $highPressureTipPools = [
      '先設定明確停止點，今天不宜為了結果持續透支。',
      '今天應以控制損耗為優先，必要時暫停比勉強推進更有利。',
      '保留自身狀態比追求立即成果更重要，避免超出承受範圍。',
    ];

    $tip = ulggFortunePick(
      $highPressureTipPools,
      $fortuneDailySeed,
      'tip-extreme-pressure|'
        . $fortuneArchetype
        . '|'
        . $bestAspect
        . '|'
        . $pressureValue
    );

    $tipSuffixes = [];
  } else {
    $tipSuffixes = [];

    if (
      $pressureValue >= 5
      && !$isHighRiskResult
    ) {
      $tipSuffixes[] =
        '推進時應控制投入程度，避免一次消耗過多資源。';
    } elseif (
      $pressureValue >= 3
      && !$isHighRiskResult
    ) {
      $tipSuffixes[] =
        '行動時記得保留資源與退路。';
    }
  }


  if ($tipSuffixes) {
    foreach ($tipSuffixes as $tipSuffix) {
      /*
     * 原本戰術已提到資源或退路時，
     * 避免再加入語意重複的負荷提醒。
     */
      if (
        str_contains($tip, '保留足夠資源')
        && str_contains($tipSuffix, '保留資源')
      ) {
        continue;
      }

      $tip .= ' ' . $tipSuffix;
    }
  }

  $messageParts = [
    $baseMessage,
    $aspectTransitionMessage,
    $rangeMessage,
    $advantageMessage,
  ];

  foreach ($warningMessages as $warningMessage) {
    $messageParts[] = $warningMessage;
  }

  $message = implode(
    ' ',
    array_filter($messageParts)
  );

  return [
    /*
     * 命運原型
     */
    'main_luck'       => $mainLuck,
    'archetype'       => $fortuneArchetype,
    'archetype_name'  => $mainLuck,
    'type_key'        => $typeKey,

    /*
     * 戰術傾向
     */
    'phase_key'       => $phaseInfo['key'],
    'phase_name'      => $phaseInfo['label'],

    'range_keys'      => $rangeInfo['keys'],
    'range_name'      => $rangeInfo['label'],
    'range_values'    => $rangeInfo['values'],

    /*
     * 結果
     */
    'stars'           => $stars,
    'message'         => $message,
    'tip'             => $tip,

    /*
     * 六角能力與獨立風險
     */
    'aspects'         => $aspectDisplay,
    'pressure'        => $pressureDisplay,

    /*
     * 未來寫入會員資料庫可直接使用
     */
    'aspect_values' => [
      'mobility' => (int)$aspectScores['mobility'],
      'offense'  => (int)$aspectScores['offense'],
      'guard'    => (int)$aspectScores['guard'],
      'insight'  => (int)$aspectScores['insight'],
      'resource' => (int)$aspectScores['resource'],
      'change'   => (int)$aspectScores['change'],
      'pressure' => (int)$aspectScores['pressure'],
    ],

    'best_aspect'     => $bestAspect,
    'best_value'      => $bestValue,
    'level_bonus'  => $levelBonus,
    'unmapped_tags' => $unmappedTags,
    'is_ex_skill' => $isExSkill,
    'skill_form'  => $isExSkill ? 'EX' : 'NORMAL',
  ];
}

$fortuneReading = ulggFortuneAnalysis(
  $fortuneSelectedSkill,
  $fortuneSelectedTags,
  $fortuneDate,
  $fortuneDailySeed,
  strtoupper(trim($fortuneSelectedCard['level'] ?? ''))
);
/*
 * =====================================================
 * 今日命運正式領取與能力累加
 *
 * 僅處理 AJAX POST：
 * fortune_claim = 1
 *
 * 測試模式不寫入資料庫。
 * =====================================================
 */
if (
  $_SERVER['REQUEST_METHOD'] === 'POST'
  && isset($_POST['fortune_claim'])
) {
  header('Content-Type: application/json; charset=UTF-8');

  /*
   * 必須登入 Steam 才能累積能力。
   */
  if (empty($_SESSION['steam_id'])) {
    http_response_code(401);

    echo json_encode([
      'success' => false,
      'status'  => 'login_required',
      'message' => '請先登入後再領取今日命運。',
    ], JSON_UNESCAPED_UNICODE);

    exit;
  }

  /*
   * 測試帳號的重新測試結果不寫入。
   */
  if ($fortuneTestMode) {
    echo json_encode([
      'success' => true,
      'status'  => 'test_mode',
      'message' => '測試模式不會累加能力值。',
    ], JSON_UNESCAPED_UNICODE);

    exit;
  }

  /*
   * 驗證 CSRF Token。
   */
  $requestCsrfToken = trim(
    (string)($_POST['csrf_token'] ?? '')
  );

  if (
    $requestCsrfToken === ''
    || !hash_equals(
      $fortuneCsrfToken,
      $requestCsrfToken
    )
  ) {
    http_response_code(403);

    echo json_encode([
      'success' => false,
      'status'  => 'invalid_csrf',
      'message' => '驗證失敗，請重新整理頁面。',
    ], JSON_UNESCAPED_UNICODE);

    exit;
  }

  if (
    !$fortuneSelectedCard
    || !$fortuneSelectedSkill
    || empty($fortuneReading)
  ) {
    http_response_code(422);

    echo json_encode([
      'success' => false,
      'status'  => 'fortune_unavailable',
      'message' => '今日命運資料尚未完成。',
    ], JSON_UNESCAPED_UNICODE);

    exit;
  }

  $steamId = trim(
    (string)$_SESSION['steam_id']
  );

  $aspectValues =
    $fortuneReading['aspect_values'] ?? [];

  $mobilityValue = (int)(
    $aspectValues['mobility'] ?? 0
  );

  $offenseValue = (int)(
    $aspectValues['offense'] ?? 0
  );

  $guardValue = (int)(
    $aspectValues['guard'] ?? 0
  );

  $insightValue = (int)(
    $aspectValues['insight'] ?? 0
  );

  $resourceValue = (int)(
    $aspectValues['resource'] ?? 0
  );

  $changeValue = (int)(
    $aspectValues['change'] ?? 0
  );

  $pressureValue = (int)(
    $aspectValues['pressure'] ?? 0
  );

  $stars = max(
    1,
    min(
      5,
      (int)($fortuneReading['stars'] ?? 1)
    )
  );

  /*
   * 星級對應經驗值。
   */
  $fortuneExp = match ($stars) {
    5       => 25,
    4       => 18,
    3       => 12,
    2       => 8,
    default => 5,
  };

  $pressureDayIncrement =
    $pressureValue > 0 ? 1 : 0;

  $fiveStarIncrement =
    $stars === 5 ? 1 : 0;

  try {
    $db->beginTransaction();

    /*
     * 先新增每日紀錄。
     *
     * INSERT IGNORE 配合唯一鍵：
     * steam_id + fortune_date + is_test
     *
     * 若已經領取過，rowCount() 會是 0，
     * 後續能力值不會再次累加。
     */
    $dailyStmt = $db->prepare("
      INSERT IGNORE INTO member_fortune_daily (
        steam_id,
        fortune_date,

        card_id,
        chara_code,
        character_name,
        character_level,

        skill_code,
        skill_name,

        main_type,
        type_key,
        main_luck,
        stars,

        mobility_value,
        offense_value,
        guard_value,
        insight_value,
        resource_value,
        change_value,
        pressure_value,

        fortune_exp,
        message,
        tip,
        seed_hash,
        is_test,
        created_at
      ) VALUES (
        :steam_id,
        :fortune_date,

        :card_id,
        :chara_code,
        :character_name,
        :character_level,

        :skill_code,
        :skill_name,

        :main_type,
        :type_key,
        :main_luck,
        :stars,

        :mobility_value,
        :offense_value,
        :guard_value,
        :insight_value,
        :resource_value,
        :change_value,
        :pressure_value,

        :fortune_exp,
        :message,
        :tip,
        :seed_hash,
        0,
        NOW()
      )
    ");

    $dailyStmt->execute([
      'steam_id'       => $steamId,
      'fortune_date'   => $fortuneDate,

      'card_id'        =>
      (int)($fortuneSelectedCard['id'] ?? 0),

      'chara_code'     =>
      (string)($fortuneSelectedCard['chara_code'] ?? ''),

      'character_name' =>
      (string)($fortuneSelectedCard['name'] ?? ''),

      'character_level' =>
      strtoupper(
        trim(
          (string)(
            $fortuneSelectedCard['level'] ?? ''
          )
        )
      ),

      'skill_code' =>
      (string)(
        $fortuneSelectedSkill['skill_code'] ?? ''
      ),

      'skill_name' =>
      (string)(
        $fortuneSelectedSkill['name_tcn'] ?? ''
      ),

      'main_type' =>
      (int)($fortuneReading['archetype'] ?? 5),

      'type_key' =>
      (string)($fortuneReading['type_key'] ?? ''),

      'main_luck' =>
      (string)($fortuneReading['main_luck'] ?? ''),

      'stars' => $stars,

      'mobility_value' => $mobilityValue,
      'offense_value'  => $offenseValue,
      'guard_value'    => $guardValue,
      'insight_value'  => $insightValue,
      'resource_value' => $resourceValue,
      'change_value'   => $changeValue,
      'pressure_value' => $pressureValue,

      'fortune_exp' => $fortuneExp,

      'message' =>
      (string)($fortuneReading['message'] ?? ''),

      'tip' =>
      (string)($fortuneReading['tip'] ?? ''),

      'seed_hash' =>
      hash('sha256', $fortuneDailySeed),
    ]);

    /*
     * INSERT IGNORE 沒新增資料，
     * 代表今天已經領取過。
     */
    if ($dailyStmt->rowCount() !== 1) {
      $db->rollBack();

      echo json_encode([
        'success' => true,
        'status'  => 'already_claimed',
        'message' => '今日命運已經累加過。',
      ], JSON_UNESCAPED_UNICODE);

      exit;
    }

    /*
     * 新增或更新會員累積能力。
     *
     * 連續天數：
     * 上次日期是昨天 → streak + 1
     * 其他情況       → 重新從 1 開始
     */
    $profileStmt = $db->prepare("
      INSERT INTO member_fortune_profile (
        steam_id,

        total_draws,
        current_streak,
        longest_streak,

        fortune_exp,
        fortune_level,

        mobility_total,
        offense_total,
        guard_total,
        insight_total,
        resource_total,
        change_total,
        pressure_total,

        pressure_days,
        five_star_count,

        last_fortune_date,
        created_at,
        updated_at
      ) VALUES (
        :steam_id,

        1,
        1,
        1,

        :insert_fortune_exp,
        :insert_fortune_level,

        :insert_mobility_total,
        :insert_offense_total,
        :insert_guard_total,
        :insert_insight_total,
        :insert_resource_total,
        :insert_change_total,
        :insert_pressure_total,

        :insert_pressure_days,
        :insert_five_star_count,

        :insert_last_fortune_date,
        NOW(),
        NOW()
      )
      ON DUPLICATE KEY UPDATE
        total_draws = total_draws + 1,

        longest_streak = GREATEST(
          longest_streak,
          CASE
            WHEN last_fortune_date =
              DATE_SUB(
                :update_fortune_date_for_longest,
                INTERVAL 1 DAY
              )
            THEN current_streak + 1
            ELSE 1
          END
        ),

        current_streak = CASE
          WHEN last_fortune_date =
            DATE_SUB(
              :update_fortune_date_for_streak,
              INTERVAL 1 DAY
            )
          THEN current_streak + 1
          ELSE 1
        END,

        fortune_level =
          1 + FLOOR(
            (
              fortune_exp
              + :update_exp_for_level
            ) / 100
          ),

        fortune_exp =
          fortune_exp
          + :update_fortune_exp,

        mobility_total =
          mobility_total
          + :update_mobility_total,

        offense_total =
          offense_total
          + :update_offense_total,

        guard_total =
          guard_total
          + :update_guard_total,

        insight_total =
          insight_total
          + :update_insight_total,

        resource_total =
          resource_total
          + :update_resource_total,

        change_total =
          change_total
          + :update_change_total,

        pressure_total =
          pressure_total
          + :update_pressure_total,

        pressure_days =
          pressure_days
          + :update_pressure_days,

        five_star_count =
          five_star_count
          + :update_five_star_count,

        last_fortune_date =
          :update_last_fortune_date,

        updated_at = NOW()
    ");

    $profileStmt->execute([
      'steam_id' => $steamId,

      /*
       * 第一次建立會員累積資料。
       */
      'insert_fortune_exp' =>
      $fortuneExp,

      'insert_fortune_level' =>
      1 + intdiv($fortuneExp, 100),

      'insert_mobility_total' =>
      $mobilityValue,

      'insert_offense_total' =>
      $offenseValue,

      'insert_guard_total' =>
      $guardValue,

      'insert_insight_total' =>
      $insightValue,

      'insert_resource_total' =>
      $resourceValue,

      'insert_change_total' =>
      $changeValue,

      'insert_pressure_total' =>
      $pressureValue,

      'insert_pressure_days' =>
      $pressureDayIncrement,

      'insert_five_star_count' =>
      $fiveStarIncrement,

      'insert_last_fortune_date' =>
      $fortuneDate,

      /*
       * 已有會員累積資料時使用。
       */
      'update_fortune_date_for_longest' =>
      $fortuneDate,

      'update_fortune_date_for_streak' =>
      $fortuneDate,

      'update_exp_for_level' =>
      $fortuneExp,

      'update_fortune_exp' =>
      $fortuneExp,

      'update_mobility_total' =>
      $mobilityValue,

      'update_offense_total' =>
      $offenseValue,

      'update_guard_total' =>
      $guardValue,

      'update_insight_total' =>
      $insightValue,

      'update_resource_total' =>
      $resourceValue,

      'update_change_total' =>
      $changeValue,

      'update_pressure_total' =>
      $pressureValue,

      'update_pressure_days' =>
      $pressureDayIncrement,

      'update_five_star_count' =>
      $fiveStarIncrement,

      'update_last_fortune_date' =>
      $fortuneDate,
    ]);

    $db->commit();

    echo json_encode([
      'success' => true,
      'status'  => 'claimed',
      'message' => '今日能力值已累加。',
      'data' => [
        'fortune_exp' => $fortuneExp,
        'stars'       => $stars,
        'aspects' => [
          'mobility' => $mobilityValue,
          'offense'  => $offenseValue,
          'guard'    => $guardValue,
          'insight'  => $insightValue,
          'resource' => $resourceValue,
          'change'   => $changeValue,
          'pressure' => $pressureValue,
        ],
      ],
    ], JSON_UNESCAPED_UNICODE);

    exit;
  } catch (Throwable $e) {
    if ($db->inTransaction()) {
      $db->rollBack();
    }

    error_log(
      'Fortune claim failed: '
        . $e->getMessage()
    );

    http_response_code(500);

    echo json_encode([
      'success' => false,
      'status'  => 'database_error',
      'message' => '能力累加失敗，請稍後再試。',
    ], JSON_UNESCAPED_UNICODE);

    exit;
  }
}
/*
 * 今日命運批次測試
 *
 * 使用方式：
 * ?fortune_batch=20
 *
 * 僅限 fortuneTestMode 帳號使用。
 */
if (
  $fortuneTestMode &&
  isset($_GET['fortune_batch']) &&
  !empty($fortunePool)
) {
  $batchRoll = trim(
    (string)($_GET['batch_roll'] ?? '')
  );

  if ($batchRoll === '') {
    $batchRoll = bin2hex(random_bytes(8));
  }
  $batchCount = max(
    1,
    min(100, (int)$_GET['fortune_batch'])
  );

  header('Content-Type: text/plain; charset=UTF-8');

  $batchCharacterProfiles =
    fortuneLoadCharacterProfiles(
      dirname(__DIR__)
        . '/data/charaProfile.json'
    );

  for (
    $batchIndex = 1;
    $batchIndex <= $batchCount;
    $batchIndex++
  ) {
    $batchSeed =
      $fortuneDate
      . '|'
      . $fortuneUserSeed
      . '|batch-roll:'
      . $batchRoll
      . '|batch:'
      . $batchIndex;

    /*
     * 重新排序角色池。
     */
    $batchPool = $fortunePool;

    usort(
      $batchPool,
      static function (
        array $a,
        array $b
      ) use ($batchSeed): int {
        return strcmp(
          hash(
            'sha256',
            $batchSeed . '|card|' . $a['id']
          ),
          hash(
            'sha256',
            $batchSeed . '|card|' . $b['id']
          )
        );
      }
    );

    $batchCards = array_slice($batchPool, 0, 3);

    if (count($batchCards) < 3) {
      continue;
    }

    $batchSelectedIndex =
      hexdec(
        substr(
          hash(
            'sha256',
            $batchSeed . '|selected'
          ),
          0,
          2
        )
      ) % 3;

    $batchCard = $batchCards[$batchSelectedIndex];
    $batchCharacterBackground = '';
    $batchCharacterInsight = '';
    $batchCharacterHumor = '';

    $batchSeasonalEasterEgg = null;
    $batchDisplayInsight = '';
    $batchDisplayInsightLabel = '啟示';

    $batchCharacterName = trim(
      (string)($batchCard['name'] ?? '')
    );

    $batchCharacterProfile =
      fortuneFindCharacterProfile(
        $batchCharacterProfiles,
        $batchCharacterName
      );

    if ($batchCharacterProfile !== null) {
      $batchCharacterBackground =
        fortuneBuildCharacterBackground(
          $batchCharacterProfile
        );

      $batchCharacterInsight =
        fortuneBuildCharacterInsight(
          $batchCharacterProfile,
          $batchSeed
        );
      $batchCharacterHumor =
        fortuneBuildCharacterHumor(
          $batchCharacterProfile,
          $batchSeed
        );
    }
    $batchSeasonalEasterEgg =
      fortuneBuildSeasonalEasterEgg(
        $batchSeed
      );

    /*
 * 批次顯示優先序：
 * 啟示 < 角色吐槽 < 彩蛋
 */
    $batchDisplayInsight =
      trim($batchCharacterInsight);

    $batchDisplayInsightLabel =
      '啟示';

    if ($batchCharacterHumor !== '') {
      $batchDisplayInsight =
        trim($batchCharacterHumor);

      $batchDisplayInsightLabel =
        '角色吐槽';
    }

    if (
      is_array($batchSeasonalEasterEgg)
      && !empty($batchSeasonalEasterEgg['text'])
    ) {
      $batchDisplayInsight =
        trim(
          (string)$batchSeasonalEasterEgg['text']
        );

      $batchDisplayInsightLabel =
        trim(
          (string)(
            $batchSeasonalEasterEgg['label']
            ?? '今日彩蛋'
          )
        );
    }
    $batchLevel = strtoupper(
      trim((string)($batchCard['level'] ?? ''))
    );

    $batchSkillSlot = match ($batchLevel) {
      'L1' => 1,
      'L2' => 2,
      'L3' => 3,
      'L4' => 4,
      'L5' => 4,

      'R1' => 1,
      'R2' => 1,
      'R3' => 2,
      'R4' => 3,
      'R5' => 4,

      default => 1,
    };

    $batchSkillCode = trim(
      (string)(
        $batchCard['skill'
          . $batchSkillSlot
          . '_code'] ?? ''
      )
    );

    if ($batchSkillCode === '') {
      for ($slot = 4; $slot >= 1; $slot--) {
        $fallbackCode = trim(
          (string)(
            $batchCard['skill'
              . $slot
              . '_code'] ?? ''
          )
        );

        if ($fallbackCode !== '') {
          $batchSkillCode = $fallbackCode;
          break;
        }
      }
    }

    if ($batchSkillCode === '') {
      continue;
    }

    $batchSkillStmt = $db->prepare("
      SELECT
        skill_code,
        name_tcn,
        info_tcn,
        phase,
        range_mask,
        require_json
      FROM unlight_skill
      WHERE skill_code = :skill_code
      LIMIT 1
    ");

    $batchSkillStmt->execute([
      'skill_code' => $batchSkillCode,
    ]);

    $batchSkill =
      $batchSkillStmt->fetch(PDO::FETCH_ASSOC)
      ?: null;

    if (!$batchSkill) {
      continue;
    }

    $batchTagStmt = $db->prepare("
      SELECT
        st.tag,
        COALESCE(d.label_zh, st.tag) AS label_zh,
        d.dimension,
        d.tag_group,
        d.description
      FROM unlight_skill_tag st
      LEFT JOIN unlight_skill_tag_dictionary d
        ON d.tag = st.tag
      WHERE st.skill_code = :skill_code
      ORDER BY st.id
    ");

    $batchTagStmt->execute([
      'skill_code' => $batchSkillCode,
    ]);

    $batchTags =
      $batchTagStmt->fetchAll(PDO::FETCH_ASSOC);

    $batchReading = ulggFortuneAnalysis(
      $batchSkill,
      $batchTags,
      $fortuneDate,
      $batchSeed,
      $batchLevel
    );

    echo '==============================' . PHP_EOL;
    echo '#' . $batchIndex . PHP_EOL;

    echo ($batchCard['name'] ?? '')
      . ' '
      . $batchLevel
      . PHP_EOL;

    echo ($batchSkill['name_tcn'] ?? '')
      . PHP_EOL;

    echo str_repeat(
      '★',
      (int)$batchReading['stars']
    );

    echo str_repeat(
      '☆',
      5 - (int)$batchReading['stars']
    );

    echo PHP_EOL;

    echo ($batchReading['main_luck'] ?? '')
      . PHP_EOL;
    echo '階段：'
      . ($batchReading['phase_name'] ?? '')
      . PHP_EOL;

    echo '距離：'
      . ($batchReading['range_name'] ?? '')
      . PHP_EOL;

    echo '技能標籤：'
      . implode(
        '、',
        array_column($batchTags, 'tag')
      )
      . PHP_EOL;
    foreach (
      $batchReading['aspects'] ?? []
      as $aspect
    ) {
      $value = (int)($aspect['value'] ?? 0);

      echo ($aspect['label'] ?? '')
        . ' '
        . ($value > 0 ? '+' : '')
        . $value
        . PHP_EOL;
    }

    $pressure = (int)(
      $batchReading['pressure']['value'] ?? 0
    );

    if ($pressure !== 0) {
      echo '負荷值 '
        . ($pressure > 0 ? '+' : '')
        . $pressure
        . PHP_EOL;
    }

    echo PHP_EOL;

    echo ($batchReading['message'] ?? '')
      . PHP_EOL;

    if ($batchCharacterBackground !== '') {
      echo '角色背景：'
        . $batchCharacterBackground
        . PHP_EOL;
    }

    if ($batchDisplayInsight !== '') {
      echo $batchDisplayInsightLabel
        . '：'
        . $batchDisplayInsight
        . PHP_EOL;
    }
    if ($batchCharacterProfile === null) {
      echo '角色背景：未找到「'
        . $batchCharacterName
        . '」'
        . PHP_EOL;
    }

    if (
      !empty($batchReading['unmapped_tags'])
    ) {
      echo '未映射標籤：'
        . implode(
          '、',
          $batchReading['unmapped_tags']
        )
        . PHP_EOL;
    }


    echo PHP_EOL;
  }

  exit;
}
?>
<!DOCTYPE html>
<html lang="zh-Hant">

<head>
  <meta charset="UTF-8">
  <title>
    <?= isset($seoTitle)
      ? htmlspecialchars($seoTitle)
      : htmlspecialchars($pageTitleFull) ?>
  </title>

  <link rel="canonical" href="https://ulgg.online/">
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <!-- Primary favicon (Google Search Results 最重要) -->
  <link rel="icon" href="/assets/favicon/favicon.ico" sizes="48x48" type="image/x-icon">

  <!-- PNG icons -->
  <link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/assets/favicon/favicon-16x16.png">
  <link rel="icon" type="image/png" sizes="192x192" href="/assets/favicon/android-chrome-192x192.png">

  <!-- Apple -->
  <link rel="apple-touch-icon" sizes="180x180" href="/assets/favicon/apple-touch-icon.png">



  <!-- 字型：科技感乾淨字型 -->
  <link rel="stylesheet"
    href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap">

  <!-- 全站主題樣式 -->
  <link rel="stylesheet" href="/assets/css/theme.css?v=86">
  <link rel="stylesheet" href="/assets/css/layout.css?v=10">

  <!-- 圖表（給 statics / dashboard 用） -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

  <!-- Bootstrap -->
  <link rel="stylesheet" href="/assets/bower_components/bootstrap/dist/css/bootstrap.min.css">

  <!-- Font Awesome -->
  <link rel="stylesheet" href="/assets/bower_components/font-awesome/css/font-awesome.min.css">
  <link rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">


  <!-- AdminLTE -->
  <link rel="stylesheet" href="/assets/dist/css/AdminLTE.min.css">
  <link rel="stylesheet" href="/assets/dist/css/skins/_all-skins.min.css">

  <!-- jQuery -->
  <script src="/assets/bower_components/jquery/dist/jquery.min.js"></script>

  <!-- Bootstrap JS -->
  <script src="/assets/bower_components/bootstrap/dist/js/bootstrap.min.js"></script>

  <!-- AdminLTE -->
  <script src="/assets/dist/js/adminlte.min.js"></script>



  <!-- Excel -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>

  <!-- Screenshot -->
  <script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>


  <style>
    .nav-user-info {
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .nav-avatar {
      width: 32px;
      height: 32px;
      border-radius: 50%;
      border: 1px solid #555;
    }

    .nav-username {
      color: #fff;
      text-decoration: none;
      font-weight: bold;
    }

    .nav-username:hover {
      text-decoration: underline;
    }

    .nav-user-info {
      position: relative;
      margin-left: auto;
    }

    .user-box {
      display: flex;
      align-items: center;
      gap: 6px;
      cursor: pointer;
      padding: 6px 10px;
      border-radius: 6px;
      transition: 0.2s;
    }

    .user-box:hover {
      background: rgba(255, 255, 255, 0.08);
    }

    .user-box .avatar {
      width: 32px;
      height: 32px;
      border-radius: 50%;
    }

    .user-box .username {
      color: #fff;
      font-size: 14px;
      font-weight: 500;
    }

    .user-box .caret {
      color: #aaa;
      font-size: 12px;
    }

    .user-dropdown {
      position: absolute;
      top: 44px;
      right: 0;
      background: rgba(30, 30, 30, 0.95);
      backdrop-filter: blur(6px);
      border: 1px solid rgba(255, 255, 255, 0.1);
      border-radius: 6px;
      min-width: 160px;
      padding: 6px 0;
      display: none;
      z-index: 999;
    }

    .user-box:hover .user-dropdown {
      display: block;
    }

    .user-dropdown a {
      display: flex;
      align-items: center;
      gap: 8px;
      padding: 10px 14px;
      font-size: 14px;
      color: #e0e0e0;
      text-decoration: none;
      transition: 0.15s;
    }

    .user-dropdown a:hover {
      background: rgba(255, 255, 255, 0.08);
    }

    .user-dropdown a.logout {
      color: #ff6b6b;
    }

    .user-dropdown a.logout:hover {
      background: rgba(255, 0, 0, 0.15);
    }

    .nav-login-btn {
      padding: 8px 18px;
      background: rgba(255, 255, 255, 0.08);
      border: 1px solid rgba(255, 255, 255, 0.15);
      border-radius: 6px;
      color: #fff;
      font-size: 14px;
      font-weight: 500;
      text-decoration: none;
      transition: 0.15s;
    }

    .nav-login-btn:hover {
      background: rgba(255, 255, 255, 0.18);
      border-color: rgba(255, 255, 255, 0.25);
    }

    /* =========================
   UL.GG Footer (Final)
   Compact / Flat Version
========================= */

    /* Footer 淡入動畫 */
    .un-footer {
      padding: 28px 0 18px;
      opacity: 0;
      transform: translateY(20px);
      transition: opacity .6s ease, transform .6s ease;
    }

    .un-footer.footer-visible {
      opacity: 1;
      transform: translateY(0);
    }

    /* Footer 容器 */
    .footer-inner {
      text-align: center;
    }

    /* =========================
   Support 區塊
========================= */

    .footer-support {
      margin-bottom: 14px;
    }

    .footer-title {
      font-size: 14px;
      font-weight: 700;
      margin: 6px 0 4px;
      letter-spacing: 0.5px;
    }

    .footer-desc {
      font-size: 13px;
      line-height: 1.45;
      color: var(--ul-text-soft, #d0d0d0);
      margin-bottom: 6px;
    }

    .footer-note {
      font-size: 12px;
      line-height: 1.45;
      color: #aaa;
      margin-bottom: 10px;
    }

    .footer-note em {
      font-style: normal;
      color: #888;
    }

    /* =========================
   Ko-fi / Action Buttons
========================= */

    .kofi-btn,
    .report-btn {
      display: inline-flex;
      align-items: center;
      gap: 6px;

      padding: 6px 14px;
      border-radius: 999px;

      font-size: 13px;
      font-weight: 600;

      color: #fff;
      text-decoration: none;

      background: linear-gradient(180deg, #3a3f52, #2b2f40);
      border: 1px solid rgba(180, 200, 255, 0.35);

      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.35);
      transition: all 0.2s ease;
    }

    .kofi-btn {
      background: linear-gradient(180deg, #2f7cf6, #1e5fd6);
      border: none;
      box-shadow: 0 2px 10px rgba(60, 120, 255, 0.35);
    }

    .kofi-btn:hover,
    .report-btn:hover {
      transform: translateY(-1px);
      box-shadow:
        0 0 10px rgba(120, 170, 255, 0.4),
        0 4px 10px rgba(0, 0, 0, 0.6);
    }

    /* =========================
   問題回報區
========================= */

    .footer-report {
      margin-top: 14px;
      padding-top: 10px;
      border-top: 1px dashed rgba(180, 200, 255, 0.25);
      text-align: center;
    }

    /* =========================
   Footer Copy
========================= */

    .footer-copy {
      margin-top: 12px;
      font-size: 11px;
      color: #777;
    }

    /* =========================
   Mobile Adjust
========================= */

    @media (max-width: 768px) {
      .un-footer {
        padding: 20px 12px 14px;
      }

      .footer-title {
        font-size: 14px;
      }

      .footer-desc,
      .footer-note {
        font-size: 12px;
      }

      .kofi-btn,
      .report-btn {
        position: relative;
        display: inline-flex;
        align-items: center;
        gap: 6px;

        padding: 6px 14px;
        border-radius: 999px;

        font-size: 13px;
        font-weight: 600;
        color: #fff;
        text-decoration: none;

        background: linear-gradient(180deg, #3a3f52, #2b2f40);
        border: 1px solid rgba(180, 200, 255, 0.35);

        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.35);
        cursor: pointer;

        transition:
          transform .15s ease,
          box-shadow .15s ease,
          border-color .15s ease;

        /* 👀 微呼吸（很輕） */
        animation: btn-breathe 4s ease-in-out infinite;
      }

      @keyframes btn-breathe {

        0%,
        100% {
          box-shadow: 0 2px 8px rgba(0, 0, 0, .35);
        }

        50% {
          box-shadow: 0 2px 10px rgba(120, 170, 255, .15);
        }
      }


      .mobile-break {
        display: none;
      }
    }

    /* 手機浮動 Ko-fi */
    /* .kofi-float {
      display: none;
    } */

    @media (max-width: 768px) {
      /* .kofi-float {
        position: fixed;
        bottom: 14px;
        left: 50%;
        transform: translateX(-50%);
        background: linear-gradient(135deg, #7c4dff, #b388ff);
        border-radius: 999px;
        padding: 10px 16px;
        box-shadow: 0 6px 18px rgba(0, 0, 0, .35);
        display: flex;
        align-items: center;
        gap: 10px;
        z-index: 999;
      }

      .kofi-float a {
        color: #fff;
        font-size: 14px;
        font-weight: 600;
        text-decoration: none;
        white-space: nowrap;
      }

      .kofi-close {
        background: rgba(255, 255, 255, .25);
        border: none;
        border-radius: 50%;
        width: 22px;
        height: 22px;
        color: #fff;
        font-size: 14px;
        cursor: pointer;
      } */
    }

    /* =========================
   Sidebar Divider
========================= */
    .sidebar-divider {
      height: 1px;
      margin: 14px 0;
      background: rgba(255, 255, 255, 0.08);
    }

    /* =========================
   Sidebar Data Status
========================= */
    .sidebar-status {
      padding: 10px 12px;
      font-size: 12px;
      color: #cfcfcf;
      line-height: 1.6;
    }

    .sidebar-status-title {
      font-weight: 600;
      font-size: 13px;
      margin-bottom: 8px;
      color: #ffffff;
      opacity: 0.9;
    }

    .status-item {
      display: flex;
      justify-content: space-between;
      margin-bottom: 4px;
    }

    .status-label {
      color: #9aa0a6;
    }

    .status-value {
      color: #e0e0e0;
      text-align: right;
    }

    /* =========================
   Status Dot
========================= */
    .status-dot {
      display: inline-block;
      width: 6px;
      height: 6px;
      border-radius: 50%;
      margin-right: 6px;
    }

    .status-green {
      background-color: #4caf50;
    }

    .status-yellow {
      background-color: #ffb300;
    }

    .status-red {
      background-color: #f44336;
    }

    /* =========================
   Sidebar Server Switcher (Refined)
========================= */

    .sidebar-server-switcher {
      display: flex;
      align-items: center;
      gap: 6px;
      margin-top: 6px;
      position: relative;
      font-size: 12px;
    }

    .server-label {
      color: #9aa0a6;
      white-space: nowrap;
    }

    .server-btn {
      background: rgba(255, 255, 255, 0.04);
      border: 1px solid rgba(255, 255, 255, 0.12);
      color: #e0e0e0;
      font-size: 12px;
      padding: 4px 8px;
      border-radius: 6px;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      transition: all 0.15s ease;
    }

    .server-btn:hover {
      background: rgba(255, 255, 255, 0.12);
      border-color: rgba(255, 255, 255, 0.25);
    }

    .server-btn i {
      opacity: 0.9;
    }

    /* Dropdown menu */
    .server-menu {
      display: none;
      position: absolute;
      top: calc(100% + 6px);
      left: 0;
      min-width: 140px;
      background: #1b1c22;
      border: 1px solid rgba(255, 255, 255, 0.12);
      border-radius: 8px;
      box-shadow: 0 6px 18px rgba(0, 0, 0, 0.45);
      overflow: hidden;
      z-index: 1000;
    }

    .server-option {
      display: flex;
      align-items: center;
      gap: 8px;
      padding: 10px 12px;
      font-size: 13px;
      color: #e0e0e0;
      text-decoration: none;
      transition: background 0.15s ease;
    }

    .server-option:hover {
      background: rgba(255, 255, 255, 0.08);
    }

    .server-option.active {
      background: rgba(120, 120, 255, 0.22);
      font-weight: 600;
    }

    /* DMM icon */
    .server-icon.dmm {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 16px;
      height: 16px;
      font-size: 11px;
      font-weight: 700;
      border-radius: 4px;
      background: rgba(255, 255, 255, 0.18);
      color: #ffffff;
    }

    /* .footer-meta {
      margin-top: 18px;
      font-size: 12px;
      color: #888;
      line-height: 1.6;
    } */

    .title-mobile {
      display: none;
    }

    @media (max-width: 768px) {
      .title-desktop {
        display: none;
      }

      .title-mobile {
        display: inline;
      }
    }

    .status-sample {
      /* margin-top: 6px;
      padding-top: 6px;
      border-top: 1px dashed rgba(255, 255, 255, 0.15); */
      font-size: 13px;
      opacity: 0.9;
    }

    .status-label {
      color: #9aa4b2;
    }

    .status-value {
      font-weight: bold;
      color: #e6edf3;
    }

    .un-main-layout {
      display: flex;
      gap: 24px;
      align-items: flex-start;
    }

    /* 中央內容 */
    .un-main-content {
      flex: 1;
      min-width: 0;
      /* 防止表格爆版 */
    }

    /* 右上熱門 */
    .un-right-panel {
      width: 300px;
      flex-shrink: 0;
      position: sticky;
      top: 72px;
      /* 避開 navbar */
    }

    /* 手機隱藏右欄 */
    @media (max-width: 992px) {
      .un-right-panel {
        display: none;
      }
    }

    /* DMM icon */
    .server-icon.dmm {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 16px;
      height: 16px;
      font-size: 11px;
      font-weight: 700;
      border-radius: 4px;
      background: rgba(255, 255, 255, 0.18);
      color: #ffffff;
    }

    .un-main-inner {
      padding-top: 0;
    }

    /* =========================
   FIX: Mobile overlay blocks navbar dropdown
   Only for this page
========================= */

    @media (max-width: 768px) {

      /* 1️⃣ 讓 overlay 不吃點擊 */
      .un-overlay {
        pointer-events: none;
      }

      /* 2️⃣ 確保 navbar 本身可以被點 */
      .un-navbar {
        position: relative;
        z-index: 1001;
      }

      /* 3️⃣ dropdown 再拉高一層（保險） */
      .un-navbar .user-dropdown {
        z-index: 1002;
      }
    }

    .ulgg-modal.hidden {
      display: none;
    }

    .ulgg-modal {
      position: fixed;
      inset: 0;
      z-index: 20000;
    }

    .ulgg-modal-backdrop {
      position: absolute;
      inset: 0;
      background: rgba(0, 0, 0, .75);
    }

    .ulgg-modal-card {
      position: relative;
      max-width: 420px;
      margin: 5vh auto;
      background: #111827;
      border-radius: 14px;
      padding: 10px 24px;
      border: 1px solid rgba(255, 255, 255, .08);
      color: #e5e7eb;
    }

    .ulgg-modal-close {
      position: absolute;
      top: 12px;
      right: 12px;
      background: none;
      border: none;
      color: #9aa4b2;
      font-size: 18px;
      cursor: pointer;
    }

    .jkopay-box {
      margin: 5px auto;
      padding: 5px;
      border-radius: 12px;
      border: 2px solid #e60012;
      text-align: center;
    }

    .jkopay-box img {
      max-width: 220px;
      width: 100%;
    }

    .ulgg-modal-note {
      font-size: 12px;
      color: #9aa4b2;
      margin-top: 6px;
    }

    .ulgg-modal-title {
      font-size: 20px;
      line-height: 1.4;
      color: #f9fafb;
      text-align: center;
      margin-top: 0px;
    }

    .ulgg-modal-title span {
      display: block;
      font-size: 14px;
      color: #facc15;
      /* 淡黃，咖啡感 */
      margin-top: 6px;
    }

    /* 疊卡容器 */
    .pay-stack {
      position: relative;
      width: 260px;
      margin: 12px auto;
      height: 320px;
    }

    /* 單張支付卡 */
    .pay-card {
      position: absolute;
      left: 0;
      right: 0;
      margin: auto;

      background: #0f172a;
      border-radius: 14px;
      padding: 10px;
      text-align: center;

      transition: transform .25s ease, box-shadow .25s ease;
      cursor: pointer;
    }

    /* QR */
    .pay-card img {
      width: 100%;
      height: 240px;
      object-fit: contain;
      /* ⭐ 關鍵 */
      background: #020617;
      border-radius: 8px;
    }


    /* 標籤 */
    .pay-label {
      margin-top: 6px;
      font-size: 13px;
      font-weight: 600;
      opacity: .9;
    }

    /* ===== 狀態控制 ===== */

    /* 上層（可掃描） */
    .pay-card.active {
      z-index: 2;
      transform: translateY(0);
      box-shadow: 0 12px 30px rgba(0, 0, 0, .45);
    }

    /* 下層（只露一點） */
    .pay-card:not(.active) {
      z-index: 1;
      transform: translateY(18px);
      opacity: .95;
    }

    /* 街口風 */
    .pay-jko {
      border: 2px solid #e60012;
    }

    /* 全支付風（黃） */
    .pay-pxp {
      border: 2px solid #facc15;
      background: #111;
      position: relative;
      /* 🔑 一定要 */
    }

    .pay-stack {
      position: relative;
      width: 260px;
      height: 360px;
      /* 🔑 控制整體高度 */
      margin: 16px auto 8px;
    }

    /* 共用卡片樣式 */
    .pay-card {
      position: absolute;
      left: 0;
      width: 100%;
      border-radius: 14px;
      transition: transform .25s ease, box-shadow .25s ease;
      cursor: pointer;
    }

    /* 主卡（街口） */
    .pay-card-main {
      top: 0;
      z-index: 2;
      box-shadow: 0 10px 30px rgba(0, 0, 0, .45);
    }

    /* 副卡（全支付）→ 露出一點點 */
    .pay-card-sub {
      /* top: 0px; */
      left: 28px;
      z-index: 1;
      transform: scale(.95);
      /* opacity: .85; */
    }

    /* Hover / 可點提示 */
    .pay-card-sub:hover {
      transform: scale(.97) translateY(-4px);
      opacity: 1;
    }

    /* =========================
   全支付 標示樣式
========================= */

    /* 黃色外框（已經有的話可保留） */
    .pay-pxp {
      border: 2px solid #facc15;
      background: #111;
      position: relative;
      /* 🔑 讓角標定位用 */
    }

    /* 右下角「全」圓章 */
    /* 右下角「全」圓章 */
    .pay-pxp::after {
      content: "全";
      position: absolute;
      right: 10px;
      bottom: 10px;

      width: 32px;
      height: 32px;
      border-radius: 50%;

      background: #facc15;
      color: #111;

      font-size: 16px;
      font-weight: 900;
      line-height: 32px;
      text-align: center;

      box-shadow: 0 2px 8px rgba(0, 0, 0, .45);
      pointer-events: none;
    }

    .pay-jko::after {
      content: "街";
      position: absolute;
      right: 10px;
      bottom: 10px;

      width: 32px;
      height: 32px;
      border-radius: 50%;

      background: #e60012;
      color: #fff;

      font-size: 16px;
      font-weight: 900;
      line-height: 32px;
      text-align: center;

      box-shadow: 0 2px 8px rgba(0, 0, 0, .45);
      pointer-events: none;
    }

    .sidebar-group {
      margin-bottom: 4px;
    }

    .sidebar-group-toggle {
      width: 100%;
      background: transparent;
      border: 0;
      padding: 10px 14px;
      display: flex;
      align-items: center;
      gap: 8px;
      color: #e5e7eb;
      font-weight: 600;
      cursor: pointer;
    }

    .sidebar-group-toggle:hover {
      background: rgba(255, 255, 255, 0.05);
    }

    .sidebar-group-items {
      display: none;
      padding-left: 8px;
    }

    .sidebar-group-items.open {
      display: block;
    }

    .sidebar-group-items .sidebar-link {
      padding-left: 28px;
    }

    .chevron {
      margin-left: auto;
      font-size: 0.75rem;
      opacity: 0.6;
    }

    /* =========================
   FIX: Sidebar double scrollbar
========================= */

    /* 1️⃣ sidebar 本體才可以捲 */
    .un-sidebar {
      height: 100vh;
      overflow-y: auto;
      overflow-x: hidden;
    }

    /* 2️⃣ 禁止內層自己產生捲軸（重點） */
    .un-sidebar nav,
    .sidebar-nav,
    .sidebar-group,
    .sidebar-group-items {
      overflow: visible !important;
    }

    /* Chrome / Edge */
    .un-sidebar::-webkit-scrollbar {
      width: 6px;
    }

    .un-sidebar::-webkit-scrollbar-track {
      background: transparent;
    }

    .un-sidebar::-webkit-scrollbar-thumb {
      background-color: #3b4252;
      border-radius: 6px;
    }

    /* Firefox */
    .un-sidebar {
      scrollbar-width: thin;
      scrollbar-color: #3b4252 transparent;
    }

    .footer-support {
      display: flex;
      justify-content: center;
      gap: 12px;
      flex-wrap: wrap;
      margin-bottom: 14px;
    }

    .footer-support .kofi-btn span {
      font-size: 11px;
      opacity: .85;
    }

    /* =========================
       今日命運
    ========================= */
    .fortune-float-btn {
      position: fixed;
      right: 12px;
      bottom: 147px;
      z-index: 15000;
      width: 46px;
      height: 46px;
      display: flex;
      align-items: center;
      justify-content: center;
      border: 1px solid rgba(244, 211, 132, .55);
      border-radius: 50%;
      color: #fff6d6;
      background: radial-gradient(circle at 35% 30%, #7c5fc9, #2d214d 70%);
      box-shadow: 0 6px 20px rgba(0, 0, 0, .45), 0 0 16px rgba(176, 131, 255, .28);
      cursor: pointer;
      font-size: 21px;
      transition: transform .2s ease, box-shadow .2s ease;
    }

    .fortune-float-btn:hover {
      transform: translateY(-2px) scale(1.05);
      box-shadow: 0 8px 24px rgba(0, 0, 0, .5), 0 0 22px rgba(219, 189, 255, .48);
    }

    /* =========================================
   今日命運 Modal 最終版
========================================= */

    .fortune-modal {
      position: fixed;
      inset: 0;
      z-index: 25000;

      display: flex;
      align-items: center;
      justify-content: center;

      padding: 14px 18px;

      overflow-y: auto;
      overflow-x: hidden;

      scrollbar-width: none;
      overscroll-behavior: contain;
    }

    .fortune-modal::-webkit-scrollbar {
      display: none;
    }

    .fortune-dialog {
      position: relative;

      width: min(1000px, calc(100vw - 32px));
      min-height: 0;
      margin: auto;

      box-sizing: border-box;
      padding: 18px 28px 20px;

      overflow: visible;

      border: 1px solid rgba(231, 207, 148, .28);
      border-radius: 22px;

      background:
        radial-gradient(circle at 50% 12%,
          rgba(113, 76, 160, .34),
          transparent 42%),
        linear-gradient(180deg,
          rgba(22, 18, 34, .98),
          rgba(9, 11, 18, .98) 76%);

      box-shadow:
        0 25px 90px rgba(0, 0, 0, .78),
        inset 0 1px 0 rgba(255, 255, 255, .035);

      color: #f5f1e8;
    }

    .fortune-modal.hidden {
      display: none;
    }

    .fortune-backdrop {
      position: absolute;
      inset: 0;
      background: radial-gradient(circle at center, rgba(43, 27, 78, .58), rgba(0, 0, 0, .9));
      backdrop-filter: blur(5px);
    }



    .fortune-close {
      position: absolute;
      top: 14px;
      right: 16px;
      z-index: 10;
      border: 0;
      background: transparent;
      color: #b9afc8;
      font-size: 22px;
      cursor: pointer;
    }

    .fortune-heading {
      text-align: center;
      margin-bottom: 12px;
    }

    .fortune-heading h2 {
      margin: 0;
      font-size: 26px;
      letter-spacing: .12em;
    }

    .fortune-heading p {
      margin: 8px 0 0;
      color: #a99db9;
      font-size: 13px;
    }



    .fortune-card img {
      width: 100%;
      height: 100%;
      display: block;
      object-fit: contain;
      border-radius: 10px;
      filter: drop-shadow(0 14px 18px rgba(0, 0, 0, .55));
    }

    .fortune-stage {
      position: relative;
      height: 300px;
      display: flex;
      align-items: center;
      justify-content: center;
      perspective: 1200px;
    }

    .fortune-result {
      margin-top: -18px;
    }

    .fortune-result-message {
      max-width: 780px;
      margin: 7px auto 3px;
      line-height: 1.55;
    }

    .fortune-character-line {
      max-width: 780px;
      margin: 3px auto 0;

      color: #cfc6d9;
      font-size: 14px;
      line-height: 1.55;
      text-align: center;
    }

    .fortune-character-line strong {
      color: #e5d4f3;
      font-weight: 700;
    }

    /* 三張牌共同旋轉的圓形區域 */
    .fortune-orbit {
      --fortune-radius: 112px;

      position: relative;
      width: 330px;
      height: 300px;

      transform-origin: 50% 50%;
      will-change: transform;
    }

    /* 每一張牌在圓周上的定位點 */
    .fortune-orbit-slot {
      position: absolute;
      left: 50%;
      top: 50%;

      width: 0;
      height: 0;

      transform:
        rotate(var(--fortune-angle)) translateY(calc(var(--fortune-radius) * -1));

      transform-origin: 0 0;
    }

    /* 卡片縮小為原本約 80% */
    .fortune-card {
      position: absolute;

      width: clamp(98px, 16vw, 152px);
      aspect-ratio: 2 / 3;

      left: 0;
      top: 0;

      opacity: 0;

      /*
   * 抵銷 slot 的角度，
   * 讓卡片初始保持直立。
   */
      transform:
        translate(-50%, -50%) rotate(calc(var(--fortune-angle) * -1)) scale(.82);

      transform-origin: center;
      filter: brightness(.72) saturate(.78);

      transition:
        left .85s cubic-bezier(.16, .8, .2, 1),
        top .85s cubic-bezier(.16, .8, .2, 1),
        transform .85s cubic-bezier(.16, .8, .2, 1),
        opacity .4s ease,
        filter .45s ease;

      will-change: transform, opacity;
    }

    .fortune-card img {
      width: 100%;
      height: 100%;
      display: block;
      object-fit: contain;
      border-radius: 10px;
      filter: drop-shadow(0 14px 18px rgba(0, 0, 0, .55));
    }

    /*
 * 整個 orbit 繞圓心逆時針旋轉。
 * 三張牌之間的位置關係完全不變。
 */
    .fortune-stage.is-orbiting .fortune-orbit {
      animation: fortune-orbit-spin 6s linear forwards;
    }

    /*
 * 卡片做反方向補償旋轉，
 * 因此公轉期間卡面會一直保持直立，
 * 不會跟著圓周自轉。
 */
    .fortune-stage.is-orbiting .fortune-card {
      opacity: 1;
      animation: fortune-card-counter-spin 6s linear forwards;
      filter: brightness(.9) saturate(.9);
    }

    @keyframes fortune-orbit-spin {
      from {
        transform: rotate(0deg);
      }

      to {
        transform: rotate(-900deg);
      }
    }

    @keyframes fortune-card-counter-spin {
      from {
        transform:
          translate(-50%, -50%) rotate(calc(var(--fortune-angle) * -1)) scale(.82);
      }

      to {
        transform:
          translate(-50%, -50%) rotate(calc((var(--fortune-angle) * -1) + 900deg)) scale(.82);
      }
    }

    /* 公轉完成後，取消圓周定位 */




    .fortune-card.is-dismissed {
      opacity: .08;
      filter: grayscale(.8) brightness(.4);

      transform:
        translate(-50%, calc(-50% + 40px)) scale(.72) !important;
    }

    .fortune-card.is-dismissed {
      opacity: .08;
      filter: grayscale(.8) brightness(.4);
      transform: translateY(55px) scale(.78) !important;
    }

    .fortune-result {
      max-width: 720px;
      margin: -8px auto 0;
      text-align: center;
      opacity: 0;
      transform: translateY(16px);
      transition: opacity .5s ease, transform .5s ease;
      pointer-events: none;
    }

    .fortune-result.is-visible {
      opacity: 1;
      transform: translateY(0);
      pointer-events: auto;
    }

    .fortune-result-name {
      font-size: 22px;
      font-weight: 800;
      color: #f7df9b;
    }

    .fortune-result-skill {
      margin-top: 4px;
      color: #d8c9ef;
      font-weight: 600;
    }

    .fortune-result-stars {
      margin: 8px 0;
      color: #ffd36a;
      font-size: 22px;
      letter-spacing: 3px;
    }

    .fortune-result-luck {
      display: inline-block;
      padding: 5px 14px;
      border-radius: 999px;
      background: rgba(134, 96, 190, .23);
      border: 1px solid rgba(201, 168, 244, .28);
      color: #e7d6ff;
      font-weight: 700;
    }

    .fortune-result-tactics {
      max-width: 480px;
      margin: 10px auto 0;

      display: flex;
      justify-content: center;
      gap: 8px;
      flex-wrap: wrap;
    }

    .fortune-result-tactics>span {
      min-width: 130px;
      padding: 7px 12px;

      display: flex;
      align-items: center;
      justify-content: center;
      gap: 7px;

      border: 1px solid rgba(183, 159, 222, .2);
      border-radius: 9px;

      background: rgba(255, 255, 255, .03);
    }

    .fortune-result-tactics small {
      color: #91879e;
      font-size: 11px;
    }

    .fortune-result-tactics strong {
      color: #e4d5f5;
      font-size: 13px;
    }

    .fortune-result-pressure {
      width: fit-content;
      margin: 8px auto 0;
      padding: 6px 12px;

      display: flex;
      align-items: center;
      gap: 8px;

      border: 1px solid rgba(185, 118, 222, .3);
      border-radius: 8px;

      background: rgba(109, 54, 138, .13);
    }

    .fortune-result-pressure span {
      color: #91879e;
      font-size: 11px;
    }

    .fortune-result-pressure strong {
      color: #d8a6ff;
      font-size: 13px;
    }

    .fortune-result-message {
      margin: 12px auto 6px;
      max-width: 620px;
      line-height: 1.75;
      color: #ece8f2;
    }

    .fortune-result-tip {
      color: #b9afc8;
      font-size: 13px;
    }



    .fortune-result-stat {
      display: inline-flex;
      align-items: center;
      gap: 5px;

      padding: 5px 9px;
      border: 1px solid rgba(201, 168, 244, .2);
      border-radius: 8px;

      color: #d9cee8;
      background: rgba(255, 255, 255, .035);

      font-size: 12px;
      line-height: 1;
    }

    .fortune-result-stat strong {
      color: #f4dc96;
      font-size: 13px;
    }

    .fortune-result-stat.is-negative strong {
      color: #ff9d9d;
    }

    .fortune-result-stat.is-load strong {
      color: #d8a6ff;
    }

    .fortune-replay-note {
      margin-top: 10px;
      color: #736b7d;
      font-size: 11px;
    }

    @media (max-width: 600px) {
      .fortune-modal {
        align-items: flex-start;
        padding: 10px;
        overflow-y: auto;
      }

      .fortune-dialog {
        width: 100%;
        min-height: 0;
        margin: 70px auto 20px;
        padding: 18px 10px 14px;
        overflow: visible;
      }

      .fortune-heading {
        margin-bottom: 4px;
      }

      .fortune-heading h2 {
        font-size: 21px;
      }

      .fortune-heading p {
        margin-top: 5px;
        font-size: 12px;
      }

      .fortune-stage {
        height: 180px;
      }

      .fortune-orbit {
        --fortune-radius: 68px;
        width: 220px;
        height: 220px;
      }

      .fortune-card {
        width: min(22vw, 88px);
      }

      .fortune-orbit-slot.is-selected .fortune-card {
        transform:
          translate(-50%, -50%) rotate(0deg) scale(1.05) !important;
      }

      .fortune-result {
        margin-top: -4px;
        padding: 0 10px;
      }

      .fortune-result-name {
        font-size: 20px;
      }

      .fortune-result-skill {
        margin-top: 2px;
        font-size: 15px;
      }

      .fortune-result-stars {
        margin: 6px 0;
        font-size: 20px;
      }

      .fortune-result-luck {
        padding: 4px 12px;
        font-size: 14px;
      }

      .fortune-result-message {
        margin: 9px auto 4px;
        max-width: 330px;
        font-size: 14px;
        line-height: 1.55;
      }

      .fortune-result-tip {
        max-width: 330px;
        margin: 0 auto;
        font-size: 12px;
        line-height: 1.5;
      }

      .fortune-result-stats {
        max-width: 340px;
        gap: 5px;
        margin-top: 9px;
      }

      .fortune-result-stat {
        padding: 5px 7px;
        font-size: 11px;
      }

      .fortune-easter-egg {
        min-height: 18px;
        margin-top: 8px;
        font-size: 13px;
      }

      .fortune-share-actions {
        margin-top: 9px;
      }

      .fortune-share-buttons {
        width: 100%;
      }

      .fortune-share-btn {
        width: min(100%, 180px);
        min-width: 0;
      }
    }

    @media (prefers-reduced-motion: reduce) {

      .fortune-card,
      .fortune-result {
        transition-duration: .01ms !important;
      }
    }

    .fortune-orbit-slot {
      transition:
        opacity .55s ease,
        filter .55s ease;
    }

    /* 未選中的兩個整體消失 */
    .fortune-orbit-slot.is-dismissed {
      opacity: 0;
      filter: blur(4px);
      pointer-events: none;
    }

    /* 避免卡片原有 dismissed 樣式干擾 */
    .fortune-card.is-dismissed {
      opacity: 1;
      filter: none;
    }

    /* 選中的定位點移回真正圓心 */
    .fortune-orbit-slot.is-selected {
      left: 50%;
      top: 50%;
      z-index: 20;

      transform: translate(0, 0) !important;
      transform-origin: 0 0;
    }

    .fortune-orbit-slot.is-selected .fortune-card {
      z-index: 20;
      opacity: 1;

      animation: none !important;
      animation-fill-mode: none !important;

      filter:
        brightness(1.08) saturate(1.12) drop-shadow(0 0 20px rgba(255, 221, 134, .8));

      transform:
        translate(-50%, -50%) rotate(0deg) scale(1.12) !important;

      transition:
        transform .85s cubic-bezier(.16, .8, .2, 1),
        filter .5s ease;
    }

    .fortune-orbit-slot.is-selected .fortune-card img {
      transform: rotate(0deg) !important;
    }

    /* 三張一起淡出 */
    .fortune-stage.is-fading .fortune-card {
      opacity: 0 !important;
      transition: opacity .45s ease !important;
    }

    /* 選中卡重新淡入 */
    .fortune-orbit-slot.is-selected .fortune-card {
      z-index: 20;
      opacity: 1;

      animation: none !important;
      animation-fill-mode: none !important;

      filter:
        brightness(1.08) saturate(1.12) drop-shadow(0 0 20px rgba(255, 221, 134, .8));

      transform:
        translate(-50%, -50%) rotate(0deg) scale(1.12) !important;

      transition:
        opacity .5s ease,
        transform .85s cubic-bezier(.16, .8, .2, 1),
        filter .5s ease;
    }

    .fortune-easter-egg {
      min-height: 24px;
      margin-top: 12px;
      color: #d8c9ef;
      font-size: 14px;
      font-weight: 600;
      text-align: center;
      opacity: 0;
      transform: translateY(5px);
      transition:
        opacity .35s ease,
        transform .35s ease;
    }

    .fortune-easter-egg.is-visible {
      opacity: 1;
      transform: translateY(0);
    }

    .fortune-share-btn:hover {
      transform: translateY(-1px);
      border-color: rgba(246, 220, 151, .7);
    }



    .fortune-share-status {
      min-height: 18px;
      color: #a99db9;
      font-size: 12px;
    }

    .fortune-share-actions {
      margin-top: 14px;
      text-align: center;
    }

    .fortune-share-buttons {
      width: min(100%, 660px);
      margin: 0 auto;

      display: grid;
      grid-template-columns:
        repeat(auto-fit, minmax(170px, 1fr));
      align-items: stretch;
      gap: 10px;
    }

    .fortune-share-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;

      box-sizing: border-box;
      width: 180px;
      min-width: 180px;
      min-height: 40px;

      margin: 0;
      padding: 8px 16px;

      border: 1px solid rgba(231, 207, 148, .38);
      border-radius: 999px;

      color: #f8e7b1;
      background:
        linear-gradient(180deg,
          rgba(117, 82, 163, .55),
          rgba(54, 38, 86, .72));

      font-family: inherit;
      font-size: 15px;
      font-weight: 700;
      line-height: 1.4;

      text-align: center;
      text-decoration: none;
      white-space: nowrap;

      cursor: pointer;
      appearance: none;
      -webkit-appearance: none;

      transition:
        transform .18s ease,
        opacity .18s ease,
        border-color .18s ease;
    }

    .fortune-share-btn i {
      width: 18px;
      flex: 0 0 18px;
      text-align: center;
    }

    .fortune-share-btn span {
      display: inline-block;
    }

    .fortune-share-btn:hover,
    .fortune-share-btn:focus {
      color: #f8e7b1;
      text-decoration: none;
      transform: translateY(-1px);
      border-color: rgba(246, 220, 151, .7);
    }

    .fortune-share-btn:disabled {
      cursor: wait;
      opacity: .55;
      transform: none;
    }

    .fortune-progress-btn {
      color: #ddd5e8;
      border-color: rgba(186, 165, 218, .3);

      background:
        linear-gradient(180deg,
          rgba(67, 75, 104, .62),
          rgba(38, 42, 62, .76));
    }

    .fortune-progress-btn:hover,
    .fortune-progress-btn:focus {
      color: #fff;
      border-color: rgba(201, 181, 231, .58);
    }

    .fortune-login-btn {
      border-color: rgba(104, 180, 229, .42);

      background:
        linear-gradient(180deg,
          rgba(48, 119, 166, .68),
          rgba(35, 69, 106, .82));
    }

    .fortune-share-status {
      display: block;
      min-height: 18px;
      margin-top: 8px;

      color: #a99db9;
      font-size: 12px;
      text-align: center;
    }

    /* =========================
   今日命運分享圖片
========================= */

    .fortune-share-card {
      position: fixed;
      left: -99999px;
      top: 0;

      width: 720px;
      height: 1000px;
      overflow: hidden;

      box-sizing: border-box;
      padding: 54px 56px 48px;

      color: #f7f1e8;
      background:
        radial-gradient(circle at 50% 22%,
          rgba(141, 95, 196, .42),
          transparent 36%),
        radial-gradient(circle at 10% 90%,
          rgba(71, 48, 108, .42),
          transparent 42%),
        linear-gradient(160deg,
          #171124 0%,
          #0c0c16 58%,
          #05070d 100%);

      font-family:
        Inter,
        "Noto Sans TC",
        "Microsoft JhengHei",
        sans-serif;

      isolation: isolate;
    }

    .fortune-share-card::before {
      content: "";
      position: absolute;
      inset: 24px;

      border: 1px solid rgba(240, 214, 150, .25);
      border-radius: 26px;

      pointer-events: none;
    }

    .fortune-share-card::after {
      content: "";
      position: absolute;
      inset: 38px;

      border: 1px solid rgba(190, 154, 231, .12);
      border-radius: 20px;

      pointer-events: none;
    }

    .fortune-share-card__glow {
      position: absolute;
      left: 50%;
      top: 320px;

      width: 390px;
      height: 390px;

      border-radius: 50%;
      transform: translate(-50%, -50%);

      background:
        radial-gradient(circle,
          rgba(198, 155, 255, .18),
          transparent 68%);

      filter: blur(10px);
    }

    .fortune-share-card__header {
      position: relative;
      z-index: 2;
      text-align: center;
    }

    .fortune-share-card__eyebrow {
      color: rgba(217, 196, 237, .72);
      font-size: 13px;
      font-weight: 600;
      letter-spacing: .24em;
    }

    .fortune-share-card__title {
      margin-top: 10px;

      color: #f8e6ad;
      font-size: 42px;
      font-weight: 800;
      letter-spacing: .16em;
    }

    .fortune-share-card__date {
      margin-top: 5px;
      color: rgba(220, 210, 229, .62);
      font-size: 14px;
      letter-spacing: .12em;
    }

    .fortune-share-card__content {
      position: relative;
      z-index: 2;
      text-align: center;
    }

    .fortune-share-card__character {
      width: 234px;
      height: 351px;
      margin: 16px auto 0;

      display: flex;
      align-items: center;
      justify-content: center;
    }

    .fortune-share-card__character img {
      width: 100%;
      height: 100%;
      display: block;
      object-fit: contain;

      filter:
        drop-shadow(0 18px 24px rgba(0, 0, 0, .65)) drop-shadow(0 0 16px rgba(223, 182, 255, .18));
    }

    .fortune-share-card__name {
      margin-top: 2px;

      color: #f7dfa1;
      font-size: 28px;
      font-weight: 800;
    }

    .fortune-share-card__name span {
      margin-left: 7px;

      color: #c9b2dd;
      font-size: 17px;
      font-weight: 600;
    }

    .fortune-share-card__skill {
      margin-top: 5px;
      color: #d8cae9;
      font-size: 17px;
      font-weight: 600;
    }

    .fortune-share-card__stars {
      margin-top: 13px;

      color: #ffd66e;
      font-size: 28px;
      letter-spacing: 5px;

      text-shadow: 0 0 15px rgba(255, 212, 105, .3);
    }

    .fortune-share-card__luck {
      display: inline-flex;
      margin-top: 12px;
      padding: 7px 20px;

      border: 1px solid rgba(213, 181, 245, .28);
      border-radius: 999px;

      color: #eadcff;
      background: rgba(131, 87, 180, .24);

      font-size: 17px;
      font-weight: 800;
    }

    .fortune-share-card__message {
      max-width: 550px;
      margin: 12px auto 0;
      color: #eee9f2;
      font-size: 18px;
      line-height: 1.65;
    }

    .fortune-share-card__tip {
      max-width: 540px;
      margin: 10px auto 0;
      padding-top: 10px;

      border-top: 1px solid rgba(255, 255, 255, .1);

      color: #bbb0c5;
      font-size: 15px;
      line-height: 1.55;
    }

    .fortune-share-card__tip span {
      display: block;
      margin-bottom: 3px;

      color: #d7c0eb;
      font-size: 13px;
      font-weight: 800;
      letter-spacing: .12em;
    }

    .fortune-share-card__watermark {
      position: absolute;
      right: 58px;
      bottom: 28px;
      z-index: 3;

      display: flex;
      flex-direction: column;
      align-items: flex-end;

      text-align: right;
    }

    .fortune-share-card__watermark strong {
      color: rgba(248, 229, 173, .9);
      font-size: 25px;
      font-weight: 900;
      letter-spacing: .08em;
    }

    .fortune-share-card__watermark small {
      margin-top: 1px;
      color: rgba(205, 192, 218, .62);
      font-size: 12px;
      letter-spacing: .08em;
    }

    /* =========================================
   今日命運 Modal：縮短高度與避免超出視窗
========================================= */

    .fortune-dialog {
      width: min(1000px, calc(100vw - 32px));
      max-height: calc(100vh - 24px);
      overflow-y: auto;
      overflow-x: hidden;
      box-sizing: border-box;
      padding: 20px 28px 18px;
      overscroll-behavior: contain;
      scrollbar-width: thin;
    }

    /* 結果區整體間距縮小 */
    .fortune-result {
      gap: 7px;
    }

    .fortune-result-name {
      margin-top: 8px;
      line-height: 1.25;
    }

    .fortune-result-skill {
      margin-top: 2px;
    }

    .fortune-result-stars {
      margin-top: 4px;
      line-height: 1.1;
    }

    .fortune-result-luck {
      margin-top: 3px;
    }

    /* 六項能力值縮小間距 */
    .fortune-result-stats {
      width: fit-content;
      max-width: 100%;
      margin: 6px auto;

      display: flex;
      align-items: center;
      justify-content: center;
      flex-wrap: wrap;
      gap: 6px;
    }

    .fortune-result-stat {
      padding: 4px 9px;
      line-height: 1.2;
    }

    /* 主文與戰術縮短上下留白 */
    .fortune-result-message {
      max-width: 760px;
      margin: 5px auto 0;
      line-height: 1.65;
    }

    .fortune-result-tip {
      max-width: 760px;
      margin: 2px auto 0;
      line-height: 1.55;
    }

    /* 角色啟示區也壓縮 */
    .fortune-character-insight {
      max-width: 760px;
      margin: 8px auto 0;
      padding: 10px 14px;
    }

    .fortune-character-background,
    .fortune-character-insight-text {
      line-height: 1.55;
    }

    /* 測試提示縮小 */
    .fortune-easter-egg {
      margin-top: 5px;
      line-height: 1.4;
    }

    /* 兩個按鈕並排 */
    .fortune-share-actions {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 10px;
      width: min(100%, 520px);
      margin: 10px auto 0;
      align-items: center;
    }

    .fortune-share-btn {
      width: 100%;
      min-width: 0;
      min-height: 42px;
      margin: 0;
      padding: 9px 14px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 7px;
      white-space: nowrap;
    }

    /* 狀態訊息需要橫跨整列 */
    .fortune-share-status {
      grid-column: 1 / -1;
      min-height: 0;
      margin: 0;
      text-align: center;
    }

    /* 中等高度螢幕進一步壓縮 */
    @media (max-height: 900px) and (min-width: 641px) {
      .fortune-dialog {
        padding-top: 14px;
        padding-bottom: 14px;
      }

      .fortune-stage {
        transform: scale(.9);
        transform-origin: center center;
        margin-top: -12px;
        margin-bottom: -15px;
      }

      .fortune-result {
        gap: 5px;
      }

      .fortune-result-message {
        line-height: 1.5;
      }

      .fortune-result-tip {
        line-height: 1.45;
      }
    }

    /* 手機仍改回單欄，避免按鈕文字擠壓 */
    @media (max-width: 640px) {
      .fortune-dialog {
        width: calc(100vw - 16px);
        max-height: calc(100dvh - 12px);
        padding: 14px 12px 16px;
      }

      .fortune-share-actions {
        grid-template-columns: 1fr;
        width: 100%;
        gap: 8px;
      }

      .fortune-share-status {
        grid-column: 1;
      }

      .fortune-share-btn {
        min-height: 40px;
        font-size: 13px;
      }

      .fortune-result-stats {
        gap: 5px;
      }

      .fortune-result-stat {
        padding: 4px 7px;
        font-size: 12px;
      }
    }

    .fortune-share-actions {
      display: block;
      width: 100%;
      margin: 12px auto 0;
      text-align: center;
    }

    .fortune-share-buttons {
      width: min(100%, 660px);
      margin: 0 auto;

      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      align-items: stretch;
      gap: 10px;
    }

    .fortune-share-btn {
      width: 100%;
      min-width: 0;
      min-height: 42px;
      margin: 0;
      padding: 8px 12px;

      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 7px;

      box-sizing: border-box;
      white-space: nowrap;
    }

    .fortune-share-status {
      display: block;
      min-height: 0;
      margin: 5px auto 0;
      text-align: center;
    }

    @media (max-width: 640px) {
      .fortune-modal {
        padding: 8px;
      }

      .fortune-dialog {
        width: 100%;
        margin: 62px auto 14px;
        padding: 14px 10px 16px;
      }

      .fortune-share-buttons {
        width: min(100%, 300px);
        grid-template-columns: 1fr;
        gap: 8px;
      }

      .fortune-stage {
        height: 180px;
      }

      .fortune-result {
        margin-top: -4px;
      }

      .fortune-character-line {
        max-width: 340px;
        font-size: 13px;
        text-align: left;
      }
    }

    .fortune-share-card__character-line {
      max-width: 550px;
      margin: 7px auto 0;

      color: #cfc5d8;
      font-size: 15px;
      line-height: 1.55;
      text-align: left;
    }

    .fortune-share-card__character-line strong {
      color: #e1ccec;
      font-weight: 800;
    }

    .fortune-character-humor {
      margin-top: 5px;
      color: #c9b8d8;
      font-size: 13px;
      font-style: italic;
    }

    .fortune-character-humor strong {
      color: #d9b9ee;
      font-style: normal;
    }

    @media (max-width: 640px) {
      .fortune-character-humor {
        font-size: 12px;
      }
    }

    .fortune-share-card__humor {
      color: #c8b7d4;
      font-style: italic;
    }

    .fortune-share-card__humor strong {
      color: #ddbfea;
      font-style: normal;
    }

    .fortune-seasonal-insight {
      color: #d9cde3;
    }

    .fortune-seasonal-insight strong {
      color: #efd58f;
    }

    .fortune-share-card__seasonal-insight {
      color: #d9cde3;
    }

    .fortune-share-card__seasonal-insight strong {
      color: #efd58f;
    }

    .fortune-result-message {
      text-align: left;
    }


    .fortune-character-line {
      text-align: left;
    }

    .fortune-share-card__character-line {
      text-align: left;
    }
  </style>
  <?php

  $isSteamLogin = !empty($_SESSION['steam_id']);

  $isVerifiedUL = $isSteamLogin && ($_SESSION['ack'] ?? 0) >= 1;
  $isApplyingUL = $isSteamLogin && ($_SESSION['ack'] ?? 0) == 0 && !empty($_SESSION['apply']);
  $isGuestSteam = $isSteamLogin && ($_SESSION['ack'] ?? 0) == 0 && empty($_SESSION['apply']);


  /**
   * Data Status (Simple Version)
   * 只支援 TW / JP
   */

  // 預設 TW
  $server =  'Steam';
  $tableName   = 'ranking_bp_TW';
  $serverLabel = 'Steam 服';

  // 撈最後更新時間
  $sql = "SELECT MAX(ts) AS last_update FROM {$tableName}";
  $stmt = $db->query($sql);
  $row  = $stmt->fetch(PDO::FETCH_ASSOC);

  $lastUpdate = $row['last_update'] ?? null;

  // 預設顯示
  $statusText = '尚無資料';
  $statusDot  = 'status-red';

  if ($lastUpdate) {
    $diffSeconds = time() - strtotime($lastUpdate);
    $diffMinutes = floor($diffSeconds / 60);

    if ($diffMinutes < 30) {
      $statusDot = 'status-green';
    } elseif ($diffMinutes < 60) {
      $statusDot = 'status-yellow';
    } else {
      $statusDot = 'status-red';
    }

    if ($diffMinutes < 1) {
      $statusText = '剛剛更新';
    } elseif ($diffMinutes < 60) {
      $statusText = "{$diffMinutes} 分鐘前";
    } else {
      $hours = floor($diffMinutes / 60);
      $statusText = "{$hours} 小時前";
    }
  }


  $server =  'JP';
  $tableName   = 'ranking_bp_JP';
  $serverLabelJP = 'DMM 服';

  // 撈最後更新時間
  $sql = "SELECT MAX(ts) AS last_update FROM {$tableName}";
  $stmt = $db->query($sql);
  $row  = $stmt->fetch(PDO::FETCH_ASSOC);

  $lastUpdate = $row['last_update'] ?? null;

  // 預設顯示
  $statusTextJP = '尚無資料';
  $statusDotJP  = 'status-red';

  if ($lastUpdate) {
    $diffSeconds = time() - strtotime($lastUpdate);
    $diffMinutes = floor($diffSeconds / 60);

    if ($diffMinutes < 30) {
      $statusDotJP = 'status-green';
    } elseif ($diffMinutes < 60) {
      $statusDotJP = 'status-yellow';
    } else {
      $statusDotJP = 'status-red';
    }

    if ($diffMinutes < 1) {
      $statusTextJP = '剛剛更新';
    } elseif ($diffMinutes < 60) {
      $statusTextJP = "{$diffMinutes} 分鐘前";
    } else {
      $hours = floor($diffMinutes / 60);
      $statusTextJP = "{$hours} 小時前";
    }
  }
  // arena_unlight 中實際用來分析的場次
  $sql = "SELECT COUNT(*)
  FROM arena_unlight
  WHERE ack1 = 1
    AND ack2 = 1
    AND (win = 1 OR lose = 1 OR tie = 1)";
  $totalSamples = (int)$db->query($sql)->fetchColumn();

  // 今日熱門搜尋
  $sql = "SELECT search_term, COUNT(*) AS cnt
  FROM visitors
  WHERE search_term IS NOT NULL
    AND search_term != ''
    AND is_bot = 0
    AND visited_at >= CURDATE()
  GROUP BY search_term
  ORDER BY cnt DESC
  LIMIT 5
";
  $topSearch = $db->query($sql)->fetchAll();

  // 今日熱門角色
  $sql = "SELECT character_name, COUNT(*) AS cnt
  FROM visitors
  WHERE character_name IS NOT NULL
    AND character_name != ''
    AND is_bot = 0
    AND visited_at >= CURDATE()
  GROUP BY character_name
  ORDER BY cnt DESC
  LIMIT 5
";
  $topChar = $db->query($sql)->fetchAll();

  ?>
</head>

<body class="un-body">
  <header class="un-navbar">

    <button class="nav-toggle" type="button" data-toggle="sidebar">
      ☰
    </button>
    <div class="nav-brand">
      <span class="nav-logo">UL.GG</span>
      <span class="nav-subtitle">Unlight Analytics</span>
    </div>
    <div class="nav-right">

      <?php if (!empty($_SESSION['steam_id'])): ?>

        <?php
        // 決定顯示名稱：UL 名優先，其次 Steam 名，再其次是 Steam ID
        $displayName = $_SESSION['username']
          ?? $_SESSION['steam_name']
          ?? $_SESSION['steam_id'];

        // 頭像 fallback
        $avatar = $_SESSION['steam_avatar_full']
          ?? "/assets/favicon/android-chrome-192x192.png";
        if (empty($avatar)) {
          $avatar = "/assets/favicon/android-chrome-192x192.png";
        }
        ?>

        <div class="nav-user-info">
          <div class="user-box" onclick="toggleUserMenu()">

            <img src="<?= htmlspecialchars($avatar) ?>" class="avatar">

            <span class="username"><?= htmlspecialchars($displayName) ?></span>

            <i class="fa fa-caret-down caret"></i>

            <div class="user-dropdown" id="userDropdown">

              <!-- UL.GG 個人頁面（有 UL ID 才顯示） -->
              <?php if ($isVerifiedUL): ?>
                <!-- ack = 1，已綁定 -->
                <a href="/pages/profile.php">
                  <i class="fa fa-user"></i> 我的 UL.GG
                </a>

              <?php elseif ($isApplyingUL): ?>
                <!-- apply = 1，審核中 -->
                <a href="/pages/bind_ulid.php" style="opacity:.7;">
                  <i class="fa fa-clock-o"></i> UL ID 審核中
                </a>

              <?php elseif ($isGuestSteam): ?>
                <!-- ack = 0 & apply = 0 -->
                <a href="/pages/bind_ulid.php">
                  <i class="fa fa-link"></i> 綁定 UL ID
                </a>
              <?php endif; ?>



              <!-- Steam Profile -->
              <a href="https://steamcommunity.com/profiles/<?= $_SESSION['steam_id'] ?>" target="_blank">
                <i class="fab fa-steam"></i> 我的 Steam
              </a>

              <!-- 登出 -->
              <a href="/pages/logout.php" class="logout">
                <i class="fa fa-sign-out"></i> 登出
              </a>
              <!-- 登出所有裝置 -->
              <a href="/pages/logout_all.php"
                class="logout-all"
                onclick="return confirm('這會讓所有裝置都登出，確定嗎？');">
                <i class="fa fa-power-off"></i> 登出所有裝置
              </a>

            </div>
          </div>
        </div>
      <?php else: ?>
        <div class="nav-login-area">
          <a href="/api/auth/steam_start.php"
            class="nav-login-btn">
            登入
          </a>
        </div>
      <?php endif; ?>
    </div>
  </header>
  <div class="un-overlay"></div><!-- ⭐ 手機遮罩 -->
  <div class="un-layout">
    <aside class="un-sidebar">
      <div class="sidebar-section sidebar-title">
        <span class="sidebar-game">UNLIGHT</span>




      </div>


      <nav class="sidebar-nav">
        <!-- 系統 -->
        <a href="/pages/index.php"
          class="sidebar-link <?= $activeMenu === 'index' ? 'is-active' : '' ?>">
          <span class="sidebar-icon">📢</span>
          <span class="sidebar-text">首頁情報 <small>Bulletin</small></span>
        </a>

        <!-- ⭐ 新增：ULGG 杯 -->
        <a href="/pages/tournament/ulgg_cup.php"
          class="sidebar-link <?= $activeMenu === 'ulgg_cup' ? 'is-active' : '' ?>">
          <span class="sidebar-icon">🏟️</span>
          <span class="sidebar-text">ULGG 杯 <small>Cup</small></span>
        </a>

        <!-- 排行 -->
        <div class="sidebar-group">

          <button class="sidebar-group-toggle" data-target="group-team">
            <span class="sidebar-icon">🏆</span>
            <span class="sidebar-text">對戰組合 <small>Team</small></span>
            <span class="chevron">▾</span>
          </button>

          <div class="sidebar-group-items" id="group-team">

            <a href="/pages/ranking_team.php"
              class="sidebar-link <?= $activeMenu === 'ranking_team' ? 'is-active' : '' ?>">
              <span class="sidebar-icon">🏆</span>
              <span class="sidebar-text">組合排行 <small>Ranking</small></span>
            </a>

            <a href="/pages/strength_rank.php"
              class="sidebar-link <?= $activeMenu === 'strength_rank' ? 'is-active' : '' ?>">
              <span class="sidebar-icon">🔥</span>
              <span class="sidebar-text">強度排行 <small>Strength</small></span>
            </a>

          </div>
        </div>



        <div class="sidebar-group">
          <button class="sidebar-group-toggle" data-target="group-character">
            <span class="sidebar-icon">🧙</span>
            <span class="sidebar-text">角色 <small>Character</small></span>
            <span class="chevron">▾</span>
          </button>

          <div class="sidebar-group-items" id="group-character">
            <a href="/pages/ranking_char.php"
              class="sidebar-link <?= $activeMenu === 'ranking_char' ? 'is-active' : '' ?>">
              <span class="sidebar-icon">🧙</span>
              <span class="sidebar-text">角色排行 <small>Ranking</small></span>
            </a>

            <a href="/pages/character_analysis.php"
              class="sidebar-link <?= $activeMenu === 'character_analysis' ? 'is-active' : '' ?>">
              <span class="sidebar-icon">🧠</span>
              <span class="sidebar-text">角色分析 <small>Analysis</small></span>
            </a>

            <a href="/pages/character_intro.php"
              class="sidebar-link <?= $activeMenu === 'character_intro' ? 'is-active' : '' ?>">
              <span class="sidebar-icon">🃏</span>
              <span class="sidebar-text">集卡冊 <small>Intro</small></span>
            </a>




            <!-- ⭐ 新增：自製卡面 Fan ART -->
            <a href="/pages/upload_card_art.php"
              class="sidebar-link <?= $activeMenu === 'upload_card_art' ? 'is-active' : '' ?>">
              <span class="sidebar-icon">🎨</span>
              <span class="sidebar-text">自製卡面 <small>Fan Art</small></span>
            </a>
          </div>
        </div>


        <div class="sidebar-group">
          <button class="sidebar-group-toggle" data-target="group-rank">
            <span class="sidebar-icon">📊</span>
            <span class="sidebar-text">排行榜 <small>Rank</small></span>
            <span class="chevron">▾</span>
          </button>

          <div class="sidebar-group-items" id="group-rank">

            <a href="/pages/bp_rank.php"
              class="sidebar-link <?= $activeMenu === 'bp_rank' ? 'is-active' : '' ?>">
              <span class="sidebar-icon">📈</span>
              <span class="sidebar-text">BP 排行</span>
            </a>

            <a href="/pages/qp_rank.php"
              class="sidebar-link <?= $activeMenu === 'qp_rank' ? 'is-active' : '' ?>">
              <span class="sidebar-icon">📊</span>
              <span class="sidebar-text">QP 排行</span>
            </a>

            <!-- 🏆 歷史名人堂 -->
            <a href="/pages/hall_of_fame.php"
              class="sidebar-link <?= $activeMenu === 'hall_of_fame' ? 'is-active' : '' ?>">
              <span class="sidebar-icon">🏆</span>
              <span class="sidebar-text">歷史名人堂</span>
            </a>

          </div>
        </div>

        <!-- 工具 -->
        <div class="sidebar-group">

          <button
            class="sidebar-group-toggle"
            type="button"
            data-target="group-tools">
            <span class="sidebar-icon">🧰</span>
            <span class="sidebar-text">
              實用工具 <small>Tools</small>
            </span>
            <span class="chevron">▾</span>
          </button>

          <div class="sidebar-group-items" id="group-tools">

            <a href="/pages/quest_map.php"
              class="sidebar-link <?= $activeMenu === 'quest_map' ? 'is-active' : '' ?>">
              <span class="sidebar-text">
                地圖任務查詢 <small>Map</small>
              </span>
            </a>

            <a href="/pages/skills.php"
              class="sidebar-link <?= $activeMenu === 'skills' ? 'is-active' : '' ?>">
              <span class="sidebar-text">
                技能戰術搜尋 <small>Skills</small>
              </span>
            </a>

            <a href="/pages/calculator.php"
              class="sidebar-link <?= $activeMenu === 'calculator' ? 'is-active' : '' ?>">
              <span class="sidebar-text">
                計算 Cost <small>Calculator</small>
              </span>
            </a>





          </div>
        </div>
        <a href="/pages/queue.php"
          class="sidebar-link <?= $activeMenu === 'queue' ? 'is-active' : '' ?>">
          <span class="sidebar-icon">👀</span>
          <span class="sidebar-text">觀察大廳 <small>Lobby</small></span>
        </a>

        <?php if (!empty($_SESSION['permission']) && $_SESSION['permission'] >= 2): ?>

          <!-- =========================
       管理員
  ========================== -->
          <div class="sidebar-divider"></div>

          <div class="sidebar-section-title">🛠 管理員</div>

          <!-- 📊 系統監控 -->
          <a href="/pages/admin_dashboard.php"
            class="sidebar-link <?= $activeMenu === 'admin_dashboard' ? 'is-active' : '' ?>">
            <span class="sidebar-icon">📊</span>
            <span class="sidebar-text">
              系統監控 <small>Dashboard</small>
            </span>
          </a>

          <!-- 👤 會員管理 -->
          <a href="/pages/admin/user_manage.php"
            class="sidebar-link <?= $activeMenu === 'user_manage' ? 'is-active' : '' ?>">
            <span class="sidebar-icon">👤</span>
            <span class="sidebar-text">
              會員管理 <small>Members</small>
            </span>
          </a>

          <?php if ($_SESSION['permission'] >= 3): ?>

            <!-- ☕ 上傳紀錄 -->
            <a href="/pages/admin/payment_qr.php"
              class="sidebar-link <?= $activeMenu === 'payment_qr' ? 'is-active' : '' ?>">
              <span class="sidebar-icon">☕</span>
              <span class="sidebar-text">
                上傳紀錄 <small>Upload</small>
              </span>
            </a>

          <?php endif; ?>


          <!-- =========================
       開發中
  ========================== -->
          <div class="sidebar-divider"></div>

          <div class="sidebar-section-title">🧪 開發中</div>

          <div class="sidebar-group <?= $activeMenu === 'card_tracker' ? 'is-open' : '' ?>">

            <!-- 🃏 公牌追蹤 -->
            <a href="/tool/unlight-card-tracker/index.php"
              class="sidebar-link sidebar-sub-link <?= $activeMenu === 'card_tracker' ? 'is-active' : '' ?>">

              <span class="sidebar-icon">🃏</span>

              <span class="sidebar-text">
                公牌追蹤 <small>Tracker</small>
              </span>
            </a>

            <a href="/pages/ruleset/index.php"
              class="sidebar-link <?= $activeMenu === 'ruleset' ? 'is-active' : '' ?>">
              <span class="sidebar-icon">📜</span>
              <span class="sidebar-text">
                社群規則 <small>Ruleset</small>
              </span>
            </a>
          </div>


        <?php endif; ?>


        <!-- =========================
              Data Status (Compact)
          ========================= -->
        <div class="sidebar-divider"></div>

        <div class="sidebar-status">

          <div class="sidebar-status-title">📡 資料狀態</div>

          <!-- <div class="status-source">
            來源：遊戲中可公開觀察之排行事件
          </div> -->

          <div class="status-row">
            <span class="status-server">
              <?= htmlspecialchars($serverLabel) ?>
            </span>
            <span class="status-update">
              <span class="status-dot <?= $statusDot ?>"></span>
              <?= htmlspecialchars($statusText) ?>
            </span>
          </div>

          <div class="status-row">
            <span class="status-server">
              <?= htmlspecialchars($serverLabelJP) ?>
            </span>
            <span class="status-update">
              <span class="status-dot <?= $statusDotJP ?>"></span>
              <?= htmlspecialchars($statusTextJP) ?>
            </span>
          </div>

          <!-- ⭐ 新增：分析樣本數 -->
          <div class="status-row status-sample">
            <span class="status-label">📊 分析樣本數</span>
            <span class="status-value">
              <?= number_format($totalSamples) ?> 場
            </span>
          </div>

        </div>


      </nav>
    </aside>

    <main class="un-main">
      <?php

      // ⭐ 錯誤顯示（正式環境可關）
      if (
        isset($_SESSION['username']) &&
        in_array($_SESSION['username'], ['way.lee', '咕嚕．挖2朵'], true)
      ) {
        ini_set('display_errors', 1);
        error_reporting(E_ALL);
        /* echo '<pre style="background:#111;color:#0f0;padding:12px;">';
        echo "SESSION ID: " . session_id() . "\n\n";
        echo "=== \$_POST ===\n";
        print_r($_POST);
        echo "=== \$_GET ===\n";
        print_r($_GET);
        echo "\n=== \$_SESSION ===\n";
        print_r($_SESSION);
        echo "\nREQUEST_URI: " . ($_SERVER['REQUEST_URI'] ?? '');
        echo "\nREFERER: " . ($_SERVER['HTTP_REFERER'] ?? '');
        echo '</pre>'; */

        /* echo '<pre>';
        foreach ($steamNews as $i => $n) {
          echo "[$i]\n";
          echo "title: {$n['title']}\n";
          echo "feed: {$n['feedname']}\n";
          echo "len(contents): " . strlen($n['contents'] ?? '') . "\n";
          echo "-----\n";
        }
        echo '</pre>';
        exit; */
      }
      ?>
      <div class="un-main-inner">
        <?php if (empty($hidePageHeader)): ?>
          <div class="page-header">
            <h1 class="page-title">
              <span class="title-desktop"><?= htmlspecialchars($pageTitleFull) ?></span>
              <span class="title-mobile"><?= htmlspecialchars($pageTitleText) ?></span>
            </h1>
          </div>
        <?php endif; ?>


        <!-- 頁面實際內容 -->
        <?= $pageContent ?? '' ?>


      </div>

      <footer class="un-footer">
        <div class="footer-inner container">

          <!-- 💜 支持 UL.GG Modal -->
          <div class="footer-support">

            <!-- 台灣支付（Modal） -->
            <a class="open-support-modal report-btn">
              💜 支持 UL.GG
              <span>街口 / 全支付</span>
            </a>

            <!-- 信用卡（Buy Me a Coffee） -->
            <a class="report-btn"
              href="https://www.buymeacoffee.com/ulgg"
              target="_blank"
              rel="noopener">
              ☕ Buy Me a Coffee
              <span>信用卡</span>
            </a>

          </div>


          <!-- 🐞 問題回報 -->
          <div class="footer-report">
            <!-- <div class="footer-title">🐞 問題回報</div>

            <div class="footer-desc">
              若您在使用本站時遇到錯誤、顯示異常或資料問題，<br>
              歡迎回報協助我們改善。
            </div> -->

            <a
              href="mailto:ulgg.online@gmail.com?subject=UL.GG 問題回報&body=請描述您遇到的問題：%0A%0A頁面網址：%0A使用裝置／瀏覽器：%0A問題發生時間：">
              回報問題
            </a>
            -
            <a href="/pages/about.php" class="footer-link">
              關於本站
            </a>
            -
            <a href="/pages/privacy.php" class="footer-link">
              隱私權政策
            </a>
          </div>

          <!-- © -->
          <div class="footer-copy">
            © <?= date('Y') ?> UL.GG • Unlight Fan-made Statistics
          </div>

        </div>
      </footer>

    </main>
  </div>
  <!-- <div class="kofi-float" id="kofiFloat">
    <a href="https://ko-fi.com/ulggs" target="_blank" rel="noopener">
      ☕ 支持 UL.GG
    </a>
    <button class="kofi-close" onclick="closeKofi()">×</button>
  </div> -->

  <div id="supportModal" class="ulgg-modal hidden">
    <div class="ulgg-modal-backdrop"></div>

    <div class="ulgg-modal-card">
      <button class="ulgg-modal-close">✕</button>

      <h3 class="ulgg-modal-title">
        💜 支持 UL.GG
        <span class="subtitle">☕ 請站長喝杯咖啡</span>
      </h3>

      <p class="ulgg-modal-desc">
        UL.GG 是由玩家自發維護的<br>
        <strong>非官方 UNLIGHT 戰績分析網站</strong>。<br>
        所有功能皆免費提供，<br>
        若你覺得本站對你有幫助，<br>
        歡迎使用 <strong>街口支付、全支付</strong> 小額支持網站維運 🙏
      </p>

      <div class="pay-stack">
        <!-- 街口支付 -->
        <div class="pay-card pay-card-main pay-jko active" data-pay="jkopay">
          <?php if (!empty($paymentQR['jkopay'])): ?>
            <img src="<?= htmlspecialchars($paymentQR['jkopay']) ?>" alt="街口支付 QR">
          <?php else: ?>
            <div class="text-muted small">尚未設定街口支付</div>
          <?php endif; ?>
        </div>

        <!-- 全支付 -->
        <div class="pay-card pay-card-sub pay-pxp" data-pay="pxpay">
          <?php if (!empty($paymentQR['pxpay'])): ?>
            <img src="<?= htmlspecialchars($paymentQR['pxpay']) ?>" alt="全支付 QR">
          <?php else: ?>
            <div class="text-muted small">今日尚未設定全支付 QR</div>
          <?php endif; ?>
        </div>
      </div>

      <div class="pay-label">
        👉 點擊露出的卡片可切換支付方式
      </div>



      <div class="ulgg-modal-note">
        ＊非官方網站，非商品交易<br>
        ＊僅用於主機與網站維運費用
      </div>
    </div>
  </div>



  <?php if (count($fortuneCards) === 3 && $fortuneSelectedCard && $fortuneSelectedSkill): ?>
    <button
      id="fortuneOpenBtn"
      class="fortune-float-btn"
      type="button"
      title="今日命運"
      aria-label="開啟今日命運"
      style="visibility:hidden;">
      🔮
    </button>
    <div id="fortuneModal" class="fortune-modal hidden" aria-hidden="true">
      <div class="fortune-backdrop" data-fortune-close></div>
      <div class="fortune-dialog" role="dialog" aria-modal="true" aria-labelledby="fortuneTitle">
        <button class="fortune-close" type="button" data-fortune-close aria-label="關閉">✕</button>

        <div class="fortune-heading">
          <h2 id="fortuneTitle">今日命運</h2>
          <p>三位引路人之中，命運將選出今日與你同行的一位。</p>
        </div>

        <div class="fortune-stage" id="fortuneStage">
          <div class="fortune-orbit">
            <?php foreach ($fortuneCards as $index => $card): ?>
              <div
                class="fortune-orbit-slot fortune-orbit-slot-<?= (int)$index ?>"
                style="--fortune-angle: <?= [30, 180, 300][$index] ?>deg;">
                <div class="fortune-card" data-index="<?= (int)$index ?>">
                  <img
                    src="<?= htmlspecialchars(
                            $fortuneCardImageBase . ltrim($card['ico'], '/'),
                            ENT_QUOTES,
                            'UTF-8'
                          ) ?>"
                    alt="<?= htmlspecialchars(
                            $card['name'] . ' ' . $card['level'],
                            ENT_QUOTES,
                            'UTF-8'
                          ) ?>"
                    loading="eager">
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <div id="fortuneResult" class="fortune-result">
          <div class="fortune-result-name">
            <?= htmlspecialchars($fortuneSelectedCard['name']) ?> <?= htmlspecialchars($fortuneSelectedCard['level']) ?>
          </div>
          <div class="fortune-result-skill">
            <?= htmlspecialchars($fortuneSelectedSkill['name_tcn'] ?? '') ?>
          </div>
          <div class="fortune-result-stars" aria-label="<?= (int)$fortuneReading['stars'] ?> 星">
            <?= str_repeat('★', (int)$fortuneReading['stars']) . str_repeat('☆', 5 - (int)$fortuneReading['stars']) ?>
          </div>
          <div class="fortune-result-luck"><?= htmlspecialchars($fortuneReading['main_luck']) ?></div>

          <div class="fortune-result-stats">
            <?php foreach (($fortuneReading['aspects'] ?? []) as $aspect): ?>
              <?php
              $aspectValue = (int)($aspect['value'] ?? 0);

              $aspectClass = [];

              if ($aspectValue < 0) {
                $aspectClass[] = 'is-negative';
              }

              if (!empty($aspect['is_load'])) {
                $aspectClass[] = 'is-load';
              }

              $aspectSign = $aspectValue > 0 ? '+' : '';
              ?>
              <span class="fortune-result-stat <?= implode(' ', $aspectClass) ?>">
                <?= htmlspecialchars((string)$aspect['label']) ?>
                <strong>
                  <?= htmlspecialchars($aspectSign . $aspectValue) ?>
                </strong>
              </span>
            <?php endforeach; ?>
          </div>
          <?php
          $pressure = $fortuneReading['pressure'] ?? [
            'label' => '負荷值',
            'value' => 0,
          ];

          $pressureValue = (int)($pressure['value'] ?? 0);
          $pressureSign = $pressureValue > 0 ? '+' : '';
          ?>


          <div class="fortune-result-message">
            <?= htmlspecialchars(
              $fortuneReading['message'],
              ENT_QUOTES,
              'UTF-8'
            ) ?>
          </div>

          <?php if ($fortuneCharacterBackground !== ''): ?>
            <div class="fortune-character-line">
              <strong>角色背景：</strong>
              <?= htmlspecialchars(
                $fortuneCharacterBackground,
                ENT_QUOTES,
                'UTF-8'
              ) ?>
            </div>
          <?php endif; ?>

          <?php if ($fortuneDisplayInsight !== ''): ?>
            <div class="fortune-character-line
              <?= $fortuneDisplayInsightIsEgg
                ? 'fortune-seasonal-insight'
                : ($fortuneDisplayInsightIsHumor
                  ? 'fortune-character-humor'
                  : '') ?>">

              <strong>
                <?= htmlspecialchars(
                  $fortuneDisplayInsightLabel,
                  ENT_QUOTES,
                  'UTF-8'
                ) ?>：
              </strong>

              <?= htmlspecialchars(
                $fortuneDisplayInsight,
                ENT_QUOTES,
                'UTF-8'
              ) ?>
            </div>
          <?php endif; ?>

          <div class="fortune-share-actions">

            <div class="fortune-share-buttons">
              <?php if ($fortuneTestMode): ?>
                <button
                  id="fortuneRerollBtn"
                  class="fortune-share-btn"
                  type="button">
                  <i class="fa-solid fa-rotate"></i>
                  <span>重新測試</span>
                </button>
              <?php endif; ?>

              <button id="fortuneImageBtn" class="fortune-share-btn" type="button">
                <i class="fa-solid fa-download"></i>
                下載命運圖片
              </button>

              <?php if (empty($_SESSION['steam_id'])): ?>

                <a
                  href="/api/auth/steam_start.php"
                  class="fortune-share-btn fortune-progress-btn fortune-login-btn"
                  title="登入後，每日命運的能力值將累積至個人檔案">
                  <i class="fa-brands fa-steam"></i>
                  <span>登入累積命運能力</span>
                </a>

              <?php else: ?>

                <a
                  id="fortuneProgressBtn"
                  href="/pages/profile.php#fortune-profile"
                  class="fortune-share-btn fortune-progress-btn"
                  title="查看已累積的六項命運能力與占卜紀錄">
                  <i class="fa-solid fa-chart-simple"></i>
                  <span>查看命運能力累計</span>
                </a>

              <?php endif; ?>
            </div>

            <span
              id="fortuneImageStatus"
              class="fortune-share-status"
              aria-live="polite">
            </span>

          </div>


          <!-- <div class="fortune-replay-note">
          今日結果固定，重新開啟仍會遇見同一位引路人。
        </div> -->
        </div>
      </div>
    </div>

    <div id="fortuneShareCard" class="fortune-share-card" aria-hidden="true">
      <div class="fortune-share-card__glow"></div>

      <div class="fortune-share-card__header">
        <div class="fortune-share-card__eyebrow">
          UNLIGHT DAILY FORTUNE
        </div>

        <div class="fortune-share-card__title">
          今日命運
        </div>

        <div class="fortune-share-card__date">
          <?= htmlspecialchars(date('Y.m.d')) ?>
        </div>
      </div>

      <div class="fortune-share-card__content">
        <div class="fortune-share-card__character">
          <img
            src="<?= htmlspecialchars(
                    $fortuneCardImageBase . ltrim($fortuneSelectedCard['ico'], '/'),
                    ENT_QUOTES,
                    'UTF-8'
                  ) ?>"
            alt="<?= htmlspecialchars(
                    $fortuneSelectedCard['name'],
                    ENT_QUOTES,
                    'UTF-8'
                  ) ?>"
            crossorigin="anonymous">
        </div>

        <div class="fortune-share-card__name">
          <?= htmlspecialchars($fortuneSelectedCard['name']) ?>
          <span><?= htmlspecialchars($fortuneSelectedCard['level']) ?></span>
        </div>

        <div class="fortune-share-card__skill">
          <?= htmlspecialchars($fortuneSelectedSkill['name_tcn'] ?? '') ?>
        </div>

        <div class="fortune-share-card__stars">
          <?= str_repeat('★', (int)$fortuneReading['stars'])
            . str_repeat('☆', 5 - (int)$fortuneReading['stars']) ?>
        </div>

        <div class="fortune-share-card__luck">
          <?= htmlspecialchars($fortuneReading['main_luck']) ?>
        </div>

        <div class="fortune-result-stats">
          <?php foreach (($fortuneReading['aspects'] ?? []) as $aspect): ?>
            <?php
            $aspectValue = (int)($aspect['value'] ?? 0);
            $aspectSign = $aspectValue > 0 ? '+' : '';
            ?>
            <span class="fortune-result-stat">
              <?= htmlspecialchars((string)$aspect['label']) ?>
              <strong>
                <?= htmlspecialchars($aspectSign . $aspectValue) ?>
              </strong>
            </span>
          <?php endforeach; ?>
        </div>

        <div class="fortune-share-card__message">
          <?= htmlspecialchars($fortuneReading['message']) ?>
        </div>
        <?php if ($fortuneCharacterBackground !== ''): ?>
          <div class="fortune-share-card__character-line">
            <strong>角色背景：</strong>
            <?= htmlspecialchars(
              $fortuneCharacterBackground,
              ENT_QUOTES,
              'UTF-8'
            ) ?>
          </div>
        <?php endif; ?>

        <?php if ($fortuneDisplayInsight !== ''): ?>
          <div class="fortune-share-card__character-line
            <?= $fortuneDisplayInsightIsEgg
              ? 'fortune-share-card__seasonal-insight'
              : ($fortuneDisplayInsightIsHumor
                ? 'fortune-share-card__humor'
                : '') ?>">

            <strong>
              <?= htmlspecialchars(
                $fortuneDisplayInsightLabel,
                ENT_QUOTES,
                'UTF-8'
              ) ?>：
            </strong>

            <?= htmlspecialchars(
              $fortuneDisplayInsight,
              ENT_QUOTES,
              'UTF-8'
            ) ?>
          </div>
        <?php endif; ?>

      </div>

      <div class="fortune-share-card__watermark">
        <strong>UL.GG</strong>
        <small>ulgg.online</small>
      </div>
    </div>
  <?php endif; ?>

  <!-- Go To Top Button -->
  <button id="backToTop" onclick="scrollToTop()">▲</button>
  <!-- Go To Bottom Button -->
  <button id="goToBottom" onclick="scrollToBottom()">▼</button>

  <script>
    // 監聽滾動事件，決定是否顯示按鈕
    window.onscroll = function() {
      let topButton = document.getElementById("backToTop");
      let bottomButton = document.getElementById("goToBottom");
      let scrollTop = document.documentElement.scrollTop;
      let scrollHeight = document.documentElement.scrollHeight;
      let clientHeight = document.documentElement.clientHeight;

      if (scrollTop > 200) {
        topButton.style.display = "flex"; // 顯示回到頂部按鈕
      } else {
        topButton.style.display = "none"; // 隱藏按鈕
      }

      if (scrollTop + clientHeight < scrollHeight - 200) {
        bottomButton.style.display = "flex"; // 顯示滾到底部按鈕
      } else {
        bottomButton.style.display = "none"; // 隱藏按鈕
      }
    };

    // 點擊按鈕回到頂部
    function scrollToTop() {
      window.scrollTo({
        top: 0,
        behavior: "smooth" // 平滑滾動效果
      });
    }

    // 點擊按鈕滾到底部
    function scrollToBottom() {
      window.scrollTo({
        top: document.documentElement.scrollHeight,
        behavior: "smooth" // 平滑滾動效果
      });
    }
  </script>


  <script>
    document.addEventListener('DOMContentLoaded', () => {
      const openBtn = document.getElementById('fortuneOpenBtn');
      const modal = document.getElementById('fortuneModal');
      const stage = document.getElementById('fortuneStage');
      const orbit = modal?.querySelector('.fortune-orbit');
      const result = document.getElementById('fortuneResult');
      const easterEgg = document.getElementById('fortuneEasterEgg');
      const imageBtn = document.getElementById('fortuneImageBtn');
      const rerollBtn = document.getElementById('fortuneRerollBtn');
      const progressBtn =
        document.getElementById('fortuneProgressBtn');
      const fortuneTestMode = <?= $fortuneTestMode ? 'true' : 'false' ?>;
      const imageStatus = document.getElementById('fortuneImageStatus');
      const shareCard = document.getElementById('fortuneShareCard');
      const claimStatus =
        document.getElementById('fortuneClaimStatus');

      const fortuneCsrfToken =
        <?= json_encode(
          $fortuneCsrfToken,
          JSON_UNESCAPED_UNICODE
        ) ?>;

      let isClaimingFortune = false;
      let hasClaimedFortune = false;

      const cards = modal ? Array.from(modal.querySelectorAll('.fortune-card')) : [];
      const slots = modal ?
        Array.from(modal.querySelectorAll('.fortune-orbit-slot')) : [];
      const selectedIndex = <?= (int)$fortuneSelectedIndex ?>;
      const fortuneDate = <?= json_encode($fortuneDate ?? date('Y-m-d')) ?>;

      const fortuneSeenKey = `ulgg_fortune_seen_${fortuneDate}`;
      const fortuneClickKey = `ulgg_fortune_clicks_${fortuneDate}`;

      const getFortuneClickCount = () => {
        const value = parseInt(
          localStorage.getItem(fortuneClickKey) || '0',
          10
        );

        return Number.isFinite(value) ? value : 0;
      };

      const increaseFortuneClickCount = () => {
        const nextCount = getFortuneClickCount() + 1;

        localStorage.setItem(
          fortuneClickKey,
          String(nextCount)
        );

        return nextCount;
      };
      const getFortuneEasterEgg = count => {
        const messages = {
          1: '',
          2: '命運今天不會改變。',
          3: '占卜師瞪著你。',
          4: '「再抽也不會變啦。」',
          5: '今天真的沒有第四張牌了。',
          6: '你是不是覺得下一次會不一樣？',
          7: '三位引路人開始假裝沒看到你。',
          8: '命運之輪已經有點暈了。',
          9: '占卜師正在考慮下班。',
          10: '……'
        };

        if (messages[count] !== undefined) {
          return messages[count];
        }

        if (count >= 20) {
          return '你已經超越命運，成為了測試人員。';
        }

        if (count >= 15) {
          return '命運之輪拒絕對此發表評論。';
        }

        if (count > 10) {
          return '……還在按？';
        }

        return '';
      };
      const showFortuneEasterEgg = count => {
        if (!easterEgg) return;

        const message = getFortuneEasterEgg(count);

        easterEgg.classList.remove('is-visible');
        easterEgg.textContent = '';

        if (!message) return;

        window.setTimeout(() => {
          easterEgg.textContent = message;
          easterEgg.classList.add('is-visible');
        }, 80);
      };

      const isProfilePage =
        window.location.pathname === '/pages/profile.php' ||
        window.location.pathname.endsWith('/profile.php');

      let timers = [];
      let isGeneratingFortuneImage = false;
      if (
        !openBtn ||
        !modal ||
        cards.length !== 3 ||
        slots.length !== 3
      ) return;
      /*
       * 一般頁面：今天按過就隱藏。
       * 我的檔案：永遠顯示，可重複開啟。
       */
      if (
        !fortuneTestMode &&
        !isProfilePage &&
        localStorage.getItem(fortuneSeenKey) === '1'
      ) {
        openBtn.style.display = 'none';
      } else {
        openBtn.style.visibility = 'visible';
      }

      const clearTimers = () => {
        timers.forEach(clearTimeout);
        timers = [];
      };

      const resetFortune = () => {
        clearTimers();

        stage?.classList.remove('is-orbiting', 'is-fading');

        if (orbit) {
          orbit.style.animation = '';
          orbit.style.transform = '';
        }

        slots.forEach(slot => {
          slot.classList.remove('is-selected', 'is-dismissed');
        });

        cards.forEach(card => {
          card.style.animation = '';
          card.style.transform = '';
        });

        result?.classList.remove('is-visible');
        easterEgg?.classList.remove('is-visible');

        if (easterEgg) {
          easterEgg.textContent = '';
        }
      };
      const setImageStatus = message => {
        if (!imageStatus) return;

        imageStatus.textContent = message;

        window.setTimeout(() => {
          if (imageStatus.textContent === message) {
            imageStatus.textContent = '';
          }
        }, 3000);
      };

      const canvasToBlob = canvas => {
        return new Promise((resolve, reject) => {
          canvas.toBlob(blob => {
            if (blob) {
              resolve(blob);
            } else {
              reject(new Error('圖片產生失敗'));
            }
          }, 'image/png', 1);
        });
      };

      const downloadFortuneImage = (blob, filename) => {
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');

        link.href = url;
        link.download = filename;

        document.body.appendChild(link);
        link.click();
        link.remove();

        window.setTimeout(() => {
          URL.revokeObjectURL(url);
        }, 1000);
      };
      const setClaimStatus = message => {
        if (!claimStatus) return;
        claimStatus.textContent = message;
      };

      const claimFortune = async () => {
        /*
         * 同一次頁面不重複送出。
         */
        if (
          hasClaimedFortune ||
          isClaimingFortune
        ) {
          return;
        }

        /*
         * 測試模式完全不寫入。
         */
        if (fortuneTestMode) {
          setClaimStatus('測試模式不會累加能力值。');
          return;
        }

        isClaimingFortune = true;
        setClaimStatus('正在記錄今日命運…');

        try {
          const formData = new FormData();

          formData.append('fortune_claim', '1');
          formData.append(
            'csrf_token',
            fortuneCsrfToken
          );

          const response = await fetch(
            window.location.pathname + window.location.search, {
              method: 'POST',
              body: formData,
              credentials: 'same-origin',
              headers: {
                'X-Requested-With': 'XMLHttpRequest'
              }
            }
          );

          const result = await response.json();

          if (!response.ok || !result.success) {
            throw new Error(
              result.message || '能力累加失敗'
            );
          }

          hasClaimedFortune = true;

          if (result.status === 'claimed') {
            setClaimStatus(
              `今日能力已累加，獲得 ${result.data?.fortune_exp ?? 0} EXP。`
            );
          } else if (
            result.status === 'already_claimed'
          ) {
            setClaimStatus('今日能力已經累加過。');
          } else {
            setClaimStatus(result.message || '');
          }
        } catch (error) {
          console.error(
            '今日命運寫入失敗：',
            error
          );

          setClaimStatus(
            error.message || '能力累加失敗，請稍後再試。'
          );
        } finally {
          isClaimingFortune = false;
        }
      };
      const generateFortuneImage = async () => {
        if (
          !shareCard ||
          !imageBtn ||
          isGeneratingFortuneImage
        ) {
          return;
        }

        isGeneratingFortuneImage = true;

        imageBtn.disabled = true;
        setImageStatus('圖片生成中…');

        try {
          /*
           * 等待字型與圖片載入完成，
           * 避免輸出的文字或角色圖缺失。
           */
          if (document.fonts?.ready) {
            await document.fonts.ready;
          }

          const images = Array.from(
            shareCard.querySelectorAll('img')
          );

          await Promise.all(
            images.map(image => {
              if (image.complete && image.naturalWidth > 0) {
                return Promise.resolve();
              }

              return new Promise((resolve, reject) => {
                image.addEventListener('load', resolve, {
                  once: true
                });

                image.addEventListener('error', reject, {
                  once: true
                });
              });
            })
          );

          const canvas = await html2canvas(shareCard, {
            backgroundColor: null,
            scale: 2,
            useCORS: true,
            allowTaint: false,
            logging: false,


            width: 720,
            height: 1000,
            windowWidth: 720,
            windowHeight: 1000,

            scrollX: 0,
            scrollY: 0
          });

          const blob = await canvasToBlob(canvas);

          const fileName =
            `ULGG-今日命運-${fortuneDate}.png`;

          /*
           * 手機支援 Web Share API 時，
           * 直接開啟分享圖片選單。
           */
          downloadFortuneImage(blob, fileName);
          setImageStatus('圖片已下載');

        } catch (error) {
          console.error('產生今日命運圖片失敗：', error);
          setImageStatus('圖片生成失敗，請稍後再試');
        } finally {
          isGeneratingFortuneImage = false;
          imageBtn.disabled = false;
        }
      };

      imageBtn?.addEventListener(
        'click',
        generateFortuneImage
      );
      rerollBtn?.addEventListener('click', () => {
        const url = new URL(window.location.href);

        url.searchParams.set(
          'fortune_roll',
          Date.now().toString()
        );

        window.location.href = url.toString();
      });
      const playFortune = (clickCount = 1) => {
        resetFortune();

        modal.classList.remove('hidden');
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';

        /*
         * 強制瀏覽器先套用重置狀態，
         * 再重新加入動畫 class。
         */
        void stage.offsetWidth;

        requestAnimationFrame(() => {
          stage.classList.add('is-orbiting');
        });

        timers.push(setTimeout(() => {
          /* 公轉結束後，三張先一起淡出 */
          stage.classList.add('is-fading');
        }, 6000));

        timers.push(setTimeout(() => {
          /* 淡出完成後，重置軌道角度 */
          stage.classList.remove('is-orbiting');

          if (orbit) {
            orbit.style.animation = 'none';
            orbit.style.transform = 'rotate(0deg)';
          }

          cards.forEach(card => {
            card.style.animation = 'none';
            card.style.transform = '';
          });

          slots.forEach((slot, index) => {
            slot.classList.add(
              index === selectedIndex ?
              'is-selected' :
              'is-dismissed'
            );
          });

          /* 選中卡重新顯示 */
          stage.classList.remove('is-fading');
        }, 6500));

        timers.push(setTimeout(() => {
          result?.classList.add('is-visible');
          showFortuneEasterEgg(clickCount);

          /*
           * 命運結果正式揭示後才寫入與累加。
           */
          claimFortune();
        }, 7200));


      };

      const closeFortune = () => {
        clearTimers();
        modal.classList.add('hidden');
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
        resetFortune();
      };
      progressBtn?.addEventListener('click', event => {
        const target =
          document.getElementById('fortune-profile');

        /*
         * 不在 profile.php：
         * 保留原本連結，正常前往個人頁。
         */
        if (!target) {
          return;
        }

        /*
         * 已在 profile.php：
         * 阻止重新載入，先關閉 Modal，
         * 再捲動到命運能力區塊。
         */
        event.preventDefault();

        closeFortune();

        /*
         * 等 Modal 關閉與 body 捲動鎖解除後再定位。
         */
        window.setTimeout(() => {
          target.scrollIntoView({
            behavior: 'smooth',
            block: 'start'
          });

          /*
           * 同步更新網址 Hash。
           */
          history.replaceState(
            null,
            '',
            '#fortune-profile'
          );
        }, 80);
      });

      openBtn.addEventListener('click', () => {
        const clickCount = increaseFortuneClickCount();

        /*
         * 一般頁面只顯示一次。
         * 我的檔案可重複開啟並觸發彩蛋。
         */
        if (
          !fortuneTestMode &&
          !isProfilePage
        ) {
          localStorage.setItem(fortuneSeenKey, '1');
          openBtn.style.display = 'none';
        }

        playFortune(clickCount);
      });
      modal.querySelectorAll('[data-fortune-close]').forEach(el => el.addEventListener('click', closeFortune));

      document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && !modal.classList.contains('hidden')) closeFortune();
      });
    });
  </script>

  <!-- <script src="/assets/js/main.js"></script> -->
  <script>
    function toggleServerMenu() {
      const menu = document.getElementById('serverMenu');
      menu.style.display = (menu.style.display === 'block') ? 'none' : 'block';
    }

    document.addEventListener('click', function(e) {
      const switcher = document.querySelector('.sidebar-server-switcher');
      if (switcher && !switcher.contains(e.target)) {
        document.getElementById('serverMenu').style.display = 'none';
      }
    });
  </script>

  <script>
    document.addEventListener('DOMContentLoaded', () => {
      const footer = document.querySelector('.un-footer');
      if (!footer) return;

      const observer = new IntersectionObserver(entries => {
        entries.forEach(entry => {
          if (entry.isIntersecting) {
            footer.classList.add('footer-visible');
            observer.disconnect(); // 只觸發一次
          }
        });
      }, {
        threshold: 0.15
      });

      observer.observe(footer);
    });
  </script>

  <!-- 切換 -->
  <script>
    document.querySelectorAll('.open-support-modal').forEach(btn => {
      btn.addEventListener('click', () => {
        document.getElementById('supportModal').classList.remove('hidden');
      });
    });

    document.querySelector('.ulgg-modal-close').onclick = () => {
      document.getElementById('supportModal').classList.add('hidden');
    };

    document.querySelector('.ulgg-modal-backdrop').onclick = () => {
      document.getElementById('supportModal').classList.add('hidden');
    };
  </script>

  <script>
    document.querySelectorAll('.pay-card').forEach(card => {
      card.addEventListener('click', () => {
        document.querySelectorAll('.pay-card').forEach(c => {
          c.classList.remove('active');
        });
        card.classList.add('active');
      });
    });
  </script>

  <script>
    document.querySelectorAll('.sidebar-group-toggle').forEach(btn => {
      btn.addEventListener('click', () => {
        const target = document.getElementById(btn.dataset.target);
        target.classList.toggle('open');
      });
    });

    // 若裡面有 is-active，自動展開
    document.querySelectorAll('.sidebar-group-items').forEach(group => {
      if (group.querySelector('.is-active')) {
        group.classList.add('open');
      }
    });
  </script>

  <!-- 1️⃣ jQuery（只能一份） -->
  <!-- <script src="/assets/bower_components/jquery/dist/jquery.min.js"></script> -->

  <!-- 2️⃣ DataTables core（一定要在 Buttons 前） -->
  <script src="/assets/bower_components/datatables.net/js/jquery.dataTables.min.js"></script>
  <script src="/assets/bower_components/datatables.net-bs/js/dataTables.bootstrap.min.js"></script>

  <!-- 3️⃣ Buttons（現在才可以） -->
  <script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
  <script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.bootstrap.min.js"></script>

  <!-- 4️⃣ 匯出相依 -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
  <script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>

  <!-- 5️⃣ 最後才是你自己的 JS -->
  <script src="/assets/js/main.js"></script>

</body>

</html>
<?php
require_once APP_ROOT . '/lib/visitor_logger.php';
?>