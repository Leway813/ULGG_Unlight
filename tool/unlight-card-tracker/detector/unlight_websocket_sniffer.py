# -*- coding: utf-8 -*-
"""Read-only CDP WebSocket observation sniffer for UNLIGHT:Revive.

The sniffer observes WebSocket frames already visible through Chrome DevTools
Protocol. It never sends a game WebSocket frame or invokes a game action.

Default output:

* deck/events.jsonl: redacted structured observation records
* deck/event-summary.json: per-event discovery statistics on shutdown
* deck/deck1.json .. deck3.json: unchanged raw deck payloads

Optional output:

* --log: redacted deck/packets.log
* --unsafe-raw-log: unredacted deck/packets.log (explicit opt-in only)
* --event-dir: redacted deck/events/<event-name>.jsonl
"""

import argparse
import asyncio
import base64
import hashlib
import json
import math
import queue
import re
import subprocess
import sys
import threading
import time
from collections import Counter
from datetime import datetime
from pathlib import Path
from urllib.parse import parse_qsl, urlencode, urlsplit, urlunsplit

import requests
import websockets


DEBUG_PORT = 9333
STEAM_GAME_ID = "3247080"
OUT_DIR = Path("deck")

DECK_EVENTS = {"db_deck1", "db_deck2", "db_deck3"}

FOCUS_EVENTS = {
    "gameStart",
    "duelStart",
    "duel_standby",
    "phaselabel_A",
    "phaselabel_B",
    "addArray",
    "deleteArray",
    "deleteDraw",
    "deleteDraw_A",
    "deleteDraw_B",
    "chara_A",
    "chara_B",
    "card",
    "draw",
    "play",
    "move",
    "initiative",
    "battle",
    "finish",
    "result",
}

VERBOSE_EVENT_KEYWORDS = {
    "card",
    "draw",
    "array",
    "deck",
    "hand",
    "play",
    "phase",
    "move",
    "result",
    "finish",
    "damage",
    "chara",
}

SENSITIVE_QUERY_KEYS = {"steamid", "token", "session", "auth"}
SENSITIVE_VALUE_KEYS = {
    "auth",
    "authorization",
    "game_token",
    "session",
    "session_id",
    "session_token",
    "steam_id",
    "steamid",
    "token",
}
ROOM_ID_KEYS = {"room", "room_id", "roomid"}

POSITIONAL_SECRET_SCHEMA = {
    ("received", "__handshake_s"): {1: "connection_id"},
    ("sent", "I_am_ok"): {0: "room_id", 1: "session_token"},
    ("sent", "bonus_get"): {0: "session_token"},
    ("sent", "card"): {0: "room_id", 1: "session_token"},
    ("sent", "db_avatar"): {0: "session_token"},
    ("sent", "db_bonusgame"): {0: "session_token"},
    ("sent", "db_characard"): {0: "session_token"},
    ("sent", "db_deck1"): {0: "session_token"},
    ("sent", "db_deck2"): {0: "session_token"},
    ("sent", "db_deck3"): {0: "session_token"},
    ("sent", "db_friend"): {0: "session_token"},
    ("sent", "db_item_avatar"): {0: "session_token"},
    ("sent", "db_item_battle"): {0: "session_token"},
    ("sent", "db_player"): {0: "session_token"},
    ("sent", "db_stamp"): {0: "session_token"},
    ("sent", "duel_end"): {0: "session_token", 1: "room_id"},
    ("sent", "gameReady"): {0: "room_id"},
    ("sent", "gameReadyA"): {0: "room_id"},
    ("sent", "gameReadyB"): {0: "room_id"},
    ("sent", "item_battle_use"): {0: "session_token"},
    ("sent", "joinRoom"): {0: "room_id"},
    ("sent", "match_room_make"): {0: "session_token"},
    ("sent", "online"): {0: "session_token"},
    ("sent", "player_duel"): {0: "session_token"},
    ("sent", "register"): {0: "session_token"},
    ("sent", "room_in"): {0: "session_token", 2: "room_id"},
    ("sent", "throwResultDice"): {0: "session_token", 1: "round_id"},
    ("sent", "warning_check"): {0: "session_token"},
}

