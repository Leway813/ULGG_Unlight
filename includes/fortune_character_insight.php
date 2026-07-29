<?php

declare(strict_types=1);

/**
 * 統一角色名稱。
 *
 * 部分彩色版本角色可能是：
 * 奧蘭(茶)、奧蘭(白黑)
 *
 * 角色背景檔只記錄「奧蘭」，
 * 因此查詢前先移除括號版本。
 */
function fortuneNormalizeCharacterName(
    string $name
): string {
    $name = trim($name);

    $name = preg_replace(
        '/[（(].*?[）)]/u',
        '',
        $name
    ) ?? $name;

    return trim($name);
}

/**
 * 載入角色背景 JSON。
 *
 * 使用 static 快取，單次請求只讀取一次。
 *
 * @return array<string, array<string, mixed>>
 */
function fortuneLoadCharacterProfiles(
    string $jsonPath
): array {
    static $cache = [];

    if (isset($cache[$jsonPath])) {
        return $cache[$jsonPath];
    }

    if (!is_file($jsonPath)) {
        $cache[$jsonPath] = [];
        return [];
    }

    $json = file_get_contents($jsonPath);

    if ($json === false || trim($json) === '') {
        $cache[$jsonPath] = [];
        return [];
    }

    $decoded = json_decode(
        $json,
        true,
        512,
        JSON_INVALID_UTF8_SUBSTITUTE
    );

    if (!is_array($decoded)) {
        $cache[$jsonPath] = [];
        return [];
    }

    $cache[$jsonPath] = $decoded;

    return $decoded;
}

/**
 * 依繁體中文名稱尋找角色背景。
 *
 * @param array<string, array<string, mixed>> $profiles
 *
 * @return array<string, mixed>|null
 */
function fortuneFindCharacterProfile(
    array $profiles,
    string $characterName
): ?array {
    $targetName =
        fortuneNormalizeCharacterName(
            $characterName
        );

    foreach ($profiles as $profileKey => $profile) {
        if (!is_array($profile)) {
            continue;
        }

        $profileName =
            fortuneNormalizeCharacterName(
                (string)($profile['name_tcn'] ?? '')
            );

        if (
            $profileName !== ''
            && $profileName === $targetName
        ) {
            $profile['_profile_key'] =
                (string)$profileKey;

            return $profile;
        }
    }

    return null;
}

/**
 * 從角色背景判斷角色主題。
 *
 * 比對順序很重要：
 * 特殊鮮明主題優先於一般攻擊、防禦主題。
 *
 * @param array<string, mixed> $profile
 */
