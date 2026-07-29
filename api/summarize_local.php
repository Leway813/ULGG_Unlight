<?php
//I'm summarize_local.php
function localSummarize($text)
{
    $summary = [];

    if (empty($text)) return "（無內容）";

    // 去除 HTML、統一空白
    $t = strip_tags($text);
    $t = preg_replace('/\s+/', ' ', $t);

    $bucket = [
        'character' => [],
        'event'     => [],
        'ranking'   => [],
        'system'    => [],
        'shop'      => [],
        'fix'       => [],
        'notice'    => [],
    ];

    $added = [];

    $add = function (string $type, string $line) use (&$bucket, &$added) {
        if (!isset($added[$line])) {
            $bucket[$type][] = $line;
            $added[$line] = true;
        }
    };

    // =========================
    // 20260714 維修公告：Rudia R5 / BP COST / 事件卡攻擊力顯示 / Paulette 暗房補登
    // =========================

    $is20260714RudiaNotice =
        preg_match('/2026\/07\/14|July 14th|7月14日/i', $t)
        || preg_match('/Rudia\s*R5|露緹亞R5/i', $t);

    // 新增稀有卡：Rudia R5 / 露緹亞R5
    if (
        $is20260714RudiaNotice
        && preg_match('/Rudia\s*R5|露緹亞R5/i', $t)
    ) {
        $add('character', "新增稀有卡「露緹亞 R5」，並追加 R4 新插圖。");
    }

    // BP COST 更新
    if (
        $is20260714RudiaNotice
        && preg_match('/Alexandre|Fornheil|亞歷山卓城|峰亥盧遺跡/i', $t)
        && preg_match('/Category\s*1|區分1|COST/i', $t)
    ) {
        $add('system', "更新 BP 變動頻道之 COST 區分（亞歷山卓城、峰亥盧遺跡）。");
    }

    // 事件卡攻擊力顯示修正
    if (
        $is20260714RudiaNotice
        && (
            preg_match('/attack power.*displayed.*opponent|event card with an effect/i', $t)
            || preg_match('/攻擊力.*對手|特殊效果的事件卡|事件卡/i', $t)
        )
    ) {
        $add('fix', "修正攻擊階段打出特殊效果事件卡時，攻擊力會顯示給對手的問題。");
    }

    // Paulette 暗房卡池漏放
    if (
        $is20260714RudiaNotice
        && preg_match('/Paulette|波蕾特/i', $t)
        && preg_match('/Dark Room rotation|Dark Room|暗房卡池|抽卡/i', $t)
    ) {
        $add('fix', "確認波蕾特未列入本週暗房卡池，預定下週重新追加至各抽卡。");
    }

    // =========================================================
    // 開發消息 Development News
    // =========================================================
    if (preg_match('/Development News|currently in development|development updates|開發進度|開發消息/i', $t)) {
        $add('system', "官方公開最新開發進度，展示開發中的新功能與介面。");

        // Steam API 通常抓不到圖片文字，這裡用 Development News 固定摘要
        if (preg_match('/Vol\.?2|Vol\.?3|Vol\.2&3/i', $t)) {
            $add('system', "展示新 UI、牌組編輯、必殺技演出、角色動畫及手機版開發進度。");
        }
    }

    // =========================================================
    // 新角色 / 新稀有卡
    // =========================================================
    $characterMap = [
        'Noella|諾艾菈' => '諾艾菈',
        'Juhani|尤哈尼' => '尤哈尼',
        'Epsilon|伊普西隆' => '伊普西隆',
        'Ariane|艾莉亞娜' => '艾莉亞娜',
        'Hugo|雨果' => '雨果',
        'Alicetaria|艾莉絲泰莉雅' => '艾莉絲泰莉雅',
        'Schillerlee|希拉莉' => '希拉莉',
        'Noichrome|諾伊庫洛姆' => '諾伊庫洛姆',
        'Dino|迪諾' => '迪諾',
        'Ideriha|出葉|イデリハ' => '出葉',
        'Orang|奧蘭|オラン' => '奧蘭',
        'Linnaeus|林奈烏斯|リンネウス' => '林奈烏斯',
        'Eureka|尤莉卡' => '尤莉卡',
    ];

    foreach ($characterMap as $pattern => $name) {
        if (
            preg_match('/Addition of New Character Card|Adding New Character Card|New Character Card|Addition of Character Card|追加角色卡|新增角色卡|實裝角色卡/i', $t)
            && preg_match('/' . $pattern . '/i', $t)
        ) {
            $prefix = preg_match('/Upcoming Maintenance|維修預告|系统维修预告/i', $t) ? "預告新增" : "新增";
            $add('character', "{$prefix}角色卡「{$name}」。");
            break;
        }
    }

    // =========================
    // 20260707 維修預告：排行榜重置 / 商店更新 / 每月成就 / 限定品結束
    // =========================

    $is20260707RankingNotice =
        preg_match('/July 7th|7月7日|2026\/07\/07/i', $t)
        && preg_match('/Ranking Reset|排行榜重置/i', $t)
        && preg_match('/June rankings|6月份排行榜/i', $t)
        && preg_match('/July rankings|7月份排行榜/i', $t);

    // 6月排行結算、7月排行開始
    if ($is20260707RankingNotice) {
        $add('ranking', "6月份排行榜結算，7月份排行榜將於維修後開始統計。");
    }

    // 7月排名獎勵更新
    if (
        $is20260707RankingNotice
        && preg_match('/Rosso|Key of Contraband|Evarist Doll|羅索|禁忌之鑰|艾伯李斯特的手提娃娃/i', $t)
    ) {
        $add('ranking', "7月份 BP／QP 排名獎勵更新，包含羅索專用裝備「禁忌之鑰」。");
    }

    // 商店與武器卡更新
    if (
        $is20260707RankingNotice
        && preg_match('/Shop Updates|商店更新|weapon cards|武器卡片|purchase deadline|購買期限|販售以下武器卡片/i', $t)
    ) {
        $add('shop', "商店更新：GEM 商品購買次數重置，武器卡片販售內容輪替。");
    }

    // 每月 BP 變動頻道成就重置
    if (
        $is20260707RankingNotice
        && (
            preg_match('/Resetting New Monthly Records|每月成就更新|records will be reset/i', $t)
            || preg_match('/Play 1 match on the BP-Variable Channel|Play 5 match on the BP-Variable Channel|在BP變動頻道進行1次對戰|在BP變動頻道進行5次對戰/i', $t)
        )
    ) {
        $add('ranking', "每月 BP 變動頻道對戰成就將於維修時重置。");
    }

    // 部分限定道具停止販售
    if (
        $is20260707RankingNotice
        && preg_match('/End of Some Limited-Time Items|部分道具停止販售|sale of the following items will end|停止販售/i', $t)
        && preg_match('/Wataboshi|Shiromuku|棉帽子|白無垢/i', $t)
    ) {
        $add('shop', "部分期間限定道具將停止販售（棉帽子、白無垢）。");
    }



    // 新稀有卡
    if (preg_match('/Shalott\s*R5|夏洛特R5/i', $t)) {
        $add('character', "新增稀有卡「夏洛特 R5」，並追加 R4 新插圖。");
    }

    if (preg_match('/Tyrell\s*R5|泰瑞爾R5/i', $t)) {
        $prefix = preg_match('/Upcoming Maintenance|維修預告|系统维修预告/i', $t) ? "預告新增" : "新增";
        $add('character', "{$prefix}稀有卡片「泰瑞爾 R5」。");
    }

    if (preg_match('/Linnaeus\s*R3|林奈烏斯 R3/i', $t)) {
        $add('character', "新增稀有卡片「林奈烏斯 R3」。");
    }

    // 特定角色補充：Juhani 新插圖
    if (preg_match('/Juhani|尤哈尼/i', $t) && preg_match('/R1.*illustration|R1.*新插圖|R1已追加全新插圖/i', $t)) {
        $add('character', "新增角色卡「尤哈尼」，並追加 R1 新插圖。");
    }

    // =========================================================
    // 角色調整 / 技能調整
    // =========================================================
    if (preg_match('/Juhani|尤哈尼/i', $t) && preg_match('/Last Light Defender|夕輝守護者|cost of L5|cost of R1|L5的COST|R1的COST/i', $t)) {
        $add('system', "調整尤哈尼角色能力與 COST，更新「夕輝守護者」效果。");
    } elseif (preg_match('/Alicetaria|艾莉絲泰莉雅/i', $t) && preg_match('/Sink Ivy|Negative Gift|cost of L2|cost of L3|cost of L4|cost of L5|R1|R2/i', $t)) {
        $add('system', "調整艾莉絲泰莉雅角色能力與 COST，並更新部分技能效果。");
    } elseif (preg_match('/Dino|迪諾/i', $t) && preg_match('/cost of L4|cost of L5|cost of R1|cost of R2|I\'ve seen through your skills/i', $t)) {
        $add('system', "調整迪諾角色能力與 COST，並更新技能效果。");
    } elseif (preg_match('/Orang|奧蘭|オラン/i', $t) && preg_match('/L4|COST|cost|17/i', $t)) {
        $add('system', "調整奧蘭 L4 的 COST 為 17。");
    } elseif (preg_match('/Ideriha|出葉|イデリハ/i', $t) && preg_match('/COST|cost|Rippling Pulse|波環紋脈/i', $t)) {
        $add('system', "調整出葉的 COST 與必殺技效果。");
    } elseif (preg_match('/Linnaeus|林奈烏斯/i', $t) && preg_match('/蟲笛|DEF\\+2|傷害無效|special move/i', $t)) {
        $add('system', "調整林奈烏斯必殺技效果，新增 DEF 強化與傷害無效領域。");
    } elseif (preg_match('/Adjustments to Some Character Cards|Changing Some Special Moves|部分角色卡調整|部分角色卡的調整|角色卡調整|必殺技規格|Special Moves/i', $t)) {
        $prefix = preg_match('/Upcoming Maintenance|維修預告|系统维修预告/i', $t) ? "預告將調整" : "調整";
        $add('system', "{$prefix}部分角色卡能力、COST 或必殺技規格。");
    }

    // 奧蘭專屬細節
    if (preg_match('/Orang|奧蘭|オラン/i', $t) && preg_match('/Playing Piano|彈鋼琴|special move/i', $t)) {
        $add('system', "奧蘭必殺技「彈鋼琴」會依提出卡片數量降低對手攻擊力。");
    }

    if (preg_match('/Orang|奧蘭|オラン/i', $t) && preg_match('/Salustiana|薩斯迦納|exclusive equipment/i', $t)) {
        $add('shop', "新增奧蘭專用裝備「薩斯迦納」，兩版本皆可裝備。");
    }

    // =========================================================
    // 活動開始 / 結束
    // =========================================================
    if (preg_match('/UNLIGHT Fes\. 2026/i', $t)) {
        if (preg_match('/will end|end at|結束/i', $t)) {
            $add('event', "期間限定活動「UNLIGHT Fes. 2026」將於維修後結束。");
        } elseif (preg_match('/bonus game|fragments obtained|increased|獎勵加成/i', $t)) {
            $add('event', "更新期間限定活動「UNLIGHT Fes. 2026」，Bonus Game 獎勵加成提升。");
        }
    }

    if (preg_match('/Training Campaign|養成活動|育成イベント/i', $t)) {
        if (preg_match('/ended|will end|結束/i', $t)) {
            $add('event', "期間限定活動「養成活動」結束，活動成就與相關措施將無法再進行。");
        } else {
            $add('event', "實施期間限定養成活動，可獲得額外獎勵並挑戰活動成就。");
        }
    }

    if (preg_match('/Approaching the Monolith: Revive/i', $t)) {
        $add('event', "期間限定活動「Approaching the Monolith: Revive」結束，活動道具與兌換期限請留意。");
    }

    if (preg_match('/Christmas Event\s*2025|聖誕活動2025/i', $t)) {
        $add('event', "舉辦期間限定活動「聖誕活動2025」。");
    }

    if (preg_match('/1st Anniversary|Release 1st Anniversary|Anniversary Campaign/i', $t)) {
        $add('event', "舉辦 STEAM 一週年期間限定活動。");
    }

    // =========================================================
    // 排名 / 每月成就
    // =========================================================
    if (
        !$is20260707RankingNotice
        && (
            preg_match('/June rankings started|6月份排行榜開始|6月份排行榜開始統計/i', $t)
            || (
                preg_match('/BP Ranking Rewards|QP Ranking Rewards|BP排行榜獎勵|QP排行榜獎勵/i', $t)
                && preg_match('/Belinda|貝琳達|Cross Flag|十字之旗/i', $t)
                && !preg_match('/Ranking Reset|排行榜重置|will end|統計期間至/i', $t)
            )
        )
    ) {
        $add('ranking', "6月份排行榜開始統計，BP／QP 獎勵同步更新。");
    } elseif (preg_match('/April rankings|March rankings|4月排名|3月排名/i', $t)) {
        $add('ranking', "月排名更新，BP／QP 獎勵同步更新。");
    } elseif (preg_match('/March rankings|Fabuary rankings|February rankings|2月排名|3月排名/i', $t)) {
        $add('ranking', "月排名更新，BP／QP 獎勵同步更新。");
    } elseif (
        !$is20260707RankingNotice
        && preg_match('/rankings? will end|rankings? started|Ranking Reset|排行榜重置|BP Ranking|QP Ranking/i', $t)
    ) {
        $add('ranking', "月排名重置並更新 BP／QP 排名獎勵。");
    }

    if (
        !$is20260707RankingNotice
        && preg_match('/records? will be reset|records were reset|Resetting New Monthly Records|每月對戰成就更新|成就已.*重置/i', $t)
    ) {
        $add('ranking', "每月對戰成就已重置。");
    }

    // =========================================================
    // BP 變動頻道 COST
    // =========================================================
    if (
        preg_match('/Alexandre|Fornheil|亞歷山卓城|峰亥盧遺跡/i', $t)
        && preg_match('/COST|Category\\s*1|區分1/i', $t)
    ) {
        $add('system', "更新 BP 變動頻道之 COST 區分（亞歷山卓城、峰亥盧遺跡）。");
    }

    // =========================================================
    // Raid / 暗房 / 系統
    // =========================================================
    if (preg_match('/Raid Mode/i', $t)) {
        $add('system', "新增多人合作玩法「Raid Mode」，可協力挑戰強力首領。");
    }

    if (preg_match('/raid/i', $t) && preg_match('/maximum number of participants|participants|Adjustments of Some Raid Monsters/i', $t)) {
        $add('system', "調整部分 Raid 內容與參與人數上限。");
    }

    if (preg_match('/Darkroom lottery|DarkRoom Lottery|暗房抽卡|抽卡輪替/i', $t)) {
        $add('system', "暗房抽卡輪替模式已變更，卡池內容與機率配置更新。");
    }

    // =========================================================
    // 商店 / 販售 / 復刻
    // =========================================================
    if (
        preg_match('/Shop Updates|商店更新|New Item Sales|available for sale|weapon cards|武器卡片|Re-sale of Some Items|復刻販售|sale of.*ended|End of Some Limited-Time Items/i', $t)
    ) {
        $add('shop', "商店更新：購買次數重置、武器卡片販售與部分限定商品調整。");
    }

    if (preg_match('/Wataboshi|綿帽子|Shiromuku|白無垢/i', $t)) {
        $add('shop', "部分道具復刻販售開放。");
    }

    if (preg_match('/Vortex Detector/i', $t)) {
        $add('shop', "調整 Raid 相關道具販售內容與價格。");
    }

    if (preg_match('/Lamplight Bundle|Red Lantern/i', $t)) {
        $add('shop', "新增期間限定販售套組（Lamplight Bundle）。");
    }

    if (preg_match('/20% off|20%OFF/i', $t)) {
        $add('shop', "商店付費道具與配件享 20% OFF 優惠。");
    }

    if (preg_match('/Special Lottery/i', $t)) {
        $add('shop', "特別抽卡登場，可獲得未持有之 R4 卡片。");
    }

    // =========================================================
    // Bug / 修正 / 補償
    // =========================================================
    if (
        preg_match('/Play 2-5 matches|Play 1 match|BP fluctuation channel|BP-Variable Channel/i', $t)
        || preg_match('/對戰2~5次|成就無法解鎖/i', $t)
    ) {
        $add('fix', "修正 BP 變動頻道對戰成就無法解鎖問題，既有紀錄與獎勵可正常取得。");
    }

    if (
        preg_match('/card slot UI|display the correct number of cards|max.*cards in hand/i', $t)
        || preg_match('/手牌上限|卡框UI|正確張數/i', $t)
    ) {
        $add('fix', "修正手牌上限減少時，卡框 UI 張數顯示異常問題。");
    }

    if (preg_match('/login bonuses|登入獎勵/i', $t)) {
        $add('fix', "修正登入獎勵發放異常並完成補發。");
    }

    if (preg_match('/Widow Dress/i', $t) && preg_match('/bug|still available for purchase|removed from the shop lineup/i', $t)) {
        $add('fix', "修正「Widow Dress」原應下架但仍可購買的問題。");
    }

    if (preg_match('/server storage failure|server provider|emergency maintenance|緊急維修|無法使用本遊戲服務|access was unavailable/i', $t)) {
        if (preg_match('/compensat|補償|Ancient Potion|古代妙藥|Ticket|抽獎劵|白色石楠/i', $t)) {
            $add('fix', "因伺服器異常或緊急維修造成服務中斷，官方已發放補償。");
        } else {
            $add('fix', "已知伺服器異常導致無法連線，服務已恢復。");
        }
    }

    if (preg_match('/Bug Fixes|Fixed an issue|異常修正|不具合修正/i', $t)) {
        $prefix = preg_match('/Upcoming Maintenance|維修預告|系统维修预告/i', $t) ? "預告進行" : "本次維修包含";
        $add('fix', "{$prefix}異常問題修正（bug fix）。");
    }

    if (preg_match('/compensat|補償/i', $t)) {
        $add('fix', "包含異常補償相關內容。");
    }

    // =========================================================
    // 使用條款 / 公平性
    // =========================================================
    if (preg_match('/Terms of Use|使用條款/i', $t)) {
        $add('notice', "官方更新《使用條款》（Terms of Use）。");
    }

    if (preg_match('/Prohibited Activities|禁止|prohibited/i', $t) && preg_match('/script|unauthorized|manipulate|interfere|外掛/i', $t)) {
        $add('notice', "重申禁止外掛、未授權操作與妨礙營運等行為。");
    }

    if (preg_match('/integrity|manipulat|ranking|win\\/loss|勝負|排名/i', $t)) {
        $add('notice', "禁止操縱勝負與排名，以維護遊戲公平性。");
    }

    if (preg_match('/Real Money Trading|RMT|現金交易/i', $t)) {
        $add('notice', "明確禁止現金交易（RMT）。");
    }

    if (preg_match('/suspend|suspension|account will be suspended|停權|限制使用/i', $t)) {
        $add('notice', "違反使用條款者，官方可採取帳號停權等限制措施。");
    }

    // =========================================================
    // 維修預告提醒
    // =========================================================
    if (preg_match('/contents may change|development status|開發狀況|另行通知|另行公告|content may change/i', $t)) {
        $add('notice', "提醒：維修內容可能依開發進度調整。");
    }

    // =========================================================
    // PART 2：2026/02 ～ 2026/01 舊公告整理版
    // =========================================================


    // =========================
    // 20260224：Dino / 活動結束 / 商店 / 補償
    // =========================

    // 新角色 Dino
    if (
        preg_match('/Dino|迪諾/i', $t)
        && preg_match('/New Character Card|Addition of New Character Card|新增角色卡|追加角色卡/i', $t)
    ) {
        $prefix = preg_match('/Upcoming Maintenance|維修預告|系统维修预告/i', $t) ? "預告新增" : "新增";
        $add('character', "{$prefix}角色卡「迪諾」。");
    }

    // Dino 技能 / COST 調整
    if (
        preg_match('/Dino|迪諾/i', $t)
        && preg_match('/cost of L4|cost of L5|cost of R1|cost of R2|I\'ve seen through your skills|COST/i', $t)
    ) {
        $add('system', "調整迪諾角色能力與 COST，並更新技能效果。");
    }

    // 活動結束：Approaching the Monolith
    if (preg_match('/Approaching the Monolith: Revive/i', $t)) {
        $add('event', "期間限定活動「Approaching the Monolith: Revive」結束，活動道具與兌換期限請留意。");
    }

    // 商店 / 活動兌換 / Lamplight
    if (
        preg_match('/Lamplight Bundle|Red Lantern|limited-time item|prize exchange|VP\\/unit|GEM/i', $t)
    ) {
        $add('shop', "維修後新增期間限定販售與活動兌換更新。");
    }

    // 伺服器儲存故障 / 補償
    if (
        preg_match('/server storage failure|access was unavailable|apology|compensat|Ancient Potion|Ticket/i', $t)
    ) {
        $add('fix', "因伺服器儲存故障導致無法連線，官方將發放補償。");
    }


    // =========================
    // 20260222：已知問題
    // =========================

    if (
        preg_match('/Known Issue/i', $t)
        || (
            preg_match('/server storage failure|access was unavailable|Service was restored/i', $t)
            && preg_match('/February 22|2\\/22/i', $t)
        )
    ) {
        $add('fix', "已知問題：2/22 因伺服器儲存故障導致無法連線，服務已恢復。");
    }

    if (preg_match('/further apologies|maintenance notice at a later date/i', $t)) {
        $add('notice', "補償內容將於後續維修公告中另行說明。");
    }


    // =========================
    // 20260217：Linnaeus R3 / COST / Raid / 登入獎勵
    // =========================

    if (preg_match('/Linnaeus\\s*R3|林奈烏斯 R3/i', $t)) {
        $add('character', "新增稀有卡片「林奈烏斯 R3」。");
    }

    if (
        preg_match('/Alexandre|Fornheil|亞歷山卓城|峰亥盧遺跡/i', $t)
        && preg_match('/COST|Category\\s*1|區分1/i', $t)
    ) {
        $add('system', "更新 BP 變動頻道之 COST 區分（亞歷山卓城、峰亥盧遺跡）。");
    }

    if (
        preg_match('/raid/i', $t)
        && preg_match('/Fixed an issue|fixed an issue|Bug Fixes|修正/i', $t)
    ) {
        $add('fix', "修正 Raid 相關顯示與操作異常。");
    }

    if (preg_match('/login bonuses|unable to receive login bonuses|登入獎勵/i', $t)) {
        $add('fix', "修正登入獎勵發放異常並完成補發。");
    }


    // =========================
    // 20260203：Raid Mode / 排名 / 商店
    // =========================

    if (preg_match('/Raid Mode/i', $t)) {
        $add('system', "新增多人合作玩法「Raid Mode」，可與其他玩家協力挑戰強力首領。");
    }

    if (
        preg_match('/Raid Mode|raid battle/i', $t)
        && preg_match('/points?|damage|積分|傷害/i', $t)
    ) {
        $add('system', "Raid 戰鬥需造成傷害並獲得積分，成功討伐後可依積分取得獎勵。");
    }

    if (
        preg_match('/raid/i', $t)
        && preg_match('/raid rankings?|ranking reward/i', $t)
    ) {
        $add('ranking', "Raid 模式設有排名機制，依積分排名發放對應獎勵。");
    }

    if (preg_match('/Vortex Detector|available for sale|JPY/i', $t)) {
        $add('shop', "維修後新增多項付費道具販售（Vortex Detector 系列）。");
    }

    if (
        preg_match('/BP Ranking|QP Ranking|rankings started|Ranking Rewards/i', $t)
    ) {
        $add('ranking', "月排名更新，BP／QP 獎勵同步更新。");
    }

    if (
        preg_match('/weapon cards|exclusive weapon|weapon/i', $t)
        && preg_match('/available for sale|on sale|販售/i', $t)
    ) {
        $add('shop', "期間限定販售多名角色的專用武器卡片。");
    }

    if (
        preg_match('/records were reset|records will be reset|Resetting New Monthly Records|reset/i', $t)
    ) {
        $add('ranking', "部分對戰紀錄與每月成就於維修後重置。");
    }


    // =========================
    // 20260129：使用條款更新
    // =========================

    if (preg_match('/Terms of Use|使用條款/i', $t)) {
        $add('notice', "官方更新《使用條款》（Terms of Use）。");
    }

    if (
        preg_match('/Prohibited Activities|禁止|prohibited/i', $t)
        && preg_match('/script|unauthorized|manipulate|interfere|外掛/i', $t)
    ) {
        $add('notice', "重申禁止外掛、未授權操作與妨礙營運等行為。");
    }

    if (
        preg_match('/integrity|manipulat|ranking|win\\/loss|勝負|排名/i', $t)
    ) {
        $add('notice', "禁止操縱勝負與排名，以維護遊戲公平性。");
    }

    if (
        preg_match('/multiple accounts|multi[- ]account|sharing one account|共用帳號|多帳號/i', $t)
    ) {
        $add('notice', "禁止多帳號操作或共用帳號以獲取不正當利益。");
    }

    if (preg_match('/Real Money Trading|RMT|現金交易/i', $t)) {
        $add('notice', "明確禁止現金交易（RMT）。");
    }

    if (
        preg_match('/suspend|suspension|account will be suspended|停權|限制使用/i', $t)
    ) {
        $add('notice', "違反使用條款者，官方可採取帳號停權等限制措施。");
    }


    // =========================
    // 20260127：出葉 / 潛影迷霧 / 補償
    // =========================

    if (
        preg_match('/Ideriha|出葉|イデリハ/i', $t)
        && preg_match('/New Character Card|新增角色卡|追加角色卡/i', $t)
    ) {
        $add('character', "新增角色卡「出葉」。");
    }

    if (
        preg_match('/Ideriha|出葉|イデリハ/i', $t)
        && preg_match('/Lurking Mist|潛影迷霧/i', $t)
    ) {
        $add('system', "新增狀態效果「潛影迷霧」，可隱藏距離資訊並影響行動判定。");
    }

    if (
        preg_match('/Ideriha|出葉|イデリハ/i', $t)
        && preg_match('/COST|cost|Rippling Pulse|波環紋脈/i', $t)
    ) {
        $add('system', "調整出葉的 COST 與必殺技「波環紋脈」效果。");
    }

    if (
        preg_match('/Friedrich|弗雷特里西/i', $t)
        && preg_match('/special move|必殺技|修正|異常/i', $t)
    ) {
        $add('fix', "修正弗雷特里西多項必殺技補正值異常。");
    }

    if (
        preg_match('/server was inaccessible|無法登入|login/i', $t)
        && preg_match('/compensat|補償/i', $t)
    ) {
        $add('fix', "發生登入異常，官方已向全體玩家發放補償。");
    }


    // =========================
    // 20260120：奧蘭
    // =========================

    if (
        preg_match('/Orang|奧蘭|オラン/i', $t)
        && preg_match('/New Character Card|新增角色卡|追加角色卡/i', $t)
    ) {
        $add('character', "新增角色卡「奧蘭」，包含棕／黑白兩種版本。");
    }

    if (
        preg_match('/Orang|奧蘭|オラン/i', $t)
        && preg_match('/cannot be put into the same deck|cannot be in the same deck|無法同時|不能同時|不可同時/i', $t)
    ) {
        $add('system', "奧蘭棕／黑白版本無法同時編入同一個牌組。");
    }

    if (
        preg_match('/Orang|奧蘭|オラン/i', $t)
        && preg_match('/Playing Piano|彈鋼琴|special move/i', $t)
    ) {
        $add('system', "奧蘭必殺技「彈鋼琴」會依提出卡片數量降低對手攻擊力。");
    }

    if (
        preg_match('/Orang|奧蘭|オラン/i', $t)
        && preg_match('/Salustiana|薩斯迦納|exclusive equipment/i', $t)
    ) {
        $add('shop', "新增奧蘭專用裝備「薩斯迦納」，兩種版本皆可裝備。");
    }

    if (
        preg_match('/Orang|奧蘭|オラン/i', $t)
        && preg_match('/L4/i', $t)
        && preg_match('/COST|cost/i', $t)
        && preg_match('/17/', $t)
    ) {
        $add('system', "調整奧蘭 L4 的 COST 為 17。");
    }


    // =========================
    // 20260113：林奈烏斯 / 養成活動
    // =========================

    if (
        preg_match('/Linnaeus|林奈烏斯|リンネウス/i', $t)
        && preg_match('/New Character Card|新增角色卡|追加角色卡/i', $t)
    ) {
        $add('character', "新增角色卡「林奈烏斯」。");
    }

    if (
        preg_match('/Linnaeus|林奈烏斯|リンネウス/i', $t)
        && preg_match('/蟲笛|DEF\\+2|傷害無效|special move/i', $t)
    ) {
        $add('system', "調整林奈烏斯必殺技「蟲笛」，新增 DEF 強化與傷害無效領域效果。");
    }

    if (preg_match('/Training Event|Training Campaign|養成活動|育成イベント/i', $t)) {
        if (preg_match('/ended|will end|結束/i', $t)) {
            $add('event', "期間限定養成活動結束，活動成就與相關措施將無法再進行。");
        } else {
            $add('event', "實施期間限定養成活動，可獲得額外獎勵並挑戰活動成就。");
        }
    }

    if (
        preg_match('/活動限定成就|完成對戰頻道獎勵遊戲|古代妙藥|白色石楠|幸運四葉草/i', $t)
    ) {
        $add('event', "活動期間可完成限定成就並獲得活動獎勵。");
    }


    // =========================
    // 舊公告 / 通用活動與商店
    // =========================

    if (preg_match('/Christmas Event\\s*2025|聖誕活動2025/i', $t)) {
        $add('event', "舉辦期間限定活動「聖誕活動2025」。");
    }

    if (preg_match('/Santa Point|\\bSP\\b|聖誕點數|圣诞点数/i', $t)) {
        $add('event', "活動期間可取得聖誕點數並於商店兌換道具。");
    }

    if (preg_match('/Gift Box|禮物箱|ギフトボックス/i', $t)) {
        $add('event', "活動道具「禮物箱」可獲得期間限定任務。");
    }

    if (
        preg_match('/期間限定成就|Achievement|討伐羊角獸|贈送任務|gift.*mission/i', $t)
    ) {
        $add('event', "開放期間限定成就並提供對應獎勵。");
    }

    if (
        preg_match('/Accessory|虛擬人物配件|配件販售|復刻販售|期間限定販售|套組/i', $t)
    ) {
        $add('shop', "同步販售或復刻期間限定虛擬人物配件與套組。");
    }

    if (preg_match('/Pick\\s*Up|pickup|銅抽/i', $t)) {
        $add('shop', "抽卡 Pick Up 開啟，活動期間提升特定內容出現率。");
    }

    if (preg_match('/20% off|20%OFF/i', $t)) {
        $add('shop', "商店付費道具與配件享 20% OFF 優惠。");
    }

    if (preg_match('/Special Lottery/i', $t)) {
        $add('shop', "特別抽卡登場，可獲得未持有之 R4 卡片。");
    }

    if (preg_match('/limited-edition title screen/i', $t)) {
        $add('system', "期間限定登入畫面同步推出。");
    }

    if (preg_match('/login server/i', $t)) {
        $add('fix', "曾發生登入伺服器異常，現已修復並提供補償。");
    }

    if (
        preg_match('/Eureka|尤莉卡/i', $t)
        && preg_match('/New Character Card|Addition of New Character Card|Adding New Character Card|新增角色卡|追加角色卡/i', $t)
    ) {
        $add('character', "新增角色卡「尤莉卡」。");
    }


    // =========================
    // 通用：BP COST / Bug / NOTICE
    // =========================

    if (
        preg_match('/BP變動|BP\\s*變動|BP-Variable|BP Variable|COST限制|COST\\s*限制|区分\\d+|區分\\d+|コスト/i', $t)
        && preg_match('/COST|Alexandre|Fornheil|亞歷山卓城|峰亥盧遺跡/i', $t)
    ) {
        $add('system', "更新 BP 變動頻道之 COST 區分。");
    }

    if (
        preg_match('/Bug Fixes|Fixed an issue|異常修正|不具合修正|修正了/i', $t)
    ) {
        $prefix = preg_match('/Upcoming Maintenance|維修預告|系统维修预告/i', $t) ? "預告進行" : "本次維修包含";
        $add('fix', "{$prefix}異常問題修正（bug fix）。");
    }

    if (
        preg_match('/hard disk|free space|drive on which the OS is installed|硬碟.*空間|剩餘空間不足|磁碟機/i', $t)
    ) {
        $add('notice', "提醒：系統碟空間不足可能造成遊戲不穩定。");
    }

    if (
        preg_match('/\\bF8\\b|客服信箱|support|聯絡我們|report/i', $t)
    ) {
        $add('notice', "遇到 BUG／異常可於遊戲內回報或聯絡客服。");
    }

    if (
        preg_match('/contents may change|development status|開發狀況|另行通知|另行公告/i', $t)
    ) {
        $add('notice', "提醒：維修內容可能依開發進度調整。");
    }

    // =========================================================
    // 組合輸出：最多 4 條
    // =========================================================
    $order = ['character', 'event', 'ranking', 'system', 'shop', 'fix', 'notice'];
    $summary = [];

    foreach ($order as $type) {
        foreach ($bucket[$type] as $line) {
            $summary[] = $line;
            if (count($summary) >= 4) break 2;
        }
    }

    if (empty($summary)) {
        $summary[] = "本次公告包含更新、活動或修正內容。";
    }

    return "• " . implode("\n• ", $summary);
}

function localRewriteTitle($title)
{
    $raw = $title;

    // 日期擷取
    preg_match('/\d{4}\/\d{2}\/\d{2}/', $raw, $dateMatch);
    $date = $dateMatch[0] ?? "";

    // 類型對應表
    $rules = [
        "/Server Maintenance Notice/i" => "伺服器維護公告",
        "/Maintenance Information/i"   => "系統維修公告",
        "/Upcoming Maintenance/i"      => "維修預告",
        "/Known Issue/i"               => "已知問題",
        "/Development News/i"          => "開發消息",
        "/Update/i"                    => "更新資訊",
        "/Patch Notes/i"               => "更新內容",
    ];

    $type = "公告";

    foreach ($rules as $en => $zh) {
        if (preg_match($en, $raw)) {
            $type = $zh;
            break;
        }
    }

    // 判斷是否來自 STEAM
    $isSteam = preg_match('/STEAM/i', $raw) ? "（STEAM）" : "";

    // 組合結果
    if ($date) {
        return "【{$type}】{$date}{$isSteam}";
    } else {
        return "【{$type}】{$isSteam}";
    }
}
