if (typeof FIELD_DECKS === "undefined") {
    throw new Error(
        "FIELD_DECKS 尚未載入，請確認 field-decks.js 位於 tracker.js 之前。"
    );
}

const STORAGE_KEY = "ul_public_deck_tracker_v3";
const LEGACY_STORAGE_KEY = "ul_public_deck_tracker_v2";

const $ = id => document.getElementById(id);

const elements = {
    field: $("field"),
    undo: $("undo"),
    shuffle: $("shuffle"),
    reset: $("reset"),
    clear: $("clear"),
    cards: $("cards"),
    types: $("types"),
    history: $("history"),
    enemyCandidates: $("enemyCandidates"),
    myHand: $("myHand"),
    initial: $("initial"),
    deckLeft: $("deckLeft"),
    myHandCount: $("myHandCount"),
    enemyCandidateCount: $("enemyCandidateCount"),
    seen: $("seen")
};

const CARD_TYPE_CONFIG = {
    劍: {
        image: "./assets/cards/acswd.png",
        label: "ATTACK"
    },
    槍: {
        image: "./assets/cards/acbow.png",
        label: "ATTACK"
    },
    防: {
        image: "./assets/cards/acshi.png",
        label: "DEFENSE"
    },
    移: {
        image: "./assets/cards/acmov.png",
        label: "MOVE"
    },
    特: {
        image: "./assets/cards/acspe.png",
        label: "SPECIAL"
    }
};

const TYPE_ORDER = [
    "劍",
    "槍",
    "防",
    "移",
    "特"
];

function getTotal(deck) {
    return Object.values(
        deck || {}
    ).reduce(
        (
            sum,
            count
        ) => {
            return (
                sum +
                Number(count || 0)
            );
        },
        0
    );
}

function createZeroDeck(initialDeck) {
    return Object.fromEntries(
        Object.keys(
            initialDeck
        ).map(cardName => [
            cardName,
            0
        ])
    );
}

function cloneDeck(deck) {
    return structuredClone(
        deck
    );
}

function createFreshState(fieldName) {
    const initialDeck = cloneDeck(
        FIELD_DECKS[fieldName]
    );

    return {
        field: fieldName,
        initial: initialDeck,
        remaining: cloneDeck(
            initialDeck
        ),
        myHand: createZeroDeck(
            initialDeck
        ),
        enemyCandidates: createZeroDeck(
            initialDeck
        ),
        sortType: null,
        history: []
    };
}

function normalizeSavedDeck(
    savedDeck,
    initialDeck
) {
    const normalized = createZeroDeck(
        initialDeck
    );

    for (
        const cardName
        of Object.keys(initialDeck)
    ) {
        const maximum = Number(
            initialDeck[cardName] || 0
        );

        const value = Number(
            savedDeck?.[cardName] || 0
        );

        normalized[cardName] = Math.max(
            0,
            Math.min(
                maximum,
                value
            )
        );
    }

    return normalized;
}

function normalizeState(saved) {
    if (
        !saved ||
        !FIELD_DECKS[saved.field]
    ) {
        return null;
    }

    const initialDeck = cloneDeck(
        FIELD_DECKS[saved.field]
    );

    return {
        field: saved.field,

        initial: initialDeck,

        remaining: normalizeSavedDeck(
            saved.remaining,
            initialDeck
        ),

        myHand: normalizeSavedDeck(
            saved.myHand,
            initialDeck
        ),
        

        enemyCandidates: normalizeSavedDeck(
            saved.enemyCandidates,
            initialDeck
        ),
        

        sortType: TYPE_ORDER.includes(
            saved.sortType
        )
            ? saved.sortType
            : null,

        history: Array.isArray(
            saved.history
        )
            ? saved.history.slice(
                0,
                100
            )
            : []
    };
}

function loadStateFromKey(key) {
    try {
        const raw = localStorage.getItem(
            key
        );

        if (!raw) {
            return null;
        }

        return normalizeState(
            JSON.parse(raw)
        );

    } catch (error) {
        console.warn(
            `讀取追蹤狀態失敗（${key}）：`,
            error
        );

        return null;
    }
}

function loadState() {
    return (
        loadStateFromKey(
            STORAGE_KEY
        ) ||
        loadStateFromKey(
            LEGACY_STORAGE_KEY
        )
    );
}

function saveState() {
    localStorage.setItem(
        STORAGE_KEY,
        JSON.stringify(state)
    );
}