function fortuneDetectCharacterTheme(
    array $profile
): string {
    $characterName = trim(
        (string)($profile['name_tcn'] ?? '')
    );

    $characterThemeOverrides = [
        '梅莉'       => 'change',
        '阿修羅'     => 'resolve',
        '瑪格莉特'   => 'engineering',
        '沃肯'       => 'engineering',
        '沃蘭德'     => 'change',
        '梅倫'       => 'fate',
        '奧蘭'       => 'change',
        '艾茵'       => 'loyalty',
        '帕茉'       => 'resolve',
        '利恩'       => 'resolve',
        '米利安'     => 'resolve',

        '魯卡'       => 'resolve',
        '貝琳達'     => 'resolve',
        '薩爾卡多'   => 'engineering',
        '諾艾菈'     => 'investigation',
        '阿奇波爾多' => 'freedom',
        '康拉德'     => 'darkness',
        '迪諾'       => 'resolve',

        '史特靈'   => 'resolve',
        '史普拉多' => 'growth',
        '里斯'     => 'resolve',
        '雨果'     => 'change',
    ];

    if (isset($characterThemeOverrides[$characterName])) {
        return $characterThemeOverrides[$characterName];
    }
    $profileText = trim(
        (string)($profile['info_tcn'] ?? '')
    );

    $skillText = implode(' ', [
        (string)($profile['skill1_info_tcn'] ?? ''),
        (string)($profile['skill2_info_tcn'] ?? ''),
        (string)($profile['skill3_info_tcn'] ?? ''),
        (string)($profile['skill4_info_tcn'] ?? ''),
    ]);

    $themeRules = [
        'fate' => [
            '因果',
            '命運',
            '現實',
            '世界線',
            '時光',
            '時間',
        ],

        'investigation' => [
            '偵探',
            '刑事',
            '追蹤',
            '謎',
            '分析',
            '線索',
            '看透',
            '記錄',
        ],

        'engineering' => [
            '工程師',
            '機械',
            '裝甲',
            '電子',
            '微型機械',
            '毫微機械',
            '裝備',
            '開發',
        ],

        'healing' => [
            '治癒',
            '療癒',
            '恢復',
            '護士',
            '醫生',
            '生命力',
            '再生',
        ],

        'loyalty' => [
            '忠誠',
            '盟友',
            '隨從',
            '軍犬',
            '追著',
            '守護',
        ],

        'wisdom' => [
            '智謀',
            '智略',
            '頭腦',
            '戰略',
            '掌握戰場',
            '觀測',
        ],

        'freedom' => [
            '逃離',
            '流浪',
            '放浪',
            '自由',
            '束縛',
            '囚禁',
            '追捕',
        ],

        'growth' => [
            '成長',
            '試煉',
            '鍛鍊',
            '修行',
            '追求力量',
            '試煉自己的力量',
        ],

        'justice' => [
            '正義',
            '審判',
            '斷罪',
            '導正',
            '糾正',
            '裁決',
            '審問官',
        ],

        'resolve' => [
            '意志',
            '不屈',
            '勇猛',
            '勇士',
            '戰士',
            '決心',
        ],

        'darkness' => [
            '黑暗',
            '死亡',
            '詛咒',
            '深淵',
            '邪惡',
            '怨恨',
            '混沌',
        ],

        'change' => [
            '轉換',
            '變化',
            '改變',
            '扭曲',
            '異世界',
            '次元',
            '空間',
        ],
    ];

    $scores = [];

    foreach (
        $themeRules
        as $theme => $keywords
    ) {
        $scores[$theme] = 0;

        $profileMatched = false;
        $skillMatched = false;

        foreach ($keywords as $keyword) {
            if (
                !$profileMatched
                && $profileText !== ''
                && mb_strpos($profileText, $keyword) !== false
            ) {
                $profileMatched = true;
            }

            if (
                !$skillMatched
                && $skillText !== ''
                && mb_strpos($skillText, $keyword) !== false
            ) {
                $skillMatched = true;
            }
        }

        if ($profileMatched) {
            $scores[$theme] += 5;
        }

        if ($skillMatched) {
            $scores[$theme] += 1;
        }
    }

    arsort($scores);

    $bestTheme =
        array_key_first($scores);

    if (
        $bestTheme === null
        || ($scores[$bestTheme] ?? 0) <= 0
    ) {
        return 'general';
    }

    return $bestTheme;
}

/**
 * 各角色主題的正式啟示文。
 *
 * @return array<string, array<int, string>>
 */
