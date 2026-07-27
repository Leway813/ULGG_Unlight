from __future__ import annotations

import tempfile
import unittest
import sqlite3
from contextlib import closing
from pathlib import Path

from fastapi.testclient import TestClient

from client_profile import verify_client_profile
from event_schema import (
    PRODUCER_VERSION,
    TEMPLATE_SET_VERSION,
    new_loading_observed_event,
)
from event_store import EventStore
from server import RuntimeStatus, create_app


class ServerApiTest(unittest.TestCase):
    def setUp(self) -> None:
        self.temporary_directory = tempfile.TemporaryDirectory()
        root = Path(self.temporary_directory.name)
        self.store = EventStore(root / "events.sqlite3")
        self.session = self.store.start_session(
            producer_version=PRODUCER_VERSION,
            source="detector",
            client_profile="steam_custom_asar_v1",
            app_asar_hash=None,
            template_set_version=TEMPLATE_SET_VERSION,
            reference_width=848,
            reference_height=760,
        )
        profile = verify_client_profile(
            root / "missing.asar",
            "0" * 64,
        )
        self.client = TestClient(
            create_app(
                event_store=self.store,
                client_profile=profile,
                runtime_status=RuntimeStatus(
                    detector="paused",
                    error="app_asar_not_found",
                ),
            )
        )

    def tearDown(self) -> None:
        self.client.close()
        self.temporary_directory.cleanup()

    def test_health_remains_available_for_unsupported_profile(self) -> None:
        response = self.client.get("/api/v1/health")

        self.assertEqual(response.status_code, 200)
        body = response.json()
        self.assertEqual(body["server"], "ready")
        self.assertEqual(body["detector"], "paused")
        self.assertFalse(body["client_profile"]["supported"])

    def test_current_session_and_cursor_event_page(self) -> None:
        stored = self.store.append_event(
            new_loading_observed_event(
                session_id=self.session["session_id"],
                is_loading=False,
                confidence=0.8,
                observation_mode="initial_baseline",
            )
        )

        session_response = self.client.get(
            "/api/v1/sessions/current"
        )
        event_response = self.client.get(
            "/api/v1/events",
            params={
                "session_id": self.session["session_id"],
                "after_sequence": 0,
                "limit": 100,
            },
        )

        self.assertEqual(session_response.status_code, 200)
        self.assertEqual(event_response.status_code, 200)
        page = event_response.json()
        self.assertEqual(page["events"][0]["event_id"], stored.event_id)
        self.assertEqual(page["next_sequence"], 1)
        self.assertFalse(page["has_more"])
        self.assertEqual(page["retention_start_sequence"], 1)

    def test_cursor_expired_has_explicit_409_error(self) -> None:
        for is_loading in (False, True):
            self.store.append_event(
                new_loading_observed_event(
                    session_id=self.session["session_id"],
                    is_loading=is_loading,
                    confidence=0.8,
                    observation_mode=(
                        "initial_baseline"
                        if not is_loading
                        else "change"
                    ),
                )
            )

        with closing(
            sqlite3.connect(self.store.database_path)
        ) as connection:
            connection.execute(
                "DELETE FROM events WHERE sequence = 1"
            )
            connection.commit()

        response = self.client.get(
            "/api/v1/events",
            params={
                "session_id": self.session["session_id"],
                "after_sequence": 0,
            },
        )

        self.assertEqual(response.status_code, 409)
        error = response.json()["error"]
        self.assertEqual(error["code"], "CURSOR_EXPIRED")
        self.assertEqual(
            error["details"]["retention_start_sequence"],
            2,
        )

    def test_sequence_gap_has_explicit_409_error(self) -> None:
        for is_loading in (False, True):
            self.store.append_event(
                new_loading_observed_event(
                    session_id=self.session["session_id"],
                    is_loading=is_loading,
                    confidence=0.8,
                    observation_mode=(
                        "initial_baseline"
                        if not is_loading
                        else "change"
                    ),
                )
            )

        with closing(
            sqlite3.connect(self.store.database_path)
        ) as connection:
            connection.execute(
                "UPDATE events SET sequence = 3 WHERE sequence = 2"
            )
            connection.commit()

        response = self.client.get(
            "/api/v1/events",
            params={
                "session_id": self.session["session_id"],
                "after_sequence": 1,
            },
        )

        self.assertEqual(response.status_code, 409)
        error = response.json()["error"]
        self.assertEqual(error["code"], "SEQUENCE_GAP")
        self.assertEqual(error["details"]["expected_sequence"], 2)
        self.assertEqual(error["details"]["actual_sequence"], 3)


if __name__ == "__main__":
    unittest.main()