let state =
    loadState() ||
    createFreshState(
        Object.keys(
            FIELD_DECKS
        )[0]
    );

function getTimeText() {
    return new Date()
        .toLocaleTimeString(
            "zh-TW",
            {
                hour12: false
            }
        );
}

function addHistory(entry) {
    state.history.unshift({
        ...entry,
        time: getTimeText()
    });

    state.history =
        state.history.slice(
            0,
            100
        );
}

function resetField(
    fieldName,
    needConfirm = true
) {
    if (
        needConfirm &&
        !confirm(
            "確定清除目前進度並載入此場地？"
        )
    ) {
        elements.field.value =
            state.field;

        return;
    }

    state = createFreshState(
        fieldName
    );

    saveState();
    render();
}

/*
 * 公牌清單左鍵／點一下：
 * 將該牌視為已公開，從剩餘清單扣除。
 */
function revealCard(cardName) {
    if (
        state.remaining[cardName] <= 0
    ) {
        return;
    }

    state.remaining[cardName] -= 1;

    addHistory({
        action: "reveal",
        card: cardName
    });

    saveState();
    render();
}

/*
 * 手機長按或電腦右鍵：
 * 將公牌移至我方手牌。
 */
function addCardToMyHand(cardName) {
    if (
        state.remaining[cardName] <= 0
    ) {
        return;
    }

    state.remaining[cardName] -= 1;
    state.myHand[cardName] += 1;

    addHistory({
        action: "add_my_hand",
        card: cardName
    });

    saveState();
    render();
}

/*
 * 點擊我方手牌：
 * 代表我方已經打出這張牌。
 *
 * 卡片只從我方手牌移除，
 * 不加回 remaining，
 * 因此公牌清單仍維持已扣除狀態。
 */
function playCardFromMyHand(
    cardName
) {
    if (
        state.myHand[cardName] <= 0
    ) {
        return;
    }

    state.myHand[cardName] -= 1;

    addHistory({
        action: "play_my_hand",
        card: cardName
    });

    saveState();
    render();
}

/*
 * 點擊敵方手牌候選：
 * 代表確認對方已打出這張牌。
 *
 * 卡片從敵方候選區移除，
 * 但不加回 remaining。
 */
function playCardFromEnemyCandidates(
    cardName
) {
    if (
        state.enemyCandidates[
            cardName
        ] <= 0
    ) {
        return;
    }

    state.enemyCandidates[
        cardName
    ] -= 1;

    addHistory({
        action: "play_enemy_candidate",
        card: cardName
    });

    saveState();
    render();
}
/*
 * 洗牌：
 * 以目前剩餘公牌建立敵方手牌候選。
 *
 * 我方手牌加入時已經從 remaining 扣除，
 * 所以 remaining 就是：
 *
 * 初始牌庫
 * - 已公開卡片
 * - 我方手牌
 */
function shuffleEnemyCandidates() {
    /*
     * 保存洗牌前狀態，供復原使用。
     */
    const previousRemaining = cloneDeck(
        state.remaining
    );

    const previousEnemyCandidates = cloneDeck(
        state.enemyCandidates
    );

    /*
     * 敵方候選不能覆蓋原本內容。
     *
     * 新候選：
     * 原本敵方候選
     * ＋目前尚未公開的公牌
     */
    const newEnemyCandidates =
        createZeroDeck(
            state.initial
        );

    for (
        const cardName
        of Object.keys(
            state.initial
        )
    ) {
        const oldEnemyCount =
            Number(
                state.enemyCandidates[
                    cardName
                ] || 0
            );

        const currentRemainingCount =
            Number(
                state.remaining[
                    cardName
                ] || 0
            );

        /*
         * 可被敵方持有的最大數量，
         * 必須扣除目前仍在我方手中的牌。
         */
        const maximumEnemyCount =
            Math.max(
                0,
                Number(
                    state.initial[
                        cardName
                    ] || 0
                ) -
                Number(
                    state.myHand[
                        cardName
                    ] || 0
                )
            );

        newEnemyCandidates[
            cardName
        ] = Math.min(
            maximumEnemyCount,
            oldEnemyCount +
            currentRemainingCount
        );
    }

    /*
     * 重建公牌清單：
     *
     * 初始牌庫
     * －我方目前手牌
     * －累加後的敵方候選
     *
     * 先前已打出／公開的牌會重新回到公牌。
     */
    const resetDeck = cloneDeck(
        state.initial
    );

    for (
        const cardName
        of Object.keys(
            state.initial
        )
    ) {
        resetDeck[
            cardName
        ] = Math.max(
            0,
            Number(
                state.initial[
                    cardName
                ] || 0
            ) -
            Number(
                state.myHand[
                    cardName
                ] || 0
            ) -
            Number(
                newEnemyCandidates[
                    cardName
                ] || 0
            )
        );
    }

    state.enemyCandidates =
        newEnemyCandidates;

    state.remaining =
        resetDeck;

    addHistory({
        action: "shuffle",
        previousRemaining,
        previousEnemyCandidates
    });

    saveState();
    render();
}

