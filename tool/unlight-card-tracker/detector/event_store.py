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


SQLITE_SCHEMA_VERSION = 1


class EventStoreError(RuntimeError):
    pass


class SessionNotFoundError(EventStoreError):
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
            if (
                version_row is not None
                and int(version_row["value"]) != SQLITE_SCHEMA_VERSION
            ):
                raise EventStoreError(
                    "unsupported sqlite_schema_version: "
                    f"{version_row['value']}"
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
                    reference_height INTEGER NOT NULL
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
            connection.execute(
                """
                INSERT INTO schema_metadata(key, value)
                VALUES('sqlite_schema_version', ?)
                ON CONFLICT(key) DO NOTHING
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
                SET status = 'aborted', ended_at = ?
                WHERE status = 'running'
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
                    reference_height
                )
                VALUES(?, ?, NULL, ?, ?, 'running', ?, ?, ?, ?, ?)
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
                SET status = ?, ended_at = ?
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
                ORDER BY started_at DESC
                LIMIT 1
                """
            ).fetchone()
        return dict(row) if row is not None else None

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
