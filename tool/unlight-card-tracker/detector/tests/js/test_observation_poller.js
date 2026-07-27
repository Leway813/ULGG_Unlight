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
