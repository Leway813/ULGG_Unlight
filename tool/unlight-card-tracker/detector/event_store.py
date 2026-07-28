from __future__ import annotations

import json
import os
import sqlite3
from contextlib import contextmanager
from dataclasses import replace
from pathlib import Path
from typing import Any, Iterator
from uuid import uuid4

from event_schema import ObservationEvent, utc_now_iso


SQLITE_SCHEMA_VERSION = 3


class EventStoreError(RuntimeError):
    pass


class SessionNotFoundError(EventStoreError):
    pass


class SessionNotRunningError(EventStoreError):
    pass


class CursorExpiredError(EventStoreError):
    def __init__(self, retention_start_sequence: int) -> None:
        super().__init__("cursor is older than retained events")
        self.retention_start_sequence = retention_start_sequence


class SequenceGapError(EventStoreError):
    def __init__(
        self,
        *,
        expected_sequence: int,
        actual_sequence: int,
    ) -> None:
        super().__init__("event sequence contains a gap")
        self.expected_sequence = expected_sequence
        self.actual_sequence = actual_sequence


class EventIdentityConflictError(EventStoreError):
    pass


class SessionIdentityConflictError(EventStoreError):
    pass


def default_database_path() -> Path:
    local_app_data = os.environ.get("LOCALAPPDATA")
    if local_app_data:
        return (
            Path(local_app_data)
            / "ULGG"
            / "unlight-card-tracker"
            / "events.sqlite3"
        )

    return Path(__file__).resolve().parent / "data" / "events.sqlite3"