function undoLastAction() {
    const lastAction =
        state.history.shift();

    if (!lastAction) {
        return;
    }

    const cardName =
        lastAction.card;

    switch (lastAction.action) {
        case "reveal":
            state.remaining[
                cardName
            ] += 1;
            break;

        case "add_my_hand":
            state.myHand[
                cardName
            ] -= 1;

            state.remaining[
                cardName
            ] += 1;
            break;

        case "play_my_hand":
            state.myHand[
                cardName
            ] += 1;
            break;

        case "play_enemy_candidate":
            state.enemyCandidates[
                cardName
            ] += 1;
            break;

        case "shuffle":
            state.remaining = cloneDeck(
                lastAction.previousRemaining ||
                state.initial
            );

            state.enemyCandidates = cloneDeck(
                lastAction.previousEnemyCandidates ||
                createZeroDeck(
                    state.initial
                )
            );
            break;

        default:
            console.warn(
                "無法復原的操作：",
                lastAction
            );
            break;
    }

    saveState();
    render();
}

function parseCardName(cardName) {
    const matches = [
        ...cardName.matchAll(
            /(劍|槍|防|移|特)(\d+)/g
        )
    ];

    return matches.map(
        match => ({
            type: match[1],
            value: Number(
                match[2]
            )
        })
    );
}

function cardContainsType(
    cardName,
    type
) {
    if (!type) {
        return false;
    }

    return parseCardName(
        cardName
    ).some(
        side =>
            side.type === type
    );
}

function getDisplayCardName(
    cardName,
    preferredType = null
) {
    const sides = parseCardName(
        cardName
    );

    if (sides.length < 2) {
        return cardName;
    }

    let [
        top,
        bottom
    ] = sides;

    if (
        preferredType &&
        bottom.type === preferredType &&
        top.type !== preferredType
    ) {
        [
            top,
            bottom
        ] = [
            bottom,
            top
        ];
    }

    return (
        `${top.type}${top.value}` +
        `${bottom.type}${bottom.value}`
    );
}


function createCardFaceHtml(
    cardName,
    preferredType = null
) {
    const sides = parseCardName(
        cardName
    );

    if (sides.length < 2) {
        return `
            <div class="game-card-fallback">
                ${cardName}
            </div>
        `;
    }

    let [
        top,
        bottom
    ] = sides;

    /*
     * 選擇屬性排序時：
     * 若指定屬性只出現在下半部，
     * 將卡片顯示方向上下交換，
     * 讓該屬性統一顯示在上半部。
     *
     * 例如選擇「劍」：
     * 槍1劍2 → 顯示成 劍2／槍1
     */
    if (
        preferredType &&
        bottom.type === preferredType &&
        top.type !== preferredType
    ) {
        [
            top,
            bottom
        ] = [
            bottom,
            top
        ];
    }

    const topConfig =
        CARD_TYPE_CONFIG[
            top.type
        ];

    const bottomConfig =
        CARD_TYPE_CONFIG[
            bottom.type
        ];

    if (
        !topConfig ||
        !bottomConfig
    ) {
        return `
            <div class="game-card-fallback">
                ${cardName}
            </div>
        `;
    }

    return `
        <div class="game-card">
            <div
                class="
                    game-card-half
                    game-card-top
                "
            >
                <img
                    src="${topConfig.image}"
                    alt="${top.type}${top.value}"
                    draggable="false"
                >

                <span class="game-card-value">
                    ${top.value}
                </span>
            </div>

            <div
                class="
                    game-card-half
                    game-card-bottom
                "
            >
                <img
                    src="${bottomConfig.image}"
                    alt="${bottom.type}${bottom.value}"
                    draggable="false"
                >

                <span class="game-card-value">
                    ${bottom.value}
                </span>
            </div>

            <div class="game-card-divider"></div>
        </div>
    `;
}

