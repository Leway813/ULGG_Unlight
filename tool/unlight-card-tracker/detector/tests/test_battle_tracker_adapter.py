import ast
import copy
import json
from pathlib import Path
import unittest

from battle_tracker_adapter import (
    ALLOWED_VISIBILITIES,
    DOMAIN_EVENT_SCHEMA_VERSION,
    adapt_battle_observation,
)


DETECTOR_DIR = Path(__file__).resolve().parents[1]
CORPUS_PATH = DETECTOR_DIR / "deck" / "battle-observations.jsonl"


def face(kind="sword", value=2):
    return {"kind": kind, "value": value}


def card(card_id=55, slot=4, rotate=False):
    face1 = face("sword", 2)
    face2 = face("shield", 2)
    return {
        "card_id": card_id,
        "slot": slot,
        "type": "normal",
        "rotate": rotate,
        "used": False,
        "clicked": False,
        "face1": face1,
        "face2": face2,
        "display_top": face2 if rotate else face1,
        "display_bottom": face1 if rotate else face2,
        "display_order_confirmation": "provisional",
    }


SOURCE_BY_TYPE = {
    "battle_started": "gameStart",
    "cards_dealt": "drawPhase",
    "event_card_received": "eventCard",
    "card_drawn": "cardDraw",
    "card_selection_changed": "cardclickedA",
    "cards_revealed": "cardOpen_A",
    "phase_ended": "endPhase",
    "turn_ended": "endTurn",
    "battle_finished": "result",
}

PAYLOAD_BY_TYPE = {
    "battle_started": {},
    "cards_dealt": {"cards": [card()]},
    "event_card_received": {"card": card()},
    "card_drawn": {"card": card()},
    "card_selection_changed": {
        "slot": 4,
        "selected": True,
        "selection": "selected",
    },
    "cards_revealed": {"cards": [card()]},
    "phase_ended": {"phase": "move"},
    "turn_ended": {"turn": 3},
    "battle_finished": {"outcome": "win"},
}


def observation(observation_type="battle_started", **overrides):
    is_selection = observation_type == "card_selection_changed"
    result = {
        "schema_version": 1,
        "timestamp": "2026-07-28T00:08:49.214+08:00",
        "sequence": 258,
        "observation_index": 0,
        "type": observation_type,
        "source_event": SOURCE_BY_TYPE.get(observation_type, "unknown"),
        "direction": "received",
        "protocol_side": "A" if is_selection else "unknown",
        "resolved_side": "self" if is_selection else "unknown",
        "visibility": "self_private" if is_selection else "public",
        "confirmation": "pending" if is_selection else "confirmed",
        "payload": copy.deepcopy(PAYLOAD_BY_TYPE.get(observation_type, {})),
    }
    if observation_type in {
        "cards_dealt",
        "event_card_received",
        "card_drawn",
    }:
        result["visibility"] = "self_private"
    if observation_type == "cards_revealed":
        result["protocol_side"] = "A"
        result["resolved_side"] = "self"
    result.update(overrides)
    return result