SAFE_LITERAL_VALUES = {
    "boot",
    "duel",
    "lose",
    "main",
    "ranked",
    "win",
}
SAFE_VALUE_PATTERNS = (
    re.compile(r"^(?:cc|mc)\d{3}(?:_r\d{2})?$", re.IGNORECASE),
    re.compile(r"^phase_[A-Za-z0-9_]+$", re.IGNORECASE),
    re.compile(
        r"^(?:bonus|reward|item|event|stage|bgm|filename)[_-][A-Za-z0-9_-]+$",
        re.IGNORECASE,
    ),
)
SAFE_TEXT_KEYS = {
    "event",
    "event_name",
    "filename",
    "host",
    "item_code",
    "name",
    "reward_code",
    "type",
}

_FILENAME_UNSAFE = re.compile(r"[^A-Za-z0-9._-]+")
_OPAQUE_IDENTIFIER = re.compile(r"^[A-Za-z0-9_-]+$")
_OPAQUE_HEX_IDENTIFIER = re.compile(r"^[0-9A-Fa-f]{24,}$")
_ALREADY_REDACTED = re.compile(r"^.{4}\.\.\..{4}$", re.DOTALL)

DIRECTION_ALIASES = {
    "received": "received",
    "sent": "sent",
    "←收到": "received",
    "→送出": "sent",
}


def current_timestamp():
    """Return a timezone-aware ISO 8601 timestamp."""
    return datetime.now().astimezone().isoformat(timespec="milliseconds")


def redact_sensitive_value(value, default="[REDACTED]"):
    """Keep only the first and last four characters of a sensitive value."""
    if value is None:
        return default
    text = str(value)
    if _ALREADY_REDACTED.fullmatch(text):
        return text
    if len(text) <= 8:
        return default
    return f"{text[:4]}...{text[-4:]}"


def redact_url(url):
    """Redact sensitive URL query parameters without changing the endpoint."""
    if not isinstance(url, str):
        return url
    try:
        parsed = urlsplit(url)
        query = parse_qsl(parsed.query, keep_blank_values=True)
        redacted_query = [
            (
                key,
                redact_sensitive_value(value)
                if key.lower() in SENSITIVE_QUERY_KEYS
                else value,
            )
            for key, value in query
        ]
        return urlunsplit(
            (
                parsed.scheme,
                parsed.netloc,
                parsed.path,
                urlencode(redacted_query, doseq=True),
                parsed.fragment,
            )
        )
    except (TypeError, ValueError):
        return url


def _normalized_key(key):
    return str(key).lower().replace("-", "_")


def normalize_direction(direction):
    return DIRECTION_ALIASES.get(direction, str(direction or "").lower())


def _shannon_entropy(value):
    counts = Counter(value)
    length = len(value)
    return -sum(
        (count / length) * math.log2(count / length)
        for count in counts.values()
    )


def is_known_safe_value(value, key_hint=None):
    """Return whether an identifier-like value is known descriptive data."""
    if not isinstance(value, str):
        return False
    if _normalized_key(key_hint) in SAFE_TEXT_KEYS:
        return True
    lowered = value.lower()
    if lowered in SAFE_LITERAL_VALUES:
        return True
    return any(pattern.fullmatch(value) for pattern in SAFE_VALUE_PATTERNS)


def looks_like_opaque_identifier(value, key_hint=None):
    """Conservatively identify high-entropy positional identifiers."""
    if not isinstance(value, str) or len(value) < 24:
        return False
    if _ALREADY_REDACTED.fullmatch(value):
        return False
    if not _OPAQUE_IDENTIFIER.fullmatch(value):
        return False
    if is_known_safe_value(value, key_hint=key_hint):
        return False
    if _OPAQUE_HEX_IDENTIFIER.fullmatch(value):
        return True

    classes = sum(
        (
            any(char.islower() for char in value),
            any(char.isupper() for char in value),
            any(char.isdigit() for char in value),
            "_" in value or "-" in value,
        )
    )
    unique_ratio = len(set(value)) / len(value)
    entropy = _shannon_entropy(value)
    return (
        (classes >= 3 and entropy >= 3.25)
        or (any(char.isdigit() for char in value) and entropy >= 3.6)
        or (entropy >= 4.2 and unique_ratio >= 0.55)
    )