function fortuneCharacterInsightPools(): array
{
    return [
        'fate' => [
            '眼前的局勢並非完全固定，今天的一次選擇，可能讓後續發展走向不同方向。',
            '命運不一定需要正面對抗，有時改變其中一個條件，就能重新排列整個局勢。',
            '過去形成了現在，但今天的決定仍然能改變下一段因果。',
        ],

        'investigation' => [
            '真正重要的線索往往藏在細節裡，今天適合多確認一次，再做出判斷。',
            '不要只相信最先看到的答案，重新整理資訊後，事情可能呈現不同面貌。',
            '先分清楚事實、推測與情緒，真正的問題便會逐漸浮現。',
        ],

        'engineering' => [
            '穩定的成果來自逐步確認每個環節，今天適合先找出真正影響整體的關鍵點。',
            '遇到問題時不必全部推翻，修正最重要的環節，往往就能讓整體重新運作。',
            '複雜的系統也能被拆成簡單步驟，今天宜先處理最明確的一項。',
        ],

        'healing' => [
            '恢復並不代表停滯，先讓自己回到穩定狀態，才能走得更遠。',
            '照顧他人之前，也要確認自己仍有足夠力量支撐接下來的行動。',
            '今天真正需要守護的，可能不是成果，而是能讓你繼續前進的狀態。',
        ],

        'loyalty' => [
            '堅守承諾能帶來力量，但真正的忠誠也包含在必要時說出真實想法。',
            '今天適合守住重要的人與目標，不必因為外界雜音輕易改變立場。',
            '可靠不是永遠獨自承擔，而是在關鍵時刻仍願意站在重要的位置上。',
        ],

        'wisdom' => [
            '真正的優勢不只是搶先行動，而是比別人更早看懂下一步。',
            '今天適合先掌握全局，再把力量放在最能改變結果的位置。',
            '判斷清楚局勢後再出手，會比單純依靠速度更加有效。',
        ],

        'freedom' => [
            '今天適合離開已經失去意義的限制，替自己保留新的行動空間。',
            '持續前進不等於逃避，重要的是你是否知道自己正朝哪個方向移動。',
            '不必讓過去的束縛替現在做決定，今天仍有重新選擇的空間。',
        ],

        'growth' => [
            '真正的成長不是證明自己從不失敗，而是每次都能比過去更進一步。',
            '今天遇到的阻力，也可能正是確認自身能力的試煉。',
            '不必急著追求完美，願意正面看見不足，本身就是成長的開始。',
        ],

        'justice' => [
            '今天適合先確認自己的原則，再決定哪些事情值得堅持到底。',
            '判斷是非之前，也要看清事情的全貌，避免讓立場取代事實。',
            '真正的正義不只是指出錯誤，也包含承擔修正錯誤的責任。',
        ],

        'resolve' => [
            '堅定能讓你穿越阻力，但今天也要分清楚堅持與勉強之間的差別。',
            '不必等待所有不安消失，確認方向後，仍然可以帶著疑慮向前。',
            '勇氣不是毫無恐懼，而是在看見風險後仍選擇穩定前進。',
        ],

        'darkness' => [
            '執念能形成強大的推力，但今天不要讓它遮住其他可能性。',
            '面對內在的陰影並不代表向它屈服，理解它反而能取回選擇權。',
            '強烈的情緒正在累積，今天宜確認它是在保護你，還是在消耗你。',
        ],

        'change' => [
            '局勢正在變化，今天不必過早把自己限制在單一答案裡。',
            '改變方法不代表否定過去，而是讓已有經驗適應新的條件。',
            '當原本的道路不再順利時，調整位置可能比增加力量更有效。',
        ],

        'general' => [
            '這名角色走過的道路提醒你，今天的選擇不只取決於能力，也取決於你準備成為什麼樣的人。',
            '過去的經歷塑造了現在的力量，而今天的行動將決定它被如何使用。',
            '能力只是條件，真正左右結果的，是你如何理解並運用這份力量。',
        ],
    ];
}

/**
 * 取得角色背景啟示。
 *
 * 同一角色、同一天、同一使用者會固定同一句。
 *
 * @param array<string, mixed> $profile
 */
function fortuneBuildCharacterInsight(
    array $profile,
    string $seed
): string {
    $theme =
        fortuneDetectCharacterTheme(
            $profile
        );

    $pools =
        fortuneCharacterInsightPools();

    $pool =
        $pools[$theme]
        ?? $pools['general'];

    if (empty($pool)) {
        return '';
    }

    $profileKey =
        (string)(
            $profile['_profile_key']
            ?? $profile['name_tcn']
            ?? ''
        );

    $hash = hash(
        'sha256',
        $seed
            . '|character-insight|'
            . $profileKey
            . '|'
            . $theme
    );

    $index =
        hexdec(substr($hash, 0, 8))
        % count($pool);

    return $pool[$index];
}

/**
 * 取得適合畫面顯示的簡短角色背景。
 *
 * 預設使用角色簡介的第一句。
 *
 * @param array<string, mixed> $profile
 */
function fortuneBuildCharacterBackground(
    array $profile
): string {
    $info = trim(
        (string)($profile['info_tcn'] ?? '')
    );

    if ($info === '') {
        return '';
    }

    $parts = preg_split(
        '/[。！？]/u',
        $info,
        2
    );

    $firstSentence = trim(
        (string)($parts[0] ?? '')
    );

    if ($firstSentence === '') {
        return '';
    }

    return $firstSentence . '。';
}

/**
 * 依角色產生固定但每日可變的幽默句。
 *
 * @param array<string, mixed> $profile
 */
