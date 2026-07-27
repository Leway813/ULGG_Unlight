import tempfile
import unittest
from copy import deepcopy
from pathlib import Path

import requests
from fastapi.testclient import TestClient

from client_profile import verify_client_profile
from event_store import EventStore
from server import RuntimeStatus, create_app
from tracker_event_client import (
    DomainEventValidationError,
    TrackerEventClient,
)


def domain_event(session_id="session-one"):
    return {
        "domain_event_schema_version": 1,
        "event_type": "hand.cards_dealt",
        "payload": {"cards": []},
        "source": "websocket",
        "source_event": "drawPhase",
        "source_sequence": 123,
        "source_observation_index": 0,
        "source_direction": "received",
        "idempotency_key": "ws:123:0:hand.cards_dealt",
        "occurred_at": "2026-07-28T12:00:00+08:00",
        "protocol_side": "unknown",
        "resolved_side": "unknown",
        "visibility": "self_private",
        "confirmation": "confirmed",
        "confidence": 1.0,
        "authority": "authoritative",
        "producer_session_id": session_id,
    }


class FakeResponse:
    def __init__(self, status_code, body):
        self.status_code = status_code
        self.body = body

    def json(self):
        return deepcopy(self.body)


class SequenceTransport:
    def __init__(self, outcomes):
        self.outcomes = list(outcomes)
        self.calls = []

    def post(self, url, *, json, timeout):
        self.calls.append(
            {
                "url": url,
                "json": deepcopy(json),
                "timeout": timeout,
            }
        )
        outcome = self.outcomes.pop(0)
        if isinstance(outcome, Exception):
            raise outcome
        return outcome


class TrackerEventClientTests(unittest.TestCase):
    def make_client(self, transport, **kwargs):
        return TrackerEventClient(
            "http://127.0.0.1:8765",
            producer_session_id="session-one",
            producer_instance="chrome",
            transport=transport,
            **kwargs,
        )

    def test_single_event_success_preserves_complete_envelope(self):
        transport = SequenceTransport(
            [FakeResponse(201, {"status": "accepted"})]
        )
        result = self.make_client(transport).submit_event(domain_event())
        self.assertTrue(result["ok"])
        sent = transport.calls[0]["json"]["event"]
        self.assertEqual(sent, domain_event())

    def test_duplicate_response_is_success(self):
        transport = SequenceTransport(
            [FakeResponse(200, {"status": "duplicate"})]
        )
        result = self.make_client(transport).submit_event(domain_event())
        self.assertTrue(result["ok"])
        self.assertTrue(result["duplicate"])

    def test_timeout_is_bounded(self):
        transport = SequenceTransport(
            [requests.Timeout(), requests.Timeout()]
        )
        result = self.make_client(transport).submit_event(domain_event())
        self.assertFalse(result["ok"])
        self.assertEqual(result["code"], "TIMEOUT")
        self.assertEqual(len(transport.calls), 2)

    def test_connection_refused_is_bounded(self):
        transport = SequenceTransport(
            [requests.ConnectionError(), requests.ConnectionError()]
        )
        result = self.make_client(transport).submit_event(domain_event())
        self.assertFalse(result["ok"])
        self.assertEqual(result["code"], "CONNECTION_ERROR")
        self.assertEqual(len(transport.calls), 2)

    def test_400_is_not_retried(self):
        transport = SequenceTransport(
            [
                FakeResponse(
                    400,
                    {"error": {"code": "INVALID_DOMAIN_EVENT"}},
                )
            ]
        )
        result = self.make_client(transport).submit_event(domain_event())
        self.assertFalse(result["ok"])
        self.assertEqual(result["code"], "INVALID_DOMAIN_EVENT")
        self.assertEqual(len(transport.calls), 1)

    def test_503_has_one_bounded_retry(self):
        transport = SequenceTransport(
            [
                FakeResponse(503, {"error": {"code": "UNAVAILABLE"}}),
                FakeResponse(201, {"status": "accepted"}),
            ]
        )
        result = self.make_client(transport).submit_event(domain_event())
        self.assertTrue(result["ok"])
        self.assertEqual(result["attempts"], 2)

    def test_retry_keeps_identical_session_and_event_identity(self):
        transport = SequenceTransport(
            [
                requests.ConnectionError(),
                FakeResponse(201, {"status": "accepted"}),
            ]
        )
        self.make_client(transport).submit_event(domain_event())
        first = transport.calls[0]["json"]
        second = transport.calls[1]["json"]
        self.assertEqual(first, second)
        self.assertEqual(first["session_id"], "session-one")
        self.assertEqual(
            first["event"]["idempotency_key"],
            "ws:123:0:hand.cards_dealt",
        )

    def test_forbidden_field_is_rejected_before_http(self):
        transport = SequenceTransport([])
        event = domain_event()
        event["payload"]["room_id"] = "secret"
        with self.assertRaises(DomainEventValidationError):
            self.make_client(transport).submit_event(event)
        self.assertEqual(transport.calls, [])

    def test_producer_session_mismatch_is_rejected(self):
        transport = SequenceTransport([])
        with self.assertRaises(DomainEventValidationError):
            self.make_client(transport).submit_event(
                domain_event("different-session")
            )


class TrackerEventClientIntegrationTests(unittest.TestCase):
    def setUp(self):
        self.temp_dir = tempfile.TemporaryDirectory()
        root = Path(self.temp_dir.name)
        self.store = EventStore(root / "events.sqlite3")
        detector = self.store.start_session(
            producer_version="0.1.0",
            source="detector",
            client_profile="steam_custom_asar_v1",
            app_asar_hash=None,
            template_set_version="1.0.0",
            reference_width=848,
            reference_height=760,
        )
        self.detector_session_id = detector["session_id"]
        profile = verify_client_profile(root / "missing.asar", "0" * 64)
        self.transport = TestClient(
            create_app(
                event_store=self.store,
                client_profile=profile,
                runtime_status=RuntimeStatus(detector="ready"),
            )
        )
        self.client = TrackerEventClient(
            "http://testserver",
            producer_session_id="session-one",
            producer_instance="chrome",
            transport=self.transport,
        )

    def tearDown(self):
        self.transport.close()
        self.temp_dir.cleanup()

    def test_testclient_to_sqlite_registration_and_duplicate(self):
        registered = self.client.register_session()
        accepted = self.client.submit_event(domain_event())
        duplicate = self.client.submit_event(domain_event())
        page = self.store.read_events(
            session_id="session-one",
            after_sequence=0,
            limit=100,
        )
        self.assertTrue(registered["ok"])
        self.assertTrue(accepted["ok"])
        self.assertTrue(duplicate["duplicate"])
        self.assertEqual(len(page["events"]), 1)
        self.assertEqual(page["events"][0]["sequence"], 1)
        self.assertEqual(
            self.store.get_current_session()["session_id"],
            self.detector_session_id,
        )


if __name__ == "__main__":
    unittest.main()
