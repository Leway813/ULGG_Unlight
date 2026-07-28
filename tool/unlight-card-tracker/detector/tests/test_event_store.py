from __future__ import annotations

import sqlite3
import tempfile
import unittest
from contextlib import closing
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path
from threading import Barrier

from event_schema import (
    PRODUCER_VERSION,
    TEMPLATE_SET_VERSION,
    new_loading_observed_event,
)
from event_store import (
    CursorExpiredError,
    EventStore,
    SequenceGapError,
    SessionNotFoundError,
    SessionNotRunningError,
)
from domain_event_schema import domain_event_to_durable_event


class EventStoreTest(unittest.TestCase):
    def setUp(self) -> None:
        self.temporary_directory = tempfile.TemporaryDirectory()
        self.database_path = (
            Path(self.temporary_directory.name) / "events.sqlite3"
        )
        self.store = EventStore(self.database_path)
        self.session = self.store.start_session(
            producer_version=PRODUCER_VERSION,
            source="detector",
            client_profile="steam_custom_asar_v1",
            app_asar_hash="ABC",
            template_set_version=TEMPLATE_SET_VERSION,
            reference_width=848,
            reference_height=760,
        )

    def tearDown(self) -> None:
        self.temporary_directory.cleanup()

    def append_loading(self, is_loading: bool):
        return self.store.append_event(
            new_loading_observed_event(
                session_id=self.session["session_id"],
                is_loading=is_loading,
                confidence=0.9,
                observation_mode=(
                    "initial_baseline"
                    if is_loading
                    else "change"
                ),
            )
        )

    def register_sniffer(
        self,
        session_id: str,
        instance: str,
    ) -> dict:
        session, _ = self.store.register_session(
            session_id=session_id,
            producer_version="0.1.0",
            source="websocket",
            producer_type="websocket_sniffer",
            producer_instance=instance,
            client_profile="steam_custom_asar_v1",
            app_asar_hash=None,
            template_set_version="not-applicable",
            reference_width=0,
            reference_height=0,
        )
        return session

    def test_allocates_session_local_sequence_in_order(self) -> None:
        first = self.append_loading(True)
        second = self.append_loading(False)

        self.assertEqual(first.sequence, 1)
        self.assertEqual(second.sequence, 2)

        page = self.store.read_events(
            session_id=self.session["session_id"],
            after_sequence=0,
            limit=1,
        )
        self.assertEqual([event["sequence"] for event in page["events"]], [1])
        self.assertEqual(page["next_sequence"], 1)
        self.assertTrue(page["has_more"])
        self.assertEqual(page["retention_start_sequence"], 1)

    def test_new_session_aborts_previous_running_session(self) -> None:
        second = self.store.start_session(
            producer_version=PRODUCER_VERSION,
            source="detector",
            client_profile="steam_custom_asar_v1",
            app_asar_hash="ABC",
            template_set_version=TEMPLATE_SET_VERSION,
            reference_width=848,
            reference_height=760,
        )

        previous = self.store.get_session(self.session["session_id"])
        self.assertEqual(previous["status"], "aborted")
        self.assertIsNotNone(previous["ended_at"])
        self.assertEqual(second["status"], "running")

    def test_rejects_duplicate_event_id_in_same_session(self) -> None:
        event = new_loading_observed_event(
            session_id=self.session["session_id"],
            is_loading=True,
            confidence=0.9,
            observation_mode="initial_baseline",
        )
        self.store.append_event(event)

        with self.assertRaises(sqlite3.IntegrityError):
            self.store.append_event(event)

    def test_detects_cursor_expiry_after_manual_pruning(self) -> None:
        self.append_loading(True)
        self.append_loading(False)
        with closing(
            sqlite3.connect(self.database_path)
        ) as connection:
            connection.execute(
                "DELETE FROM events WHERE sequence = 1"
            )
            connection.commit()

        with self.assertRaises(CursorExpiredError):
            self.store.read_events(
                session_id=self.session["session_id"],
                after_sequence=0,
                limit=100,
            )

    def test_detects_sequence_gap_inside_page(self) -> None:
        self.append_loading(True)
        self.append_loading(False)
        with closing(
            sqlite3.connect(self.database_path)
        ) as connection:
            connection.execute(
                "UPDATE events SET sequence = 3 WHERE sequence = 2"
            )
            connection.commit()

        with self.assertRaises(SequenceGapError):
            self.store.read_events(
                session_id=self.session["session_id"],
                after_sequence=1,
                limit=100,
            )

    def test_detector_start_only_aborts_previous_detector(self) -> None:
        chrome, _ = self.store.register_session(
            session_id="chrome-session",
            producer_version="0.1.0",
            source="websocket",
            producer_type="websocket_sniffer",
            producer_instance="chrome",
            client_profile="steam_custom_asar_v1",
            app_asar_hash=None,
            template_set_version="not-applicable",
            reference_width=0,
            reference_height=0,
        )
        electron, _ = self.store.register_session(
            session_id="electron-session",
            producer_version="0.1.0",
            source="websocket",
            producer_type="websocket_sniffer",
            producer_instance="electron",
            client_profile="steam_custom_asar_v1",
            app_asar_hash=None,
            template_set_version="not-applicable",
            reference_width=0,
            reference_height=0,
        )
        replacement = self.store.start_session(
            producer_version=PRODUCER_VERSION,
            source="detector",
            client_profile="steam_custom_asar_v1",
            app_asar_hash="ABC",
            template_set_version=TEMPLATE_SET_VERSION,
            reference_width=848,
            reference_height=760,
        )
        self.assertEqual(
            self.store.get_session(self.session["session_id"])["status"],
            "aborted",
        )
        self.assertEqual(
            self.store.get_session(chrome["session_id"])["status"],
            "running",
        )
        self.assertEqual(
            self.store.get_session(electron["session_id"])["status"],
            "running",
        )
        self.assertEqual(
            self.store.get_current_session()["session_id"],
            replacement["session_id"],
        )

    def test_sniffer_sessions_have_independent_sequences(self) -> None:
        for session_id, instance in (
            ("chrome-session", "chrome"),
            ("electron-session", "electron"),
        ):
            self.store.register_session(
                session_id=session_id,
                producer_version="0.1.0",
                source="websocket",
                producer_type="websocket_sniffer",
                producer_instance=instance,
                client_profile="steam_custom_asar_v1",
                app_asar_hash=None,
                template_set_version="not-applicable",
                reference_width=0,
                reference_height=0,
            )
            domain = {
                "domain_event_schema_version": 1,
                "event_type": "battle.started",
                "payload": {},
                "source": "websocket",
                "source_event": "gameStart",
                "source_sequence": 1,
                "source_observation_index": 0,
                "source_direction": "received",
                "idempotency_key": "ws:1:0:battle.started",
                "occurred_at": "2026-07-28T12:00:00+08:00",
                "protocol_side": "unknown",
                "resolved_side": "unknown",
                "visibility": "public",
                "confirmation": "confirmed",
                "confidence": 1.0,
                "authority": "authoritative",
                "producer_session_id": session_id,
            }
            stored, duplicate = self.store.append_event_idempotent(
                domain_event_to_durable_event(
                    domain,
                    session_id=session_id,
                )
            )
            self.assertFalse(duplicate)
            self.assertEqual(stored.sequence, 1)

    def test_initial_sessions_are_inactive(self) -> None:
        self.assertEqual(self.session["tracker_active"], 0)
        self.assertIsNone(self.store.get_active_session())

    def test_activation_switches_detector_chrome_and_electron(self) -> None:
        chrome = self.register_sniffer("chrome-session", "chrome")
        electron = self.register_sniffer("electron-session", "electron")

        detector_active, detector_duplicate = self.store.activate_session(
            self.session["session_id"]
        )
        chrome_active, _ = self.store.activate_session(
            chrome["session_id"]
        )
        electron_active, _ = self.store.activate_session(
            electron["session_id"]
        )

        self.assertFalse(detector_duplicate)
        self.assertEqual(detector_active["tracker_active"], 1)
        self.assertEqual(chrome_active["tracker_active"], 1)
        self.assertEqual(electron_active["tracker_active"], 1)
        self.assertEqual(
            self.store.get_session(self.session["session_id"])[
                "tracker_active"
            ],
            0,
        )
        self.assertEqual(
            self.store.get_session(chrome["session_id"])[
                "tracker_active"
            ],
            0,
        )
        self.assertEqual(
            self.store.get_active_session()["session_id"],
            electron["session_id"],
        )

    def test_repeated_activation_is_idempotent(self) -> None:
        first, first_duplicate = self.store.activate_session(
            self.session["session_id"]
        )
        second, second_duplicate = self.store.activate_session(
            self.session["session_id"]
        )

        self.assertFalse(first_duplicate)
        self.assertTrue(second_duplicate)
        self.assertEqual(first["session_id"], second["session_id"])
        self.assertEqual(second["tracker_active"], 1)

    def test_activation_rejects_missing_and_terminal_sessions(self) -> None:
        with self.assertRaises(SessionNotFoundError):
            self.store.activate_session("missing-session")

        for status in ("completed", "aborted"):
            session_id = f"{status}-session"
            self.register_sniffer(session_id, "chrome")
            self.store.finish_session(session_id, status=status)
            with self.assertRaises(SessionNotRunningError):
                self.store.activate_session(session_id)

    def test_finishing_or_replacing_session_clears_active(self) -> None:
        chrome = self.register_sniffer("chrome-session", "chrome")
        self.store.activate_session(chrome["session_id"])
        self.store.finish_session(chrome["session_id"])
        self.assertIsNone(self.store.get_active_session())

        self.store.activate_session(self.session["session_id"])
        self.store.start_session(
            producer_version=PRODUCER_VERSION,
            source="detector",
            client_profile="steam_custom_asar_v1",
            app_asar_hash="ABC",
            template_set_version=TEMPLATE_SET_VERSION,
            reference_width=848,
            reference_height=760,
        )
        self.assertIsNone(self.store.get_active_session())

    def test_clear_active_session_is_idempotent(self) -> None:
        self.store.activate_session(self.session["session_id"])
        previous = self.store.clear_active_session()
        repeated = self.store.clear_active_session()

        self.assertEqual(
            previous["session_id"],
            self.session["session_id"],
        )
        self.assertIsNone(repeated)
        self.assertIsNone(self.store.get_active_session())

    def test_concurrent_activation_leaves_exactly_one_active(self) -> None:
        chrome = self.register_sniffer("chrome-session", "chrome")
        electron = self.register_sniffer("electron-session", "electron")
        barrier = Barrier(2)

        def activate(session_id: str) -> None:
            barrier.wait(timeout=5)
            self.store.activate_session(session_id)

        with ThreadPoolExecutor(max_workers=2) as executor:
            futures = [
                executor.submit(activate, chrome["session_id"]),
                executor.submit(activate, electron["session_id"]),
            ]
            for future in futures:
                future.result(timeout=10)

        with closing(sqlite3.connect(self.database_path)) as connection:
            active_count = connection.execute(
                """
                SELECT COUNT(*)
                FROM sessions
                WHERE tracker_active = 1
                """
            ).fetchone()[0]
        self.assertEqual(active_count, 1)
        self.assertIn(
            self.store.get_active_session()["session_id"],
            {chrome["session_id"], electron["session_id"]},
        )

    def test_partial_unique_index_rejects_two_active_rows(self) -> None:
        chrome = self.register_sniffer("chrome-session", "chrome")
        with closing(sqlite3.connect(self.database_path)) as connection:
            connection.execute(
                """
                UPDATE sessions
                SET tracker_active = 1
                WHERE session_id = ?
                """,
                (self.session["session_id"],),
            )
            with self.assertRaises(sqlite3.IntegrityError):
                connection.execute(
                    """
                    UPDATE sessions
                    SET tracker_active = 1
                    WHERE session_id = ?
                    """,
                    (chrome["session_id"],),
                )

    def test_migrates_v1_sessions_with_detector_defaults(self) -> None:
        legacy_path = Path(self.temporary_directory.name) / "legacy.sqlite3"
        with closing(sqlite3.connect(legacy_path)) as connection:
            connection.executescript(
                """
                CREATE TABLE schema_metadata (
                    key TEXT PRIMARY KEY,
                    value TEXT NOT NULL
                );
                INSERT INTO schema_metadata VALUES(
                    'sqlite_schema_version', '1'
                );
                CREATE TABLE sessions (
                    session_id TEXT PRIMARY KEY,
                    started_at TEXT NOT NULL,
                    ended_at TEXT,
                    producer_version TEXT NOT NULL,
                    source TEXT NOT NULL,
                    status TEXT NOT NULL,
                    client_profile TEXT NOT NULL,
                    app_asar_hash TEXT,
                    template_set_version TEXT NOT NULL,
                    reference_width INTEGER NOT NULL,
                    reference_height INTEGER NOT NULL
                );
                INSERT INTO sessions VALUES(
                    'legacy', '2026-01-01T00:00:00Z', NULL,
                    '0.1.0', 'detector', 'running', 'legacy',
                    NULL, '1.0.0', 848, 760
                );
                CREATE TABLE events (
                    row_id INTEGER PRIMARY KEY AUTOINCREMENT,
                    event_id TEXT NOT NULL,
                    session_id TEXT NOT NULL,
                    sequence INTEGER NOT NULL,
                    event_type TEXT NOT NULL,
                    payload_json TEXT NOT NULL,
                    confidence REAL NOT NULL,
                    occurred_at TEXT NOT NULL,
                    source TEXT NOT NULL,
                    event_schema_version INTEGER NOT NULL,
                    producer_version TEXT NOT NULL,
                    template_set_version TEXT NOT NULL,
                    created_at TEXT NOT NULL,
                    UNIQUE(session_id, sequence),
                    UNIQUE(session_id, event_id)
                );
                """
            )
        legacy_store = EventStore(legacy_path)
        legacy_store.initialize()
        session = legacy_store.get_session("legacy")
        self.assertEqual(session["producer_type"], "detector")
        self.assertEqual(session["producer_instance"], "screen")
        self.assertEqual(session["tracker_active"], 0)
        with closing(sqlite3.connect(legacy_path)) as connection:
            version = connection.execute(
                """
                SELECT value FROM schema_metadata
                WHERE key = 'sqlite_schema_version'
                """
            ).fetchone()[0]
        self.assertEqual(version, "3")

    def test_migrates_v2_sessions_to_inactive(self) -> None:
        legacy_path = (
            Path(self.temporary_directory.name) / "legacy-v2.sqlite3"
        )
        with closing(sqlite3.connect(legacy_path)) as connection:
            connection.executescript(
                """
                CREATE TABLE schema_metadata (
                    key TEXT PRIMARY KEY,
                    value TEXT NOT NULL
                );
                INSERT INTO schema_metadata VALUES(
                    'sqlite_schema_version', '2'
                );
                CREATE TABLE sessions (
                    session_id TEXT PRIMARY KEY,
                    started_at TEXT NOT NULL,
                    ended_at TEXT,
                    producer_version TEXT NOT NULL,
                    source TEXT NOT NULL,
                    status TEXT NOT NULL,
                    client_profile TEXT NOT NULL,
                    app_asar_hash TEXT,
                    template_set_version TEXT NOT NULL,
                    reference_width INTEGER NOT NULL,
                    reference_height INTEGER NOT NULL,
                    producer_type TEXT NOT NULL DEFAULT 'detector',
                    producer_instance TEXT NOT NULL DEFAULT 'screen'
                );
                INSERT INTO sessions VALUES(
                    'legacy-v2', '2026-01-01T00:00:00Z', NULL,
                    '0.1.0', 'websocket', 'running', 'legacy',
                    NULL, 'not-applicable', 0, 0,
                    'websocket_sniffer', 'chrome'
                );
                """
            )

        legacy_store = EventStore(legacy_path)
        legacy_store.initialize()
        migrated = legacy_store.get_session("legacy-v2")
        self.assertEqual(migrated["tracker_active"], 0)
        self.assertIsNone(legacy_store.get_active_session())


if __name__ == "__main__":
    unittest.main()