function fortuneBuildCharacterHumor(
    array $profile,
    string $seed
): string {
    $characterName = trim(
        (string)($profile['name_tcn'] ?? '')
    );

    if ($characterName === '') {
        return '';
    }

    /*
     * 每個角色可以放 2～5 句。
     * 同一玩家同一天固定，隔天可能更換。
     */
    $characterHumorPools = [
        '阿貝爾' => [
            '今天的修行內容：先別對空氣使用霸王閃擊。',
            '劍術可以慢慢練，但早餐最好不要省略。',
            '遇到阻力時可以拔劍，但不是每個問題都需要拔劍。',
        ],

        '艾伯李斯特' => [
            '他大概已經算到你會看到這句了。',
            '計畫沒有失敗，只是對手沒有按照計畫配合。',
            '今天若有人說「隨機應變」，請先確認他到底有沒有計畫。',
        ],

        '艾依查庫' => [
            '忠誠沒有問題，問題是別人可能只是請你幫忙拿個東西。',
            '今天仍會可靠地站在重要位置，只是希望那裡有椅子。',
            '不屈之心很好，但該休息時還是要休息。',
        ],

        '音音夢' => [
            '她說只是普通注射，請不要問針筒為什麼那麼大。',
            '照顧別人之前記得吃飯，牛奶不能算完整的一餐。',
            '今天適合溫柔提醒，不適合拿著針筒追人。',
        ],

        '布朗寧' => [
            '真相只有一個，但今天的待辦事項可能有十個。',
            '他已經發現線索了，現在只差一杯咖啡。',
            '不要忽略小細節，尤其是你剛剛放錯地方的鑰匙。',
        ],

        '阿修羅' => [
            '忍者不會遲到，只是比預定時間晚一點現身。',
            '保持低調很好，但一直躲著也不會讓事情自己完成。',
            '今天適合隱密行動，不適合在群組裡突然消失。',
        ],

        '梅莉' => [
            '夢裡什麼都有，除了準時起床的方法。',
            '今天可以保留幻想，但鬧鐘還是要設定。',
            '若現實不如預期，先確認自己是不是還沒睡醒。',
        ],

        '瑪格莉特' => [
            '工程師說「小問題」時，通常代表工具箱還沒打開。',
            '不用全部拆掉重做，至少先忍住五分鐘。',
            '混沌可以追查，但桌面上的電線最好先整理。',
        ],

        '沃肯' => [
            '自動人偶很精密，唯獨不負責提醒主人休息。',
            '創造生命之前，建議先備份。',
            '如果零件多出一顆，請先不要假裝它不重要。',
        ],

        '傑多' => [
            '因果之線錯綜複雜，但耳機線通常更複雜。',
            '改變命運需要勇氣，改變鬧鐘時間只需要一秒。',
            '他能看見因果，卻未必能找到剛才放下的東西。',
        ],

        '利恩' => [
            '暴風駕馭者不怕逆風，只怕油箱見底。',
            '背刺適合用在戰鬥，不適合用在工作群組。',
            '今天可以快速行動，但先確認方向不是反的。',
        ],

        '露緹亞' => [
            '黑暗正在侵蝕，但她目前比較在意披風有沒有弄髒。',
            '面對內心陰影之前，記得先把房間的燈打開。',
            '執念可以推動前進，但導航還是要看。',
        ],

        '魯卡' => [
            '大公的尊嚴很重要，但迷路時問路也不會失去繼承權。',
            '繼承權可以爭，最後一塊蛋糕也可以商量。',
            '今天適合展現氣勢，但不用每句話都像在下令。',
        ],

        '迪諾' => [
            '這當然是本大爺真正的實力，只是骰子還不知道。',
            '自信可以提高氣勢，但不會自動提高骰點。',
            '今天看起來很帥，至少他本人是這麼認為的。',
        ],

        '里斯' => [
            '王牌不一定最早到，但通常會說自己壓軸登場。',
            '煉獄很熱，今天記得補充水分。',
            '問題可以一劍解決，但文件還是得一頁一頁填。',
        ],

        '史普拉多' => [
            '挑戰者永遠向前，除非前面寫著維修中。',
            '今天適合挑戰極限，但不包含鬧鐘的貪睡次數。',
            '貪食者提醒你：先確認冰箱裡真的還有東西。',
        ],

        '康拉德' => [
            '企圖帶來混亂之前，他可能會先整理一下衣領。',
            '黑教父的計畫很深遠，但會議仍可能延後。',
            '今天的怒氣很充足，建議不要全部用在網路速度上。',
        ],

        '諾艾菈' => [
            '失落文明很難找，昨天放的東西有時更難找。',
            '遺跡獵人提醒你：翻找之前先回想自己放在哪裡。',
            '探索未知很好，但先不要把每個抽屜都打開。',
        ],

        '奧蘭' => [
            '表演可以失誤，只要你看起來像是故意的。',
            '今天可能要跳火圈，也可能只是跨過充電線。',
            '獸型侍者的專業，就是把意外演成節目效果。',
        ],
    ];

    $pool = $characterHumorPools[$characterName] ?? [];

    if (!$pool) {
        return '';
    }

    $hash = hash(
        'sha256',
        $seed
            . '|character-humor|'
            . $characterName
    );

    $index =
        hexdec(substr($hash, 0, 8))
        % count($pool);

    return $pool[$index];
}