function sortCardNames(
    cardNames,
    countDeck
) {
    return [
        ...cardNames
    ].sort(
        (
            a,
            b
        ) => {
            const aCount = Number(
                countDeck[a] || 0
            );

            const bCount = Number(
                countDeck[b] || 0
            );

            const aEmpty =
                aCount === 0;

            const bEmpty =
                bCount === 0;

            /*
             * 數量為 0 的卡片固定排到最後。
             */
            if (
                aEmpty !== bEmpty
            ) {
                return aEmpty
                    ? 1
                    : -1;
            }

            /*
             * 有選擇屬性時，
             * 含該屬性的牌排在前方。
             */
            if (state.sortType) {
                const aMatch =
                    cardContainsType(
                        a,
                        state.sortType
                    );

                const bMatch =
                    cardContainsType(
                        b,
                        state.sortType
                    );

                if (
                    aMatch !== bMatch
                ) {
                    return aMatch
                        ? -1
                        : 1;
                }
            }

            return a.localeCompare(
                b,
                "zh-Hant"
            );
        }
    );
}

function createCardButtonHtml({
    cardName,
    current,
    maximum,
    className = "",
    compact = false,
    metaText = ""
}) {
    const displayCardName =
        getDisplayCardName(
            cardName,
            state.sortType
        );

    return `
        <button
            class="
                card
                game-card-button
                ${
                    compact
                        ? "compact-card"
                        : ""
                }
                ${className}
            "
            data-card="${cardName}"
            title="${displayCardName}"
        >
            <div class="game-card-preview">
                ${
                    createCardFaceHtml(
                        cardName,
                        state.sortType
                    )
                }
            </div>

            <div class="game-card-info">
                <div class="n">
                    ${displayCardName}
                </div>

                <div class="c">
                    ${current} / ${maximum}
                </div>

                <div class="m">
                    ${metaText}
                </div>
            </div>
        </button>
    `;
}

function bindPublicCardActions() {
    elements.cards
        .querySelectorAll(".card")
        .forEach(button => {
            let longPressTimer = null;
            let longPressed = false;

            /*
             * 在任何畫面重繪前先保存這張按鈕
             * 所代表的原始牌名，避免排序改變後抓錯卡。
             */
            const cardName =
                button.dataset.card;

            const cancelLongPress = () => {
                if (longPressTimer) {
                    clearTimeout(
                        longPressTimer
                    );

                    longPressTimer = null;
                }
            };

            button.addEventListener(
                "pointerdown",
                event => {
                    /*
                     * 滑鼠只有左鍵需要偵測長按。
                     * 右鍵交給 contextmenu。
                     */
                    if (
                        event.pointerType === "mouse" &&
                        event.button !== 0
                    ) {
                        return;
                    }

                    longPressed = false;

                    longPressTimer =
                        window.setTimeout(
                            () => {
                                longPressed = true;

                                addCardToMyHand(
                                    cardName
                                );

                                if (
                                    navigator.vibrate
                                ) {
                                    navigator.vibrate(
                                        40
                                    );
                                }
                            },
                            600
                        );
                }
            );

            button.addEventListener(
                "pointerup",
                event => {
                    cancelLongPress();

                    /*
                     * 滑鼠右鍵不能執行公開扣除。
                     */
                    if (
                        event.pointerType === "mouse" &&
                        event.button !== 0
                    ) {
                        return;
                    }

                    if (longPressed) {
                        event.preventDefault();
                        return;
                    }

                    /*
                     * 左鍵／手機短按：
                     * 公開扣除。
                     */
                    revealCard(
                        cardName
                    );
                }
            );

            button.addEventListener(
                "pointerleave",
                cancelLongPress
            );

            button.addEventListener(
                "pointercancel",
                cancelLongPress
            );

            /*
             * 電腦右鍵：
             * 加入我方手牌。
             */
            button.addEventListener(
                "contextmenu",
                event => {
                    event.preventDefault();
                    cancelLongPress();

                    addCardToMyHand(
                        cardName
                    );
                }
            );
        });
}

