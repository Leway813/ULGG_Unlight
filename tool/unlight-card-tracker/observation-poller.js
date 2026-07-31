(function (global) {
    "use strict";

    const ACTIVE_INTERVAL_MS = 500;
    const IDLE_INTERVAL_MS = 1000;
    const MAX_RETRY_MS = 5000;
    const CONSUMER_ID = "observation-poller-v1";
    const CONSUMER_SCHEMA_VERSION = 1;
    let fallbackCommitCounter = 0;

    function defaultCommitToken() {
        if (
            typeof global.crypto?.randomUUID ===
            "function"
        ) {
            return global.crypto.randomUUID();
        }
        fallbackCommitCounter += 1;
        return [
            Date.now().toString(36),
            fallbackCommitCounter.toString(36)
        ].join("-");
    }

    function initialState() {
        return {
            connection: "connecting",
            sessionId: null,
            cursor: 0,
            loading: null,
            observationMode: null,
            confidence: null,
            lastEventTime: null,
            error: null,
            sessionChanged: false,
            hasMore: false,
            retryDelayMs: 0,
            persistence: "initializing",
            persistenceError: null,
            checkpointSessionId: null,
            checkpointCursor: 0,
            checkpointCommitToken: null
        };
    }

    function copyState(state) {
        return { ...state };
    }

    function createObservationPoller({
        api,
        view,
        schedule = (callback, delay) =>
            global.setTimeout(callback, delay),
        cancel = timer =>
            global.clearTimeout(timer),
        isHidden = () =>
            Boolean(global.document?.hidden),
        eventLimit = 100,
        checkpointStore = null,
        now = () => new Date().toISOString(),
        createCommitToken = defaultCommitToken
    }) {
        if (!api || !view) {
            throw new TypeError(
                "api and view are required"
            );
        }

        const state = initialState();
        let running = false;
        let inFlight = false;
        let timer = null;
        let retryDelayMs = 0;
        let pausedSessionId = null;
        let persistenceEnabled = (
            checkpointStore !== null
        );
        let persistenceInitialized = false;
        let persistedCheckpoint = null;

        function render() {
            view.render(copyState(state));
        }

        function setError(error) {
            state.error = (
                error && error.code
                    ? `${error.code}: ${error.message}`
                    : String(error)
            );
        }

        function setPersistenceFailure(error) {
            state.persistence = (
                error?.code === "INDEXEDDB_UNAVAILABLE"
                    ? "unavailable"
                    : "error"
            );
            state.persistenceError = (
                error && error.code
                    ? `${error.code}: ${error.message}`
                    : String(error)
            );
            persistenceEnabled = false;
        }

        async function initializePersistence() {
            if (persistenceInitialized) {
                return;
            }
            persistenceInitialized = true;

            if (!checkpointStore) {
                state.persistence = "unavailable";
                state.persistenceError =
                    "memory-only";
                persistenceEnabled = false;
                return;
            }

            try {
                await checkpointStore
                    .openTrackerDatabase();
                persistedCheckpoint =
                    await checkpointStore
                        .loadConsumerCheckpoint(
                            CONSUMER_ID
                        );
                state.persistence = "ready";
                state.persistenceError = null;
                if (persistedCheckpoint) {
                    state.checkpointSessionId =
                        persistedCheckpoint.session_id;
                    state.checkpointCursor =
                        persistedCheckpoint
                            .after_sequence;
                    state.checkpointCommitToken =
                        persistedCheckpoint
                            .commit_token;
                }
            } catch (error) {
                setPersistenceFailure(error);
            }
        }

        async function persistCheckpoint(
            sessionId,
            afterSequence
        ) {
            if (!persistenceEnabled) {
                return true;
            }
            if (
                persistedCheckpoint &&
                persistedCheckpoint.session_id ===
                    sessionId &&
                persistedCheckpoint.after_sequence ===
                    afterSequence
            ) {
                return true;
            }

            const checkpoint = {
                consumer_id: CONSUMER_ID,
                session_id: sessionId,
                after_sequence: afterSequence,
                updated_at: now(),
                commit_token: createCommitToken(),
                consumer_schema_version:
                    CONSUMER_SCHEMA_VERSION
            };

            try {
                persistedCheckpoint =
                    await checkpointStore
                        .saveConsumerCheckpoint(
                            checkpoint
                        );
                state.persistence = "ready";
                state.persistenceError = null;
                state.checkpointSessionId =
                    persistedCheckpoint.session_id;
                state.checkpointCursor =
                    persistedCheckpoint.after_sequence;
                state.checkpointCommitToken =
                    persistedCheckpoint.commit_token;
                return true;
            } catch (error) {
                setPersistenceFailure(error);
                return false;
            }
        }

        function validateEvent(event) {
            if (
                !event ||
                typeof event !== "object" ||
                !Number.isInteger(event.sequence) ||
                event.sequence < 1 ||
                typeof event.event_type !== "string"
            ) {
                const error = new Error(
                    "Malformed observation event"
                );
                error.code = "MALFORMED_RESPONSE";
                throw error;
            }
        }

        function consumeEvent(event) {
            if (event.event_type !== "loading_observed") {
                return;
            }

            const payload = event.payload;
            if (
                !payload ||
                typeof payload.is_loading !== "boolean" ||
                typeof payload.observation_mode !== "string" ||
                typeof event.confidence !== "number"
            ) {
                const error = new Error(
                    "Malformed loading_observed payload"
                );
                error.code = "MALFORMED_RESPONSE";
                throw error;
            }

            state.loading = payload.is_loading;
            state.observationMode =
                payload.observation_mode;
            state.confidence = event.confidence;
            state.lastEventTime =
                event.occurred_at || null;
        }

        async function pollPage() {
            await initializePersistence();
            const session = await api.getCurrentSession();
            const nextSessionId = session.session_id;

            state.sessionChanged = (
                state.sessionId !== null &&
                state.sessionId !== nextSessionId
            );

            if (state.sessionId !== nextSessionId) {
                const canRestore = (
                    state.sessionId === null &&
                    persistedCheckpoint &&
                    persistedCheckpoint.session_id ===
                        nextSessionId
                );
                state.sessionId = nextSessionId;
                state.cursor = canRestore
                    ? persistedCheckpoint.after_sequence
                    : 0;
                state.loading = null;
                state.observationMode = null;
                state.confidence = null;
                state.lastEventTime = null;
                pausedSessionId = null;

                if (
                    !canRestore &&
                    !await persistCheckpoint(
                        state.sessionId,
                        0
                    )
                ) {
                    state.connection = "warning";
                    state.hasMore = false;
                    render();
                    return;
                }
            }

            if (pausedSessionId === state.sessionId) {
                state.connection = "paused";
                state.hasMore = false;
                render();
                return;
            }

            const page = await api.getEvents({
                sessionId: state.sessionId,
                afterSequence: state.cursor,
                limit: eventLimit
            });

            let processedSequence = state.cursor;
            for (const event of page.events) {
                validateEvent(event);

                if (event.sequence <= processedSequence) {
                    continue;
                }
                if (event.sequence !== processedSequence + 1) {
                    const error = new Error(
                        "Observation sequence gap"
                    );
                    error.code = "SEQUENCE_GAP";
                    error.details = {
                        expected_sequence:
                            processedSequence + 1,
                        actual_sequence: event.sequence
                    };
                    throw error;
                }

                consumeEvent(event);
                processedSequence = event.sequence;
            }

            if (page.next_sequence !== processedSequence) {
                const error = new Error(
                    "next_sequence does not match processed events"
                );
                error.code = "MALFORMED_RESPONSE";
                throw error;
            }

            if (
                page.next_sequence !== state.cursor &&
                !await persistCheckpoint(
                    state.sessionId,
                    page.next_sequence
                )
            ) {
                state.connection = "warning";
                state.hasMore = false;
                render();
                return;
            }

            state.cursor = page.next_sequence;
            state.hasMore = page.has_more;
            state.connection = "connected";
            state.error = (
                state.sessionChanged
                    ? "session changed"
                    : null
            );
            state.retryDelayMs = 0;
            retryDelayMs = 0;
            render();
        }

        async function handleFailure(error) {
            state.hasMore = false;
            setError(error);

            if (error.code === "NO_CURRENT_SESSION") {
                state.connection = "waiting";
                retryDelayMs = IDLE_INTERVAL_MS;
            } else if (error.code === "CURSOR_EXPIRED") {
                const retentionStart = Number(
                    error.details?.retention_start_sequence
                );
                state.connection = "warning";
                if (
                    Number.isInteger(retentionStart) &&
                    retentionStart >= 1
                ) {
                    const recoveryCursor =
                        retentionStart - 1;
                    if (
                        await persistCheckpoint(
                            state.sessionId,
                            recoveryCursor
                        )
                    ) {
                        state.cursor = recoveryCursor;
                    }
                }
                retryDelayMs = IDLE_INTERVAL_MS;
            } else if (
                error.code === "SEQUENCE_GAP" ||
                error.code === "MALFORMED_RESPONSE" ||
                error.code === "INVALID_JSON"
            ) {
                state.connection = "paused";
                pausedSessionId = state.sessionId;
                retryDelayMs = IDLE_INTERVAL_MS;
            } else {
                state.connection = "offline";
                retryDelayMs = Math.min(
                    retryDelayMs > 0
                        ? retryDelayMs * 2
                        : 1000,
                    MAX_RETRY_MS
                );
            }

            state.retryDelayMs = retryDelayMs;
            render();
        }

        async function pollOnce() {
            if (inFlight) {
                return copyState(state);
            }

            inFlight = true;
            try {
                await initializePersistence();
                await pollPage();
            } catch (error) {
                await handleFailure(error);
            } finally {
                inFlight = false;
            }
            return copyState(state);
        }

        function nextDelay() {
            if (state.hasMore) {
                return 0;
            }
            if (retryDelayMs > 0) {
                return retryDelayMs;
            }
            return isHidden()
                ? IDLE_INTERVAL_MS
                : ACTIVE_INTERVAL_MS;
        }

        async function tick() {
            if (!running) {
                return;
            }
            await pollOnce();
            if (running) {
                timer = schedule(tick, nextDelay());
            }
        }

        function start() {
            if (running) {
                return;
            }
            running = true;
            render();
            timer = schedule(tick, 0);
        }

        function stop() {
            running = false;
            if (timer !== null) {
                cancel(timer);
                timer = null;
            }
        }

        return Object.freeze({
            start,
            stop,
            pollOnce,
            getState: () => copyState(state)
        });
    }

    function createDomView(documentObject) {
        const elements = {
            root: documentObject.getElementById(
                "observation-status"
            ),
            connection: documentObject.getElementById(
                "observation-connection"
            ),
            session: documentObject.getElementById(
                "observation-session"
            ),
            cursor: documentObject.getElementById(
                "observation-cursor"
            ),
            loading: documentObject.getElementById(
                "observation-loading"
            ),
            mode: documentObject.getElementById(
                "observation-mode"
            ),
            confidence: documentObject.getElementById(
                "observation-confidence"
            ),
            lastEvent: documentObject.getElementById(
                "observation-last-event"
            ),
            error: documentObject.getElementById(
                "observation-error"
            ),
            persistence: documentObject.getElementById(
                "observation-persistence"
            ),
            checkpointSession:
                documentObject.getElementById(
                    "observation-checkpoint-session"
                ),
            checkpointCursor:
                documentObject.getElementById(
                    "observation-checkpoint-cursor"
                ),
            checkpointCommitToken:
                documentObject.getElementById(
                    "observation-checkpoint-token"
                ),
            persistenceError:
                documentObject.getElementById(
                    "observation-persistence-error"
            )
        };

        return {
            render(state) {
                elements.root.dataset.status =
                    state.connection;
                elements.connection.textContent =
                    state.connection;
                elements.session.textContent =
                    state.sessionId
                        ? state.sessionId.slice(0, 8)
                        : "—";
                elements.cursor.textContent =
                    String(state.cursor);
                elements.loading.textContent = (
                    state.loading === null
                        ? "—"
                        : state.loading
                            ? "是"
                            : "否"
                );
                elements.mode.textContent =
                    state.observationMode || "—";
                elements.confidence.textContent = (
                    state.confidence === null
                        ? "—"
                        : state.confidence.toFixed(3)
                );
                elements.lastEvent.textContent =
                    state.lastEventTime || "—";
                elements.error.textContent =
                    state.error || "—";
                elements.persistence.textContent =
                    state.persistence;
                elements.checkpointSession.textContent =
                    state.checkpointSessionId
                        ? state.checkpointSessionId.slice(
                            0,
                            8
                        )
                        : "—";
                elements.checkpointCursor.textContent =
                    String(state.checkpointCursor);
                elements.checkpointCommitToken.textContent =
                    state.checkpointCommitToken || "—";
                elements.persistenceError.textContent =
                    state.persistenceError || "—";
            }
        };
    }

    function bootstrap() {
        if (
            !global.document ||
            !global.TrackerApi ||
            !global.document.getElementById(
                "observation-status"
            )
        ) {
            return;
        }

        const poller = createObservationPoller({
            api: global.TrackerApi.createTrackerApi(),
            view: createDomView(global.document),
            checkpointStore: global.TrackerDb || null
        });
        global.observationPoller = poller;
        poller.start();
        global.addEventListener(
            "beforeunload",
            () => poller.stop()
        );
    }

    global.ObservationPoller = Object.freeze({
        createObservationPoller,
        createDomView
    });

    if (global.document) {
        if (global.document.readyState === "loading") {
            global.document.addEventListener(
                "DOMContentLoaded",
                bootstrap,
                { once: true }
            );
        } else {
            bootstrap();
        }
    }
})(
    typeof window !== "undefined"
        ? window
        : globalThis
);
