const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const test = require("node:test");
const vm = require("node:vm");


function loadPoller() {
    const sandbox = {
        setTimeout,
        clearTimeout
    };
    vm.runInNewContext(
        fs.readFileSync(
            path.resolve(
                __dirname,
                "..",
                "..",
                "..",
                "observation-poller.js"
            ),
            "utf8"
        ),
        sandbox
    );
    return sandbox.ObservationPoller;
}


function loadingEvent(
    sequence,
    isLoading,
    mode = "change"
) {
    return {
        event_id: `event-${sequence}`,
        session_id: "session-1",
        sequence,
        event_type: "loading_observed",
        payload: {
            is_loading: isLoading,
            observation_mode: mode
        },
        confidence: 0.9,
        occurred_at: `time-${sequence}`
    };
}


function page(
    events,
    {
        sessionId = "session-1",
        nextSequence = (
            events.length > 0
                ? events.at(-1).sequence
                : 0
        ),
        hasMore = false
    } = {}
) {
    return {
        session_id: sessionId,
        after_sequence: 0,
        events,
        next_sequence: nextSequence,
        has_more: hasMore,
        retention_start_sequence: 1
    };
}


function view() {
    return {
        states: [],
        render(state) {
            this.states.push(state);
        }
    };
}


function storedCheckpoint(
    sessionId = "session-1",
    sequence = 0
) {
    return {
        consumer_id: "observation-poller-v1",
        session_id: sessionId,
        after_sequence: sequence,
        updated_at: "2026-07-27T00:00:00.000Z",
        commit_token: `commit-${sequence}`,
        consumer_schema_version: 1
    };
}


function checkpointStore({
    initial = null,
    saveError = null,
    openError = null,
    loadError = null,
    onSave = null
} = {}) {
    let record = initial;
    return {
        saveCalls: [],
        async openTrackerDatabase() {
            if (openError) {
                throw openError;
            }
            return {};
        },
        async loadConsumerCheckpoint() {
            if (loadError) {
                throw loadError;
            }
            return record;
        },
        async saveConsumerCheckpoint(value) {
            this.saveCalls.push(value);
            onSave?.(value);
            if (saveError) {
                throw saveError;
            }
            record = value;
            return value;
        }
    };
}


test("starts session, consumes loading event, and advances cursor", async () => {
    const { createObservationPoller } = loadPoller();
    const rendered = view();
    const poller = createObservationPoller({
        api: {
            async getCurrentSession() {
                return { session_id: "session-1" };
            },
            async getEvents(options) {
                assert.equal(options.afterSequence, 0);
                return page([
                    loadingEvent(
                        1,
                        true,
                        "initial_baseline"
                    )
                ]);
            }
        },
        view: rendered
    });

    const state = await poller.pollOnce();
    assert.equal(state.connection, "connected");
    assert.equal(state.sessionId, "session-1");
    assert.equal(state.cursor, 1);
    assert.equal(state.loading, true);
    assert.equal(
        state.observationMode,
        "initial_baseline"
    );
    assert.equal(state.confidence, 0.9);
});


test("drains has_more pages without re-consuming duplicates", async () => {
    const { createObservationPoller } = loadPoller();
    let call = 0;
    const poller = createObservationPoller({
        api: {
            async getCurrentSession() {
                return { session_id: "session-1" };
            },
            async getEvents({ afterSequence }) {
                call += 1;
                if (call === 1) {
                    assert.equal(afterSequence, 0);
                    return page(
                        [loadingEvent(1, true)],
                        { hasMore: true }
                    );
                }
                assert.equal(afterSequence, 1);
                return page(
                    [
                        loadingEvent(1, false),
                        loadingEvent(2, false)
                    ],
                    { nextSequence: 2 }
                );
            }
        },
        view: view()
    });

    const first = await poller.pollOnce();
    assert.equal(first.cursor, 1);
    assert.equal(first.hasMore, true);

    const second = await poller.pollOnce();
    assert.equal(second.cursor, 2);
    assert.equal(second.loading, false);
    assert.equal(second.lastEventTime, "time-2");
});


test("session change resets cursor before reading new session", async () => {
    const { createObservationPoller } = loadPoller();
    let sessionId = "session-1";
    const seenQueries = [];
    const poller = createObservationPoller({
        api: {
            async getCurrentSession() {
                return { session_id: sessionId };
            },
            async getEvents(options) {
                seenQueries.push(options);
                return page(
                    options.sessionId === "session-1"
                        ? [loadingEvent(1, true)]
                        : [],
                    {
                        sessionId: options.sessionId,
                        nextSequence: 0
                    }
                );
            }
        },
        view: view()
    });

    await poller.pollOnce();
    sessionId = "session-2";
    const changed = await poller.pollOnce();

    assert.equal(changed.sessionId, "session-2");
    assert.equal(changed.cursor, 0);
    assert.equal(changed.sessionChanged, true);
    assert.equal(
        seenQueries.at(-1).afterSequence,
        0
    );
});


