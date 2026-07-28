from __future__ import annotations

import http.client
import io
import json
import os
import signal
import socket
import sqlite3
import subprocess
import sys
import tempfile
import time
import unittest
from contextlib import closing
from contextlib import redirect_stderr
from pathlib import Path

from fastapi.testclient import TestClient

from event_store import EventStore, default_database_path
from tracker_api_server import (
    DEFAULT_HOST,
    DEFAULT_LOG_LEVEL,
    DEFAULT_PORT,
    build_app,
    main,
    parse_args,
)


DETECTOR_ROOT = Path(__file__).resolve().parent.parent
ENTRYPOINT = DETECTOR_ROOT / "tracker_api_server.py"


def sample_domain_event(session_id: str) -> dict[str, object]:
    return {
        "domain_event_schema_version": 1,
        "event_type": "battle.started",
        "payload": {"battle_mode": "pvp"},
        "source": "websocket",
        "source_event": "gameStart",
        "source_sequence": 1,
        "source_observation_index": 0,
        "source_direction": "received",
        "idempotency_key": f"{session_id}:1:0:battle.started",
        "occurred_at": "2026-07-28T02:00:00+08:00",
        "protocol_side": None,
        "resolved_side": None,
        "visibility": "public",
        "confirmation": "confirmed",
        "confidence": 1.0,
        "authority": "websocket",
        "producer_session_id": session_id,
    }


def unused_local_port() -> int:
    with socket.socket() as probe:
        probe.bind(("127.0.0.1", 0))
        return int(probe.getsockname()[1])


