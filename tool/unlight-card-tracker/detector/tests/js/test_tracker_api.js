const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const test = require("node:test");
const vm = require("node:vm");


function loadApi() {
    const sandbox = {
        URLSearchParams
    };
    vm.runInNewContext(
        fs.readFileSync(
            path.resolve(
                __dirname,
                "..",
                "..",
                "..",
                "tracker-api.js"
            ),
            "utf8"
        ),
        sandbox
    );
    return sandbox.TrackerApi;
}


function response({
    status = 200,
    body
}) {
    return {
        ok: status >= 200 && status < 300,
        status,
        async text() {
            return (
                typeof body === "string"
                    ? body
                    : JSON.stringify(body)
            );
        }
    };
}


test("reads current session", async () => {
    const TrackerApi = loadApi();
    const api = TrackerApi.createTrackerApi({
        fetchImpl: async url => {
            assert.equal(
                url,
                "/api/v1/sessions/current"
            );
            return response({
                body: {
                    api_version: "v1",
                    session: {
                        session_id: "session-1"
                    }
                }
            });
        }
    });

    const session = await api.getCurrentSession();
    assert.equal(session.session_id, "session-1");
});


test("sends cursor event query and validates page", async () => {
    const TrackerApi = loadApi();
    const api = TrackerApi.createTrackerApi({
        fetchImpl: async url => {
            const parsed = new URL(
                url,
                "http://tracker.test"
            );
            assert.equal(
                parsed.pathname,
                "/api/v1/events"
            );
            assert.equal(
                parsed.searchParams.get("session_id"),
                "session-1"
            );
            assert.equal(
                parsed.searchParams.get("after_sequence"),
                "7"
            );
            return response({
                body: {
                    session_id: "session-1",
                    after_sequence: 7,
                    events: [],
                    next_sequence: 7,
                    has_more: false,
                    retention_start_sequence: 1
                }
            });
        }
    });

    const page = await api.getEvents({
        sessionId: "session-1",
        afterSequence: 7
    });
    assert.equal(page.next_sequence, 7);
});


test("preserves structured API errors", async () => {
    const TrackerApi = loadApi();
    const api = TrackerApi.createTrackerApi({
        fetchImpl: async () => response({
            status: 409,
            body: {
                error: {
                    code: "CURSOR_EXPIRED",
                    message: "expired",
                    details: {
                        retention_start_sequence: 5
                    }
                }
            }
        })
    });

    await assert.rejects(
        api.getEvents({
            sessionId: "session-1"
        }),
        error => (
            error.code === "CURSOR_EXPIRED" &&
            error.details.retention_start_sequence === 5
        )
    );
});


test("reports network and invalid JSON failures", async () => {
    const TrackerApi = loadApi();
    const networkApi = TrackerApi.createTrackerApi({
        fetchImpl: async () => {
            throw new Error("offline");
        }
    });
    await assert.rejects(
        networkApi.getCurrentSession(),
        error => error.code === "NETWORK_ERROR"
    );

    const invalidJsonApi = TrackerApi.createTrackerApi({
        fetchImpl: async () => response({
            body: "not-json"
        })
    });
    await assert.rejects(
        invalidJsonApi.getCurrentSession(),
        error => error.code === "INVALID_JSON"
    );
});