test("cursor expired moves only in-memory cursor to retention boundary", async () => {
    const { createObservationPoller } = loadPoller();
    const error = new Error("expired");
    error.code = "CURSOR_EXPIRED";
    error.details = {
        retention_start_sequence: 5
    };
    const poller = createObservationPoller({
        api: {
            async getCurrentSession() {
                return { session_id: "session-1" };
            },
            async getEvents() {
                throw error;
            }
        },
        view: view()
    });

    const state = await poller.pollOnce();
    assert.equal(state.connection, "warning");
    assert.equal(state.cursor, 4);
    assert.match(state.error, /CURSOR_EXPIRED/);
});


test("sequence gap pauses event consumption for current session", async () => {
    const { createObservationPoller } = loadPoller();
    let eventCalls = 0;
    const poller = createObservationPoller({
        api: {
            async getCurrentSession() {
                return { session_id: "session-1" };
            },
            async getEvents() {
                eventCalls += 1;
                return page(
                    [loadingEvent(2, true)],
                    { nextSequence: 2 }
                );
            }
        },
        view: view()
    });

    const failed = await poller.pollOnce();
    assert.equal(failed.connection, "paused");
    assert.equal(failed.cursor, 0);
    assert.match(failed.error, /SEQUENCE_GAP/);

    await poller.pollOnce();
    assert.equal(eventCalls, 1);
});


test("network failures back off from one to five seconds", async () => {
    const { createObservationPoller } = loadPoller();
    const error = new Error("offline");
    error.code = "NETWORK_ERROR";
    const poller = createObservationPoller({
        api: {
            async getCurrentSession() {
                throw error;
            }
        },
        view: view()
    });

    assert.equal(
        (await poller.pollOnce()).retryDelayMs,
        1000
    );
    assert.equal(
        (await poller.pollOnce()).retryDelayMs,
        2000
    );
    await poller.pollOnce();
    assert.equal(
        (await poller.pollOnce()).retryDelayMs,
        5000
    );
});


test("no active session is a nonfatal waiting state", async () => {
    const { createObservationPoller } = loadPoller();
    const error = new Error("no current session");
    error.code = "NO_CURRENT_SESSION";
    const poller = createObservationPoller({
        api: {
            async getCurrentSession() {
                throw error;
            },
            async getEvents() {
                throw new Error("events must not be requested");
            }
        },
        view: view()
    });

    const state = await poller.pollOnce();
    assert.equal(state.connection, "waiting");
    assert.equal(state.cursor, 0);
});


test("hidden pages use the low-frequency polling interval", async () => {
    const { createObservationPoller } = loadPoller();
    const scheduled = [];
    const poller = createObservationPoller({
        api: {
            async getCurrentSession() {
                return { session_id: "session-hidden" };
            },
            async getEvents() {
                return page();
            }
        },
        view: view(),
        isHidden: () => true,
        schedule(callback, delay) {
            scheduled.push({ callback, delay });
            return scheduled.length;
        },
        cancel() {}
    });

    poller.start();
    assert.equal(scheduled[0].delay, 0);
    await scheduled[0].callback();
    assert.equal(scheduled[1].delay, 1000);
    poller.stop();
});


test("malformed event response pauses the current session", async () => {
    const { createObservationPoller } = loadPoller();
    let eventCalls = 0;
    const poller = createObservationPoller({
        api: {
            async getCurrentSession() {
                return { session_id: "session-malformed" };
            },
            async getEvents() {
                eventCalls += 1;
                return {
                    events: [],
                    next_sequence: 2,
                    has_more: false,
                    retention_start_sequence: 1
                };
            }
        },
        view: view()
    });

    const state = await poller.pollOnce();
    assert.equal(state.connection, "paused");
    assert.match(state.error, /next_sequence/i);
    await poller.pollOnce();
    assert.equal(eventCalls, 1);
});


test("restores cursor when checkpoint session matches", async () => {
    const { createObservationPoller } = loadPoller();
    const seen = [];
    const store = checkpointStore({
        initial: storedCheckpoint("session-1", 7)
    });
    const poller = createObservationPoller({
        api: {
            async getCurrentSession() {
                return { session_id: "session-1" };
            },
            async getEvents(query) {
                seen.push(query);
                return page([], {
                    nextSequence: 7
                });
            }
        },
        view: view(),
        checkpointStore: store
    });

    const state = await poller.pollOnce();
    assert.equal(seen[0].afterSequence, 7);
    assert.equal(state.cursor, 7);
    assert.equal(state.persistence, "ready");
    assert.equal(
        state.checkpointCommitToken,
        "commit-7"
    );
    assert.equal(store.saveCalls.length, 0);
});


