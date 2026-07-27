from __future__ import annotations

import json
import http.client
import socket
import tempfile
import time
import unittest
from pathlib import Path
from threading import Thread

import uvicorn

from client_profile import verify_client_profile
from event_schema import PRODUCER_VERSION, TEMPLATE_SET_VERSION
from event_store import EventStore
from server import RuntimeStatus, create_app


class UvicornSmokeTest(unittest.TestCase):
    def test_serves_health_over_real_localhost_socket(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            store = EventStore(root / "events.sqlite3")
            session = store.start_session(
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

            with socket.socket() as probe:
                probe.bind(("127.0.0.1", 0))
                port = probe.getsockname()[1]

            server = uvicorn.Server(
                uvicorn.Config(
                    create_app(
                        event_store=store,
                        client_profile=profile,
                        runtime_status=RuntimeStatus(
                            detector="paused",
                            error="app_asar_not_found",
                        ),
                    ),
                    host="127.0.0.1",
                    port=port,
                    log_level="error",
                )
            )
            thread = Thread(target=server.run, daemon=True)
            thread.start()

            try:
                deadline = time.monotonic() + 5
                while not server.started:
                    if not thread.is_alive():
                        self.fail("Uvicorn stopped before startup")
                    if time.monotonic() >= deadline:
                        self.fail("Uvicorn startup timed out")
                    time.sleep(0.02)

                connection = http.client.HTTPConnection(
                    "127.0.0.1",
                    port,
                    timeout=2,
                )
                connection.request("GET", "/")
                root_response = connection.getresponse()
                root_response.read()
                self.assertEqual(root_response.status, 302)
                self.assertEqual(
                    root_response.getheader("location"),
                    "/tracker/",
                )

                for path in (
                    "/tracker/",
                    "/tracker/tracker.js",
                    "/tracker/tracker-api.js",
                    "/tracker/observation-poller.js",
                ):
                    connection.request("GET", path)
                    static_response = connection.getresponse()
                    static_response.read()
                    self.assertEqual(static_response.status, 200)

                connection.request("GET", "/api/v1/health")
                response = connection.getresponse()
                body = json.loads(
                    response.read().decode("utf-8")
                )
                connection.close()

                self.assertEqual(response.status, 200)
                self.assertEqual(body["server"], "ready")
                self.assertEqual(
                    body["session_id"],
                    session["session_id"],
                )
            finally:
                server.should_exit = True
                thread.join(timeout=5)


if __name__ == "__main__":
    unittest.main()