class BattleTrackerAdapterTests(unittest.TestCase):
    def assert_event_type(self, observation_type, event_type):
        events = adapt_battle_observation(observation(observation_type))
        self.assertEqual(len(events), 1)
        self.assertEqual(events[0]["event_type"], event_type)
        return events[0]

    def test_maps_battle_started(self):
        self.assert_event_type("battle_started", "battle.started")

    def test_maps_cards_dealt(self):
        self.assert_event_type("cards_dealt", "hand.cards_dealt")

    def test_maps_event_card_received(self):
        self.assert_event_type(
            "event_card_received", "hand.event_card_received"
        )

    def test_maps_card_drawn(self):
        self.assert_event_type("card_drawn", "hand.card_drawn")

    def test_maps_card_selection_changed(self):
        self.assert_event_type(
            "card_selection_changed", "play.selection_changed"
        )

    def test_maps_cards_revealed(self):
        self.assert_event_type("cards_revealed", "play.cards_revealed")

    def test_maps_phase_ended(self):
        self.assert_event_type("phase_ended", "battle.phase_ended")

    def test_maps_turn_ended(self):
        self.assert_event_type("turn_ended", "battle.turn_ended")

    def test_maps_battle_finished(self):
        self.assert_event_type("battle_finished", "battle.finished")

    def test_public_visibility_is_allowed(self):
        event = self.assert_event_type("battle_started", "battle.started")
        self.assertEqual(event["visibility"], "public")

    def test_self_private_visibility_is_allowed(self):
        event = self.assert_event_type("card_drawn", "hand.card_drawn")
        self.assertEqual(event["visibility"], "self_private")

    def test_opponent_revealed_visibility_is_allowed(self):
        value = observation(
            "cards_revealed",
            source_event="cardOpen_B",
            protocol_side="B",
            resolved_side="opponent",
            visibility="opponent_revealed",
        )
        event = adapt_battle_observation(value)[0]
        self.assertEqual(event["visibility"], "opponent_revealed")

    def test_restricted_visibility_is_rejected(self):
        value = observation(
            "cards_revealed",
            visibility="restricted_opponent_hidden",
            resolved_side="opponent",
        )
        self.assertEqual(adapt_battle_observation(value), [])

    def test_diagnostic_visibility_is_rejected(self):
        value = observation("battle_started", visibility="diagnostic_only")
        self.assertEqual(adapt_battle_observation(value), [])

    def test_unknown_visibility_is_rejected(self):
        value = observation("battle_started", visibility="secret")
        self.assertEqual(adapt_battle_observation(value), [])

    def test_allowed_visibility_contract_is_exact(self):
        self.assertEqual(
            ALLOWED_VISIBILITIES,
            frozenset({"public", "self_private", "opponent_revealed"}),
        )

    def test_hidden_opponent_card_never_emits(self):
        value = observation(
            "cards_revealed",
            resolved_side="opponent",
            visibility="restricted_opponent_hidden",
        )
        self.assertEqual(adapt_battle_observation(value), [])

    def test_cards_dealt_preserves_complete_card(self):
        original = card(31, 2, True)
        value = observation("cards_dealt", payload={"cards": [original]})
        output = adapt_battle_observation(value)[0]["payload"]["cards"][0]
        self.assertEqual(output, original)

    def test_event_card_received_preserves_complete_card(self):
        original = card(67, 1)
        value = observation(
            "event_card_received", payload={"card": original}
        )
        output = adapt_battle_observation(value)[0]["payload"]["card"]
        self.assertEqual(output, original)

    def test_card_drawn_preserves_complete_card(self):
        original = card(88, 6)
        value = observation("card_drawn", payload={"card": original})
        output = adapt_battle_observation(value)[0]["payload"]["card"]
        self.assertEqual(output, original)

    def test_selection_does_not_remove_or_play_a_card(self):
        event = adapt_battle_observation(
            observation("card_selection_changed")
        )[0]
        self.assertEqual(
            event["payload"],
            {"slot": 4, "selected": True, "selection": "selected"},
        )
        self.assertNotIn("card", event["payload"])
        self.assertNotIn("from_zone", event["payload"])
        self.assertNotIn("to_zone", event["payload"])

    def test_returned_selection_is_preserved(self):
        value = observation(
            "card_selection_changed",
            payload={"slot": 4, "selected": False, "selection": "returned"},
        )
        self.assertEqual(
            adapt_battle_observation(value)[0]["payload"]["selection"],
            "returned",
        )

    def test_inconsistent_selection_payload_is_rejected(self):
        value = observation(
            "card_selection_changed",
            payload={"slot": 4, "selected": False, "selection": "selected"},
        )
        self.assertEqual(adapt_battle_observation(value), [])

    def test_selection_requires_pending_confirmation(self):
        value = observation(
            "card_selection_changed", confirmation="confirmed"
        )
        self.assertEqual(adapt_battle_observation(value), [])

    def test_reveal_requires_confirmed_confirmation(self):
        value = observation("cards_revealed", confirmation="pending")
        self.assertEqual(adapt_battle_observation(value), [])

    def test_reveal_with_unknown_side_is_rejected(self):
        value = observation(
            "cards_revealed",
            source_event="cardOpen",
            protocol_side="unknown",
            resolved_side="unknown",
        )
        self.assertEqual(adapt_battle_observation(value), [])

    def test_selection_with_unknown_side_is_rejected(self):
        value = observation(
            "card_selection_changed",
            protocol_side="unknown",
            resolved_side="unknown",
            visibility="self_private",
        )
        self.assertEqual(adapt_battle_observation(value), [])

    def test_self_private_hand_event_may_preserve_unknown_side(self):
        event = adapt_battle_observation(observation("card_drawn"))[0]
        self.assertEqual(event["resolved_side"], "unknown")

    def test_hand_event_cannot_claim_opponent_side(self):
        value = observation("card_drawn", resolved_side="opponent")
        self.assertEqual(adapt_battle_observation(value), [])

    def test_phase_payload_is_preserved(self):
        event = self.assert_event_type("phase_ended", "battle.phase_ended")
        self.assertEqual(event["payload"], {"phase": "move"})

    def test_turn_payload_is_preserved(self):
        event = self.assert_event_type("turn_ended", "battle.turn_ended")
        self.assertEqual(event["payload"], {"turn": 3})

    def test_result_outcome_is_preserved(self):
        event = self.assert_event_type("battle_finished", "battle.finished")
        self.assertEqual(event["payload"], {"outcome": "win"})

    def test_invalid_result_is_rejected(self):
        value = observation(
            "battle_finished", payload={"outcome": "victory"}
        )
        self.assertEqual(adapt_battle_observation(value), [])

    def test_same_observation_has_same_idempotency_key(self):
        value = observation("card_drawn")
        first = adapt_battle_observation(value)[0]
        second = adapt_battle_observation(copy.deepcopy(value))[0]
        self.assertEqual(first["idempotency_key"], second["idempotency_key"])

    def test_idempotency_key_contract(self):
        event = adapt_battle_observation(observation("card_drawn"))[0]
        self.assertEqual(
            event["idempotency_key"], "ws:258:0:hand.card_drawn"
        )

    def test_repeated_selection_toggles_have_distinct_keys(self):
        first = observation("card_selection_changed", sequence=300)
        second = observation("card_selection_changed", sequence=301)
        first_key = adapt_battle_observation(first)[0]["idempotency_key"]
        second_key = adapt_battle_observation(second)[0]["idempotency_key"]
        self.assertNotEqual(first_key, second_key)

    def test_observation_index_participates_in_identity(self):
        first = observation("card_drawn", observation_index=0)
        second = observation("card_drawn", observation_index=1)
        self.assertNotEqual(
            adapt_battle_observation(first)[0]["idempotency_key"],
            adapt_battle_observation(second)[0]["idempotency_key"],
        )

    def test_source_metadata_is_complete(self):
        event = adapt_battle_observation(observation("card_drawn"))[0]
        self.assertEqual(event["source"], "websocket")
        self.assertEqual(event["source_event"], "cardDraw")
        self.assertEqual(event["source_sequence"], 258)
        self.assertEqual(event["source_observation_index"], 0)
        self.assertEqual(event["source_direction"], "received")

    def test_confirmed_event_is_authoritative(self):
        event = adapt_battle_observation(observation("card_drawn"))[0]
        self.assertEqual(event["confirmation"], "confirmed")
        self.assertEqual(event["authority"], "authoritative")

    def test_pending_event_is_provisional(self):
        event = adapt_battle_observation(
            observation("card_selection_changed")
        )[0]
        self.assertEqual(event["confirmation"], "pending")
        self.assertEqual(event["authority"], "provisional")

    def test_websocket_transport_confidence_is_one(self):
        event = adapt_battle_observation(observation("card_drawn"))[0]
        self.assertEqual(event["confidence"], 1.0)

    def test_domain_event_schema_version_is_one(self):
        event = adapt_battle_observation(observation("card_drawn"))[0]
        self.assertEqual(
            event["domain_event_schema_version"],
            DOMAIN_EVENT_SCHEMA_VERSION,
        )

    def test_face_enums_are_not_translated_to_tracker_labels(self):
        event = adapt_battle_observation(observation("card_drawn"))[0]
        projected = event["payload"]["card"]
        self.assertEqual(projected["face1"], {"kind": "sword", "value": 2})
        self.assertEqual(projected["face2"], {"kind": "shield", "value": 2})

    def test_rotate_display_order_is_preserved(self):
        rotated = card(55, 4, True)
        value = observation("card_drawn", payload={"card": rotated})
        projected = adapt_battle_observation(value)[0]["payload"]["card"]
        self.assertEqual(projected["display_top"], rotated["face2"])
        self.assertEqual(projected["display_bottom"], rotated["face1"])

    def test_invalid_face_kind_is_rejected(self):
        invalid = card()
        invalid["face1"] = face("magic", 3)
        value = observation("card_drawn", payload={"card": invalid})
        self.assertEqual(adapt_battle_observation(value), [])

    def test_invalid_blank_face_value_is_rejected(self):
        invalid = card()
        invalid["face1"] = face("blank", 1)
        value = observation("card_drawn", payload={"card": invalid})
        self.assertEqual(adapt_battle_observation(value), [])

    def test_missing_card_field_is_rejected(self):
        invalid = card()
        del invalid["display_bottom"]
        value = observation("card_drawn", payload={"card": invalid})
        self.assertEqual(adapt_battle_observation(value), [])

    def test_empty_card_list_is_rejected(self):
        value = observation("cards_dealt", payload={"cards": []})
        self.assertEqual(adapt_battle_observation(value), [])

    def test_malformed_inputs_return_empty_without_raising(self):
        malformed = [
            None,
            [],
            "observation",
            {},
            {"schema_version": 1},
            observation("card_drawn", payload=None),
            observation("card_drawn", sequence=True),
            observation("card_drawn", observation_index=-1),
        ]
        for value in malformed:
            with self.subTest(value=value):
                self.assertEqual(adapt_battle_observation(value), [])

    def test_unsupported_observation_type_is_ignored(self):
        self.assertEqual(adapt_battle_observation(observation("unknown")), [])

    def test_character_state_is_not_in_this_slice(self):
        self.assertEqual(
            adapt_battle_observation(observation("character_state")), []
        )

    def test_reserve_character_state_is_not_emitted(self):
        value = observation(
            "character_state",
            payload={"main": False, "chara_id": "cc041"},
            visibility="restricted_opponent_hidden",
        )
        self.assertEqual(adapt_battle_observation(value), [])

    def test_duel_standby_is_diagnostic_only(self):
        self.assertEqual(
            adapt_battle_observation(observation("duel_standby")), []
        )

    def test_wrong_source_event_is_rejected(self):
        value = observation("card_drawn", source_event="drawPhase")
        self.assertEqual(adapt_battle_observation(value), [])

    def test_unsupported_observation_schema_is_rejected(self):
        value = observation("card_drawn", schema_version=2)
        self.assertEqual(adapt_battle_observation(value), [])

    def test_missing_timestamp_is_rejected_without_using_current_time(self):
        value = observation("card_drawn", timestamp=None)
        self.assertEqual(adapt_battle_observation(value), [])

    def test_occurred_at_is_source_timestamp(self):
        value = observation(
            "card_drawn", timestamp="2026-07-28T00:09:00+08:00"
        )
        event = adapt_battle_observation(value)[0]
        self.assertEqual(event["occurred_at"], value["timestamp"])

    def test_adapter_does_not_mutate_input(self):
        value = observation("card_drawn")
        before = copy.deepcopy(value)
        adapt_battle_observation(value)
        self.assertEqual(value, before)

    def test_adapter_output_is_deterministic(self):
        value = observation("cards_dealt")
        self.assertEqual(
            adapt_battle_observation(value),
            adapt_battle_observation(copy.deepcopy(value)),
        )

    def test_safe_extra_fields_are_not_copied(self):
        value = observation("card_drawn", debug_score=0.92)
        value["payload"]["debug_score"] = 0.91
        serialized = json.dumps(
            adapt_battle_observation(value), ensure_ascii=False
        )
        self.assertNotIn("debug_score", serialized)

    def test_forbidden_fields_are_rejected_recursively(self):
        forbidden = [
            "raw",
            "args",
            "args_summary",
            "token",
            "session_token",
            "room_id",
            "session_target",
            "websocket_url",
        ]
        for key in forbidden:
            value = observation("card_drawn")
            value["payload"]["nested"] = {key: "secret-value"}
            with self.subTest(key=key):
                self.assertEqual(adapt_battle_observation(value), [])

    def test_output_never_contains_secret_transport_keys(self):
        output = json.dumps(
            adapt_battle_observation(observation("cards_dealt"))
        ).lower()
        for key in (
            "token",
            "session_token",
            "room_id",
            "args_summary",
            "websocket_url",
        ):
            self.assertNotIn(key, output)

    def test_sent_direction_is_observed_but_never_becomes_an_action(self):
        value = observation("card_selection_changed", direction="sent")
        event = adapt_battle_observation(value)[0]
        self.assertEqual(event["source_direction"], "sent")
        self.assertNotIn("send", event)
        self.assertNotIn("command", event)

    def test_invalid_direction_is_rejected(self):
        value = observation("battle_started", direction="outbound")
        self.assertEqual(adapt_battle_observation(value), [])

    def test_module_has_no_io_network_or_tracker_dependency(self):
        source_path = DETECTOR_DIR / "battle_tracker_adapter.py"
        tree = ast.parse(source_path.read_text(encoding="utf-8"))
        imports = {
            node.names[0].name.split(".")[0]
            for node in ast.walk(tree)
            if isinstance(node, ast.Import)
        }
        imports.update(
            node.module.split(".")[0]
            for node in ast.walk(tree)
            if isinstance(node, ast.ImportFrom) and node.module
        )
        forbidden_imports = {
            "asyncio",
            "pathlib",
            "sqlite3",
            "socket",
            "urllib",
            "webbrowser",
            "websockets",
        }
        self.assertTrue(imports.isdisjoint(forbidden_imports))

    @unittest.skipUnless(CORPUS_PATH.exists(), "local PvP corpus not present")
    def test_real_safe_corpus_replays_without_exceptions(self):
        outputs = []
        for line in CORPUS_PATH.read_text(encoding="utf-8").splitlines():
            outputs.extend(adapt_battle_observation(json.loads(line)))
        self.assertGreater(len(outputs), 0)
        self.assertTrue(
            all(event["source"] == "websocket" for event in outputs)
        )

    @unittest.skipUnless(CORPUS_PATH.exists(), "local PvP corpus not present")
    def test_real_safe_corpus_never_leaks_restricted_fields(self):
        outputs = []
        for line in CORPUS_PATH.read_text(encoding="utf-8").splitlines():
            outputs.extend(adapt_battle_observation(json.loads(line)))
        serialized = json.dumps(outputs, ensure_ascii=False).lower()
        for key in (
            "session_token",
            "room_id",
            "args_summary",
            "websocket_url",
        ):
            self.assertNotIn(key, serialized)


if __name__ == "__main__":
    unittest.main()
