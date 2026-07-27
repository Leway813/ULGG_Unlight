import ast
import inspect
import json
import tempfile
import unittest
from pathlib import Path

import battle_observation_stream
from battle_observation_stream import BattleObservationStream
from unlight_websocket_sniffer import ULSniffer, build_argument_parser


def card(card_id=4, *, slot=None):
    return {
        "swd1": 1,
        "gun1": 0,
        "shi1": 0,
        "mov1": 0,
        "spe1": 0,
        "swd2": 0,
        "gun2": 0,
        "shi2": 1,
        "mov2": 0,
        "spe2": 0,
        "index": card_id,
        "type": "ac",
        "clicked": False,
        "used": False,
        "rotate": False,
        "cardindex": slot,
    }


def record(event, args, *, sequence=1, direction="received"):
    return {
        "timestamp": "2026-07-27T12:00:00+08:00",
        "direction": direction,
        "event": event,
        "args_summary": args,
        "sequence": sequence,
        "session_target": "must-not-copy",
        "websocket_url": "wss://must-not-copy.invalid/",
    }


class BattleObservationStreamTests(unittest.TestCase):
    def setUp(self):
        self.temp_dir = tempfile.TemporaryDirectory()
        self.output_path = Path(self.temp_dir.name) / "observations.jsonl"
        self.streams = []

    def tearDown(self):
        for stream in self.streams:
            stream.close()
        self.temp_dir.cleanup()

    def make_stream(self, **kwargs):
        stream = BattleObservationStream(self.output_path, **kwargs)
        self.streams.append(stream)
        return stream

    def read_output(self):
        if not self.output_path.exists():
            return []
        return [
            json.loads(line)
            for line in self.output_path.read_text(encoding="utf-8").splitlines()
            if line
        ]

    def test_public_observation_is_written(self):
        written = self.make_stream().process(record("gameStart", []))
        self.assertEqual(len(written), 1)
        self.assertEqual(written[0]["visibility"], "public")

    def test_self_private_observation_is_written(self):
        written = self.make_stream().process(
            record("drawPhase", [[card()], 1])
        )
        self.assertEqual(len(written), 1)
        self.assertEqual(written[0]["visibility"], "self_private")

    def test_opponent_revealed_observation_is_written(self):
        stream = self.make_stream(local_side="A", battle_mode="pvp")
        written = stream.process(
            record(
                "cardOpen_B",
                [[{"card": card(), "cardindex": 3}]],
            )
        )
        self.assertEqual(written[0]["visibility"], "opponent_revealed")
        self.assertEqual(written[0]["resolved_side"], "opponent")

    def test_restricted_observation_is_rejected(self):
        stream = self.make_stream()
        stream.process(
            record(
                "chara_B",
                [{
                    "chara": "cc043",
                    "filename": "cc043_r04",
                    "charaIndex": 428,
                    "chara_index": 1,
                    "main": False,
                    "known": False,
                }],
            )
        )
        self.assertEqual(self.read_output(), [])
        self.assertEqual(stream.summary()["rejected_restricted"], 1)

    def test_diagnostic_observation_is_rejected(self):
        stream = self.make_stream()
        stream.process(record("cardclickedA", [3, True, False]))
        self.assertEqual(self.read_output(), [])
        self.assertEqual(stream.summary()["rejected_diagnostic"], 1)

    def test_rejected_payload_is_not_persisted_or_summarized(self):
        stream = self.make_stream()
        hidden = "cc024_r05"
        stream.process(
            record(
                "chara_B",
                [{
                    "chara": "cc024",
                    "filename": hidden,
                    "chara_index": 2,
                    "main": False,
                    "known": False,
                }],
            )
        )
        self.assertNotIn(hidden, self.output_path.read_text(encoding="utf-8"))
        self.assertNotIn(hidden, repr(stream.summary()))

    def test_duel_standby_is_not_written(self):
        stream = self.make_stream()
        stream.process(
            record(
                "duel_standby",
                [{"room_playerBdeck": {"eventIndex": [1, 2, 3]}}],
            )
        )
        self.assertEqual(self.read_output(), [])

    def test_hidden_reserve_character_is_not_written(self):
        stream = self.make_stream()
        stream.process(
            record(
                "chara_A",
                [
                    {
                        "chara": "cc041",
                        "filename": "cc041_r05",
                        "chara_index": 0,
                        "main": True,
                        "known": True,
                    },
                    {
                        "chara": "cc043",
                        "filename": "cc043_r04",
                        "chara_index": 1,
                        "main": False,
                        "known": False,
                    },
                ],
            )
        )
        output = self.read_output()
        self.assertEqual(len(output), 1)
        self.assertEqual(output[0]["payload"]["slot"], 0)
        self.assertNotIn("cc043", repr(output))

    def test_cardclicked_for_local_side_remains_pending(self):
        stream = self.make_stream(local_side="A")
        written = stream.process(
            record("cardclickedA", [3, True, False])
        )
        self.assertEqual(written[0]["confirmation"], "pending")
        self.assertEqual(written[0]["payload"]["slot"], 3)
        self.assertNotIn("card_id", written[0]["payload"])

    def test_card_open_remains_confirmed(self):
        written = self.make_stream().process(
            record(
                "cardOpen_A",
                [[{"card": card(), "cardindex": 5}]],
            )
        )
        self.assertEqual(written[0]["confirmation"], "confirmed")

    def test_card_and_result_events_keep_protocol_observation_types(self):
        stream = self.make_stream()
        cases = [
            ("drawPhase", [[card()], 1], "cards_dealt"),
            ("eventCard", [card(67), 1], "event_card_received"),
            ("cardDraw", [card(31)], "card_drawn"),
            ("result", ["win", 34, 70], "battle_finished"),
        ]
        for sequence, (event_name, args, expected_type) in enumerate(cases, 1):
            written = stream.process(
                record(event_name, args, sequence=sequence)
            )
            self.assertEqual(written[0]["type"], expected_type)

    def test_identical_pending_toggles_are_not_deduplicated(self):
        stream = self.make_stream(local_side="A")
        pending = record("cardclickedA", [3, True, False], sequence=71)
        stream.process(pending)
        stream.process(pending)
        rows = self.read_output()
        self.assertEqual(len(rows), 2)
        self.assertTrue(all(row["confirmation"] == "pending" for row in rows))

    def test_multiple_observations_keep_frame_sequence_and_index(self):
        written = self.make_stream().process(
            record(
                "chara_A",
                [
                    {
                        "chara": "cc041",
                        "chara_index": 0,
                        "main": True,
                        "known": True,
                    },
                    {
                        "chara": "cc042",
                        "chara_index": 1,
                        "main": True,
                        "known": True,
                    },
                ],
                sequence=88,
            )
        )
        self.assertEqual([item["sequence"] for item in written], [88, 88])
        self.assertEqual(
            [item["observation_index"] for item in written],
            [0, 1],
        )

    def test_malformed_frame_is_isolated(self):
        messages = []
        stream = self.make_stream(console=messages.append)
        self.assertEqual(
            stream.process(record("__malformed_json__", [])),
            [],
        )
        self.assertEqual(stream.summary()["parse_errors"], 1)
        self.assertNotIn("args_summary", messages[0])

    def test_parser_exception_is_isolated(self):
        messages = []

        def broken_parser(_record, context=None):
            raise RuntimeError("secret raw payload")

        stream = self.make_stream(
            parser=broken_parser,
            console=messages.append,
        )
        self.assertEqual(stream.process(record("gameStart", [])), [])
        self.assertEqual(stream.summary()["parse_errors"], 1)
        self.assertNotIn("secret raw payload", messages[0])

    def test_output_omits_discovery_transport_and_secret_fields(self):
        stream = self.make_stream()
        stream.process(record("drawPhase", [[card()], 1]))
        text = self.output_path.read_text(encoding="utf-8")
        for forbidden in (
            "args_summary",
            "must-not-copy",
            "room_id",
            "session_target",
            "token",
            "websocket_url",
            "wss://",
        ):
            self.assertNotIn(forbidden, text)

    def test_local_side_a_resolution(self):
        written = self.make_stream(local_side="A").process(
            record(
                "cardOpen_A",
                [[{"card": card(), "cardindex": 1}]],
            )
        )
        self.assertEqual(written[0]["resolved_side"], "self")

    def test_local_side_b_resolution(self):
        written = self.make_stream(local_side="B").process(
            record(
                "cardOpen_A",
                [[{"card": card(), "cardindex": 1}]],
            )
        )
        self.assertEqual(written[0]["resolved_side"], "opponent")

    def test_missing_local_side_stays_unknown(self):
        written = self.make_stream().process(
            record(
                "cardOpen_A",
                [[{"card": card(), "cardindex": 1}]],
            )
        )
        self.assertEqual(written[0]["protocol_side"], "A")
        self.assertEqual(written[0]["resolved_side"], "unknown")

    def test_add_array_and_delete_draw_do_not_write(self):
        stream = self.make_stream()
        stream.process(record("addArray", [1]))
        stream.process(record("deleteDraw_B", [1]))
        self.assertEqual(self.read_output(), [])
        self.assertEqual(stream.summary()["unknown_events"], 2)

    def test_close_flushes_and_is_idempotent(self):
        stream = self.make_stream()
        stream.process(record("gameStart", []))
        first = stream.close()
        second = stream.close()
        self.assertEqual(first, second)
        self.assertEqual(len(self.read_output()), 1)

    def test_schema_mismatch_isolated_without_partial_output(self):
        messages = []
        stream = self.make_stream(console=messages.append)
        bad_record = record("drawPhase", {"not": "a list"})
        stream.process(bad_record)
        self.assertEqual(stream.summary()["parse_errors"], 1)
        self.assertEqual(self.read_output(), [])

    def test_representative_corpus_shapes_have_no_parse_errors(self):
        stream = self.make_stream(local_side="A", battle_mode="pvp")
        records = [
            record("gameStart", [], sequence=1),
            record("drawPhase", [[card()], 1], sequence=2),
            record("eventCard", [card(67), 1], sequence=3),
            record("cardclickedA", [0, True, False], sequence=4),
            record(
                "cardOpen_B",
                [[{"card": card(19), "cardindex": 4}]],
                sequence=5,
            ),
            record("result", ["win", 34, 70], sequence=6),
        ]
        for item in records:
            stream.process(item)
        self.assertEqual(stream.summary()["parse_errors"], 0)

    def test_writer_reapplies_visibility_policy(self):
        def unsafe_parser(_record, context=None):
            return [{
                "type": "character_state",
                "source_event": "chara_B",
                "direction": "received",
                "sequence": 1,
                "timestamp": "2026-07-27T12:00:00+08:00",
                "side": "B",
                "visibility": "public",
                "confirmation": "confirmed",
                "payload": {
                    "slot": 1,
                    "chara_id": "cc043",
                    "main": False,
                    "known": False,
                },
            }]

        stream = self.make_stream(parser=unsafe_parser)
        stream.process(record("chara_B", [{"chara": "cc043"}]))
        self.assertEqual(self.read_output(), [])
        self.assertEqual(stream.summary()["rejected_restricted"], 1)

    def test_writer_has_no_cdp_or_game_websocket_transport(self):
        tree = ast.parse(inspect.getsource(battle_observation_stream))
        imports = {
            alias.name.split(".")[0]
            for node in ast.walk(tree)
            if isinstance(node, ast.Import)
            for alias in node.names
        }
        calls = {
            node.func.attr
            for node in ast.walk(tree)
            if isinstance(node, ast.Call) and isinstance(node.func, ast.Attribute)
        }
        self.assertFalse(
            imports
            & {"asyncio", "requests", "socket", "websocket", "websockets"}
        )
        self.assertNotIn("send", calls)