def redact_payload_for_log(payload, event_name=None, direction=None):
    """Return a recursively redacted copy suitable for default logs."""

    def redact(value, key_hint=None):
        normalized_key = _normalized_key(key_hint) if key_hint is not None else ""

        if normalized_key in ROOM_ID_KEYS:
            return redact_sensitive_value(value)
        if normalized_key in SENSITIVE_VALUE_KEYS:
            return redact_sensitive_value(value)

        if isinstance(value, dict):
            return {key: redact(item, key) for key, item in value.items()}
        if isinstance(value, list):
            return [redact(item) for item in value]
        if isinstance(value, tuple):
            return [redact(item) for item in value]
        if isinstance(value, str):
            redacted_url = redact_url(value)
            if redacted_url != value:
                return redacted_url
            if looks_like_opaque_identifier(value, key_hint=key_hint):
                return redact_sensitive_value(value)
            return value
        return value

    redacted = redact(payload)

    positional_args = (
        redacted.get("args")
        if isinstance(redacted, dict)
        else redacted
    )
    original_args = (
        payload.get("args")
        if isinstance(payload, dict)
        else payload
    )
    schema = POSITIONAL_SECRET_SCHEMA.get(
        (normalize_direction(direction), event_name),
        {},
    )
    if (
        isinstance(positional_args, list)
        and isinstance(original_args, (list, tuple))
    ):
        for index in schema:
            if (
                index < len(positional_args)
                and index < len(original_args)
                and isinstance(original_args[index], str)
            ):
                positional_args[index] = redact_sensitive_value(
                    original_args[index]
                )

    return redacted


def sanitize_event_filename(event_name):
    """Return a traversal-safe, deterministic file stem for an event."""
    raw_name = str(event_name or "unnamed")
    safe_name = _FILENAME_UNSAFE.sub("_", raw_name).strip("._-")
    if not safe_name:
        safe_name = "unnamed"
    safe_name = safe_name[:80]
    if safe_name != raw_name:
        suffix = hashlib.sha256(raw_name.encode("utf-8")).hexdigest()[:8]
        safe_name = f"{safe_name}-{suffix}"
    return safe_name


def redact_discovery_record(record):
    """Reapply current redaction rules to one discovery JSON object."""
    redacted = redact_payload_for_log(record)
    if not isinstance(record, dict) or not isinstance(redacted, dict):
        return redacted

    event_name = record.get("event")
    direction = record.get("direction")
    args_summary = record.get("args_summary")
    if isinstance(args_summary, (list, tuple, dict)):
        redacted["args_summary"] = redact_payload_for_log(
            args_summary,
            event_name=event_name,
            direction=direction,
        )
    if isinstance(record.get("websocket_url"), str):
        redacted["websocket_url"] = redact_url(record["websocket_url"])
    return redacted


def _malformed_redaction_record(line_number, raw_line):
    encoded = raw_line.encode("utf-8", errors="replace")
    return {
        "type": "redaction_error",
        "reason": "malformed_json",
        "source_line": line_number,
        "byte_length": len(encoded),
        "sha256": hashlib.sha256(encoded).hexdigest(),
    }


def redact_existing_jsonl(source_path, output_path=None):
    """Write a redacted sibling JSONL without modifying the source file."""
    source = Path(source_path)
    if not source.is_file():
        raise FileNotFoundError(source)

    destination = (
        Path(output_path)
        if output_path is not None
        else source.with_name(source.name + ".redacted.jsonl")
    )
    if source.resolve() == destination.resolve():
        raise ValueError("redaction output must differ from its source")
    if destination.exists():
        raise FileExistsError(destination)
    destination.parent.mkdir(parents=True, exist_ok=True)

    stats = {
        "source": str(source),
        "output": str(destination),
        "total_lines": 0,
        "successful_lines": 0,
        "malformed_lines": 0,
        "redacted_lines": 0,
    }
    with source.open("r", encoding="utf-8", errors="replace") as source_file:
        with destination.open("x", encoding="utf-8") as output_file:
            for line_number, line in enumerate(source_file, 1):
                stats["total_lines"] += 1
                raw_line = line.rstrip("\r\n")
                try:
                    original = json.loads(raw_line)
                except (json.JSONDecodeError, TypeError, ValueError):
                    redacted = _malformed_redaction_record(
                        line_number,
                        raw_line,
                    )
                    stats["malformed_lines"] += 1
                else:
                    redacted = redact_discovery_record(original)
                    stats["successful_lines"] += 1
                    if redacted != original:
                        stats["redacted_lines"] += 1
                output_file.write(
                    json.dumps(
                        redacted,
                        ensure_ascii=False,
                        separators=(",", ":"),
                    )
                    + "\n"
                )
    return stats