function renderPublicCards() {
    const unknownTotal =
        getTotal(
            state.remaining
        );

    const cardNames =
        sortCardNames(
            Object.keys(
                state.initial
            ),
            state.remaining
        );

    elements.cards.innerHTML =
        cardNames
            .map(cardName => {
                const current =
                    state.remaining[
                        cardName
                    ];

                const maximum =
                    state.initial[
                        cardName
                    ];

                let className = "";

                if (
                    current === 0
                ) {
                    className =
                        "zero";

                } else if (
                    current /
                    maximum
                    <= 0.34
                ) {
                    className =
                        "low";
                }

                const probability =
                    unknownTotal > 0
                        ? (
                            current /
                            unknownTotal *
                            100
                        )
                        : 0;

                return createCardButtonHtml({
                    cardName,
                    current,
                    maximum,
                    className,
                    metaText:
                        "未公開占比 " +
                        probability
                            .toFixed(1) +
                        "%"
                });
            })
            .join("");

    bindPublicCardActions();
}

function renderMyHand() {
    const names =
        sortCardNames(
            Object.keys(
                state.myHand
            ).filter(
                cardName =>
                    state.myHand[
                        cardName
                    ] > 0
            ),
            state.myHand
        );

    if (!names.length) {
        elements.myHand.innerHTML = `
            <div class="zone-empty">
                尚未加入我方手牌
            </div>
        `;

        return;
    }

    elements.myHand.innerHTML =
        names
            .map(cardName => {
                return createCardButtonHtml({
                    cardName,

                    current:
                        state.myHand[
                            cardName
                        ],

                    maximum:
                        state.initial[
                            cardName
                        ],

                    className:
                        "in-hand",

                    compact: true,

                    metaText:
                        ""
                });
            })
            .join("");

    elements.myHand
        .querySelectorAll(".card")
        .forEach(button => {
            const cardName =
                button.dataset.card;

            button.addEventListener(
                "click",
                () => {
                    playCardFromMyHand(
                        cardName
                    );
                }
            );

            button.addEventListener(
                "contextmenu",
                event => {
                    event.preventDefault();
                }
            );
        });
}

function renderEnemyCandidates() {
    const names =
        sortCardNames(
            Object.keys(
                state.enemyCandidates
            ).filter(
                cardName =>
                    state.enemyCandidates[
                        cardName
                    ] > 0
            ),
            state.enemyCandidates
        );

    if (!names.length) {
        elements.enemyCandidates.innerHTML = `
            <div class="zone-empty">
                尚未建立敵方手牌候選，請按洗牌
            </div>
        `;

        return;
    }

    const total = getTotal(
        state.enemyCandidates
    );

    elements.enemyCandidates.innerHTML =
        names
            .map(cardName => {
                const count =
                    state.enemyCandidates[
                        cardName
                    ];

                const probability =
                    total > 0
                        ? (
                            count /
                            total *
                            100
                        )
                        : 0;

                return createCardButtonHtml({
                    cardName,

                    current:
                        count,

                    maximum:
                        state.initial[
                            cardName
                        ],

                    className:
                        "enemy-candidate",

                    compact: true,

                    metaText:
                        `
                            <span class="meta-label">
                                候選占比
                            </span>

                            <span class="meta-value">
                                ${probability.toFixed(1)}%
                            </span>
                        `
                });
            })
            .join("");

    elements.enemyCandidates
        .querySelectorAll(".card")
        .forEach(button => {
            const cardName =
                button.dataset.card;

            button.addEventListener(
                "click",
                () => {
                    playCardFromEnemyCandidates(
                        cardName
                    );
                }
            );

            button.addEventListener(
                "contextmenu",
                event => {
                    event.preventDefault();
                }
            );
        });
}

function renderTypeStats() {
    const typeCounts =
        Object.fromEntries(
            TYPE_ORDER.map(
                type => [
                    type,
                    0
                ]
            )
        );

    for (
        const [
            cardName,
            count
        ]
        of Object.entries(
            state.remaining
        )
    ) {
        const matches =
            cardName.matchAll(
                /(劍|槍|防|移|特)\d+/g
            );

        for (
            const match
            of matches
        ) {
            typeCounts[
                match[1]
            ] += count;
        }
    }

    elements.types.innerHTML =
        TYPE_ORDER
            .map(type => {
                const active =
                    state.sortType
                    === type;

                return `
                    <button
                        class="
                            type-button
                            ${
                                active
                                    ? "active"
                                    : ""
                            }
                        "
                        data-type="${type}"
                    >
                        <strong>
                            ${type}
                        </strong>

                        <span>
                            ${typeCounts[type]}
                        </span>
                    </button>
                `;
            })
            .join("");

    elements.types
        .querySelectorAll(
            ".type-button"
        )
        .forEach(button => {
            button.addEventListener(
                "click",
                () => {
                    const type =
                        button
                            .dataset
                            .type;

                    state.sortType =
                        state.sortType
                        === type
                            ? null
                            : type;

                    saveState();
                    render();
                }
            );
        });
}