test("new session resets and persists cursor zero", async () => {
    const { createObservationPoller } = loadPoller();
    const seen = [];
    const store = checkpointStore({
        initial: storedCheckpoint("old-session", 9)
    });
    const poller = createObservationPoller({
        api: {
            async getCurrentSession() {
                return { session_id: "new-session" };
            },
            async getEvents(query) {
                seen.push(query);
                return page([], {
                    sessionId: "new-session"
                });
            }
        },
        view: view(),
        checkpointStore: store
    });

    const state = await poller.pollOnce();
    assert.equal(seen[0].afterSequence, 0);
    assert.equal(state.cursor, 0);
    assert.equal(store.saveCalls.length, 1);
    assert.equal(
        store.saveCalls[0].session_id,
        "new-session"
    );
    assert.equal(
        store.saveCalls[0].after_sequence,
        0
    );
});


test("consumes observations before persisting cursor", async () => {
    const { createObservationPoller } = loadPoller();
    const order = [];
    const event = loadingEvent(1, true);
    let loadingValue = event.payload.is_loading;
    Object.defineProperty(
        event.payload,
        "is_loading",
        {
            get() {
                order.push("consume");
                return loadingValue;
            },
            set(value) {
                loadingValue = value;
            }
        }
    );
    const store = checkpointStore({
        initial: storedCheckpoint("session-1", 0),
        onSave() {
            order.push("persist");
        }
    });
    const poller = createObservationPoller({
        api: {
            async getCurrentSession() {
                return { session_id: "session-1" };
            },
            async getEvents() {
                return page([event], {
                    nextSequence: 1
                });
            }
        },
        view: view(),
        checkpointStore: store
    });

    const state = await poller.pollOnce();
    assert.equal(state.cursor, 1);
    assert.equal(order.at(-1), "persist");
    assert.equal(
        order
            .slice(0, -1)
            .every(item => item === "consume"),
        true
    );
});


test("persist failure does not advance memory cursor", async () => {
    const { createObservationPoller } = loadPoller();
    const error = new Error("quota exceeded");
    error.code = "INDEXEDDB_ERROR";
    const store = checkpointStore({
        initial: storedCheckpoint("session-1", 0),
        saveError: error
    });
    const poller = createObservationPoller({
        api: {
            async getCurrentSession() {
                return { session_id: "session-1" };
            },
            async getEvents() {
                return page(
                    [loadingEvent(1, true)],
                    { nextSequence: 1 }
                );
            }
        },
        view: view(),
        checkpointStore: store
    });

    const state = await poller.pollOnce();
    assert.equal(state.cursor, 0);
    assert.equal(state.connection, "warning");
    assert.equal(
        state.checkpointCommitToken,
        "commit-0"
    );
    assert.equal(state.persistence, "error");
    assert.match(
        state.persistenceError,
        /quota exceeded/
    );
});


test("load failure is visible and starts from the initial cursor", async () => {
    const { createObservationPoller } = loadPoller();
    const error = new Error("read failed");
    error.code = "INDEXEDDB_ERROR";
    const seen = [];
    const store = checkpointStore({
        loadError: error
    });
    const poller = createObservationPoller({
        api: {
            async getCurrentSession() {
                return { session_id: "session-1" };
            },
            async getEvents(query) {
                seen.push(query);
                return page([]);
            }
        },
        view: view(),
        checkpointStore: store
    });

    const state = await poller.pollOnce();
    assert.equal(seen[0].afterSequence, 0);
    assert.equal(state.cursor, 0);
    assert.equal(state.persistence, "error");
    assert.match(state.persistenceError, /read failed/);
});


test("successful checkpoint writes update the commit token", async () => {
    const { createObservationPoller } = loadPoller();
    const tokens = ["initial-token", "event-token"];
    const store = checkpointStore();
    const seenCursors = [];
    let call = 0;
    const poller = createObservationPoller({
        api: {
            async getCurrentSession() {
                return { session_id: "session-1" };
            },
            async getEvents({ afterSequence }) {
                seenCursors.push(afterSequence);
                call += 1;
                return call === 1
                    ? page([])
                    : page(
                        [loadingEvent(1, true)],
                        { nextSequence: 1 }
                    );
            }
        },
        view: view(),
        checkpointStore: store,
        createCommitToken: () => tokens.shift()
    });

    const initial = await poller.pollOnce();
    assert.equal(
        initial.checkpointCommitToken,
        "initial-token"
    );
    const advanced = await poller.pollOnce();
    assert.equal(
        advanced.checkpointCommitToken,
        "event-token"
    );
    assert.deepEqual(
        store.saveCalls.map(
            item => item.commit_token
        ),
        ["initial-token", "event-token"]
    );
    assert.deepEqual(seenCursors, [0, 0]);
});


