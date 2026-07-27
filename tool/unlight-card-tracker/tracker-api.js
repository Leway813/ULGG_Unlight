(function (global) {
    "use strict";

    class TrackerApiError extends Error {
        constructor(
            code,
            message,
            {
                status = null,
                details = {},
                cause = null
            } = {}
        ) {
            super(message);
            this.name = "TrackerApiError";
            this.code = code;
            this.status = status;
            this.details = details;
            this.cause = cause;
        }
    }

    function requireObject(value, label) {
        if (
            value === null ||
            typeof value !== "object" ||
            Array.isArray(value)
        ) {
            throw new TrackerApiError(
                "MALFORMED_RESPONSE",
                `${label} must be an object`
            );
        }
        return value;
    }

    function requireInteger(value, label) {
        if (!Number.isInteger(value) || value < 0) {
            throw new TrackerApiError(
                "MALFORMED_RESPONSE",
                `${label} must be a non-negative integer`
            );
        }
        return value;
    }

    function createTrackerApi({
        fetchImpl = global.fetch
            ? global.fetch.bind(global)
            : null,
        apiBase = "/api/v1"
    } = {}) {
        if (typeof fetchImpl !== "function") {
            throw new TypeError("fetchImpl is required");
        }

        async function request(path) {
            let response;
            try {
                response = await fetchImpl(
                    `${apiBase}${path}`,
                    {
                        headers: {
                            "Accept": "application/json"
                        }
                    }
                );
            } catch (error) {
                throw new TrackerApiError(
                    "NETWORK_ERROR",
                    "Unable to reach Tracker API",
                    { cause: error }
                );
            }

            let body;
            try {
                body = JSON.parse(
                    await response.text()
                );
            } catch (error) {
                throw new TrackerApiError(
                    "INVALID_JSON",
                    "Tracker API returned invalid JSON",
                    {
                        status: response.status,
                        cause: error
                    }
                );
            }

            if (!response.ok) {
                const envelope = (
                    body &&
                    typeof body === "object" &&
                    body.error &&
                    typeof body.error === "object"
                )
                    ? body.error
                    : {};

                throw new TrackerApiError(
                    envelope.code || "HTTP_ERROR",
                    envelope.message ||
                        `Tracker API returned HTTP ${response.status}`,
                    {
                        status: response.status,
                        details: envelope.details || {}
                    }
                );
            }

            return requireObject(body, "response");
        }

        async function getCurrentSession() {
            const body = await request(
                "/sessions/current"
            );
            const session = requireObject(
                body.session,
                "session"
            );
            if (
                typeof session.session_id !== "string" ||
                session.session_id.length === 0
            ) {
                throw new TrackerApiError(
                    "MALFORMED_RESPONSE",
                    "session.session_id is required"
                );
            }
            return session;
        }

        async function getEvents({
            sessionId,
            afterSequence = 0,
            limit = 100
        }) {
            if (
                typeof sessionId !== "string" ||
                sessionId.length === 0
            ) {
                throw new TypeError("sessionId is required");
            }
            requireInteger(
                afterSequence,
                "afterSequence"
            );
            if (
                !Number.isInteger(limit) ||
                limit < 1
            ) {
                throw new TypeError(
                    "limit must be a positive integer"
                );
            }

            const query = new URLSearchParams({
                session_id: sessionId,
                after_sequence: String(afterSequence),
                limit: String(limit)
            });
            const body = await request(
                `/events?${query.toString()}`
            );

            if (body.session_id !== sessionId) {
                throw new TrackerApiError(
                    "MALFORMED_RESPONSE",
                    "events response session_id does not match"
                );
            }
            if (!Array.isArray(body.events)) {
                throw new TrackerApiError(
                    "MALFORMED_RESPONSE",
                    "events must be an array"
                );
            }
            requireInteger(
                body.next_sequence,
                "next_sequence"
            );
            requireInteger(
                body.retention_start_sequence,
                "retention_start_sequence"
            );
            if (typeof body.has_more !== "boolean") {
                throw new TrackerApiError(
                    "MALFORMED_RESPONSE",
                    "has_more must be a boolean"
                );
            }

            return body;
        }

        return Object.freeze({
            getCurrentSession,
            getEvents
        });
    }

    global.TrackerApi = Object.freeze({
        TrackerApiError,
        createTrackerApi
    });
})(
    typeof window !== "undefined"
        ? window
        : globalThis
);
