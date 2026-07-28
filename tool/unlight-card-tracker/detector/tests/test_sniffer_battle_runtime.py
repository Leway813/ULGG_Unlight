import io
import json
import tempfile
import unittest
from contextlib import redirect_stdout
from copy import deepcopy
from pathlib import Path
from unittest import mock

import unlight_websocket_sniffer as sniffer_module
from battle_stream_processor import BattleStreamProcessor
from unlight_websocket_sniffer import (
    ULSniffer,
    build_argument_parser,
    create_producer_session_id,
    format_battle_runtime_result,
    main,
    validate_runtime_arguments,
)


DETECTOR_DIR = Path(__file__).resolve().parents[1]
DISCOVERY_CORPUS = (
    DETECTOR_DIR / "deck" / "events.jsonl.redacted.jsonl"
)


def card(card_id=55, slot=3, rotate=False):
    return {
        "card_id": card_id,
        "slot": slot,
        "type": "event",
        "rotate": rotate,
        "used": False,
        "clicked": False,
        "face1": {"kind": "gun", "value": 1},
        "face2": {"kind": "move", "value": 1},
        "display_top": {"kind": "gun", "value": 1},
        "display_bottom": {"kind": "move", "value": 1},
        "display_order_confirmation": "confirmed",
    }


def observation(
    observation_type,
    *,
    sequence=1,
    payload=None,
    protocol_side="unknown",
    resolved_side="unknown",
    visibility="public",
    confirmation="confirmed",
    source_event=None,
):
    source_events = {
        "battle_started": "gameStart",
        "cards_dealt": "drawPhase",
        "card_drawn": "cardDraw",
        "card_selection_changed": "cardclickedA",
        "cards_revealed": "cardOpen_A",
        "phase_ended": "endPhase",
        "turn_ended": "endTurn",
        "battle_finished": "result",
    }
    return {
        "schema_version": 1,
        "timestamp": "2026-07-28T12:00:00+08:00",
        "sequence": sequence,
        "observation_index": 0,
        "type": observation_type,
        "source_event": source_event or source_events[observation_type],
        "direction": "received",
        "protocol_side": protocol_side,
        "resolved_side": resolved_side,
        "visibility": visibility,
        "confirmation": confirmation,
        "payload": payload or {},
    }


def frame(event, args=None):
    return {
        "response": {
            "opcode": 1,
            "payloadData": json.dumps(
                {"event": event, "args": args or []}
            ),
        }
    }


class RecordingProcessor:
    def __init__(self, producer_session_id, *, exception=None):
        self.producer_session_id = producer_session_id
        self.exception = exception
        self.observations = []
        self.state = {
            "battle_status": "idle",
            "turn": 0,
            "self_hand": [],
            "self_pending_selection": [],
            "self_revealed_cards": [],
            "opponent_revealed_cards": [],
            "outcome": None,
        }

    def process(self, value):
        self.observations.append(deepcopy(value))
        if self.exception is not None:
            raise self.exception
        return {
            "accepted": False,
            "observation_type": value.get("type"),
            "domain_events": [],
            "state_changed": False,
            "state": deepcopy(self.state),
            "diagnostics": [],
        }

    def snapshot(self):
        return deepcopy(self.state)


class RecordingSafeStream:
    def __init__(self, observations):
        self.observations = observations
        self.records = []

    def process(self, record):
        self.records.append(deepcopy(record))
        return deepcopy(self.observations)

    def close(self):
        return {
            "written": len(self.observations),
            "rejected_restricted": 0,
            "rejected_diagnostic": 0,
            "parse_errors": 0,
            "unknown_events": 0,
        }


class RecordingTrackerClient:
    def __init__(self, results=None):
        self.results = list(results or [])
        self.events = []

    def submit_event(self, event):
        self.events.append(deepcopy(event))
        if self.results:
            result = self.results.pop(0)
            if isinstance(result, Exception):
                raise result
            return deepcopy(result)
        return {
            "ok": True,
            "duplicate": False,
            "status": "accepted",
            "code": None,
        }


