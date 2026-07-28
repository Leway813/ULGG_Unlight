from __future__ import annotations

import io
import signal
import subprocess
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch
from uuid import uuid4

import tracker_launcher


class FakeResponse:
    def __init__(self, status_code=200, body=None):
        self.status_code = status_code
        self._body = body or {}

    def json(self):
        return self._body


class FakeHttp:
    def __init__(self):
        self.api_ready = False
        self.cdp_ready = False
        self.active_session_id = None
        self.activate_status = 200
        self.finished = []
        self.calls = []

    def get(self, url, timeout):
        self.calls.append(("GET", url))
        if url.endswith("/api/v1/health"):
            if self.api_ready:
                return FakeResponse(
                    body={
                        "api_version": "v1",
                        "server": "ready",
                    }
                )
            raise tracker_launcher.requests.ConnectionError()
        if url.endswith("/json/version"):
            if self.cdp_ready:
                return FakeResponse(
                    body={
                        "Browser": "Electron",
                        "webSocketDebuggerUrl": "ws://safe",
                    }
                )
            raise tracker_launcher.requests.ConnectionError()
        if url.endswith("/api/v1/sessions/active"):
            return FakeResponse(
                body={
                    "session": {
                        "session_id": self.active_session_id,
                    }
                }
            )
        raise AssertionError(url)

    def post(self, url, timeout):
        self.calls.append(("POST", url))
        if url.endswith("/activate"):
            return FakeResponse(status_code=self.activate_status)
        if url.endswith("/finish"):
            self.finished.append(url)
            return FakeResponse()
        raise AssertionError(url)


class FakeProcess:
    def __init__(self, lines=()):
        self.stdout = io.StringIO("".join(f"{line}\n" for line in lines))
        self.returncode = None
        self.actions = []
        self.wait_timeouts = []

    def poll(self):
        return self.returncode

    def send_signal(self, value):
        self.actions.append(("signal", value))

    def wait(self, timeout):
        self.wait_timeouts.append(timeout)
        self.actions.append(("wait", timeout))
        self.returncode = 0
        return 0

    def terminate(self):
        self.actions.append(("terminate", None))
        self.returncode = 1

    def kill(self):
        self.actions.append(("kill", None))
        self.returncode = -9


class TimeoutProcess(FakeProcess):
    def __init__(self):
        super().__init__()
        self.wait_count = 0

    def wait(self, timeout):
        self.wait_count += 1
        self.actions.append(("wait", timeout))
        if self.wait_count == 1:
            raise subprocess.TimeoutExpired("child", timeout)
        self.returncode = 1
        return 1


def config(**overrides):
    values = {
        "api_host": "127.0.0.1",
        "api_port": 8765,
        "cdp_host": "127.0.0.1",
        "cdp_port": 9333,
        "no_browser": True,
        "startup_timeout": 0.5,
        "shutdown_timeout": 0.1,
    }
    values.update(overrides)
    return tracker_launcher.LauncherConfig(**values)