class EventStore:
    def __init__(self, database_path: Path | str | None = None) -> None:
        self.database_path = Path(
            database_path or default_database_path()
        )

    def _connect(self) -> sqlite3.Connection:
        connection = sqlite3.connect(
            self.database_path,
            timeout=10.0,
        )
        connection.row_factory = sqlite3.Row
        connection.execute("PRAGMA foreign_keys = ON")
        connection.execute("PRAGMA busy_timeout = 10000")
        return connection

    @contextmanager
    def _connection(self) -> Iterator[sqlite3.Connection]:
        connection = self._connect()
        try:
            yield connection
            connection.commit()
        except Exception:
            connection.rollback()
            raise
        finally:
            connection.close()

    def initialize(self) -> None:
        self.database_path.parent.mkdir(parents=True, exist_ok=True)
        with self._connection() as connection:
            connection.execute(
                """
                CREATE TABLE IF NOT EXISTS schema_metadata (
                    key TEXT PRIMARY KEY,
                    value TEXT NOT NULL
                )
                """
            )
            version_row = connection.execute(
                """
                SELECT value
                FROM schema_metadata
                WHERE key = 'sqlite_schema_version'
                """
            ).fetchone()
            existing_version = (
                int(version_row["value"])
                if version_row is not None
                else SQLITE_SCHEMA_VERSION
            )
            if existing_version not in {
                1,
                2,
                SQLITE_SCHEMA_VERSION,
            }:
                raise EventStoreError(
                    "unsupported sqlite_schema_version: "
                    f"{existing_version}"
                )

            connection.executescript(
                """
                PRAGMA journal_mode = WAL;

                CREATE TABLE IF NOT EXISTS sessions (
                    session_id TEXT PRIMARY KEY,
                    started_at TEXT NOT NULL,
                    ended_at TEXT,
                    producer_version TEXT NOT NULL,
                    source TEXT NOT NULL,
                    status TEXT NOT NULL
                        CHECK(status IN (
                            'running',
                            'completed',
                            'aborted',
                            'failed'
                        )),
                    client_profile TEXT NOT NULL,
                    app_asar_hash TEXT,
                    template_set_version TEXT NOT NULL,
                    reference_width INTEGER NOT NULL,
                    reference_height INTEGER NOT NULL,
                    producer_type TEXT NOT NULL DEFAULT 'detector'
                        CHECK(producer_type IN (
                            'detector',
                            'websocket_sniffer'
                        )),
                    producer_instance TEXT NOT NULL DEFAULT 'screen',
                    tracker_active INTEGER NOT NULL DEFAULT 0
                        CHECK(tracker_active IN (0, 1))
                );

                CREATE TABLE IF NOT EXISTS events (
                    row_id INTEGER PRIMARY KEY AUTOINCREMENT,
                    event_id TEXT NOT NULL,
                    session_id TEXT NOT NULL,
                    sequence INTEGER NOT NULL CHECK(sequence > 0),
                    event_type TEXT NOT NULL,
                    payload_json TEXT NOT NULL,
                    confidence REAL NOT NULL
                        CHECK(confidence >= 0.0 AND confidence <= 1.0),
                    occurred_at TEXT NOT NULL,
                    source TEXT NOT NULL,
                    event_schema_version INTEGER NOT NULL,
                    producer_version TEXT NOT NULL,
                    template_set_version TEXT NOT NULL,
                    created_at TEXT NOT NULL,
                    FOREIGN KEY(session_id)
                        REFERENCES sessions(session_id),
                    UNIQUE(session_id, sequence),
                    UNIQUE(session_id, event_id)
                );

                CREATE INDEX IF NOT EXISTS idx_events_session_sequence
                    ON events(session_id, sequence);
                """
            )
            columns = {
                row["name"]
                for row in connection.execute(
                    "PRAGMA table_info(sessions)"
                ).fetchall()
            }
            if existing_version == 1:
                if "producer_type" not in columns:
                    connection.execute(
                        """
                        ALTER TABLE sessions
                        ADD COLUMN producer_type TEXT NOT NULL
                            DEFAULT 'detector'
                            CHECK(producer_type IN (
                                'detector',
                                'websocket_sniffer'
                            ))
                        """
                    )
                    columns.add("producer_type")
                if "producer_instance" not in columns:
                    connection.execute(
                        """
                        ALTER TABLE sessions
                        ADD COLUMN producer_instance TEXT NOT NULL
                            DEFAULT 'screen'
                        """
                    )
                    columns.add("producer_instance")
            if "tracker_active" not in columns:
                connection.execute(
                    """
                    ALTER TABLE sessions
                    ADD COLUMN tracker_active INTEGER NOT NULL
                        DEFAULT 0
                        CHECK(tracker_active IN (0, 1))
                    """
                )
            connection.execute(
                """
                CREATE UNIQUE INDEX IF NOT EXISTS
                    idx_sessions_single_tracker_active
                ON sessions(tracker_active)
                WHERE tracker_active = 1
                """
            )
            connection.execute(
                """
                INSERT INTO schema_metadata(key, value)
                VALUES('sqlite_schema_version', ?)
                ON CONFLICT(key) DO UPDATE SET value = excluded.value
                """,
                (str(SQLITE_SCHEMA_VERSION),),
            )

    def start_session(
        self,
        *,
        producer_version: str,
        source: str,
        client_profile: str,
        app_asar_hash: str | None,
        template_set_version: str,
        reference_width: int,
        reference_height: int,
    ) -> dict[str, Any]:
        self.initialize()
        session_id = str(uuid4())
        started_at = utc_now_iso()

        with self._connection() as connection:
            connection.execute("BEGIN IMMEDIATE")
            connection.execute(
                """
                UPDATE sessions
                SET status = 'aborted',
                    ended_at = ?,
                    tracker_active = 0
                WHERE status = 'running'
                    AND producer_type = 'detector'
                """,
                (started_at,),
            )
            connection.execute(
                """
                INSERT INTO sessions(
                    session_id,
                    started_at,
                    ended_at,
                    producer_version,
                    source,
                    status,
                    client_profile,
                    app_asar_hash,
                    template_set_version,
                    reference_width,
                    reference_height,
                    producer_type,
                    producer_instance
                )
                VALUES(
                    ?, ?, NULL, ?, ?, 'running', ?, ?, ?, ?, ?,
                    'detector', 'screen'
                )
                """,
                (
                    session_id,
                    started_at,
                    producer_version,
                    source,
                    client_profile,
                    app_asar_hash,
                    template_set_version,
                    reference_width,
                    reference_height,
                ),
            )
            connection.commit()

        session = self.get_session(session_id)
        assert session is not None
        return session

    def register_session(
        self,
        *,
        session_id: str,
        producer_version: str,
        source: str,
        producer_type: str,
        producer_instance: str,
        client_profile: str,
        app_asar_hash: str | None,
        template_set_version: str,
        reference_width: int,
        reference_height: int,
    ) -> tuple[dict[str, Any], bool]:
        """Register a caller-owned producer session without aborting peers."""
        if not session_id:
            raise ValueError("session_id is required")
        if producer_type != "websocket_sniffer":
            raise ValueError("unsupported caller-owned producer_type")
        if not producer_instance:
            raise ValueError("producer_instance is required")
        self.initialize()
        started_at = utc_now_iso()
        with self._connection() as connection:
            connection.execute("BEGIN IMMEDIATE")
            existing = connection.execute(
                "SELECT * FROM sessions WHERE session_id = ?",
                (session_id,),
            ).fetchone()
            if existing is not None:
                existing_dict = dict(existing)
                if (
                    existing_dict["producer_type"] == producer_type
                    and existing_dict["producer_instance"]
                    == producer_instance
                    and existing_dict["producer_version"]
                    == producer_version
                    and existing_dict["status"] == "running"
                ):
                    return existing_dict, True
                raise SessionIdentityConflictError(session_id)
            connection.execute(
                """
                INSERT INTO sessions(
                    session_id,
                    started_at,
                    ended_at,
                    producer_version,
                    source,
                    status,
                    client_profile,
                    app_asar_hash,
                    template_set_version,
                    reference_width,
                    reference_height,
                    producer_type,
                    producer_instance
                )
                VALUES(
                    ?, ?, NULL, ?, ?, 'running', ?, ?, ?, ?, ?, ?, ?
                )
                """,
                (
                    session_id,
                    started_at,
                    producer_version,
                    source,
                    client_profile,
                    app_asar_hash,
                    template_set_version,
                    reference_width,
                    reference_height,
                    producer_type,
                    producer_instance,
                ),
            )
        session = self.get_session(session_id)
        assert session is not None
        return session, False

    def finish_session(
        self,
        session_id: str,
        *,
        status: str = "completed",
    ) -> None:
        if status not in {"completed", "aborted", "failed"}:
            raise ValueError("invalid terminal session status")

        with self._connection() as connection:
            cursor = connection.execute(
                """
                UPDATE sessions
                SET status = ?,
                    ended_at = ?,
                    tracker_active = 0
                WHERE session_id = ? AND status = 'running'
                """,
                (status, utc_now_iso(), session_id),
            )
            if cursor.rowcount == 0:
                raise SessionNotFoundError(session_id)

    def get_session(self, session_id: str) -> dict[str, Any] | None:
        with self._connection() as connection:
            row = connection.execute(
                "SELECT * FROM sessions WHERE session_id = ?",
                (session_id,),
            ).fetchone()
        return dict(row) if row is not None else None

    def get_current_session(self) -> dict[str, Any] | None:
        with self._connection() as connection:
            row = connection.execute(
                """
                SELECT *
                FROM sessions
                WHERE status = 'running'
                    AND producer_type = 'detector'
                ORDER BY started_at DESC
                LIMIT 1
                """
            ).fetchone()
        return dict(row) if row is not None else None

    def get_active_session(self) -> dict[str, Any] | None:
        with self._connection() as connection:
            row = connection.execute(
                """
                SELECT *
                FROM sessions
                WHERE tracker_active = 1
                LIMIT 1
                """
            ).fetchone()
        return dict(row) if row is not None else None

    def activate_session(
        self,
        session_id: str,
    ) -> tuple[dict[str, Any], bool]:
        """Make one running session the sole Tracker authority."""
        with self._connection() as connection:
            connection.execute("BEGIN IMMEDIATE")
            row = connection.execute(
                """
                SELECT *
                FROM sessions
                WHERE session_id = ?
                """,
                (session_id,),
            ).fetchone()
            if row is None:
                raise SessionNotFoundError(session_id)
            if row["status"] != "running":
                raise SessionNotRunningError(session_id)

            already_active = row["tracker_active"] == 1
            if not already_active:
                connection.execute(
                    """
                    UPDATE sessions
                    SET tracker_active = 0
                    WHERE tracker_active = 1
                    """
                )
                connection.execute(
                    """
                    UPDATE sessions
                    SET tracker_active = 1
                    WHERE session_id = ? AND status = 'running'
                    """,
                    (session_id,),
                )
            active = connection.execute(
                """
                SELECT *
                FROM sessions
                WHERE session_id = ?
                """,
                (session_id,),
            ).fetchone()

        assert active is not None
        return dict(active), already_active

    def clear_active_session(self) -> dict[str, Any] | None:
        with self._connection() as connection:
            connection.execute("BEGIN IMMEDIATE")
            active = connection.execute(
                """
                SELECT *
                FROM sessions
                WHERE tracker_active = 1
                LIMIT 1
                """
            ).fetchone()
            if active is not None:
                connection.execute(
                    """
                    UPDATE sessions
                    SET tracker_active = 0
                    WHERE tracker_active = 1
                    """
                )
        return dict(active) if active is not None else None

    def append_event(
        self,
        event: ObservationEvent,
    ) -> ObservationEvent:
        if event.sequence is not None:
            raise ValueError("sequence must be allocated by EventStore")

        payload_json = json.dumps(
            event.payload,
            ensure_ascii=False,
            sort_keys=True,
            separators=(",", ":"),
        )

        with self._connection() as connection:
            connection.execute("BEGIN IMMEDIATE")
            session = connection.execute(
                """
                SELECT status
                FROM sessions
                WHERE session_id = ?
                """,
                (event.session_id,),
            ).fetchone()

            if session is None or session["status"] != "running":
                raise SessionNotFoundError(event.session_id)

            next_sequence = connection.execute(
                """
                SELECT COALESCE(MAX(sequence), 0) + 1
                FROM events
                WHERE session_id = ?
                """,
                (event.session_id,),
            ).fetchone()[0]

            connection.execute(
                """
                INSERT INTO events(
                    event_id,
                    session_id,
                    sequence,
                    event_type,
                    payload_json,
                    confidence,
                    occurred_at,
                    source,
                    event_schema_version,
                    producer_version,
                    template_set_version,
                    created_at
                )
                VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                """,
                (
                    event.event_id,
                    event.session_id,
                    next_sequence,
                    event.event_type,
                    payload_json,
                    event.confidence,
                    event.occurred_at,
                    event.source,
                    event.event_schema_version,
                    event.producer_version,
                    event.template_set_version,
                    utc_now_iso(),
                ),
            )
            connection.commit()

        return replace(event, sequence=int(next_sequence))

    def append_event_idempotent(
        self,
        event: ObservationEvent,
    ) -> tuple[ObservationEvent, bool]:
        """Append once, returning duplicate=True for identical retries."""
        if event.sequence is not None:
            raise ValueError("sequence must be allocated by EventStore")
        payload_json = json.dumps(
            event.payload,
            ensure_ascii=False,
            sort_keys=True,
            separators=(",", ":"),
        )
        with self._connection() as connection:
            connection.execute("BEGIN IMMEDIATE")
            session = connection.execute(
                """
                SELECT status
                FROM sessions
                WHERE session_id = ?
                """,
                (event.session_id,),
            ).fetchone()
            if session is None or session["status"] != "running":
                raise SessionNotFoundError(event.session_id)

            existing = connection.execute(
                """
                SELECT *
                FROM events
                WHERE session_id = ? AND event_id = ?
                """,
                (event.session_id, event.event_id),
            ).fetchone()
            if existing is not None:
                stored = self._event_row_to_dict(existing)
                comparable = {
                    "event_id": event.event_id,
                    "session_id": event.session_id,
                    "event_type": event.event_type,
                    "payload": event.payload,
                    "confidence": event.confidence,
                    "occurred_at": event.occurred_at,
                    "source": event.source,
                    "event_schema_version": event.event_schema_version,
                    "producer_version": event.producer_version,
                    "template_set_version": event.template_set_version,
                }
                stored_comparable = {
                    key: stored[key] for key in comparable
                }
                if stored_comparable != comparable:
                    raise EventIdentityConflictError(event.event_id)
                return replace(
                    event,
                    sequence=int(existing["sequence"]),
                ), True

            next_sequence = connection.execute(
                """
                SELECT COALESCE(MAX(sequence), 0) + 1
                FROM events
                WHERE session_id = ?
                """,
                (event.session_id,),
            ).fetchone()[0]
            connection.execute(
                """
                INSERT INTO events(
                    event_id,
                    session_id,
                    sequence,
                    event_type,
                    payload_json,
                    confidence,
                    occurred_at,
                    source,
                    event_schema_version,
                    producer_version,
                    template_set_version,
                    created_at
                )
                VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                """,
                (
                    event.event_id,
                    event.session_id,
                    next_sequence,
                    event.event_type,
                    payload_json,
                    event.confidence,
                    event.occurred_at,
                    event.source,
                    event.event_schema_version,
                    event.producer_version,
                    event.template_set_version,
                    utc_now_iso(),
                ),
            )
        return replace(event, sequence=int(next_sequence)), False

    def read_events(
        self,
        *,
        session_id: str,
        after_sequence: int,
        limit: int,
    ) -> dict[str, Any]:
        if after_sequence < 0:
            raise ValueError("after_sequence must be >= 0")
        if not 1 <= limit <= 1000:
            raise ValueError("limit must be between 1 and 1000")
        if self.get_session(session_id) is None:
            raise SessionNotFoundError(session_id)

        with self._connection() as connection:
            bounds = connection.execute(
                """
                SELECT MIN(sequence) AS minimum, MAX(sequence) AS maximum
                FROM events
                WHERE session_id = ?
                """,
                (session_id,),
            ).fetchone()

            retention_start = (
                int(bounds["minimum"])
                if bounds["minimum"] is not None
                else 1
            )

            if after_sequence < retention_start - 1:
                raise CursorExpiredError(retention_start)

            rows = connection.execute(
                """
                SELECT *
                FROM events
                WHERE session_id = ? AND sequence > ?
                ORDER BY sequence ASC
                LIMIT ?
                """,
                (session_id, after_sequence, limit + 1),
            ).fetchall()

        page_rows = rows[:limit]
        if page_rows:
            expected = after_sequence + 1
            actual = int(page_rows[0]["sequence"])
            if actual != expected:
                raise SequenceGapError(
                    expected_sequence=expected,
                    actual_sequence=actual,
                )

            for previous, current in zip(page_rows, page_rows[1:]):
                expected = int(previous["sequence"]) + 1
                actual = int(current["sequence"])
                if actual != expected:
                    raise SequenceGapError(
                        expected_sequence=expected,
                        actual_sequence=actual,
                    )

        events = [self._event_row_to_dict(row) for row in page_rows]
        next_sequence = (
            int(page_rows[-1]["sequence"])
            if page_rows
            else after_sequence
        )

        return {
            "session_id": session_id,
            "after_sequence": after_sequence,
            "events": events,
            "next_sequence": next_sequence,
            "has_more": len(rows) > limit,
            "retention_start_sequence": retention_start,
        }

    def storage_summary(self) -> dict[str, Any]:
        with self._connection() as connection:
            row = connection.execute(
                """
                SELECT
                    (SELECT COUNT(*) FROM sessions) AS session_count,
                    (SELECT COUNT(*) FROM events) AS event_count,
                    (SELECT MIN(started_at) FROM sessions) AS oldest,
                    (SELECT MAX(started_at) FROM sessions) AS newest
                """
            ).fetchone()

        return {
            "database_path": str(self.database_path),
            "database_size_bytes": (
                self.database_path.stat().st_size
                if self.database_path.exists()
                else 0
            ),
            "session_count": int(row["session_count"]),
            "event_count": int(row["event_count"]),
            "oldest_session_started_at": row["oldest"],
            "newest_session_started_at": row["newest"],
        }

    @staticmethod
    def _event_row_to_dict(row: sqlite3.Row) -> dict[str, Any]:
        return {
            "event_id": row["event_id"],
            "session_id": row["session_id"],
            "sequence": int(row["sequence"]),
            "event_type": row["event_type"],
            "payload": json.loads(row["payload_json"]),
            "confidence": float(row["confidence"]),
            "occurred_at": row["occurred_at"],
            "source": row["source"],
            "event_schema_version": int(row["event_schema_version"]),
            "producer_version": row["producer_version"],
            "template_set_version": row["template_set_version"],
        }
