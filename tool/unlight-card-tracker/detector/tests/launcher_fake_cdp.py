"""Local-only fake CDP endpoint used by the launcher EXE smoke test."""

from __future__ import annotations

import argparse
import asyncio
import json
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

import websockets


class VersionHandler(BaseHTTPRequestHandler):
    websocket_port = 0

    def do_GET(self):
        if self.path != "/json/version":
            self.send_error(404)
            return
        body = json.dumps(
            {
                "Browser": "Electron/Fake",
                "webSocketDebuggerUrl": (
                    "ws://127.0.0.1:"
                    f"{self.websocket_port}/devtools/browser/fake"
                ),
            }
        ).encode("utf-8")
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, _format, *_args):
        return


async def hold_connection(connection):
    async for _message in connection:
        pass


async def run(http_port: int, websocket_port: int) -> None:
    VersionHandler.websocket_port = websocket_port
    httpd = ThreadingHTTPServer(
        ("127.0.0.1", http_port),
        VersionHandler,
    )
    thread = threading.Thread(
        target=httpd.serve_forever,
        daemon=True,
    )
    thread.start()
    try:
        async with websockets.serve(
            hold_connection,
            "127.0.0.1",
            websocket_port,
        ):
            await asyncio.Future()
    finally:
        httpd.shutdown()
        httpd.server_close()


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--http-port", type=int, default=9333)
    parser.add_argument("--websocket-port", type=int, default=9334)
    args = parser.parse_args()
    asyncio.run(run(args.http_port, args.websocket_port))


if __name__ == "__main__":
    main()