function getHistoryDescription(item) {
    switch (item.action) {
        case "reveal":
            return {
                title:
                    `公開扣除：${item.card}`,
                amount: "−1"
            };

        case "add_my_hand":
            return {
                title:
                    `加入我方手牌：${item.card}`,
                amount: "→手牌"
            };

        case "play_my_hand":
            return {
                title:
                    `我方出牌：${item.card}`,
                amount: "已出牌"
            };

        case "play_enemy_candidate":
            return {
                title:
                    `敵方出牌：${item.card}`,
                amount: "已出牌"
            };

        case "shuffle":
            return {
                title:
                    "重新建立敵方手牌候選",
                amount:
                    "洗牌"
            };

        default:
            return {
                title:
                    "未知操作",
                amount:
                    ""
            };
    }
}

function renderHistory() {
    if (
        !state.history.length
    ) {
        elements.history.innerHTML =
            '<div class="empty">尚無操作紀錄</div>';

        return;
    }

    elements.history.innerHTML =
        state.history
            .slice(
                0,
                20
            )
            .map(item => {
                const description =
                    getHistoryDescription(
                        item
                    );

                return `
                    <div class="hist">
                        <div>
                            <b>
                                ${description.title}
                            </b>

                            <br>

                            <small>
                                ${item.time || ""}
                            </small>
                        </div>

                        <span>
                            ${description.amount}
                        </span>
                    </div>
                `;
            })
            .join("");
}

function renderFieldOptions() {
    elements.field.innerHTML =
        Object.values(
            FIELD_GROUPS
        )
            .map(group => {
                const options =
                    group.fields
                        .map(
                            fieldName => {
                                const hasDeck =
                                    Boolean(
                                        FIELD_DECKS[
                                            fieldName
                                        ]
                                    );

                                return `
                                    <option
                                        value="${fieldName}"
                                        ${
                                            hasDeck
                                                ? ""
                                                : "disabled"
                                        }
                                    >
                                        ${fieldName}
                                        ${
                                            hasDeck
                                                ? ""
                                                : "（尚無牌組資料）"
                                        }
                                    </option>
                                `;
                            }
                        )
                        .join("");

                return `
                    <optgroup
                        label="【${group.label}】"
                    >
                        ${options}
                    </optgroup>
                `;
            })
            .join("");

    elements.field.value =
        state.field;
}

function renderSummary() {
    const initialTotal =
        getTotal(
            state.initial
        );

    const deckLeft =
        getTotal(
            state.remaining
        );

    const myHandTotal =
        getTotal(
            state.myHand
        );

    const enemyCandidateTotal =
        getTotal(
            state.enemyCandidates
        );

    const seenTotal =
        Math.max(
            0,
            initialTotal -
            deckLeft -
            myHandTotal
        );

    elements.initial.textContent =
        initialTotal;

    elements.deckLeft.textContent =
        deckLeft;

    elements.myHandCount.textContent =
        myHandTotal;

    elements
        .enemyCandidateCount
        .textContent =
            enemyCandidateTotal;

    elements.seen.textContent =
        seenTotal;
}

function render() {
    renderFieldOptions();
    renderSummary();
    renderEnemyCandidates();
    renderPublicCards();
    renderMyHand();
    renderTypeStats();
    renderHistory();

    elements.undo.disabled =
        state.history.length === 0;
}

elements.field.addEventListener(
    "change",
    () => {
        resetField(
            elements.field.value
        );
    }
);

elements.undo.addEventListener(
    "click",
    undoLastAction
);

elements.shuffle.addEventListener(
    "click",
    shuffleEnemyCandidates
);

elements.reset.addEventListener(
    "click",
    () => {
        resetField(
            state.field
        );
    }
);

elements.clear.addEventListener(
    "click",
    () => {
        state.history = [];

        saveState();
        render();
    }
);

render();