test("diagnostics render the persisted checkpoint", () => {
    const { createDomView } = loadPoller();
    const elements = new Map();
    const ids = [
        "observation-status",
        "observation-connection",
        "observation-session",
        "observation-cursor",
        "observation-loading",
        "observation-mode",
        "observation-confidence",
        "observation-last-event",
        "observation-error",
        "observation-persistence",
        "observation-checkpoint-session",
        "observation-checkpoint-cursor",
        "observation-checkpoint-token",
        "observation-persistence-error"
    ];
    for (const id of ids) {
        elements.set(id, {
            dataset: {},
            textContent: ""
        });
    }
    const domView = createDomView({
        getElementById(id) {
            return elements.get(id);
        }
    });

    domView.render({
        connection: "connected",
        sessionId: "session-current",
        cursor: 8,
        loading: false,
        observationMode: "change",
        confidence: 0.9,
        lastEventTime: "time-8",
        error: null,
        persistence: "ready",
        checkpointSessionId: "session-checkpoint",
        checkpointCursor: 8,
        checkpointCommitToken: "commit-visible",
        persistenceError: null
    });

    assert.equal(
        elements.get(
            "observation-checkpoint-cursor"
        ).textContent,
        "8"
    );
    assert.equal(
        elements.get(
            "observation-checkpoint-token"
        ).textContent,
        "commit-visible"
    );
    assert.equal(
        elements.get(
            "observation-persistence"
        ).textContent,
        "ready"
    );
});


test("cursor expired persists the retention boundary", async () => {
    const { createObservationPoller } = loadPoller();
    const error = new Error("expired");
    error.code = "CURSOR_EXPIRED";
    error.details = {
        retention_start_sequence: 5
    };
    const store = checkpointStore({
        initial: storedCheckpoint("session-1", 2)
    });
    const poller = createObservationPoller({
        api: {
            async getCurrentSession() {
                return { session_id: "session-1" };
            },
            async getEvents() {
                throw error;
            }
        },
        view: view(),
        checkpointStore: store
    });

    const state = await poller.pollOnce();
    assert.equal(state.cursor, 4);
    assert.equal(
        store.saveCalls.at(-1).after_sequence,
        4
    );
});


test("sequence gap does not persist a checkpoint", async () => {
    const { createObservationPoller } = loadPoller();
    const store = checkpointStore({
        initial: storedCheckpoint("session-1", 0)
    });
    const poller = createObservationPoller({
        api: {
            async getCurrentSession() {
                return { session_id: "session-1" };
            },
            async getEvents() {
                return page(
                    [loadingEvent(2, true)],
                    { nextSequence: 2 }
                );
            }
        },
        view: view(),
        checkpointStore: store
    });

    const state = await poller.pollOnce();
    assert.equal(state.connection, "paused");
    assert.equal(state.cursor, 0);
    assert.equal(store.saveCalls.length, 0);
});


test("unavailable IndexedDB falls back once to memory cursor", async () => {
    const { createObservationPoller } = loadPoller();
    const error = new Error("blocked");
    error.code = "INDEXEDDB_UNAVAILABLE";
    let openCalls = 0;
    const store = checkpointStore({
        openError: error
    });
    const originalOpen =
        store.openTrackerDatabase;
    store.openTrackerDatabase = async () => {
        openCalls += 1;
        return originalOpen();
    };
    const poller = createObservationPoller({
        api: {
            async getCurrentSession() {
                return { session_id: "session-1" };
            },
            async getEvents() {
                return page(
                    [loadingEvent(1, true)],
                    { nextSequence: 1 }
                );
            }
        },
        view: view(),
        checkpointStore: store
    });

    const first = await poller.pollOnce();
    const second = await poller.pollOnce();
    assert.equal(first.cursor, 1);
    assert.equal(second.cursor, 1);
    assert.equal(first.persistence, "unavailable");
    assert.equal(openCalls, 1);
});


test("empty page at current cursor does not write checkpoint", async () => {
    const { createObservationPoller } = loadPoller();
    const store = checkpointStore({
        initial: storedCheckpoint("session-1", 3)
    });
    const poller = createObservationPoller({
        api: {
            async getCurrentSession() {
                return { session_id: "session-1" };
            },
            async getEvents() {
                return page([], {
                    nextSequence: 3
                });
            }
        },
        view: view(),
        checkpointStore: store
    });

    const state = await poller.pollOnce();
    assert.equal(state.cursor, 3);
    assert.equal(store.saveCalls.length, 0);
});
