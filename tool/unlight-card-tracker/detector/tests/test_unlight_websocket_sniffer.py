import asyncio
import base64
import hashlib
import json
import tempfile
import unittest
from pathlib import Path

from unlight_websocket_sniffer import (
    ULSniffer,
    redact_existing_directory,
    redact_existing_jsonl,
    redact_payload_for_log,
    redact_sensitive_value,
    redact_url,
    sanitize_event_filename,
)


class FakeGameWebSocket:
    def __init__(self):
        self.sent = []

    async def send(self, payload):
        self.sent.append(payload)


class WebSocketSnifferTests(unittest.TestCase):
    def setUp(self):
        self.temp_dir = tempfile.TemporaryDirectory()
        self.output_dir = Path(self.temp_dir.name)
        self.sniffers = []

    def tearDown(self):
        for sniffer in self.sniffers:
            sniffer.close()
        self.temp_dir.cleanup()

    def make_sniffer(self, **kwargs):
        sniffer = ULSniffer(
            output_dir=self.output_dir,
            enable_stdin_markers=False,
            **kwargs,
        )
        self.sniffers.append(sniffer)
        return sniffer

    def read_events(self):
        path = self.output_dir / "events.jsonl"
        return [
            json.loads(line)
            for line in path.read_text(encoding="utf-8").splitlines()
            if line
        ]

    def test_session_token_redaction_keeps_four_characters_at_each_end(self):
        self.assertEqual(
            redact_sensitive_value("Csql1234567890000"),
            "Csql...0000",
        )

    def test_url_redacts_steamid_token_session_and_auth(self):
        original = (
            "wss://game.example/socket?"
            "steamid=76561198012345678&token=abcdefghijkl&"
            "session=1234567890&auth=auth-secret-value&safe=yes"
        )
        redacted = redact_url(original)
        self.assertNotIn("76561198012345678", redacted)
        self.assertNotIn("abcdefghijkl", redacted)
        self.assertNotIn("1234567890", redacted)
        self.assertNotIn("auth-secret-value", redacted)
        self.assertIn("safe=yes", redacted)

    def test_room_id_is_redacted_recursively(self):
        payload = {"nested": {"room_id": "room-1234567890"}}
        redacted = redact_payload_for_log(payload)
        self.assertEqual(redacted["nested"]["room_id"], "room...7890")

    def test_card_positional_room_and_session_are_redacted(self):
        room_id = "RoomIdentifier1234567890ABCDEF"
        token = "SessionToken1234567890ABCDEFGHIJKL"
        redacted = redact_payload_for_log(
            [room_id, token, 4],
            event_name="card",
            direction="sent",
        )
        self.assertEqual(redacted, ["Room...CDEF", "Sess...IJKL", 4])

    def test_i_am_ok_positional_secrets_are_redacted(self):
        room_id = "RoomIdentifier1234567890ABCDEF"
        token = "SessionToken1234567890ABCDEFGHIJKL"
        redacted = redact_payload_for_log(
            [room_id, token],
            event_name="I_am_ok",
            direction="sent",
        )
        self.assertEqual(redacted, ["Room...CDEF", "Sess...IJKL"])

    def test_game_ready_room_is_redacted(self):
        room_id = "RoomIdentifier1234567890ABCDEF"
        redacted = redact_payload_for_log(
            [room_id, False, 2],
            event_name="gameReady",
            direction="sent",
        )
        self.assertEqual(redacted, ["Room...CDEF", False, 2])

    def test_register_token_is_redacted(self):
        token = "SessionToken1234567890ABCDEFGHIJKL"
        redacted = redact_payload_for_log(
            [token],
            event_name="register",
            direction="sent",
        )
        self.assertEqual(redacted, ["Sess...IJKL"])

    def test_db_deck_token_is_redacted(self):
        token = "SessionToken1234567890ABCDEFGHIJKL"
        redacted = redact_payload_for_log(
            [token],
            event_name="db_deck2",
            direction="sent",
        )
        self.assertEqual(redacted, ["Sess...IJKL"])

    def test_sent_and_received_positional_schemas_can_differ(self):
        short_token = "short-session"
        sent = redact_payload_for_log(
            [short_token],
            event_name="register",
            direction="sent",
        )
        received = redact_payload_for_log(
            [short_token],
            event_name="register",
            direction="received",
        )
        self.assertEqual(sent, ["shor...sion"])
        self.assertEqual(received, [short_token])

    def test_opaque_identifier_fallback_is_redacted(self):
        opaque = "Ab9Zx7Qw2Er5Ty8Ui1Op4As6Df0Gh3Jk"
        redacted = redact_payload_for_log(
            [opaque],
            event_name="unknownEvent",
            direction="received",
        )
        self.assertEqual(redacted, ["Ab9Z...h3Jk"])

    def test_opaque_session_target_is_redacted_in_discovery_log(self):
        opaque_target = "BD7542E2C0A9AE00734C09E28B8E8952"
        sniffer = self.make_sniffer()
        record = sniffer.handle_frame(
            {
                "response": {
                    "opcode": 1,
                    "payloadData": json.dumps(
                        {"event": "gameStart", "args": []}
                    ),
                }
            },
            "received",
            session_id=opaque_target,
        )

        self.assertEqual(record["session_target"], "BD75...8952")

    def test_known_safe_identifier_values_are_not_redacted(self):
        safe_values = [
            "cc041",
            "cc041_r05",
            "phase_d",
            "bonus_reward_code_2026_special",
            "item_battle_potion_2026_special",
        ]
        redacted = redact_payload_for_log(
            safe_values,
            event_name="unknownEvent",
            direction="received",
        )
        self.assertEqual(redacted, safe_values)

    def test_default_packet_log_does_not_contain_full_token(self):
        token = "Csql1234567890000"
        sniffer = self.make_sniffer(log_all=True)
        payload = json.dumps({"event": "db_deck1", "args": [token]})

        sniffer.handle_frame(
            {"response": {"opcode": 1, "payloadData": payload}},
            "sent",
        )

        logged = (self.output_dir / "packets.log").read_text(encoding="utf-8")
        self.assertNotIn(token, logged)
        self.assertIn("Csql...0000", logged)

    def test_unsafe_raw_log_retains_original_payload_only_when_enabled(self):
        token = "Csql1234567890000"
        sniffer = self.make_sniffer(
            log_all=True,
            unsafe_raw_log=True,
            event_dir=True,
        )
        payload = json.dumps({"event": "db_deck1", "args": [token]})

        sniffer.handle_frame(
            {"response": {"opcode": 1, "payloadData": payload}},
            "sent",
        )

        logged = (self.output_dir / "packets.log").read_text(encoding="utf-8")
        self.assertIn(token, logged)
        discovery = (self.output_dir / "events.jsonl").read_text(
            encoding="utf-8"
        )
        event_specific = next(
            (self.output_dir / "events").glob("*.jsonl")
        ).read_text(encoding="utf-8")
        self.assertNotIn(token, discovery)
        self.assertNotIn(token, event_specific)

    def test_event_filename_is_sanitized_against_path_traversal(self):
        filename = sanitize_event_filename("../../bad:event")
        self.assertNotIn("/", filename)
        self.assertNotIn("\\", filename)
        self.assertNotIn(":", filename)
        self.assertFalse(filename.startswith("."))
        self.assertEqual(filename, sanitize_event_filename("../../bad:event"))

    def test_malformed_json_is_recorded_without_crashing(self):
        sniffer = self.make_sniffer()
        record = sniffer.handle_frame(
            {"response": {"opcode": 1, "payloadData": "{broken"}},
            "received",
        )

        self.assertEqual(record["event"], "__malformed_json__")
        self.assertEqual(self.read_events()[0]["sequence"], 1)

    def test_binary_frame_records_metadata_without_raw_content(self):
        sniffer = self.make_sniffer()
        binary = b"\x00\x01secret-binary"
        encoded = base64.b64encode(binary).decode("ascii")

        record = sniffer.handle_frame(
            {"response": {"opcode": 2, "payloadData": encoded}},
            "received",
        )

        summary = record["args_summary"]
        self.assertEqual(summary["opcode"], 2)
        self.assertEqual(summary["byte_length"], len(binary))
        self.assertEqual(summary["sha256"], hashlib.sha256(binary).hexdigest())
        self.assertNotIn(encoded, json.dumps(record))

    def test_frame_and_marker_sequences_are_monotonic(self):
        sniffer = self.make_sniffer()
        for event in ("gameStart", "duelStart"):
            sniffer.handle_frame(
                {
                    "response": {
                        "opcode": 1,
                        "payloadData": json.dumps({"event": event, "args": []}),
                    }
                },
                "received",
            )
        sniffer.record_manual_marker("initial_hand")

        self.assertEqual(
            [record["sequence"] for record in self.read_events()],
            [1, 2, 3],
        )

    def test_manual_marker_is_inserted_into_discovery_log(self):
        sniffer = self.make_sniffer()
        marker = sniffer.record_manual_marker("draw_one")

        self.assertEqual(marker["type"], "manual_marker")
        self.assertEqual(marker["label"], "draw_one")
        self.assertEqual(self.read_events()[0], marker)

    def test_event_summary_tracks_counts_types_times_and_max_payload(self):
        sniffer = self.make_sniffer()
        small = json.dumps({"event": "addArray", "args": [1]})
        large = json.dumps({"event": "addArray", "args": [1, "more-data"]})
        sniffer.handle_frame(
            {"response": {"opcode": 1, "payloadData": small}},
            "received",
        )
        sniffer.handle_frame(
            {"response": {"opcode": 1, "payloadData": large}},
            "received",
        )
        sniffer.handle_frame(
            {"response": {"opcode": 1, "payloadData": small}},
            "sent",
        )

        summary = sniffer.write_event_summary()
        stats = summary["events"]["addArray"]
        self.assertEqual(stats["received_count"], 2)
        self.assertEqual(stats["sent_count"], 1)
        self.assertTrue(stats["first_seen_at"])
        self.assertTrue(stats["last_seen_at"])
        self.assertEqual(stats["max_payload_size"], len(large.encode("utf-8")))
        self.assertEqual(stats["arg_types"]["[\"int\"]"], 2)
        self.assertEqual(stats["arg_types"]["[\"int\",\"string\"]"], 1)

    def test_deck_none_entries_are_not_counted(self):
        deck = {f"event{index}": None for index in range(1, 19)}
        formatted = ULSniffer.format_deck(deck)

        self.assertIn("有效事件(0張): 尚未設定", formatted)
        self.assertIn("事件統計: 尚未設定", formatted)
        self.assertNotIn("Nonex18", formatted)

    def test_frame_observation_does_not_send_a_game_action(self):
        sniffer = self.make_sniffer()
        fake_websocket = FakeGameWebSocket()
        sniffer.ws = fake_websocket

        sniffer.handle_frame(
            {
                "response": {
                    "opcode": 1,
                    "payloadData": json.dumps(
                        {"event": "play", "args": [{"card": 55}]}
                    ),
                }
            },
            "sent",
        )

        self.assertEqual(fake_websocket.sent, [])

    def test_event_dir_writes_sanitized_per_event_jsonl(self):
        sniffer = self.make_sniffer(event_dir=True)
        sniffer.handle_frame(
            {
                "response": {
                    "opcode": 1,
                    "payloadData": json.dumps(
                        {"event": "../../add:Array", "args": [1]}
                    ),
                }
            },
            "received",
        )

        files = list((self.output_dir / "events").glob("*.jsonl"))
        self.assertEqual(len(files), 1)
        self.assertEqual(files[0].parent, self.output_dir / "events")
        self.assertEqual(json.loads(files[0].read_text())["sequence"], 1)

    def test_existing_jsonl_rewrite_redacts_and_preserves_source(self):
        source = self.output_dir / "captured.jsonl"
        room_id = "RoomIdentifier1234567890ABCDEF"
        token = "SessionToken1234567890ABCDEFGHIJKL"
        original = json.dumps(
            {
                "direction": "sent",
                "event": "card",
                "args_summary": [room_id, token, 4],
                "websocket_url": (
                    "wss://game.example/socket?token=" + token
                ),
                "sequence": 1,
            }
        )
        source.write_text(original + "\n", encoding="utf-8")

        stats = redact_existing_jsonl(source)

        output = Path(stats["output"])
        self.assertEqual(source.read_text(encoding="utf-8"), original + "\n")
        self.assertNotEqual(output, source)
        self.assertIn(".redacted.jsonl", output.name)
        rewritten = output.read_text(encoding="utf-8")
        self.assertNotIn(room_id, rewritten)
        self.assertNotIn(token, rewritten)
        self.assertEqual(stats["total_lines"], 1)
        self.assertEqual(stats["successful_lines"], 1)
        self.assertEqual(stats["redacted_lines"], 1)

    def test_existing_jsonl_malformed_line_becomes_safe_json_record(self):
        source = self.output_dir / "malformed.jsonl"
        source.write_text(
            '{"event":"gameStart","args_summary":[]}\n{broken\n',
            encoding="utf-8",
        )

        stats = redact_existing_jsonl(source)

        output_lines = Path(stats["output"]).read_text(
            encoding="utf-8"
        ).splitlines()
        parsed = [json.loads(line) for line in output_lines]
        self.assertEqual(len(parsed), 2)
        self.assertEqual(parsed[1]["type"], "redaction_error")
        self.assertEqual(parsed[1]["reason"], "malformed_json")
        self.assertNotIn("{broken", output_lines[1])
        self.assertEqual(stats["malformed_lines"], 1)

    def test_existing_jsonl_does_not_overwrite_source_or_existing_output(self):
        source = self.output_dir / "source.jsonl"
        source.write_text('{"event":"gameStart"}\n', encoding="utf-8")
        first_stats = redact_existing_jsonl(source)

        with self.assertRaises(FileExistsError):
            redact_existing_jsonl(source)

        self.assertEqual(
            source.read_text(encoding="utf-8"),
            '{"event":"gameStart"}\n',
        )
        self.assertTrue(Path(first_stats["output"]).is_file())

    def test_directory_rewrite_preserves_event_structure(self):
        source_root = self.output_dir / "corpus"
        events_dir = source_root / "events"
        events_dir.mkdir(parents=True)
        token = "SessionToken1234567890ABCDEFGHIJKL"
        record = json.dumps(
            {
                "direction": "sent",
                "event": "register",
                "args_summary": [token],
            }
        )
        (source_root / "events.jsonl").write_text(
            record + "\n",
            encoding="utf-8",
        )
        (events_dir / "register.jsonl").write_text(
            record + "\n",
            encoding="utf-8",
        )
        (source_root / "deck1.json").write_text(
            '{"unchanged":true}',
            encoding="utf-8",
        )

        stats = redact_existing_directory(source_root)

        output_root = Path(stats["output"])
        self.assertTrue((output_root / "events.jsonl").is_file())
        self.assertTrue(
            (output_root / "events" / "register.jsonl").is_file()
        )
        self.assertFalse((output_root / "deck1.json").exists())
        self.assertNotIn(
            token,
            (output_root / "events.jsonl").read_text(encoding="utf-8"),
        )
        self.assertEqual(len(stats["files"]), 2)

    def test_cdp_send_is_explicitly_a_cdp_control_message(self):
        sniffer = self.make_sniffer()
        fake_websocket = FakeGameWebSocket()
        sniffer.ws = fake_websocket

        asyncio.run(sniffer.send("Network.enable", {}, "target-1"))

        sent = json.loads(fake_websocket.sent[0])
        self.assertEqual(sent["method"], "Network.enable")
        self.assertNotIn("Network.sendWebSocket", sent["method"])


if __name__ == "__main__":
    unittest.main()