class TrackerLauncherTests(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        root = Path(self.directory.name)
        self.paths = tracker_launcher.RuntimePaths(
            detector_root=root / "tracker" / "detector",
            tracker_root=root / "tracker",
            data_root=root / "data",
            frozen=False,
        )

    def tearDown(self):
        self.directory.cleanup()

    def launcher(self, http=None, **kwargs):
        return tracker_launcher.TrackerLauncher(
            config(),
            paths=self.paths,
            http=http or FakeHttp(),
            sleep=lambda _delay: None,
            output=lambda _message: None,
            **kwargs,
        )

    def test_parser_defaults_and_no_browser(self):
        parsed = tracker_launcher.parse_args([])
        self.assertEqual(parsed.api_port, 8765)
        self.assertEqual(parsed.cdp_port, 9333)
        self.assertFalse(parsed.no_browser)
        self.assertTrue(
            tracker_launcher.parse_args(["--no-browser"]).no_browser
        )
        with self.assertRaises(SystemExit):
            tracker_launcher.parse_args(["--api-host", "0.0.0.0"])

    def test_source_path_resolution(self):
        paths = tracker_launcher.resolve_runtime_paths(
            frozen=False,
            module_file=self.paths.detector_root / "tracker_launcher.py",
            data_root=self.paths.data_root,
        )
        self.assertEqual(paths.detector_root, self.paths.detector_root)
        self.assertEqual(paths.tracker_root, self.paths.tracker_root)

    def test_frozen_path_resolution(self):
        bundle = Path(self.directory.name) / "_internal"
        paths = tracker_launcher.resolve_runtime_paths(
            frozen=True,
            bundle_root=bundle,
            data_root=self.paths.data_root,
        )
        self.assertEqual(paths.tracker_root, bundle.resolve() / "tracker")
        command = tracker_launcher.component_command(
            "api", ["--port", "8765"], paths
        )
        self.assertEqual(command[1:3], ["--internal-component", "api"])

    def test_parses_only_complete_uuid_session_line(self):
        session_id = str(uuid4())
        self.assertEqual(
            tracker_launcher.parse_session_line(
                f"TRACKER_SESSION_ID={session_id}"
            ),
            session_id,
        )
        self.assertIsNone(
            tracker_launcher.parse_session_line(
                "TRACKER_SESSION_ID=abcd1234"
            )
        )

    def test_console_output_flushes_machine_readable_lines(self):
        with patch("builtins.print") as mocked_print:
            tracker_launcher.console_output(
                "TRACKER_SESSION_ID=00000000-0000-0000-0000-000000000000"
            )
        mocked_print.assert_called_once_with(
            "TRACKER_SESSION_ID=00000000-0000-0000-0000-000000000000",
            flush=True,
        )

    def test_existing_compatible_api_is_reused(self):
        http = FakeHttp()
        http.api_ready = True
        launcher = self.launcher(http=http)
        launcher._port_open = lambda *_args: True
        launcher.ensure_api()
        self.assertFalse(launcher.api_owned)
        self.assertIsNone(launcher.api_process)

    def test_launcher_starts_api_when_unavailable(self):
        http = FakeHttp()
        process = FakeProcess()

        def popen(*_args, **_kwargs):
            http.api_ready = True
            return process

        launcher = self.launcher(http=http, popen=popen)
        launcher._port_open = lambda *_args: False
        launcher.ensure_api()
        self.assertTrue(launcher.api_owned)
        self.assertIs(launcher.api_process, process)

    def test_non_tracker_port_occupant_is_rejected(self):
        launcher = self.launcher()
        launcher._port_open = lambda *_args: True
        with self.assertRaisesRegex(
            tracker_launcher.LauncherError,
            "非 Tracker",
        ):
            launcher.ensure_api()

    def test_waits_for_cdp_then_starts_sniffer(self):
        http = FakeHttp()
        checks = {"count": 0}

        def cdp_ready():
            checks["count"] += 1
            return checks["count"] >= 2

        session_id = str(uuid4())
        process = FakeProcess(
            [f"TRACKER_SESSION_ID={session_id}"]
        )
        launcher = self.launcher(
            http=http,
            popen=lambda *_args, **_kwargs: process,
        )
        launcher._cdp_ready = cdp_ready
        launcher.wait_for_cdp()
        launcher.start_sniffer()
        self.assertEqual(launcher.session_id, session_id)

    def test_registration_failure_detects_sniffer_exit(self):
        process = FakeProcess()
        process.returncode = 2
        launcher = self.launcher(
            popen=lambda *_args, **_kwargs: process,
        )
        with self.assertRaises(tracker_launcher.ComponentExitedError):
            launcher.start_sniffer()

    def test_activate_and_verify_active_session(self):
        http = FakeHttp()
        session_id = str(uuid4())
        http.active_session_id = session_id
        launcher = self.launcher(http=http)
        launcher.session_id = session_id
        launcher.activate_session()
        self.assertTrue(
            any(url.endswith("/activate") for _, url in http.calls)
        )

    def test_active_session_mismatch_fails(self):
        http = FakeHttp()
        launcher = self.launcher(http=http)
        launcher.session_id = str(uuid4())
        http.active_session_id = str(uuid4())
        with self.assertRaisesRegex(
            tracker_launcher.LauncherError,
            "不一致",
        ):
            launcher.activate_session()

    def test_browser_opens_once_and_no_browser_skips(self):
        opened = []
        launcher = tracker_launcher.TrackerLauncher(
            config(no_browser=False),
            paths=self.paths,
            http=FakeHttp(),
            browser_open=opened.append,
            output=lambda _message: None,
        )
        launcher.open_browser()
        launcher.open_browser()
        self.assertEqual(opened, [launcher.config.tracker_url])

        disabled = self.launcher()
        disabled.browser_open = opened.append
        disabled.open_browser()
        self.assertEqual(len(opened), 1)

    def test_shutdown_stops_sniffer_before_owned_api_and_finishes(self):
        order = []
        http = FakeHttp()
        launcher = self.launcher(http=http)
        launcher.session_id = str(uuid4())
        launcher.sniffer_process = FakeProcess()
        launcher.api_process = FakeProcess()
        launcher.api_owned = True
        original = launcher._stop_process

        def stop(process, component):
            order.append(component)
            original(process, component)

        launcher._stop_process = stop
        launcher.shutdown()
        self.assertEqual(order, ["Sniffer", "Tracker API"])
        self.assertEqual(len(http.finished), 1)

    def test_external_api_is_not_stopped(self):
        launcher = self.launcher()
        external = FakeProcess()
        launcher.api_process = external
        launcher.api_owned = False
        launcher.shutdown()
        self.assertEqual(external.actions, [])

    def test_graceful_timeout_falls_back_to_terminate(self):
        launcher = self.launcher()
        process = TimeoutProcess()
        launcher._stop_process(process, "Sniffer")
        self.assertIn(("terminate", None), process.actions)
        self.assertNotIn(("kill", None), process.actions)

    def test_api_and_sniffer_unexpected_exit_are_named(self):
        launcher = self.launcher()
        launcher.api_owned = True
        launcher.api_process = FakeProcess()
        launcher.api_process.returncode = 3
        with self.assertRaisesRegex(
            tracker_launcher.ComponentExitedError,
            "Tracker API",
        ):
            launcher.wait()

        launcher.api_owned = False
        launcher.sniffer_process = FakeProcess()
        launcher.sniffer_process.returncode = 4
        with self.assertRaisesRegex(
            tracker_launcher.ComponentExitedError,
            "Sniffer",
        ):
            launcher.wait()

    def test_child_commands_preserve_formal_sniffer_options(self):
        session_id = str(uuid4())
        captured = []
        process = FakeProcess(
            [f"TRACKER_SESSION_ID={session_id}"]
        )

        def popen(command, **_kwargs):
            captured.extend(command)
            return process

        launcher = self.launcher(popen=popen)
        launcher.start_sniffer()
        for value in (
            "--all",
            "--log",
            "--event-dir",
            "--battle-observations",
            "--process-battle-state",
            "--battle-console-level",
            "--tracker-api-url",
            "--tracker-api-required",
        ):
            self.assertIn(value, captured)

    def test_build_spec_is_one_folder_console_and_excludes_runtime_data(self):
        detector = Path(__file__).resolve().parent.parent
        spec = (detector / "UnlightTrackerLauncher.spec").read_text(
            encoding="utf-8"
        )
        self.assertIn('name="UnlightTrackerLauncher"', spec)
        self.assertIn("console=True", spec)
        self.assertIn("COLLECT(", spec)
        for forbidden in (
            "debug_frames",
            "events.sqlite3",
            "__pycache__",
            "deck/",
        ):
            self.assertNotIn(forbidden, spec)


class ServerResourceRootTests(unittest.TestCase):
    def test_server_supports_frozen_tracker_root_environment(self):
        server_source = (
            Path(__file__).resolve().parent.parent / "server.py"
        ).read_text(encoding="utf-8")
        self.assertIn("UNLIGHT_TRACKER_ROOT", server_source)


if __name__ == "__main__":
    unittest.main()
