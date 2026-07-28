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

    @staticmethod
    def domain_event(session_id, sequence=1):
        return {
            "domain_event_schema_version": 1,
            "event_type": "battle.started",
            "payload": {},
            "source": "websocket",
            "source_event": "gameStart",
            "source_sequence": sequence,
            "source_observation_index": 0,
            "source_direction": "received",
            "idempotency_key": f"ws:{sequence}:0:battle.started",
            "occurred_at": "2026-07-28T12:00:00+08:00",
            "protocol_side": "unknown",
            "resolved_side": "unknown",
            "visibility": "public",
            "confirmation": "confirmed",
            "confidence": 1.0,
            "authority": "authoritative",
            "producer_session_id": session_id,
        }

    def register_sniffer(self, session_id, instance):
        return self.client.post(
            "/api/v1/sessions",
            json={
                "session_id": session_id,
                "producer_type": "websocket_sniffer",
                "producer_instance": instance,
                "producer_version": "0.1.0",
            },
        )

    def test_sniffer_session_finish_is_idempotent_and_clears_active(self):
        self.register_sniffer("launcher-session", "electron")
        activated = self.client.post(
            "/api/v1/sessions/launcher-session/activate"
        )
        first = self.client.post(
            "/api/v1/sessions/launcher-session/finish"
        )
        second = self.client.post(
            "/api/v1/sessions/launcher-session/finish"
        )
        active = self.client.get("/api/v1/sessions/active")

        self.assertEqual(activated.status_code, 200)
        self.assertEqual(first.status_code, 200)
        self.assertEqual(first.json()["status"], "finished")
        self.assertEqual(second.status_code, 200)
        self.assertEqual(
            second.json()["status"],
            "already_finished",
        )
        self.assertEqual(
            first.json()["session"]["status"],
            "completed",
        )
        self.assertEqual(active.status_code, 404)

    def test_sniffer_registration_keeps_detector_current(self) -> None:
        chrome = self.register_sniffer("chrome-session", "chrome")
        electron = self.register_sniffer("electron-session", "electron")
        current = self.client.get("/api/v1/sessions/current")
        self.assertEqual(chrome.status_code, 201)
        self.assertEqual(electron.status_code, 201)
        self.assertEqual(
            current.json()["session"]["session_id"],
            self.session["session_id"],
        )
        self.assertEqual(
            self.store.get_session("chrome-session")["status"],
            "running",
        )
        self.assertEqual(
            self.store.get_session("electron-session")["status"],
            "running",
        )

    def test_active_session_lifecycle_and_current_contract(self) -> None:
        no_active = self.client.get("/api/v1/sessions/active")
        detector_activation = self.client.post(
            f"/api/v1/sessions/{self.session['session_id']}/activate"
        )
        detector_repeated = self.client.post(
            f"/api/v1/sessions/{self.session['session_id']}/activate"
        )
        self.register_sniffer("chrome-session", "chrome")
        chrome_activation = self.client.post(
            "/api/v1/sessions/chrome-session/activate"
        )
        self.register_sniffer("electron-session", "electron")
        electron_activation = self.client.post(
            "/api/v1/sessions/electron-session/activate"
        )
        active = self.client.get("/api/v1/sessions/active")
        current = self.client.get("/api/v1/sessions/current")

        self.assertEqual(no_active.status_code, 404)
        self.assertEqual(
            no_active.json()["error"]["code"],
            "NO_ACTIVE_SESSION",
        )
        self.assertEqual(detector_activation.status_code, 200)
        self.assertEqual(
            detector_activation.json()["status"],
            "activated",
        )
        self.assertEqual(
            detector_repeated.json()["status"],
            "already_active",
        )
        self.assertEqual(chrome_activation.status_code, 200)
        self.assertEqual(electron_activation.status_code, 200)
        self.assertEqual(
            active.json()["session"]["session_id"],
            "electron-session",
        )
        self.assertEqual(
            current.json()["session"]["session_id"],
            self.session["session_id"],
        )
        self.assertEqual(
            self.store.get_session("chrome-session")["tracker_active"],
            0,
        )

    def test_active_session_errors_finish_and_clear(self) -> None:
        missing = self.client.post(
            "/api/v1/sessions/missing-session/activate"
        )
        self.register_sniffer("completed-session", "chrome")
        self.store.finish_session("completed-session")
        completed = self.client.post(
            "/api/v1/sessions/completed-session/activate"
        )
        self.register_sniffer("aborted-session", "electron")
        self.store.finish_session(
            "aborted-session",
            status="aborted",
        )
        aborted = self.client.post(
            "/api/v1/sessions/aborted-session/activate"
        )

        self.register_sniffer("active-session", "chrome")
        self.client.post(
            "/api/v1/sessions/active-session/activate"
        )
        self.store.finish_session("active-session")
        cleared_by_finish = self.client.get(
            "/api/v1/sessions/active"
        )

        self.register_sniffer("clear-session", "electron")
        self.client.post(
            "/api/v1/sessions/clear-session/activate"
        )
        clear = self.client.delete("/api/v1/sessions/active")
        repeated_clear = self.client.delete(
            "/api/v1/sessions/active"
        )

        self.assertEqual(missing.status_code, 404)
        self.assertEqual(
            missing.json()["error"]["code"],
            "SESSION_NOT_FOUND",
        )
        for response in (completed, aborted):
            self.assertEqual(response.status_code, 409)
            self.assertEqual(
                response.json()["error"]["code"],
                "SESSION_NOT_RUNNING",
            )
        self.assertEqual(cleared_by_finish.status_code, 404)
        self.assertEqual(clear.status_code, 200)
        self.assertEqual(clear.json()["status"], "cleared")
        self.assertEqual(
            clear.json()["previous_session"]["session_id"],
            "clear-session",
        )
        self.assertEqual(
            repeated_clear.json()["status"],
            "no_active",
        )

    def test_domain_event_post_is_idempotent_and_cursor_ordered(self) -> None:
        self.register_sniffer("chrome-session", "chrome")
        event = self.domain_event("chrome-session")
        first = self.client.post(
            "/api/v1/events",
            json={"session_id": "chrome-session", "event": event},
        )
        duplicate = self.client.post(
            "/api/v1/events",
            json={"session_id": "chrome-session", "event": event},
        )
        second_event = self.domain_event("chrome-session", sequence=2)
        second = self.client.post(
            "/api/v1/events",
            json={
                "session_id": "chrome-session",
                "event": second_event,
            },
        )
        page = self.client.get(
            "/api/v1/events",
            params={
                "session_id": "chrome-session",
                "after_sequence": 0,
            },
        ).json()
        self.assertEqual(first.status_code, 201)
        self.assertEqual(duplicate.status_code, 200)
        self.assertEqual(duplicate.json()["status"], "duplicate")
        self.assertEqual(second.status_code, 201)
        self.assertEqual(
            [item["sequence"] for item in page["events"]],
            [1, 2],
        )
        self.assertEqual(page["next_sequence"], 2)
        self.assertEqual(
            page["events"][0]["payload"]["domain_event"],
            event,
        )

    def test_domain_event_rejects_session_mismatch(self) -> None:
        self.register_sniffer("chrome-session", "chrome")
        event = self.domain_event("different-session")
        response = self.client.post(
            "/api/v1/events",
            json={"session_id": "chrome-session", "event": event},
        )
        self.assertEqual(response.status_code, 400)
        self.assertEqual(
            response.json()["error"]["code"],
            "INVALID_DOMAIN_EVENT",
        )


if __name__ == "__main__":
    unittest.main()