def _merge_redaction_stats(target, source):
    for key in (
        "total_lines",
        "successful_lines",
        "malformed_lines",
        "redacted_lines",
    ):
        target[key] += source[key]


def redact_existing_directory(source_directory):
    """Redact events.jsonl and events/*.jsonl into a sibling tree."""
    source_root = Path(source_directory)
    if not source_root.is_dir():
        raise NotADirectoryError(source_root)

    inputs = []
    root_events = source_root / "events.jsonl"
    if root_events.is_file():
        inputs.append((root_events, Path("events.jsonl")))
    events_directory = source_root / "events"
    if events_directory.is_dir():
        inputs.extend(
            (event_path, Path("events") / event_path.name)
            for event_path in sorted(events_directory.glob("*.jsonl"))
            if event_path.is_file()
        )

    output_root = source_root / "redacted"
    aggregate = {
        "source": str(source_root),
        "output": str(output_root),
        "files": [],
        "total_lines": 0,
        "successful_lines": 0,
        "malformed_lines": 0,
        "redacted_lines": 0,
    }
    for source, relative_path in inputs:
        file_stats = redact_existing_jsonl(
            source,
            output_path=output_root / relative_path,
        )
        aggregate["files"].append(file_stats)
        _merge_redaction_stats(aggregate, file_stats)
    return aggregate


def print_redaction_stats(stats):
    print(f"輸出：{stats['output']}")
    if "files" in stats:
        print(f"檔案數：{len(stats['files'])}")
    print(f"總行數：{stats['total_lines']}")
    print(f"成功行數：{stats['successful_lines']}")
    print(f"Malformed 行數：{stats['malformed_lines']}")
    print(f"發生 redaction 行數：{stats['redacted_lines']}")


def json_type_name(value):
    if value is None:
        return "null"
    if isinstance(value, bool):
        return "bool"
    if isinstance(value, int):
        return "int"
    if isinstance(value, float):
        return "float"
    if isinstance(value, str):
        return "string"
    if isinstance(value, list):
        return "array"
    if isinstance(value, dict):
        return "object"
    return type(value).__name__


def summarize_binary_payload(payload_data, opcode=2):
    """Summarize a CDP binary frame without retaining its raw content."""
    encoded = payload_data if isinstance(payload_data, str) else str(payload_data)
    decode_error = None
    try:
        binary = base64.b64decode(encoded, validate=True)
    except (ValueError, TypeError) as exc:
        binary = encoded.encode("utf-8", errors="replace")
        decode_error = type(exc).__name__

    summary = {
        "kind": "binary",
        "opcode": opcode,
        "byte_length": len(binary),
        "sha256": hashlib.sha256(binary).hexdigest(),
    }
    if decode_error:
        summary["base64_decode_error"] = decode_error
    return summary


def summarize_unparsed_payload(payload_data, kind="malformed_text"):
    encoded = (
        payload_data.encode("utf-8", errors="replace")
        if isinstance(payload_data, str)
        else str(payload_data).encode("utf-8", errors="replace")
    )
    return {
        "kind": kind,
        "byte_length": len(encoded),
        "sha256": hashlib.sha256(encoded).hexdigest(),
    }


