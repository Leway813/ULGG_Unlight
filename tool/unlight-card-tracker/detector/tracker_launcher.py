"""Foreground Windows launcher for the Unlight Tracker runtime."""

from __future__ import annotations

import argparse
import os
import signal
import socket
import subprocess
import sys
import threading
import time
import webbrowser
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Callable, Sequence
from urllib.parse import quote
from uuid import UUID

import requests


DEFAULT_API_HOST = "127.0.0.1"
DEFAULT_API_PORT = 8765
DEFAULT_CDP_HOST = "127.0.0.1"
DEFAULT_CDP_PORT = 9333
DEFAULT_STARTUP_TIMEOUT = 30.0
DEFAULT_SHUTDOWN_TIMEOUT = 10.0
TRACKER_PATH = "/tracker/"
SESSION_LINE_PREFIX = "TRACKER_SESSION_ID="
INTERNAL_COMPONENT_FLAG = "--internal-component"


class LauncherError(RuntimeError):
    pass


class ComponentExitedError(LauncherError):
    def __init__(self, component: str, exit_code: int) -> None:
        super().__init__(
            f"{component} 非預期結束，exit code={exit_code}"
        )
        self.component = component
        self.exit_code = exit_code


@dataclass(frozen=True)
class RuntimePaths:
    detector_root: Path
    tracker_root: Path
    data_root: Path
    frozen: bool


@dataclass(frozen=True)
class LauncherConfig:
    api_host: str
    api_port: int
    cdp_host: str
    cdp_port: int
    no_browser: bool
    startup_timeout: float
    shutdown_timeout: float

    @property
    def api_origin(self) -> str:
        return f"http://{self.api_host}:{self.api_port}"

    @property
    def tracker_url(self) -> str:
        return self.api_origin + TRACKER_PATH


def default_data_root() -> Path:
    local_app_data = os.environ.get("LOCALAPPDATA")
    if local_app_data:
        return (
            Path(local_app_data)
            / "ULGG"
            / "unlight-card-tracker"
        )
    return Path.home() / ".ulgg" / "unlight-card-tracker"


def resolve_runtime_paths(
    *,
    frozen: bool | None = None,
    module_file: str | Path | None = None,
    bundle_root: str | Path | None = None,
    data_root: str | Path | None = None,
) -> RuntimePaths:
    is_frozen = (
        bool(getattr(sys, "frozen", False))
        if frozen is None
        else frozen
    )
    if is_frozen:
        root = Path(
            bundle_root
            or getattr(sys, "_MEIPASS", Path(sys.executable).parent)
        ).resolve()
        detector_root = root / "detector"
        tracker_root = root / "tracker"
    else:
        detector_root = Path(
            module_file or __file__
        ).resolve().parent
        tracker_root = detector_root.parent
    return RuntimePaths(
        detector_root=detector_root,
        tracker_root=tracker_root,
        data_root=Path(
            data_root or default_data_root()
        ).resolve(),
        frozen=is_frozen,
    )


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        description="啟動 Unlight Tracker API、Sniffer 與瀏覽器。",
    )
    parser.add_argument("--api-host", default=DEFAULT_API_HOST)
    parser.add_argument("--api-port", type=int, default=DEFAULT_API_PORT)
    parser.add_argument("--cdp-host", default=DEFAULT_CDP_HOST)
    parser.add_argument("--cdp-port", type=int, default=DEFAULT_CDP_PORT)
    parser.add_argument("--no-browser", action="store_true")
    parser.add_argument(
        "--startup-timeout",
        type=float,
        default=DEFAULT_STARTUP_TIMEOUT,
    )
    parser.add_argument(
        "--shutdown-timeout",
        type=float,
        default=DEFAULT_SHUTDOWN_TIMEOUT,
    )
    return parser