class SnifferBattleObservationIntegrationTests(unittest.TestCase):
    def test_cli_options_parse(self):
        args = build_argument_parser().parse_args(
            [
                "--battle-observations",
                "--local-side",
                "B",
                "--battle-mode",
                "pve",
            ]
        )
        self.assertTrue(args.battle_observations)
        self.assertEqual(args.local_side, "B")
        self.assertEqual(args.battle_mode, "pve")

    def test_sniffer_without_flag_keeps_existing_behavior(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            sniffer = ULSniffer(
                output_dir=temp_dir,
                enable_stdin_markers=False,
            )
            try:
                sniffer.handle_frame(
                    {
                        "response": {
                            "opcode": 1,
                            "payloadData": json.dumps(
                                {"event": "gameStart", "args": []}
                            ),
                        }
                    },
                    "received",
                )
            finally:
                sniffer.close()
            self.assertFalse(
                (Path(temp_dir) / "battle-observations.jsonl").exists()
            )

    def test_sniffer_flag_writes_and_close_flushes_stream(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            sniffer = ULSniffer(
                output_dir=temp_dir,
                enable_stdin_markers=False,
                battle_observations=True,
                local_side="A",
                battle_mode="pvp",
            )
            sniffer.handle_frame(
                {
                    "response": {
                        "opcode": 1,
                        "payloadData": json.dumps(
                            {"event": "gameStart", "args": []}
                        ),
                    }
                },
                "received",
            )
            sniffer.close()
            output = Path(temp_dir) / "battle-observations.jsonl"
            rows = [
                json.loads(line)
                for line in output.read_text(encoding="utf-8").splitlines()
            ]
            self.assertEqual(len(rows), 1)
            self.assertEqual(rows[0]["type"], "battle_started")


if __name__ == "__main__":
    unittest.main()