class ULSniffer:
    def __init__(
        self,
        port=DEBUG_PORT,
        log_all=False,
        unsafe_raw_log=False,
        event_dir=False,
        output_dir=OUT_DIR,
        enable_stdin_markers=True,
    ):
        self.port = port
        self.log_all = log_all
        self.unsafe_raw_log = unsafe_raw_log
        self.write_event_files = event_dir
        self.output_dir = Path(output_dir)
        self.enable_stdin_markers = enable_stdin_markers

        self.msg_id = 0
        self.sequence = 0
        self.ws = None
        self.sessions = set()
        self.session_token = None
        self.game_url = None
        self.decks = {}
        self.websocket_urls = {}
        self.event_stats = {}
        self._record_lock = threading.RLock()
        self._closed = False
        self._marker_thread = None
        self._marker_errors = queue.SimpleQueue()

        self.output_dir.mkdir(parents=True, exist_ok=True)
        self.events_path = self.output_dir / "events.jsonl"
        self.events_file = self.events_path.open(
            "a", encoding="utf-8", buffering=1
        )
        self.events_dir = self.output_dir / "events"
        if self.write_event_files:
            self.events_dir.mkdir(parents=True, exist_ok=True)

        self.logfile = None
        if log_all:
            self.logfile = (self.output_dir / "packets.log").open(
                "a", encoding="utf-8", buffering=1
            )

    # ------------------------------------------------------------ CDP
    def next_id(self):
        self.msg_id += 1
        return self.msg_id

    async def send(self, method, params=None, session_id=None):
        """Send a CDP control command, never a game WebSocket frame."""
        msg = {"id": self.next_id(), "method": method, "params": params or {}}
        if session_id:
            msg["sessionId"] = session_id
        await self.ws.send(json.dumps(msg))
        return msg["id"]

    def browser_endpoint(self):
        url = f"http://127.0.0.1:{self.port}/json/version"
        info = requests.get(url, timeout=5).json()
        return info["webSocketDebuggerUrl"]

    async def run(self):
        endpoint = self.browser_endpoint()
        print(f"[+] 連上 CDP: {redact_url(endpoint)}")
        self.start_marker_reader()

        try:
            async with websockets.connect(endpoint, max_size=None) as ws:
                self.ws = ws
                await self.send("Target.setDiscoverTargets", {"discover": True})
                await self.send(
                    "Target.setAutoAttach",
                    {
                        "autoAttach": True,
                        "waitForDebuggerOnStart": True,
                        "flatten": True,
                    },
                )

                print("[+] WebSocket observation discovery 執行中")
                if self.enable_stdin_markers and sys.stdin.isatty():
                    print("    可輸入 MARK <label> 加入人工 observation marker")
                print("    Ctrl+C 結束\n")

                async for raw in ws:
                    try:
                        await self.on_message(json.loads(raw))
                    except Exception as exc:
                        print(f"[!] 處理 CDP 訊息失敗，已略過: {exc}")
                    self._report_marker_errors()
        finally:
            self.close()

    async def on_message(self, msg):
        method = msg.get("method")
        params = msg.get("params", {})
        session_id = msg.get("sessionId")

        if method == "Target.attachedToTarget":
            child = params["sessionId"]
            info = params["targetInfo"]
            self.sessions.add(child)
            await self.send("Network.enable", {}, child)
            await self.send(
                "Target.setAutoAttach",
                {
                    "autoAttach": True,
                    "waitForDebuggerOnStart": True,
                    "flatten": True,
                },
                child,
            )
            await self.send("Runtime.runIfWaitingForDebugger", {}, child)
            target_url = redact_url(info.get("url", ""))
            print(f"[+] attach: {info.get('type')} {target_url[:160]}")

        elif method == "Network.webSocketCreated":
            url = params.get("url", "")
            request_id = params.get("requestId")
            self.websocket_urls[(session_id, request_id)] = url
            print(f"[WS] 建立連線 {redact_url(url)}")
            if "playunlight" in url:
                self.game_url = url

        elif method == "Network.webSocketFrameSent":
            self.handle_frame(params, "sent", session_id=session_id)

        elif method == "Network.webSocketFrameReceived":
            self.handle_frame(params, "received", session_id=session_id)

    # ------------------------------------------------------------ discovery
    def _websocket_url_for(self, params, session_id):
        request_id = params.get("requestId")
        return self.websocket_urls.get(
            (session_id, request_id),
            self.game_url,
        )

    def _next_sequence_unlocked(self):
        self.sequence += 1
        return self.sequence

    def _write_jsonl_unlocked(self, path, record):
        serialized = json.dumps(record, ensure_ascii=False, separators=(",", ":"))
        if path == self.events_path:
            self.events_file.write(serialized + "\n")
            self.events_file.flush()
            return
        with path.open("a", encoding="utf-8") as event_file:
            event_file.write(serialized + "\n")

    def _update_stats_unlocked(self, record, payload_size):
        event_name = record.get("event") or "__unnamed__"
        stats = self.event_stats.setdefault(
            event_name,
            {
                "received_count": 0,
                "sent_count": 0,
                "first_seen_at": record["timestamp"],
                "last_seen_at": record["timestamp"],
                "arg_types": Counter(),
                "max_payload_size": 0,
            },
        )
        direction_key = f"{record.get('direction')}_count"
        if direction_key in stats:
            stats[direction_key] += 1
        stats["last_seen_at"] = record["timestamp"]
        type_signature = json.dumps(
            record.get("arg_types", []), ensure_ascii=False, separators=(",", ":")
        )
        stats["arg_types"][type_signature] += 1
        stats["max_payload_size"] = max(
            stats["max_payload_size"], int(payload_size)
        )

    def _record_frame(
        self,
        direction,
        event_name,
        arg_types,
        args_summary,
        session_id,
        websocket_url,
        opcode,
        payload_size,
    ):
        with self._record_lock:
            if self._closed:
                return None
            record = {
                "timestamp": current_timestamp(),
                "direction": direction,
                "event": event_name,
                "arg_types": arg_types,
                "args_summary": args_summary,
                "session_target": redact_payload_for_log(session_id),
                "websocket_url": redact_url(websocket_url),
                "sequence": self._next_sequence_unlocked(),
                "opcode": opcode,
                "payload_size": payload_size,
            }
            self._write_jsonl_unlocked(self.events_path, record)
            if self.write_event_files:
                filename = sanitize_event_filename(event_name) + ".jsonl"
                self._write_jsonl_unlocked(self.events_dir / filename, record)
            self._update_stats_unlocked(record, payload_size)
            return record

    def _write_packet_log(self, timestamp, direction, raw_payload, decoded, event):
        if not self.logfile:
            return
        if self.unsafe_raw_log:
            log_payload = (
                raw_payload
                if isinstance(raw_payload, str)
                else str(raw_payload)
            )
        elif decoded is not None:
            redacted = redact_payload_for_log(
                decoded,
                event_name=event,
                direction=direction,
            )
            log_payload = json.dumps(redacted, ensure_ascii=False)
        else:
            log_payload = json.dumps(
                summarize_unparsed_payload(raw_payload),
                ensure_ascii=False,
            )
        self.logfile.write(f"{timestamp} {direction} {log_payload}\n")
        self.logfile.flush()

    def handle_frame(self, params, direction, session_id=None):
        response = params.get("response", {})
        payload = response.get("payloadData", "")
        opcode = response.get("opcode", 1)
        websocket_url = self._websocket_url_for(params, session_id)
        timestamp = current_timestamp()

        if opcode == 2:
            binary_summary = summarize_binary_payload(payload, opcode=opcode)
            record = self._record_frame(
                direction=direction,
                event_name="__binary__",
                arg_types=[],
                args_summary=binary_summary,
                session_id=session_id,
                websocket_url=websocket_url,
                opcode=opcode,
                payload_size=binary_summary["byte_length"],
            )
            self._write_packet_log(
                timestamp, direction, payload, decoded=None, event="__binary__"
            )
            return record

        try:
            decoded = json.loads(payload)
        except (json.JSONDecodeError, TypeError, ValueError):
            malformed_summary = summarize_unparsed_payload(payload)
            record = self._record_frame(
                direction=direction,
                event_name="__malformed_json__",
                arg_types=[],
                args_summary=malformed_summary,
                session_id=session_id,
                websocket_url=websocket_url,
                opcode=opcode,
                payload_size=malformed_summary["byte_length"],
            )
            self._write_packet_log(
                timestamp,
                direction,
                payload,
                decoded=None,
                event="__malformed_json__",
            )
            return record

        if not isinstance(decoded, dict):
            args_summary = {
                "kind": "non_object_json",
                "json_type": json_type_name(decoded),
            }
            payload_size = len(
                str(payload).encode("utf-8", errors="replace")
            )
            record = self._record_frame(
                direction=direction,
                event_name="__non_object_json__",
                arg_types=[],
                args_summary=args_summary,
                session_id=session_id,
                websocket_url=websocket_url,
                opcode=opcode,
                payload_size=payload_size,
            )
            self._write_packet_log(
                timestamp,
                direction,
                payload,
                decoded=decoded,
                event="__non_object_json__",
            )
            return record

        event = str(decoded.get("event") or "__unnamed__")
        args = decoded.get("args", [])
        if not isinstance(args, list):
            args = [args]
        arg_types = [json_type_name(arg) for arg in args]
        redacted_args = redact_payload_for_log(
            args,
            event_name=event,
            direction=direction,
        )
        payload_size = len(str(payload).encode("utf-8", errors="replace"))

        record = self._record_frame(
            direction=direction,
            event_name=event,
            arg_types=arg_types,
            args_summary=redacted_args,
            session_id=session_id,
            websocket_url=websocket_url,
            opcode=opcode,
            payload_size=payload_size,
        )
        self._write_packet_log(
            timestamp,
            direction,
            payload,
            decoded=decoded,
            event=event,
        )

        is_noise = event.startswith("_ping") or event.startswith("_pong")
        if not is_noise and self._should_print_event(event):
            print(
                f"{direction} {event}: "
                f"{json.dumps(redacted_args, ensure_ascii=False)}"
            )

        if event in DECK_EVENTS:
            if args and isinstance(args[0], str):
                if self.session_token != args[0]:
                    self.session_token = args[0]
                    safe_token = redact_sensitive_value(args[0])
                    print(f"[*] 取得 session token: {safe_token}")
            elif args and isinstance(args[0], dict):
                self.save_deck(event, args[0])

        return record

    def _should_print_event(self, event_name):
        lowered = event_name.lower()
        return (
            self.log_all
            or event_name in FOCUS_EVENTS
            or any(keyword in lowered for keyword in VERBOSE_EVENT_KEYWORDS)
        )

    # ------------------------------------------------------------ manual markers
    def start_marker_reader(self):
        if (
            not self.enable_stdin_markers
            or not sys.stdin
            or not sys.stdin.isatty()
            or self._marker_thread is not None
        ):
            return

        def read_markers():
            try:
                for line in sys.stdin:
                    stripped = line.strip()
                    if not stripped:
                        continue
                    if not stripped.upper().startswith("MARK "):
                        print("    marker 格式：MARK <label>")
                        continue
                    label = stripped[5:].strip()
                    if label:
                        self.record_manual_marker(label)
            except Exception as exc:
                self._marker_errors.put(str(exc))

        self._marker_thread = threading.Thread(
            target=read_markers,
            name="unlight-marker-reader",
            daemon=True,
        )
        self._marker_thread.start()

    def _report_marker_errors(self):
        while True:
            try:
                error = self._marker_errors.get_nowait()
            except queue.Empty:
                return
            print(f"[!] marker reader 發生錯誤: {error}")

    def record_manual_marker(self, label):
        clean_label = str(label).strip()
        if not clean_label:
            return None
        if len(clean_label) > 200:
            clean_label = clean_label[:200]

        with self._record_lock:
            if self._closed:
                return None
            record = {
                "type": "manual_marker",
                "label": clean_label,
                "sequence": self._next_sequence_unlocked(),
                "timestamp": current_timestamp(),
            }
            self._write_jsonl_unlocked(self.events_path, record)
        print(f"[MARK] {clean_label}")
        return record

    # ------------------------------------------------------------ deck output
    def save_deck(self, event, deck):
        self.decks[event] = deck
        path = self.output_dir / f"{event.replace('db_', '')}.json"
        with path.open("w", encoding="utf-8") as deck_file:
            # Keep the original db_deck payload unchanged.
            json.dump(deck, deck_file, indent=2, ensure_ascii=False)
        print(f"\n[✓] {event} → {path}")
        print(self.format_deck(deck) + "\n")

    @staticmethod
    def format_deck(deck):
        lines = []
        chara = [
            f"{deck.get(f'chara{i}')}(#{deck.get(f'charaIndex{i}')})"
            for i in (1, 2, 3)
        ]
        lines.append(f"    角色: {'  '.join(str(card) for card in chara)}")
        weapon = [deck.get(f"weapon{i}") for i in (1, 2, 3)]
        lines.append(f"    武器: {weapon}")

        events = []
        index = 1
        while f"event{index}" in deck:
            events.append(deck[f"event{index}"])
            index += 1

        valid_events = [event for event in events if event is not None]
        if valid_events:
            lines.append(f"    有效事件({len(valid_events)}張): {valid_events}")
            tally = Counter(valid_events)
            tally_text = "  ".join(
                f"{event}x{count}" for event, count in sorted(tally.items())
            )
            lines.append(f"    事件統計: {tally_text}")
        else:
            lines.append("    有效事件(0張): 尚未設定")
            lines.append("    事件統計: 尚未設定")

        lines.append(f"    Cost: {deck.get('cost')}")
        return "\n".join(lines)

    # ------------------------------------------------------------ summary / cleanup
    def _serializable_stats_unlocked(self):
        result = {}
        for event_name in sorted(self.event_stats):
            stats = self.event_stats[event_name]
            result[event_name] = {
                "received_count": stats["received_count"],
                "sent_count": stats["sent_count"],
                "first_seen_at": stats["first_seen_at"],
                "last_seen_at": stats["last_seen_at"],
                "arg_types": dict(sorted(stats["arg_types"].items())),
                "max_payload_size": stats["max_payload_size"],
            }
        return result

    def write_event_summary(self):
        with self._record_lock:
            summary = {
                "generated_at": current_timestamp(),
                "total_sequences": self.sequence,
                "events": self._serializable_stats_unlocked(),
            }
            path = self.output_dir / "event-summary.json"
            with path.open("w", encoding="utf-8") as summary_file:
                json.dump(summary, summary_file, indent=2, ensure_ascii=False)
            return summary

    def print_event_summary(self, summary):
        print("\n[+] Event discovery summary")
        for event_name, stats in summary["events"].items():
            print(
                f"    {event_name}: "
                f"received={stats['received_count']} "
                f"sent={stats['sent_count']} "
                f"first={stats['first_seen_at']} "
                f"last={stats['last_seen_at']} "
                f"max_payload={stats['max_payload_size']}"
            )

    def close(self):
        with self._record_lock:
            if self._closed:
                return
            summary = {
                "generated_at": current_timestamp(),
                "total_sequences": self.sequence,
                "events": self._serializable_stats_unlocked(),
            }
            summary_path = self.output_dir / "event-summary.json"
            with summary_path.open("w", encoding="utf-8") as summary_file:
                json.dump(summary, summary_file, indent=2, ensure_ascii=False)
            self.events_file.close()
            if self.logfile:
                self.logfile.close()
            self._closed = True
        self.print_event_summary(summary)