class TrackerApiServerUnitTests(unittest.TestCase):
    def test_parser_defaults(self) -> None:
        args = parse_args([])
        self.assertEqual(args.host, DEFAULT_HOST)
        self.assertEqual(args.port, DEFAULT_PORT)
        self.assertEqual(args.database, default_database_path())
        self.assertEqual(args.log_level, DEFAULT_LOG_LEVEL)

    def test_parser_accepts_custom_host_port_database(self) -> None:
        args = parse_args(
            [
                "--host",
                "127.0.0.2",
                "--port",
                "8876",
                "--database",
                r"C:\tracker data\custom.sqlite3",
                "--log-level",
                "debug",
            ]
        )
        self.assertEqual(args.host, "127.0.0.2")
        self.assertEqual(args.port, 8876)
        self.assertEqual(
            args.database,
            Path(r"C:\tracker data\custom.sqlite3"),
        )
        self.assertEqual(args.log_level, "debug")

    def test_parser_rejects_electron_cdp_port(self) -> None:
        with self.assertRaises(SystemExit):
            parse_args(["--port", "9333"])

    def test_build_app_initializes_database_and_health(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            database = Path(directory) / "nested" / "events.sqlite3"
            app = build_app(database)
            self.assertTrue(database.is_file())

            with TestClient(app) as client:
                response = client.get("/api/v1/health")

            self.assertEqual(response.status_code, 200)
            body = response.json()
            self.assertEqual(body["server"], "ready")
            self.assertEqual(body["detector"], "not_running")
            self.assertIsNone(body["error"])
            self.assertIsNone(body["session_id"])
            self.assertEqual(
                body["client_profile"]["reason"],
                "not_checked_by_tracker_api_server",
            )

    def test_build_app_does_not_create_detector_session(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            database = Path(directory) / "events.sqlite3"
            app = build_app(database)

            with TestClient(app) as client:
                response = client.get("/api/v1/sessions/current")

            self.assertEqual(response.status_code, 404)
            self.assertEqual(
                response.json()["error"]["code"],
                "NO_CURRENT_SESSION",
            )
            self.assertEqual(
                EventStore(database).storage_summary()["session_count"],
                0,
            )

    def test_session_registration_and_event_ingestion(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            database = Path(directory) / "events.sqlite3"
            app = build_app(database)
            session_id = "standalone-api-sniffer-session"

            with TestClient(app) as client:
                registration = client.post(
                    "/api/v1/sessions",
                    json={
                        "session_id": session_id,
                        "producer_type": "websocket_sniffer",
                        "producer_instance": "electron",
                        "producer_version": "0.1.0",
                    },
                )
                ingestion = client.post(
                    "/api/v1/events",
                    json={
                        "session_id": session_id,
                        "event": sample_domain_event(session_id),
                    },
                )
                page = client.get(
                    "/api/v1/events",
                    params={
                        "session_id": session_id,
                        "after_sequence": 0,
                    },
                )

            self.assertEqual(registration.status_code, 201)
            self.assertEqual(ingestion.status_code, 201)
            self.assertEqual(ingestion.json()["status"], "accepted")
            self.assertEqual(page.status_code, 200)
            self.assertEqual(len(page.json()["events"]), 1)
            self.assertEqual(page.json()["next_sequence"], 1)

    def test_build_app_migrates_v1_database(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            database = Path(directory) / "events.sqlite3"
            with closing(sqlite3.connect(database)) as connection:
                connection.executescript(
                    """
                    CREATE TABLE schema_metadata (
                        key TEXT PRIMARY KEY,
                        value TEXT NOT NULL
                    );
                    INSERT INTO schema_metadata(key, value)
                    VALUES('sqlite_schema_version', '1');

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
                    """
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
                    VALUES(?, ?, NULL, ?, ?, ?, ?, NULL, ?, ?, ?)
                    """,
                    (
                        "legacy-detector",
                        "2026-07-27T00:00:00+08:00",
                        "0.0.1",
                        "detector",
                        "running",
                        "steam_custom_asar_v1",
                        "1.0.0",
                        848,
                        760,
                    ),
                )
                connection.commit()

            build_app(database)

            with closing(sqlite3.connect(database)) as connection:
                connection.row_factory = sqlite3.Row
                row = connection.execute(
                    """
                    SELECT
                        producer_type,
                        producer_instance,
                        tracker_active
                    FROM sessions
                    WHERE session_id = 'legacy-detector'
                    """
                ).fetchone()
                version = connection.execute(
                    """
                    SELECT value
                    FROM schema_metadata
                    WHERE key = 'sqlite_schema_version'
                    """
                ).fetchone()[0]

            self.assertEqual(row["producer_type"], "detector")
            self.assertEqual(row["producer_instance"], "screen")
            self.assertEqual(row["tracker_active"], 0)
            self.assertEqual(version, "3")

    def test_occupied_port_has_explicit_error(self) -> None:
        with socket.socket() as occupied:
            occupied.bind(("127.0.0.1", 0))
            port = occupied.getsockname()[1]
            error_output = io.StringIO()
            with redirect_stderr(error_output):
                return_code = main(
                    [
                        "--host",
                        "127.0.0.1",
                        "--port",
                        str(port),
                    ]
                )

        self.assertEqual(return_code, 2)
        self.assertIn("Tracker API startup error", error_output.getvalue())
        self.assertIn("already in use", error_output.getvalue())

    def test_import_does_not_load_live_screen_monitor(self) -> None:
        result = subprocess.run(
            [
                sys.executable,
                "-c",
                (
                    "import sys; import tracker_api_server; "
                    "raise SystemExit("
                    "int('live_screen_monitor' in sys.modules))"
                ),
            ],
            cwd=DETECTOR_ROOT,
            capture_output=True,
            text=True,
            timeout=10,
            check=False,
        )
        self.assertEqual(
            result.returncode,
            0,
            result.stdout + result.stderr,
        )


class TrackerApiServerSubprocessTests(unittest.TestCase):
    def test_uvicorn_subprocess_smoke_and_graceful_shutdown(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            database = Path(directory) / "events.sqlite3"
            port = unused_local_port()
            command = [
                sys.executable,
                str(ENTRYPOINT),
                "--host",
                "127.0.0.1",
                "--port",
                str(port),
                "--database",
                str(database),
                "--log-level",
                "error",
            ]
            creationflags = (
                subprocess.CREATE_NEW_PROCESS_GROUP
                if os.name == "nt"
                else 0
            )
            process = subprocess.Popen(
                command,
                cwd=DETECTOR_ROOT,
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                text=True,
                creationflags=creationflags,
            )

            connection: http.client.HTTPConnection | None = None
            try:
                deadline = time.monotonic() + 10
                first_health: dict[str, object] | None = None
                while time.monotonic() < deadline:
                    if process.poll() is not None:
                        stdout, stderr = process.communicate(timeout=2)
                        self.fail(
                            "standalone API stopped before startup\n"
                            + stdout
                            + stderr
                        )
                    try:
                        connection = http.client.HTTPConnection(
                            "127.0.0.1",
                            port,
                            timeout=1,
                        )
                        connection.request("GET", "/api/v1/health")
                        response = connection.getresponse()
                        first_health = json.loads(
                            response.read().decode("utf-8")
                        )
                        if response.status == 200:
                            break
                    except OSError:
                        time.sleep(0.05)
                    finally:
                        if connection is not None:
                            connection.close()
                            connection = None
                else:
                    self.fail("standalone API startup timed out")

                self.assertEqual(first_health["server"], "ready")
                self.assertEqual(
                    first_health["detector"],
                    "not_running",
                )
                self.assertIsNone(first_health["session_id"])
                self.assertTrue(database.is_file())

                time.sleep(3)
                self.assertIsNone(
                    process.poll(),
                    "standalone API exited during the liveness window",
                )

                connection = http.client.HTTPConnection(
                    "127.0.0.1",
                    port,
                    timeout=2,
                )
                connection.request("GET", "/api/v1/health")
                second_response = connection.getresponse()
                second_health = json.loads(
                    second_response.read().decode("utf-8")
                )
                connection.close()
                connection = None

                self.assertEqual(second_response.status, 200)
                self.assertEqual(second_health["server"], "ready")
                self.assertEqual(
                    second_health["detector"],
                    "not_running",
                )
                self.assertIsNone(second_health["session_id"])
                self.assertIsNone(process.poll())

                shutdown_signal = (
                    signal.CTRL_BREAK_EVENT
                    if os.name == "nt"
                    else signal.SIGINT
                )
                process.send_signal(shutdown_signal)
                return_code = process.wait(timeout=10)
                self.assertEqual(return_code, 0)
            finally:
                if connection is not None:
                    connection.close()
                if process.poll() is None:
                    process.terminate()
                    try:
                        process.wait(timeout=5)
                    except subprocess.TimeoutExpired:
                        process.kill()
                        process.wait(timeout=5)
                if process.stdout is not None:
                    process.stdout.close()
                if process.stderr is not None:
                    process.stderr.close()


if __name__ == "__main__":
    unittest.main()