/**
 * 建立節日與生活情境彩蛋。
 *
 * 同一天、同一位玩家結果固定。
 *
 * @return array{
 *   type: string,
 *   label: string,
 *   text: string
 * }|null
 */
function fortuneBuildSeasonalEasterEgg(
    string $seed,
    ?DateTimeImmutable $now = null
): ?array {
    $now ??= new DateTimeImmutable(
        'now',
        new DateTimeZone('Asia/Taipei')
    );

    $monthDay = $now->format('m-d');
    $month = (int)$now->format('n');
    $day = (int)$now->format('j');
    $weekday = (int)$now->format('N');
    $hour = (int)$now->format('G');

    /*
     * 固定日期節日。
     */
    $festivalPools = [
        '01-01' => [
            'label' => '新年彩蛋',
            'texts' => [
                '新的一年才剛開始，命運建議先不要急著把所有目標排在今天完成。',
                '今年的第一份命運已送達，至於去年沒完成的事情，命運選擇保持沉默。',
                '新年新氣象，但昨天留下的待辦事項並不會因此自動消失。',
            ],
        ],

        '02-14' => [
            'label' => '情人節彩蛋',
            'texts' => [
                '今天適合表達心意，但不要把「在嗎」當成完整告白。',
                '命運提醒你：好感度不會因為連續按占卜而自動上升。',
                '無論今天是否有人同行，都不要忘記替自己保留一份巧克力。',
            ],
        ],

        '04-01' => [
            'label' => '愚人節彩蛋',
            'texts' => [
                '今天的占卜結果完全可信。大概。',
                '命運表示今天不會騙你，但占卜師沒有做出同樣承諾。',
                '這不是愚人節玩笑，你今天真的抽到了這張牌。',
            ],
        ],

        '05-01' => [
            'label' => '勞動節彩蛋',
            'texts' => [
                '今天的命運主題是休息，但待辦清單似乎持不同意見。',
                '努力工作值得肯定，適時離開座位也同樣重要。',
                '命運已批准今日休息申請，至於主管是否批准則不在占卜範圍內。',
            ],
        ],

        '10-31' => [
            'label' => '萬聖節彩蛋',
            'texts' => [
                '今天遇到奇怪的影子不用緊張，也可能只是螢幕反光。',
                '不給糖就搗蛋，但命運目前只提供角色卡。',
                '今晚適合探索黑暗，但走廊的燈還是建議打開。',
            ],
        ],

        '12-24' => [
            'label' => '平安夜彩蛋',
            'texts' => [
                '今晚適合放慢腳步，至少不要在睡前再開一個新專案。',
                '平安夜的命運較為溫和，除非你還沒準備明天的禮物。',
                '命運祝你今晚平安，並提醒你記得設定鬧鐘。',
            ],
        ],

        '12-25' => [
            'label' => '聖誕祝福',
            'texts' => [
                '聖誕老人已經出發，但你的快遞可能仍在配送中心。',
                '今天適合分享好運，也適合分享甜點。',
                '命運送來祝福，包裝紙則需要你自己準備。',
            ],
        ],

        '12-31' => [
            'label' => '跨年彩蛋',
            'texts' => [
                '今年即將結束，未完成事項可以先改名為明年計畫。',
                '跨年前最重要的不是倒數，而是先確認回家的交通方式。',
                '命運之輪準備跨年，但它表示不想再轉一百次。',
            ],
        ],
    ];

    if (isset($festivalPools[$monthDay])) {
        $event = $festivalPools[$monthDay];

        return [
            'type' => 'festival',
            'label' => $event['label'],
            'text' => fortunePickSeasonalText(
                $event['texts'],
                $seed,
                'festival|' . $monthDay
            ),
        ];
    }

    /*
     * 日期區間型季節彩蛋。
     */
    $seasonalEvents = [];

    if (
        ($month === 7 && in_array($day, [7, 15, 23, 31], true))
        || ($month === 8 && in_array($day, [8, 18, 28], true))
    ) {
        $seasonalEvents[] = [
            'type' => 'season',
            'label' => '盛夏彩蛋',
            'texts' => [
                '今天的行動力或許很高，但氣溫也一樣，記得補充水分。',
                '夏日適合主動行動，前提是不要忘記防曬。',
                '命運提醒你保持冷靜，物理意義上的冷靜也包含在內。',
            ],
        ];
    }

    if (
        ($month === 12)
        || ($month === 1)
        || ($month === 2)
    ) {
        $seasonalEvents[] = [
            'type' => 'season',
            'label' => '冬日彩蛋',
            'texts' => [
                '今天適合保存體力，也適合再多躺五分鐘。',
                '冬天的行動速度稍慢很正常，棉被具有強力控制效果。',
                '命運建議保持溫暖，尤其是在離開椅子之前。',
            ],
        ];
    }

    /*
     * 日常生活彩蛋。
     */
    if ($weekday === 1) {
        $seasonalEvents[] = [
            'type' => 'daily',
            'label' => '星期一彩蛋',
            'texts' => [
                '星期一的第一個任務，是接受今天確實是星期一。',
                '今天不必一次恢復全部狀態，先成功登入系統就好。',
                '命運已經開始運作，你的精神可能還在讀取中。',
            ],
        ];
    }

    if ($weekday === 5) {
        $seasonalEvents[] = [
            'type' => 'daily',
            'label' => '星期五提醒',
            'texts' => [
                '星期五的最大陷阱，是下午突然出現一句「有空嗎」。',
                '今天適合完成收尾，避免在下班前開啟新的支線任務。',
                '週末就在前方，請勿在最後一格耗盡全部資源。',
            ],
        ];
    }

    if ($day <= 3) {
        $seasonalEvents[] = [
            'type' => 'daily',
            'label' => '月初彩蛋',
            'texts' => [
                '新的月份開始了，新的計畫也正排隊等待被延期。',
                '月初適合重新整理目標，但不必一次建立二十個。',
                '命運提醒你：月初的餘裕不代表月底仍會存在。',
            ],
        ];
    }

    if ($day >= 27) {
        $seasonalEvents[] = [
            'type' => 'daily',
            'label' => '月底彩蛋',
            'texts' => [
                '月底即將到來，尚未處理的事情開始集體浮現。',
                '今天適合收尾，不適合假裝日曆還停留在月初。',
                '月底的資源力需要謹慎分配，包含時間與錢包。',
            ],
        ];
    }

    if ($hour >= 23 || $hour <= 4) {
        $seasonalEvents[] = [
            'type' => 'daily',
            'label' => '深夜提醒',
            'texts' => [
                '這個時間還在占卜，命運建議你也考慮一下睡眠。',
                '深夜容易出現靈感，也容易把小問題想成世界末日。',
                '今天最後一項任務，可以是把手機放下。',
            ],
        ];
    } elseif ($hour >= 5 && $hour <= 8) {
        $seasonalEvents[] = [
            'type' => 'daily',
            'label' => '早點名',
            'texts' => [
                '早晨的命運已經醒了，希望你也是。',
                '今天的第一個加成來自早餐。',
                '清晨適合規劃方向，但咖啡完成前不宜做重大決策。',
            ],
        ];
    }

    if (!$seasonalEvents) {
        return null;
    }

    /*
     * 同時符合多個生活事件時，
     * 使用每日種子固定挑選一項。
     */
    $eventIndex =
        hexdec(
            substr(
                hash(
                    'sha256',
                    $seed . '|seasonal-event'
                ),
                0,
                8
            )
        )
        % count($seasonalEvents);

    $event = $seasonalEvents[$eventIndex];

    return [
        'type' => (string)$event['type'],
        'label' => (string)$event['label'],
        'text' => fortunePickSeasonalText(
            $event['texts'],
            $seed,
            'seasonal|'
                . $event['type']
                . '|'
                . $event['label']
        ),
    ];
}

/**
 * 固定挑選節日彩蛋文案。
 *
 * @param array<int, string> $pool
 */
function fortunePickSeasonalText(
    array $pool,
    string $seed,
    string $key
): string {
    $pool = array_values(
        array_filter(
            $pool,
            static fn(mixed $text): bool =>
            is_string($text)
                && trim($text) !== ''
        )
    );

    if (!$pool) {
        return '';
    }

    $index =
        hexdec(
            substr(
                hash(
                    'sha256',
                    $seed . '|seasonal-text|' . $key
                ),
                0,
                8
            )
        )
        % count($pool);

    return $pool[$index];
}