def launch_game(port=DEBUG_PORT):
    """Launch the game through Steam and wait for its CDP endpoint."""
    print(f"[+] 透過 Steam 啟動遊戲 (ID: {STEAM_GAME_ID})…")
    subprocess.Popen(f"start steam://rungameid/{STEAM_GAME_ID}", shell=True)

    for _ in range(60):
        try:
            requests.get(f"http://127.0.0.1:{port}/json/version", timeout=1)
            print("[+] debug port 已就緒")
            return True
        except requests.RequestException:
            time.sleep(1)

    print(f"[!] 等不到 debug port {port}")
    print(f"    請確認 Steam 啟動選項有加 --remote-debugging-port={port}")
    return False


def build_argument_parser():
    parser = argparse.ArgumentParser()
    redaction_group = parser.add_mutually_exclusive_group()
    redaction_group.add_argument(
        "--redact-existing",
        type=Path,
        metavar="PATH",
        help="旁路重寫既有 JSONL 為 PATH.redacted.jsonl，完成後退出",
    )
    redaction_group.add_argument(
        "--redact-existing-directory",
        type=Path,
        metavar="PATH",
        help="重寫 PATH/events.jsonl 與 PATH/events/*.jsonl 到 PATH/redacted",
    )
    parser.add_argument("--launch", action="store_true", help="順便啟動遊戲")
    parser.add_argument("--all", action="store_true", help="終端顯示所有事件")
    parser.add_argument(
        "--log",
        action="store_true",
        help="寫入經遮蔽的 deck/packets.log",
    )
    parser.add_argument(
        "--unsafe-raw-log",
        action="store_true",
        help="明確允許 packets.log 保存未遮蔽原始封包",
    )
    parser.add_argument(
        "--event-dir",
        action="store_true",
        help="另依事件寫入 deck/events/<event-name>.jsonl",
    )
    parser.add_argument("--port", type=int, default=DEBUG_PORT)
    return parser


def main():
    args = build_argument_parser().parse_args()

    if args.redact_existing is not None:
        print_redaction_stats(redact_existing_jsonl(args.redact_existing))
        return
    if args.redact_existing_directory is not None:
        print_redaction_stats(
            redact_existing_directory(args.redact_existing_directory)
        )
        return

    if args.launch:
        launch_game(args.port)

    if args.unsafe_raw_log:
        print("[!] UNSAFE RAW LOG 已啟用：packets.log 將包含未遮蔽資料")

    sniffer = ULSniffer(
        port=args.port,
        log_all=args.all or args.log or args.unsafe_raw_log,
        unsafe_raw_log=args.unsafe_raw_log,
        event_dir=args.event_dir,
    )
    try:
        asyncio.run(sniffer.run())
    except KeyboardInterrupt:
        sniffer.close()
        print("\n[+] 結束")
    except requests.RequestException:
        sniffer.close()
        print(f"[!] 連不上 127.0.0.1:{args.port}")
        print("    請確認遊戲使用 --remote-debugging-port 啟動")


if __name__ == "__main__":
    main()