def parse_args(
    argv: Sequence[str] | None = None,
) -> LauncherConfig:
    parser = build_parser()
    args = parser.parse_args(argv)
    for name in ("api_port", "cdp_port"):
        if not 1 <= getattr(args, name) <= 65535:
            parser.error(f"--{name.replace('_', '-')} must be 1..65535")
    if args.api_host != DEFAULT_API_HOST:
        parser.error("Tracker API must bind to 127.0.0.1")
    if args.api_port == args.cdp_port:
        parser.error("Tracker API 與 Electron CDP 不可使用相同 port")
    if args.cdp_host not in {"127.0.0.1", "localhost"}:
        parser.error("第一版只支援本機 Electron CDP")
    if args.startup_timeout <= 0 or args.shutdown_timeout <= 0:
        parser.error("timeouts must be positive")
    return LauncherConfig(
        api_host=args.api_host,
        api_port=args.api_port,
        cdp_host=args.cdp_host,
        cdp_port=args.cdp_port,
        no_browser=args.no_browser,
        startup_timeout=args.startup_timeout,
        shutdown_timeout=args.shutdown_timeout,
    )


def install_shutdown_signal_handlers() -> None:
    handled = [signal.SIGINT, signal.SIGTERM]
    if os.name == "nt":
        handled.append(signal.SIGBREAK)
    for handled_signal in handled:
        signal.signal(
            handled_signal,
            signal.default_int_handler,
        )


def _child_process_options() -> dict[str, Any]:
    if os.name == "nt":
        return {
            "creationflags": subprocess.CREATE_NEW_PROCESS_GROUP,
        }
    return {"start_new_session": True}


def component_command(
    component: str,
    component_args: Sequence[str],
    paths: RuntimePaths,
) -> list[str]:
    if component not in {"api", "sniffer"}:
        raise ValueError("unsupported component")
    if paths.frozen:
        return [
            sys.executable,
            INTERNAL_COMPONENT_FLAG,
            component,
            "--",
            *component_args,
        ]
    script_name = (
        "tracker_api_server.py"
        if component == "api"
        else "unlight_websocket_sniffer.py"
    )
    return [
        sys.executable,
        str(paths.detector_root / script_name),
        *component_args,
    ]


def parse_session_line(line: str) -> str | None:
    if not line.startswith(SESSION_LINE_PREFIX):
        return None
    value = line[len(SESSION_LINE_PREFIX):].strip()
    try:
        parsed = UUID(value)
    except (ValueError, AttributeError):
        return None
    return str(parsed) if str(parsed) == value.lower() else None


def console_output(message: str) -> None:
    print(message, flush=True)


