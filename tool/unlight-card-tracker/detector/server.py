from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path
from threading import Lock
from typing import Any, Literal

from fastapi import FastAPI, Query
from pydantic import BaseModel
from fastapi.responses import (
    FileResponse,
    JSONResponse,
    RedirectResponse,
)
from fastapi.staticfiles import StaticFiles

from client_profile import ClientProfileStatus
from domain_event_schema import (
    DOMAIN_TEMPLATE_SET_VERSION,
    DomainEventValidationError,
    domain_event_to_durable_event,
)
from event_store import (
    CursorExpiredError,
    EventIdentityConflictError,
    EventStore,
    SequenceGapError,
    SessionIdentityConflictError,
    SessionNotFoundError,
)


API_VERSION = "v1"
DEFAULT_EVENT_LIMIT = 100
MAX_EVENT_LIMIT = 1000
WARNING_THRESHOLD_BYTES = 256 * 1024 * 1024
CRITICAL_THRESHOLD_BYTES = 1024 * 1024 * 1024
TRACKER_ROOT = Path(__file__).resolve().parent.parent


class SnifferSessionRegistration(BaseModel):
    session_id: str
    producer_type: Literal["websocket_sniffer"]
    producer_instance: str
    producer_version: str

    class Config:
        extra = "forbid"


class DomainEventSubmission(BaseModel):
    session_id: str
    event: dict[str, Any]

    class Config:
        extra = "forbid"


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

    @app.post("/api/v1/sessions")
    def register_session(request: SnifferSessionRegistration) -> Any:
        if not request.session_id or not request.producer_instance:
            return error_response(
                status_code=400,
                code="INVALID_SESSION",
                message="Session identity fields are required.",
            )
        try:
            session, duplicate = event_store.register_session(
                session_id=request.session_id,
                producer_version=request.producer_version,
                source="websocket",
                producer_type=request.producer_type,
                producer_instance=request.producer_instance,
                client_profile=client_profile.profile_id,
                app_asar_hash=client_profile.actual_app_asar_sha256,
                template_set_version=DOMAIN_TEMPLATE_SET_VERSION,
                reference_width=0,
                reference_height=0,
            )
        except SessionIdentityConflictError:
            return error_response(
                status_code=409,
                code="SESSION_ID_CONFLICT",
                message="The session ID is already registered differently.",
                details={"session_id": request.session_id},
            )
        return JSONResponse(
            status_code=200 if duplicate else 201,
            content={
                "api_version": API_VERSION,
                "status": "existing" if duplicate else "registered",
                "session": session,
            },
        )

    @app.post("/api/v1/events")
    def submit_event(request: DomainEventSubmission) -> Any:
        session = event_store.get_session(request.session_id)
        if session is None:
            return error_response(
                status_code=404,
                code="SESSION_NOT_FOUND",
                message="The producer session does not exist.",
                details={"session_id": request.session_id},
            )
        if session["status"] != "running":
            return error_response(
                status_code=409,
                code="SESSION_NOT_RUNNING",
                message="The producer session is not running.",
                details={"session_id": request.session_id},
            )
        if session["producer_type"] != "websocket_sniffer":
            return error_response(
                status_code=409,
                code="SESSION_PRODUCER_MISMATCH",
                message="Domain events require a websocket sniffer session.",
                details={"session_id": request.session_id},
            )
        try:
            durable = domain_event_to_durable_event(
                request.event,
                session_id=request.session_id,
            )
        except DomainEventValidationError as error:
            return error_response(
                status_code=400,
                code="INVALID_DOMAIN_EVENT",
                message=str(error),
            )
        try:
            stored, duplicate = event_store.append_event_idempotent(durable)
        except EventIdentityConflictError:
            return error_response(
                status_code=409,
                code="EVENT_ID_CONFLICT",
                message=(
                    "The idempotency key already exists with different data."
                ),
                details={
                    "session_id": request.session_id,
                    "event_id": durable.event_id,
                },
            )
        except SessionNotFoundError:
            return error_response(
                status_code=409,
                code="SESSION_NOT_RUNNING",
                message="The producer session stopped before commit.",
                details={"session_id": request.session_id},
            )
        return JSONResponse(
            status_code=200 if duplicate else 201,
            content={
                "api_version": API_VERSION,
                "status": "duplicate" if duplicate else "accepted",
                "event": stored.to_dict(),
            },
        )

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
