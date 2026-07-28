from __future__ import annotations

import argparse
import os
import signal
import socket
import sys
from pathlib import Path
from typing import Sequence

import uvicorn
from fastapi import FastAPI

from client_profile import (
    APP_ASAR_PATH,
    EXPECTED_APP_ASAR_SHA256,
    PROFILE_ID,
    ClientProfileStatus,
)
from event_store import EventStore, default_database_path
from server import RuntimeStatus, create_app


DEFAULT_HOST = "127.0.0.1"
DEFAULT_PORT = 8765
DEFAULT_LOG_LEVEL = "info"
ELECTRON_CDP_PORT = 9333
LOG_LEVELS = ("critical", "error", "warning", "info", "debug", "trace")


class PortUnavailableError(RuntimeError):
    pass


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        description=(
            "Run the durable Tracker API without the screen detector."
        ),
    )
    parser.add_argument(
        "--host",
        default=DEFAULT_HOST,
        help=f"API bind host (default: {DEFAULT_HOST})",
    )
    parser.add_argument(
        "--port",
        type=int,
        default=DEFAULT_PORT,
        help=f"API bind port (default: {DEFAULT_PORT})",
    )
    parser.add_argument(
        "--database",
        type=Path,
        default=default_database_path(),
        help="SQLite event log path",
    )
    parser.add_argument(
        "--log-level",
        choices=LOG_LEVELS,
        default=DEFAULT_LOG_LEVEL,
        help=f"Uvicorn log level (default: {DEFAULT_LOG_LEVEL})",
    )
    return parser


def parse_args(
    argv: Sequence[str] | None = None,
) -> argparse.Namespace:
    parser = build_parser()
    args = parser.parse_args(argv)
    if not 1 <= args.port <= 65535:
        parser.error("--port must be between 1 and 65535")
    if args.port == ELECTRON_CDP_PORT:
        parser.error(
            f"port {ELECTRON_CDP_PORT} is reserved for Electron CDP"
        )
    return args


def standalone_client_profile() -> ClientProfileStatus:
    """Describe that this process intentionally does not inspect app.asar."""
    return ClientProfileStatus(
        profile_id=PROFILE_ID,
        supported=False,
        app_asar_path=str(APP_ASAR_PATH),
        expected_app_asar_sha256=EXPECTED_APP_ASAR_SHA256,
        actual_app_asar_sha256=None,
        reason="not_checked_by_tracker_api_server",
    )


def build_app(
    database_path: Path | str | None = None,
) -> FastAPI:
    event_store = EventStore(database_path)
    event_store.initialize()
    return create_app(
        event_store=event_store,
        client_profile=standalone_client_profile(),
        runtime_status=RuntimeStatus(
            detector="not_running",
            error=None,
        ),
    )


def ensure_port_available(host: str, port: int) -> None:
    try:
        addresses = socket.getaddrinfo(
            host,
            port,
            type=socket.SOCK_STREAM,
        )
    except OSError as error:
        raise PortUnavailableError(
            f"cannot resolve bind address {host}:{port}: {error}"
        ) from error

    last_error: OSError | None = None
    for family, socket_type, protocol, _, address in addresses:
        try:
            with socket.socket(family, socket_type, protocol) as probe:
                probe.bind(address)
            return
        except OSError as error:
            last_error = error

    raise PortUnavailableError(
        f"cannot bind Tracker API to {host}:{port}; "
        f"the address is unavailable or already in use: {last_error}"
    )


def run_server(args: argparse.Namespace) -> int:
    ensure_port_available(args.host, args.port)
    database_path = Path(args.database).resolve()
    app = build_app(database_path)

    print(f"Tracker UI: http://{args.host}:{args.port}/tracker/")
    print(
        "Tracker API: "
        f"http://{args.host}:{args.port}/api/v1/health"
    )
    print(f"SQLite database: {database_path}")
    print("Detector: not running (standalone API mode)")

    server = uvicorn.Server(
        uvicorn.Config(
            app,
            host=args.host,
            port=args.port,
            log_level=args.log_level,
        )
    )
    previous_sigbreak_handler = None
    if os.name == "nt":
        previous_sigbreak_handler = signal.getsignal(signal.SIGBREAK)

        def finish_sigbreak(
            _signal_number: int,
            _frame: object,
        ) -> None:
            return None

        signal.signal(signal.SIGBREAK, finish_sigbreak)
    try:
        server.run()
    finally:
        if previous_sigbreak_handler is not None:
            signal.signal(
                signal.SIGBREAK,
                previous_sigbreak_handler,
            )
    return 0


def main(argv: Sequence[str] | None = None) -> int:
    args = parse_args(argv)
    try:
        return run_server(args)
    except PortUnavailableError as error:
        print(f"Tracker API startup error: {error}", file=sys.stderr)
        return 2
    except KeyboardInterrupt:
        return 0


if __name__ == "__main__":
    raise SystemExit(main())
