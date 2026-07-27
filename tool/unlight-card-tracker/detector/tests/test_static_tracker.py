from __future__ import annotations

import tempfile
import unittest
from pathlib import Path

from fastapi.testclient import TestClient

from client_profile import verify_client_profile
from event_schema import PRODUCER_VERSION, TEMPLATE_SET_VERSION
from event_store import EventStore
from server import RuntimeStatus, create_app


class StaticTrackerTest(unittest.TestCase):
    def setUp(self) -> None:
        self.temporary_directory = tempfile.TemporaryDirectory()
        root = Path(self.temporary_directory.name)
        store = EventStore(root / "events.sqlite3")
        store.start_session(
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
                event_store=store,
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

    def test_root_redirects_to_tracker(self) -> None:
        response = self.client.get(
            "/",
            follow_redirects=False,
        )

        self.assertEqual(response.status_code, 302)
        self.assertEqual(
            response.headers["location"],
            "/tracker/",
        )

    def test_tracker_index_is_served(self) -> None:
        response = self.client.get("/tracker/")

        self.assertEqual(response.status_code, 200)
        self.assertIn(
            "text/html",
            response.headers["content-type"],
        )
        self.assertIn("field-decks.js", response.text)
        self.assertIn("tracker.js", response.text)

    def test_tracker_javascript_is_served(self) -> None:
        field_decks = self.client.get(
            "/tracker/field-decks.js"
        )
        tracker = self.client.get(
            "/tracker/tracker.js"
        )

        self.assertEqual(field_decks.status_code, 200)
        self.assertEqual(tracker.status_code, 200)
        self.assertIn(
            "javascript",
            field_decks.headers["content-type"],
        )
        self.assertIn(
            "javascript",
            tracker.headers["content-type"],
        )

    def test_tracker_asset_is_served(self) -> None:
        response = self.client.get(
            "/tracker/assets/cards/acswd.png"
        )

        self.assertEqual(response.status_code, 200)
        self.assertEqual(
            response.headers["content-type"],
            "image/png",
        )

    def test_api_routes_are_not_shadowed(self) -> None:
        response = self.client.get("/api/v1/health")

        self.assertEqual(response.status_code, 200)
        self.assertEqual(
            response.json()["api_version"],
            "v1",
        )

    def test_detector_and_readme_are_not_public(self) -> None:
        detector = self.client.get(
            "/tracker/detector/server.py"
        )
        readme = self.client.get(
            "/tracker/README_updated.md"
        )

        self.assertEqual(detector.status_code, 404)
        self.assertEqual(readme.status_code, 404)

    def test_missing_tracker_file_returns_404(self) -> None:
        response = self.client.get(
            "/tracker/not-a-real-file.js"
        )

        self.assertEqual(response.status_code, 404)

    def test_asset_path_traversal_is_rejected(self) -> None:
        response = self.client.get(
            "/tracker/assets/%2e%2e/detector/server.py"
        )

        self.assertEqual(response.status_code, 404)


if __name__ == "__main__":
    unittest.main()
