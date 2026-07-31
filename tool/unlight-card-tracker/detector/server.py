from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path
from threading import Lock
from typing import Any

from fastapi import FastAPI, Query
from fastapi.responses import (
    FileResponse,
    JSONResponse,
    RedirectResponse,
)
from fastapi.staticfiles import StaticFiles

from client_profile import ClientProfileStatus
from event_store import (
    CursorExpiredError,
    EventStore,
    SequenceGapError,
    SessionNotFoundError,
)


API_VERSION = "v1"
DEFAULT_EVENT_LIMIT = 100
MAX_EVENT_LIMIT = 1000
WARNING_THRESHOLD_BYTES = 256 * 1024 * 1024
CRITICAL_THRESHOLD_BYTES = 1024 * 1024 * 1024
TRACKER_ROOT = Path(__file__).resolve().parent.parent


@dataclass
class RuntimeStatus:
    detector: str = "initializing"
    error: str | None = None

    def __post_init__(self) -> None:
        self._lock = Lock()

    def update(
        self,
        *,
        detector: str,
        error: str | None = None,
    ) -> None:
        with self._lock:
            self.detector = detector
            self.error = error

    def snapshot(self) -> dict[str, str | None]:
        with self._lock:
            return {
                "detector": self.detector,
                "error": self.error,
            }


def error_response(
    *,
    status_code: int,
    code: str,
    message: str,
    details: dict[str, Any] | None = None,
) -> JSONResponse:
    return JSONResponse(
        status_code=status_code,
        content={
            "error": {
                "code": code,
                "message": message,
                "details": details or {},
            }
        },
    )


def create_app(
    *,
    event_store: EventStore,
    client_profile: ClientProfileStatus,
    runtime_status: RuntimeStatus,
) -> FastAPI:
    app = FastAPI(
        title="UL.GG Card Tracker API",
        version="1.0.0",
    )

    @app.get("/api/v1/health")
    def health() -> dict[str, Any]:
        session = event_store.get_current_session()
        storage = event_store.storage_summary()
        size = storage["database_size_bytes"]
        if size >= CRITICAL_THRESHOLD_BYTES:
            storage_level = "critical"
        elif size >= WARNING_THRESHOLD_BYTES:
            storage_level = "warning"
        else:
            storage_level = "normal"

        return {
            "api_version": API_VERSION,
            "server": "ready",
            **runtime_status.snapshot(),
            "session_id": (
                session["session_id"] if session is not None else None
            ),
            "client_profile": client_profile.to_dict(),
            **storage,
            "warning_threshold_bytes": WARNING_THRESHOLD_BYTES,
            "critical_threshold_bytes": CRITICAL_THRESHOLD_BYTES,
            "storage_status": storage_level,
            "storage_warning": storage_level != "normal",
        }

    @app.get("/api/v1/sessions/current")
    def current_session() -> Any:
        session = event_store.get_current_session()
        if session is None:
            return error_response(
                status_code=404,
                code="NO_CURRENT_SESSION",
                message="No running producer session exists.",
            )
        return {
            "api_version": API_VERSION,
            "session": session,
        }

    @app.get("/api/v1/events")
    def events(
        session_id: str,
        after_sequence: int = Query(default=0, ge=0),
        limit: int = Query(
            default=DEFAULT_EVENT_LIMIT,
            ge=1,
            le=MAX_EVENT_LIMIT,
        ),
    ) -> Any:
        try:
            page = event_store.read_events(
                session_id=session_id,
                after_sequence=after_sequence,
                limit=limit,
            )
        except SessionNotFoundError:
            return error_response(
                status_code=404,
                code="SESSION_NOT_FOUND",
                message="The requested producer session does not exist.",
                details={"session_id": session_id},
            )
        except CursorExpiredError as error:
            return error_response(
                status_code=409,
                code="CURSOR_EXPIRED",
                message="The requested cursor is older than retained events.",
                details={
                    "session_id": session_id,
                    "after_sequence": after_sequence,
                    "retention_start_sequence": (
                        error.retention_start_sequence
                    ),
                },
            )
        except SequenceGapError as error:
            return error_response(
                status_code=409,
                code="SEQUENCE_GAP",
                message="The event sequence contains a gap.",
                details={
                    "session_id": session_id,
                    "after_sequence": after_sequence,
                    "expected_sequence": error.expected_sequence,
                    "actual_sequence": error.actual_sequence,
                },
            )

        return {
            "api_version": API_VERSION,
            **page,
        }

    @app.get(
        "/",
        include_in_schema=False,
    )
    def tracker_root_redirect() -> RedirectResponse:
        return RedirectResponse(
            url="/tracker/",
            status_code=302,
        )

    @app.get(
        "/tracker/",
        include_in_schema=False,
    )
    def tracker_index() -> FileResponse:
        return FileResponse(
            TRACKER_ROOT / "control.html",
            media_type="text/html",
        )

    @app.get(
        "/tracker/field-decks.js",
        include_in_schema=False,
    )
    def tracker_field_decks() -> FileResponse:
        return FileResponse(
            TRACKER_ROOT / "field-decks.js",
            media_type="text/javascript",
        )

    @app.get(
        "/tracker/tracker.js",
        include_in_schema=False,
    )
    def tracker_script() -> FileResponse:
        return FileResponse(
            TRACKER_ROOT / "tracker.js",
            media_type="text/javascript",
        )

    @app.get(
        "/tracker/tracker-db.js",
        include_in_schema=False,
    )
    def tracker_database_script() -> FileResponse:
        return FileResponse(
            TRACKER_ROOT / "tracker-db.js",
            media_type="text/javascript",
        )

    @app.get(
        "/tracker/tracker-api.js",
        include_in_schema=False,
    )
    def tracker_api_script() -> FileResponse:
        return FileResponse(
            TRACKER_ROOT / "tracker-api.js",
            media_type="text/javascript",
        )

    @app.get(
        "/tracker/observation-poller.js",
        include_in_schema=False,
    )
    def observation_poller_script() -> FileResponse:
        return FileResponse(
            TRACKER_ROOT / "observation-poller.js",
            media_type="text/javascript",
        )

    app.mount(
        "/tracker/assets",
        StaticFiles(
            directory=str(TRACKER_ROOT / "assets"),
        ),
        name="tracker-assets",
    )

    return app