class SnifferBattleRuntimeTests(unittest.TestCase):
    def setUp(self):
        self.temp_dir = tempfile.TemporaryDirectory()
        self.sniffers = []

    def tearDown(self):
        for sniffer in self.sniffers:
            sniffer.close()
        self.temp_dir.cleanup()

    def make_sniffer(self, **kwargs):
        sniffer = ULSniffer(
            output_dir=self.temp_dir.name,
            enable_stdin_markers=False,
            console=lambda _line: None,
            **kwargs,
        )
        self.sniffers.append(sniffer)
        return sniffer

    def test_no_processor_flag_preserves_previous_runtime_behavior(self):
        injected = RecordingProcessor("session-one")
        sniffer = self.make_sniffer(battle_processor=injected)
        sniffer.handle_frame(frame("gameStart"), "received")
        self.assertIsNone(sniffer.battle_processor)
        self.assertEqual(injected.observations, [])
        self.assertFalse(
            (Path(self.temp_dir.name) / "battle-observations.jsonl").exists()
        )

    def test_main_creates_exactly_one_runtime_producer_session(self):
        captured = {}

        class FakeSniffer:
            def __init__(self, **kwargs):
                captured.update(kwargs)

            async def run(self):
                return None

        with mock.patch.object(
            sniffer_module,
            "create_producer_session_id",
            return_value="runtime-session",
        ) as create_session:
            with mock.patch.object(sniffer_module, "ULSniffer", FakeSniffer):
                with mock.patch(
                    "sys.argv",
                    ["sniffer", "--process-battle-state"],
                ):
                    main()

        create_session.assert_called_once_with()
        self.assertEqual(
            captured["producer_session_id"],
            "runtime-session",
        )

    def test_successful_registration_prints_machine_session_id(self):
        session_id = "65fe49e0-a902-44ae-8c94-ec8a3cf65c71"

        class FakeClient:
            def __init__(self, *_args, **_kwargs):
                pass

            def register_session(self):
                return {"ok": True, "status": "registered"}

        class FakeSniffer:
            def __init__(self, **_kwargs):
                pass

            def record_tracker_api_registration(self, _result):
                pass

            async def run(self):
                return None

        output = io.StringIO()
        with mock.patch.object(
            sniffer_module,
            "create_producer_session_id",
            return_value=session_id,
        ), mock.patch.object(
            sniffer_module,
            "TrackerEventClient",
            FakeClient,
        ), mock.patch.object(
            sniffer_module,
            "ULSniffer",
            FakeSniffer,
        ), redirect_stdout(output):
            result = main(
                [
                    "--process-battle-state",
                    "--tracker-api-url",
                    "http://127.0.0.1:8765",
                    "--tracker-api-required",
                ]
            )

        self.assertEqual(result, 0)
        self.assertIn(
            f"TRACKER_SESSION_ID={session_id}",
            output.getvalue(),
        )

    def test_required_registration_failure_returns_exit_two(self):
        class FakeClient:
            def __init__(self, *_args, **_kwargs):
                pass

            def register_session(self):
                return {"ok": False, "code": "CONNECTION_ERROR"}

        class FakeSniffer:
            def __init__(self, **_kwargs):
                pass

            def record_tracker_api_registration(self, _result):
                pass

        with mock.patch.object(
            sniffer_module,
            "TrackerEventClient",
            FakeClient,
        ), mock.patch.object(
            sniffer_module,
            "ULSniffer",
            FakeSniffer,
        ):
            result = main(
                [
                    "--process-battle-state",
                    "--tracker-api-url",
                    "http://127.0.0.1:8765",
                    "--tracker-api-required",
                ]
            )

        self.assertEqual(result, 2)

    def test_processor_receives_caller_owned_session(self):
        sniffer = self.make_sniffer(
            process_battle_state=True,
            producer_session_id="caller-session",
        )
        self.assertEqual(
            sniffer.battle_processor.producer_session_id,
            "caller-session",
        )
        sniffer.handle_frame(frame("gameStart"), "received")
        self.assertEqual(
            sniffer.battle_processor.snapshot()[
                "last_producer_session_id"
            ],
            "caller-session",
        )

    def test_two_runtime_session_ids_are_distinct(self):
        self.assertNotEqual(
            create_producer_session_id(),
            create_producer_session_id(),
        )

    def test_processing_can_keep_safe_observation_writer_enabled(self):
        sniffer = self.make_sniffer(
            battle_observations=True,
            process_battle_state=True,
            producer_session_id="writer-session",
        )
        sniffer.handle_frame(frame("gameStart"), "received")
        path = Path(self.temp_dir.name) / "battle-observations.jsonl"
        rows = [
            json.loads(line)
            for line in path.read_text(encoding="utf-8").splitlines()
        ]
        self.assertEqual(rows[0]["type"], "battle_started")
        self.assertEqual(
            sniffer.battle_processor.snapshot()["battle_status"],
            "active",
        )

    def test_processing_without_writer_does_not_create_observation_jsonl(self):
        sniffer = self.make_sniffer(
            process_battle_state=True,
            producer_session_id="memory-session",
        )
        sniffer.handle_frame(frame("gameStart"), "received")
        self.assertFalse(
            (Path(self.temp_dir.name) / "battle-observations.jsonl").exists()
        )
        self.assertEqual(
            sniffer.battle_processor.snapshot()["battle_status"],
            "active",
        )
        self.assertEqual(
            sniffer.battle_observation_stream.summary()["written"],
            0,
        )

    def test_safe_observation_is_sent_to_processor(self):
        safe = observation("battle_started")
        processor = RecordingProcessor("safe-session")
        stream = RecordingSafeStream([safe])
        sniffer = self.make_sniffer(
            process_battle_state=True,
            producer_session_id="safe-session",
            battle_processor=processor,
            battle_observation_stream=stream,
        )
        sniffer.handle_frame(frame("ignored"), "received")
        self.assertEqual(processor.observations, [safe])

    def test_restricted_observation_is_not_sent_to_processor(self):
        processor = RecordingProcessor("restricted-session")
        stream = RecordingSafeStream([])
        sniffer = self.make_sniffer(
            process_battle_state=True,
            producer_session_id="restricted-session",
            battle_processor=processor,
            battle_observation_stream=stream,
        )
        sniffer.handle_frame(frame("cardClicked_B", [1]), "received")
        self.assertEqual(processor.observations, [])

    def test_processor_exception_is_captured_and_counted(self):
        output = []
        processor = RecordingProcessor(
            "exception-session",
            exception=RuntimeError("secret raw payload"),
        )
        sniffer = ULSniffer(
            output_dir=self.temp_dir.name,
            enable_stdin_markers=False,
            process_battle_state=True,
            producer_session_id="exception-session",
            battle_processor=processor,
            battle_observation_stream=RecordingSafeStream(
                [observation("battle_started")]
            ),
            console=output.append,
        )
        self.sniffers.append(sniffer)
        sniffer.handle_frame(frame("gameStart"), "received")
        summary = sniffer.battle_processor_summary()
        self.assertEqual(summary["processor_errors"], 1)
        self.assertNotIn("secret raw payload", "\n".join(output))

    def test_processor_exception_stops_later_observations_in_same_frame(self):
        processor = RecordingProcessor(
            "stop-session",
            exception=RuntimeError("failure"),
        )
        stream = RecordingSafeStream(
            [
                observation("battle_started"),
                observation("battle_started", sequence=2),
            ]
        )
        sniffer = self.make_sniffer(
            process_battle_state=True,
            producer_session_id="stop-session",
            battle_processor=processor,
            battle_observation_stream=stream,
        )
        sniffer.handle_frame(frame("gameStart"), "received")
        self.assertEqual(len(processor.observations), 1)

    def test_local_side_a_resolves_reveal_to_self(self):
        processor = BattleStreamProcessor(
            "side-a",
            local_side="A",
        )
        processor.process(observation("battle_started"))
        result = processor.process(
            observation(
                "cards_revealed",
                sequence=2,
                payload={"cards": [card()]},
                protocol_side="A",
                visibility="public",
            )
        )
        self.assertEqual(
            result["domain_events"][0]["resolved_side"],
            "self",
        )

    def test_local_side_b_resolves_a_reveal_to_opponent(self):
        processor = BattleStreamProcessor(
            "side-b",
            local_side="B",
        )
        processor.process(observation("battle_started"))
        result = processor.process(
            observation(
                "cards_revealed",
                sequence=2,
                payload={"cards": [card()]},
                protocol_side="A",
                visibility="public",
            )
        )
        self.assertEqual(
            result["domain_events"][0]["resolved_side"],
            "opponent",
        )

    def test_local_side_none_does_not_guess_and_warns(self):
        processor = BattleStreamProcessor("side-unknown")
        processor.process(observation("battle_started"))
        value = observation(
            "cards_revealed",
            sequence=2,
            payload={"cards": [card()]},
            protocol_side="A",
            visibility="public",
        )
        result = processor.process(value)
        lines = format_battle_runtime_result(value, result, "important")
        self.assertEqual(result["domain_events"], [])
        self.assertIn(
            "[BATTLE WARN] reveal ignored because local side is unresolved",
            lines,
        )

    def test_game_start_transitions_runtime_state_to_active(self):
        sniffer = self.make_sniffer(
            process_battle_state=True,
            producer_session_id="start-session",
        )
        sniffer.handle_frame(frame("gameStart"), "received")
        self.assertEqual(
            sniffer.battle_processor.snapshot()["battle_status"],
            "active",
        )

    def test_midstream_strict_mode_remains_idle(self):
        sniffer = self.make_sniffer(
            process_battle_state=True,
            producer_session_id="strict-session",
            battle_start_mode="strict",
        )
        sniffer._process_battle_observation(
            observation(
                "card_drawn",
                payload={"card": card()},
                visibility="self_private",
            )
        )
        self.assertEqual(
            sniffer.battle_processor.snapshot()["battle_status"],
            "idle",
        )

    def test_diagnostic_bootstrap_does_not_synthesize_start(self):
        sniffer = self.make_sniffer(
            process_battle_state=True,
            producer_session_id="bootstrap-session",
            battle_start_mode="diagnostic-bootstrap",
        )
        result = sniffer._process_battle_observation(
            observation(
                "card_drawn",
                payload={"card": card()},
                visibility="self_private",
            )
        )
        state = sniffer.battle_processor.snapshot()
        self.assertEqual(state["battle_status"], "idle")
        self.assertIn(
            "stream_started_mid_battle",
            [item["code"] for item in result["diagnostics"]],
        )

    def test_result_updates_runtime_state_to_finished(self):
        sniffer = self.make_sniffer(
            process_battle_state=True,
            producer_session_id="finish-session",
        )
        sniffer._process_battle_observation(
            observation("battle_started")
        )
        sniffer._process_battle_observation(
            observation(
                "battle_finished",
                sequence=2,
                payload={"outcome": "win"},
            )
        )
        state = sniffer.battle_processor.snapshot()
        self.assertEqual(state["battle_status"], "finished")
        self.assertEqual(state["outcome"], "win")

    def test_quiet_formatter_emits_warnings_and_errors_only(self):
        value = observation(
            "cards_revealed",
            protocol_side="A",
        )
        lines = format_battle_runtime_result(
            value,
            {
                "domain_events": [
                    {
                        "event_type": "battle.started",
                        "payload": {},
                    }
                ],
                "diagnostics": [
                    {
                        "code": "event_before_battle_start",
                        "event_type": "cards_revealed",
                        "source_sequence": 1,
                    }
                ],
            },
            "quiet",
        )
        self.assertNotIn("[BATTLE] started", lines)
        self.assertTrue(any("[BATTLE WARN]" in line for line in lines))

    def test_important_formatter_shows_safe_card_fields(self):
        result = {
            "domain_events": [
                {
                    "event_type": "play.cards_revealed",
                    "payload": {"cards": [card()]},
                    "resolved_side": "self",
                    "confirmation": "confirmed",
                }
            ],
            "diagnostics": [],
        }
        output = "\n".join(
            format_battle_runtime_result({}, result, "important")
        )
        for expected in (
            "slot=3",
            "top=gun1",
            "bottom=move1",
            "rotate=False",
            "confirmation=confirmed",
        ):
            self.assertIn(expected, output)
        self.assertNotIn("{", output)

    def test_debug_formatter_uses_safe_metadata_not_raw_payload(self):
        secret = "SessionToken1234567890ABCDEFGHIJKL"
        value = observation("battle_started")
        value["raw"] = secret
        lines = format_battle_runtime_result(
            value,
            {
                "accepted": True,
                "domain_events": [],
                "state_changed": True,
                "state": {"battle_status": "active", "turn": 0},
                "diagnostics": [],
            },
            "debug",
        )
        output = "\n".join(lines)
        self.assertIn("[BATTLE DEBUG]", output)
        self.assertNotIn(secret, output)

    def test_console_level_does_not_change_state(self):
        states = []
        for level in ("quiet", "important", "debug"):
            sniffer = self.make_sniffer(
                process_battle_state=True,
                producer_session_id=f"console-{level}",
                battle_console_level=level,
            )
            sniffer._process_battle_observation(
                observation("battle_started")
            )
            states.append(
                {
                    key: value
                    for key, value in sniffer.battle_processor.snapshot().items()
                    if key
                    not in {
                        "applied_event_ids",
                        "last_producer_session_id",
                    }
                }
            )
        self.assertEqual(states[0], states[1])
        self.assertEqual(states[1], states[2])

    def test_ctrl_c_summary_is_safe_and_uses_short_session(self):
        session_id = "12345678-1234-1234-1234-123456789abc"
        sniffer = self.make_sniffer(
            process_battle_state=True,
            producer_session_id=session_id,
        )
        sniffer._process_battle_observation(
            observation("battle_started")
        )
        summary = sniffer.battle_processor_summary()
        self.assertEqual(summary["producer_session_id"], "1234...9abc")
        self.assertEqual(summary["observations_processed"], 1)
        self.assertEqual(summary["domain_events_emitted"], 1)
        self.assertEqual(summary["state_changes"], 1)
        self.assertEqual(summary["battle_status"], "active")
        for key in (
            "duplicate_events",
            "processor_errors",
            "turn",
            "self_hand_count",
            "self_pending_count",
            "self_revealed_count",
            "opponent_revealed_count",
            "outcome",
        ):
            self.assertIn(key, summary)
        self.assertNotIn("self_hand", summary)

    def test_duplicate_events_are_counted_without_state_replay(self):
        sniffer = self.make_sniffer(
            process_battle_state=True,
            producer_session_id="duplicate-session",
        )
        started = observation("battle_started")
        sniffer._process_battle_observation(started)
        sniffer._process_battle_observation(started)
        summary = sniffer.battle_processor_summary()
        self.assertEqual(summary["duplicate_events"], 1)
        self.assertEqual(summary["state_changes"], 1)

    def test_writer_failure_prevents_processor_attempt(self):
        class FailingStream:
            def process(self, _record):
                raise OSError("writer failed")

            def close(self):
                return {}

        processor = RecordingProcessor("writer-failure")
        sniffer = self.make_sniffer(
            process_battle_state=True,
            producer_session_id="writer-failure",
            battle_processor=processor,
            battle_observation_stream=FailingStream(),
        )
        with self.assertRaises(OSError):
            sniffer.handle_frame(frame("gameStart"), "received")
        self.assertEqual(processor.observations, [])

    def test_no_tracker_url_keeps_api_disabled(self):
        sniffer = self.make_sniffer(
            process_battle_state=True,
            producer_session_id="no-api",
        )
        self.assertFalse(
            sniffer.battle_processor_summary()["api_enabled"]
        )

    def test_api_receives_processor_session_and_domain_event(self):
        api = RecordingTrackerClient()
        sniffer = self.make_sniffer(
            process_battle_state=True,
            producer_session_id="api-session",
            tracker_event_client=api,
        )
        sniffer._process_battle_observation(
            observation("battle_started")
        )
        self.assertEqual(len(api.events), 1)
        self.assertEqual(
            api.events[0]["producer_session_id"],
            "api-session",
        )

    def test_non_required_api_failure_continues_local_processing(self):
        api = RecordingTrackerClient(
            [
                {"ok": False, "code": "CONNECTION_ERROR"},
                {"ok": True, "duplicate": False},
            ]
        )
        sniffer = self.make_sniffer(
            process_battle_state=True,
            producer_session_id="optional-api",
            tracker_event_client=api,
        )
        sniffer._process_battle_observation(
            observation("battle_started")
        )
        sniffer._process_battle_observation(
            observation(
                "card_drawn",
                sequence=2,
                payload={"card": card()},
                visibility="self_private",
            )
        )
        summary = sniffer.battle_processor_summary()
        self.assertEqual(summary["api_errors"], 1)
        self.assertEqual(summary["api_events_attempted"], 2)
        self.assertEqual(summary["self_hand_count"], 1)
        self.assertTrue(summary["api_diverged"])

    def test_required_api_failure_stops_later_processing(self):
        api = RecordingTrackerClient(
            [{"ok": False, "code": "HTTP_400"}]
        )
        sniffer = self.make_sniffer(
            process_battle_state=True,
            producer_session_id="required-api",
            tracker_event_client=api,
            tracker_api_required=True,
        )
        sniffer._process_battle_observation(
            observation("battle_started")
        )
        second = sniffer._process_battle_observation(
            observation(
                "card_drawn",
                sequence=2,
                payload={"card": card()},
                visibility="self_private",
            )
        )
        self.assertIsNone(second)
        self.assertEqual(len(api.events), 1)
        self.assertEqual(
            sniffer.battle_processor_summary()["self_hand_count"],
            0,
        )

    def test_api_submission_preserves_domain_event_order(self):
        api = RecordingTrackerClient()
        sniffer = self.make_sniffer(
            process_battle_state=True,
            producer_session_id="ordered-api",
            tracker_event_client=api,
        )
        events = [
            {"event_type": "first", "source_sequence": 1},
            {"event_type": "second", "source_sequence": 1},
        ]
        sniffer._submit_domain_events(events)
        self.assertEqual(
            [event["event_type"] for event in api.events],
            ["first", "second"],
        )

    def test_api_failure_stops_remaining_events_in_frame(self):
        api = RecordingTrackerClient(
            [{"ok": False, "code": "HTTP_503"}]
        )
        sniffer = self.make_sniffer(
            process_battle_state=True,
            producer_session_id="partial-api",
            tracker_event_client=api,
        )
        sniffer._submit_domain_events(
            [
                {"event_type": "first", "source_sequence": 1},
                {"event_type": "second", "source_sequence": 1},
            ]
        )
        summary = sniffer.battle_processor_summary()
        self.assertEqual(len(api.events), 1)
        self.assertTrue(summary["api_diverged"])
        self.assertEqual(summary["api_last_error_code"], "HTTP_503")

    def test_api_duplicate_updates_summary(self):
        api = RecordingTrackerClient(
            [{"ok": True, "duplicate": True}]
        )
        sniffer = self.make_sniffer(
            process_battle_state=True,
            producer_session_id="duplicate-api",
            tracker_event_client=api,
        )
        sniffer._submit_domain_events(
            [{"event_type": "battle.started", "source_sequence": 1}]
        )
        summary = sniffer.battle_processor_summary()
        self.assertEqual(summary["api_duplicates"], 1)
        self.assertEqual(summary["api_events_accepted"], 0)

    def test_api_summary_does_not_include_url_or_payload(self):
        api = RecordingTrackerClient()
        sniffer = self.make_sniffer(
            process_battle_state=True,
            producer_session_id="safe-api-summary",
            tracker_event_client=api,
        )
        summary = json.dumps(
            sniffer.battle_processor_summary(),
            ensure_ascii=False,
        )
        self.assertNotIn("http://", summary)
        self.assertNotIn("payload", summary)

    def test_api_failure_does_not_remove_safe_jsonl_observation(self):
        api = RecordingTrackerClient(
            [{"ok": False, "code": "CONNECTION_ERROR"}]
        )
        sniffer = self.make_sniffer(
            battle_observations=True,
            process_battle_state=True,
            producer_session_id="writer-survives-api",
            tracker_event_client=api,
        )
        sniffer.handle_frame(frame("gameStart"), "received")
        rows = (
            Path(self.temp_dir.name) / "battle-observations.jsonl"
        ).read_text(encoding="utf-8").splitlines()
        self.assertEqual(len(rows), 1)
        self.assertEqual(json.loads(rows[0])["type"], "battle_started")
        self.assertTrue(
            sniffer.battle_processor_summary()["api_diverged"]
        )

    def test_tracker_api_cli_requires_processor_and_url(self):
        parser = build_argument_parser()
        with self.assertRaises(SystemExit):
            validate_runtime_arguments(
                parser,
                parser.parse_args(
                    ["--tracker-api-url", "http://127.0.0.1:8765"]
                ),
            )
        with self.assertRaises(SystemExit):
            validate_runtime_arguments(
                parser,
                parser.parse_args(["--tracker-api-required"]),
            )

    def test_tracker_api_cli_defaults(self):
        parser = build_argument_parser()
        args = validate_runtime_arguments(
            parser,
            parser.parse_args(
                [
                    "--process-battle-state",
                    "--tracker-api-url",
                    "http://127.0.0.1:8765",
                ]
            ),
        )
        self.assertEqual(args.tracker_api_timeout, 2.0)
        self.assertFalse(args.tracker_api_required)

    def test_processor_failure_keeps_written_observation_explainable(self):
        output = []
        processor = RecordingProcessor(
            "processor-failure",
            exception=RuntimeError("failure"),
        )
        sniffer = ULSniffer(
            output_dir=self.temp_dir.name,
            enable_stdin_markers=False,
            battle_observations=True,
            process_battle_state=True,
            producer_session_id="processor-failure",
            battle_processor=processor,
            console=output.append,
        )
        self.sniffers.append(sniffer)
        sniffer.handle_frame(frame("gameStart"), "received")
        rows = (
            Path(self.temp_dir.name) / "battle-observations.jsonl"
        ).read_text(encoding="utf-8").splitlines()
        self.assertEqual(len(rows), 1)
        self.assertEqual(
            sniffer.battle_processor_summary()["processor_errors"],
            1,
        )
        self.assertTrue(
            any("processor_exception" in line for line in output)
        )

    def test_summary_does_not_include_secret_or_raw_payload(self):
        token = "SessionToken1234567890ABCDEFGHIJKL"
        sniffer = self.make_sniffer(
            process_battle_state=True,
            producer_session_id="summary-session",
        )
        summary_text = json.dumps(
            sniffer.battle_processor_summary(),
            ensure_ascii=False,
        )
        self.assertNotIn(token, summary_text)
        self.assertNotIn("room_id", summary_text)
        self.assertNotIn("raw", summary_text)

    def test_chrome_and_electron_processors_are_isolated(self):
        chrome = self.make_sniffer(
            port=1221,
            process_battle_state=True,
            producer_session_id="chrome-session",
            local_side="A",
        )
        electron_dir = tempfile.TemporaryDirectory()
        self.addCleanup(electron_dir.cleanup)
        electron = ULSniffer(
            port=9333,
            output_dir=electron_dir.name,
            enable_stdin_markers=False,
            process_battle_state=True,
            producer_session_id="electron-session",
            local_side="B",
            console=lambda _line: None,
        )
        self.sniffers.append(electron)
        chrome._process_battle_observation(
            observation("battle_started")
        )
        self.assertEqual(
            chrome.battle_processor.snapshot()["battle_status"],
            "active",
        )
        self.assertEqual(
            electron.battle_processor.snapshot()["battle_status"],
            "idle",
        )
        self.assertNotEqual(
            chrome.producer_session_id,
            electron.producer_session_id,
        )

    def test_existing_all_log_event_dir_cli_still_parses(self):
        args = build_argument_parser().parse_args(
            ["--all", "--log", "--event-dir"]
        )
        self.assertTrue(args.all)
        self.assertTrue(args.log)
        self.assertTrue(args.event_dir)
        self.assertFalse(args.process_battle_state)

    def test_new_processor_cli_options_parse(self):
        args = build_argument_parser().parse_args(
            [
                "--process-battle-state",
                "--battle-start-mode",
                "diagnostic-bootstrap",
                "--local-side",
                "B",
                "--battle-console-level",
                "debug",
            ]
        )
        self.assertTrue(args.process_battle_state)
        self.assertEqual(args.battle_start_mode, "diagnostic-bootstrap")
        self.assertEqual(args.local_side, "B")
        self.assertEqual(args.battle_console_level, "debug")

    def test_processor_only_options_require_processor_flag(self):
        parser = build_argument_parser()
        args = parser.parse_args(
            ["--battle-console-level", "debug"]
        )
        with self.assertRaises(SystemExit):
            validate_runtime_arguments(parser, args)

    def test_explicit_default_processor_option_still_requires_flag(self):
        parser = build_argument_parser()
        args = parser.parse_args(
            ["--battle-start-mode", "strict"]
        )
        with self.assertRaises(SystemExit):
            validate_runtime_arguments(parser, args)

    def test_validated_processor_cli_applies_runtime_defaults(self):
        parser = build_argument_parser()
        args = validate_runtime_arguments(
            parser,
            parser.parse_args(["--process-battle-state"]),
        )
        self.assertEqual(args.battle_start_mode, "strict")
        self.assertEqual(args.battle_console_level, "important")

    def test_full_discovery_corpus_parses_and_reduces_without_exception(self):
        if not DISCOVERY_CORPUS.is_file():
            self.skipTest("redacted discovery corpus is not available")
        sniffer = self.make_sniffer(
            process_battle_state=True,
            producer_session_id="corpus-session",
            local_side="A",
            battle_console_level="quiet",
        )
        with DISCOVERY_CORPUS.open("r", encoding="utf-8") as corpus:
            for line in corpus:
                record = json.loads(line)
                observations = sniffer.battle_observation_stream.process(
                    record
                )
                for safe_observation in observations:
                    sniffer._process_battle_observation(safe_observation)
        summary = sniffer.battle_processor_summary()
        self.assertGreater(summary["observations_processed"], 0)
        self.assertEqual(summary["processor_errors"], 0)


if __name__ == "__main__":
    unittest.main()
