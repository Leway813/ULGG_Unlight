from __future__ import annotations

import sqlite3
import tempfile
import unittest
from contextlib import closing
from pathlib import Path

from event_schema import (
    PRODUCER_VERSION,
    TEMPLATE_SET_VERSION,
    new_loading_observed_event,
)
from event_store import (
    CursorExpiredError,
    EventStore,
    SequenceGapError,
)


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


if __name__ == "__main__":
    unittest.main()