class TrackerLauncher:
    def __init__(
        self,
        config: LauncherConfig,
        *,
        paths: RuntimePaths | None = None,
        http: Any = None,
        popen: Callable[..., Any] = subprocess.Popen,
        browser_open: Callable[[str], Any] = webbrowser.open,
        sleep: Callable[[float], None] = time.sleep,
        monotonic: Callable[[], float] = time.monotonic,
        output: Callable[[str], None] = console_output,
    ) -> None:
        self.config = config
        self.paths = paths or resolve_runtime_paths()
        self.http = http or requests.Session()
        self.popen = popen
        self.browser_open = browser_open
        self.sleep = sleep
        self.monotonic = monotonic
        self.output = output
        self.api_process: Any = None
        self.sniffer_process: Any = None
        self.api_owned = False
        self.session_id: str | None = None
        self.browser_opened = False
        self._session_ready = threading.Event()
        self._reader_threads: list[threading.Thread] = []

    def _port_open(self, host: str, port: int) -> bool:
        try:
            with socket.create_connection((host, port), timeout=0.5):
                return True
        except OSError:
            return False

    def _health_ready(self) -> bool:
        try:
            response = self.http.get(
                self.config.api_origin + "/api/v1/health",
                timeout=1.0,
            )
            data = response.json()
        except (requests.RequestException, ValueError, TypeError):
            return False
        return (
            response.status_code == 200
            and isinstance(data, dict)
            and data.get("api_version") == "v1"
            and data.get("server") == "ready"
        )

    def _cdp_ready(self) -> bool:
        try:
            response = self.http.get(
                "http://"
                f"{self.config.cdp_host}:{self.config.cdp_port}"
                "/json/version",
                timeout=1.0,
            )
            data = response.json()
        except (requests.RequestException, ValueError, TypeError):
            return False
        return (
            response.status_code == 200
            and isinstance(data, dict)
            and (
                isinstance(data.get("webSocketDebuggerUrl"), str)
                or isinstance(data.get("Browser"), str)
            )
        )

    def _spawn(self, command: Sequence[str], component: str) -> Any:
        environment = os.environ.copy()
        environment["PYTHONUNBUFFERED"] = "1"
        environment["UNLIGHT_TRACKER_ROOT"] = str(
            self.paths.tracker_root
        )
        self.paths.data_root.mkdir(parents=True, exist_ok=True)
        process = self.popen(
            list(command),
            cwd=self.paths.data_root,
            env=environment,
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            text=True,
            encoding="utf-8",
            errors="replace",
            bufsize=1,
            **_child_process_options(),
        )
        thread = threading.Thread(
            target=self._forward_output,
            args=(process, component),
            name=f"tracker-{component}-output",
            daemon=True,
        )
        thread.start()
        self._reader_threads.append(thread)
        return process

    def _forward_output(self, process: Any, component: str) -> None:
        stream = process.stdout
        if stream is None:
            return
        for raw_line in stream:
            line = raw_line.rstrip("\r\n")
            session_id = (
                parse_session_line(line)
                if component == "Sniffer"
                else None
            )
            if session_id is not None:
                self.session_id = session_id
                self._session_ready.set()
            self.output(f"[{component}] {line}")

    def _wait_until(
        self,
        predicate: Callable[[], bool],
        *,
        component: str,
        process: Any = None,
    ) -> None:
        deadline = self.monotonic() + self.config.startup_timeout
        while self.monotonic() < deadline:
            if predicate():
                return
            if process is not None and process.poll() is not None:
                raise ComponentExitedError(
                    component,
                    process.returncode,
                )
            self.sleep(0.25)
        raise LauncherError(f"{component} 啟動逾時")

    def ensure_api(self) -> None:
        if self._health_ready():
            self.output("沿用已啟動的相容 Tracker API")
            return
        if self._port_open(
            self.config.api_host,
            self.config.api_port,
        ):
            raise LauncherError(
                f"{self.config.api_port} 已被非 Tracker 程序占用"
            )

        command = component_command(
            "api",
            [
                "--host",
                self.config.api_host,
                "--port",
                str(self.config.api_port),
            ],
            self.paths,
        )
        self.api_process = self._spawn(command, "API")
        self.api_owned = True
        self._wait_until(
            self._health_ready,
            component="Tracker API",
            process=self.api_process,
        )
        self.output("Tracker API 已就緒")

    def wait_for_cdp(self) -> None:
        if not self._cdp_ready():
            self.output(
                f"尚未偵測到遊戲 CDP {self.config.cdp_port}，"
                "請先啟動遊戲"
            )
        self._wait_until(
            self._cdp_ready,
            component="Unlight Electron CDP",
        )
        self.output("已偵測到遊戲 CDP")

    def start_sniffer(self) -> None:
        command = component_command(
            "sniffer",
            [
                "--all",
                "--log",
                "--event-dir",
                "--battle-observations",
                "--process-battle-state",
                "--battle-console-level",
                "important",
                "--tracker-api-url",
                self.config.api_origin,
                "--tracker-api-required",
                "--port",
                str(self.config.cdp_port),
            ],
            self.paths,
        )
        self.sniffer_process = self._spawn(command, "Sniffer")
        self._wait_until(
            self._session_ready.is_set,
            component="Sniffer session registration",
            process=self.sniffer_process,
        )
        self.output(f"Sniffer 已連線，session={self.session_id}")

    def activate_session(self) -> None:
        if self.session_id is None:
            raise LauncherError("Session 建立失敗")
        path_id = quote(self.session_id, safe="")
        try:
            response = self.http.post(
                self.config.api_origin
                + f"/api/v1/sessions/{path_id}/activate",
                timeout=2.0,
            )
            active = self.http.get(
                self.config.api_origin
                + "/api/v1/sessions/active",
                timeout=2.0,
            )
            body = active.json()
        except (requests.RequestException, ValueError, TypeError) as error:
            raise LauncherError("Session 啟用失敗") from error
        if response.status_code not in {200, 201}:
            raise LauncherError(
                f"Session 啟用失敗，HTTP {response.status_code}"
            )
        active_id = (
            body.get("session", {}).get("session_id")
            if isinstance(body, dict)
            else None
        )
        if active.status_code != 200 or active_id != self.session_id:
            raise LauncherError("Active session 驗證不一致")
        self.output("Session 已啟用")

    def open_browser(self) -> None:
        if self.config.no_browser or self.browser_opened:
            return
        self.browser_open(self.config.tracker_url)
        self.browser_opened = True
        self.output("Tracker 已開啟")

    def start(self) -> None:
        self.ensure_api()
        self.wait_for_cdp()
        self.start_sniffer()
        self.activate_session()
        self.open_browser()

    def wait(self) -> None:
        while True:
            if (
                self.api_owned
                and self.api_process is not None
                and self.api_process.poll() is not None
            ):
                raise ComponentExitedError(
                    "Tracker API",
                    self.api_process.returncode,
                )
            if (
                self.sniffer_process is not None
                and self.sniffer_process.poll() is not None
            ):
                raise ComponentExitedError(
                    "Sniffer",
                    self.sniffer_process.returncode,
                )
            self.sleep(0.25)

    def _stop_process(self, process: Any, component: str) -> None:
        if process is None or process.poll() is not None:
            return
        graceful_signal = (
            signal.CTRL_BREAK_EVENT
            if os.name == "nt"
            else signal.SIGINT
        )
        try:
            process.send_signal(graceful_signal)
            process.wait(timeout=self.config.shutdown_timeout)
            return
        except (OSError, subprocess.TimeoutExpired):
            self.output(f"{component} 未能於期限內正常停止")
        process.terminate()
        try:
            process.wait(timeout=min(3.0, self.config.shutdown_timeout))
            return
        except subprocess.TimeoutExpired:
            process.kill()
            process.wait(timeout=3.0)

    def _finish_session(self) -> None:
        if self.session_id is None:
            return
        try:
            response = self.http.post(
                self.config.api_origin
                + "/api/v1/sessions/"
                + quote(self.session_id, safe="")
                + "/finish",
                timeout=2.0,
            )
        except requests.RequestException:
            self.output("Session 完成狀態寫入失敗")
            return
        if response.status_code not in {200, 201}:
            self.output(
                f"Session 完成狀態寫入失敗，HTTP {response.status_code}"
            )

    def shutdown(self) -> None:
        self.output("正在正常關閉")
        self._stop_process(self.sniffer_process, "Sniffer")
        self._finish_session()
        if self.api_owned:
            self._stop_process(self.api_process, "Tracker API")

    def run(self) -> int:
        try:
            self.start()
            self.wait()
        except KeyboardInterrupt:
            return 0
        finally:
            self.shutdown()
        return 0


def run_internal_component(argv: Sequence[str]) -> int:
    if len(argv) < 2 or argv[0] != INTERNAL_COMPONENT_FLAG:
        raise ValueError("invalid internal component invocation")
    component = argv[1]
    child_args = list(argv[2:])
    if child_args and child_args[0] == "--":
        child_args.pop(0)
    paths = resolve_runtime_paths(frozen=True)
    os.environ["UNLIGHT_TRACKER_ROOT"] = str(paths.tracker_root)
    if component == "api":
        from tracker_api_server import main as api_main

        return int(api_main(child_args))
    if component == "sniffer":
        from unlight_websocket_sniffer import main as sniffer_main

        return int(sniffer_main(child_args) or 0)
    raise ValueError("unknown internal component")


def main(argv: Sequence[str] | None = None) -> int:
    values = list(sys.argv[1:] if argv is None else argv)
    if values and values[0] == INTERNAL_COMPONENT_FLAG:
        return run_internal_component(values)
    config = parse_args(values)
    install_shutdown_signal_handlers()
    launcher = TrackerLauncher(config)
    try:
        return launcher.run()
    except LauncherError as error:
        print(f"[錯誤] {error}", file=sys.stderr, flush=True)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
