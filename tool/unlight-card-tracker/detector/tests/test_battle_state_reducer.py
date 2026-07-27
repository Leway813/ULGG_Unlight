import ast
import copy
import json
from pathlib import Path
import unittest

from battle_state_reducer import (
    BATTLE_STATE_SCHEMA_VERSION,
    initial_battle_state,
    reduce_battle_event,
    reduce_battle_events,
    with_producer_session_id,
)
from battle_tracker_adapter import adapt_battle_observation


DETECTOR_DIR = Path(__file__).resolve().parents[1]
CORPUS_PATH = DETECTOR_DIR / "deck" / "battle-observations.jsonl"


def face(kind="sword", value=2):
    return {"kind": kind, "value": value}


def card(card_id=55, slot=4, rotate=False):
    first = face("sword", 2)
    second = face("shield", 2)
    return {
        "card_id": card_id,
        "slot": slot,
        "type": "normal",
        "rotate": rotate,
        "used": False,
        "clicked": False,
        "face1": first,
        "face2": second,
        "display_top": second if rotate else first,
        "display_bottom": first if rotate else second,
        "display_order_confirmation": "provisional",
    }


def domain_event(
    event_type,
    *,
    payload=None,
    sequence=1,
    session="producer-1",
    authority=None,
    confirmation=None,
    visibility=None,
    side="unknown",
):
    provisional = event_type == "play.selection_changed"
    if authority is None:
        authority = "provisional" if provisional else "authoritative"
    if confirmation is None:
        confirmation = "pending" if provisional else "confirmed"
    if visibility is None:
        if event_type.startswith("hand.") or provisional:
            visibility = "self_private"
        elif event_type == "play.cards_revealed" and side == "opponent":
            visibility = "opponent_revealed"
        else:
            visibility = "public"
    return {
        "domain_event_schema_version": 1,
        "event_type": event_type,
        "payload": copy.deepcopy(payload or {}),
        "source": "websocket",
        "source_event": "test",
        "source_sequence": sequence,
        "source_observation_index": 0,
        "source_direction": "received",
        "idempotency_key": f"ws:{sequence}:0:{event_type}",
        "occurred_at": f"2026-07-28T00:00:{sequence % 60:02d}+08:00",
        "protocol_side": "unknown",
        "resolved_side": side,
        "visibility": visibility,
        "confirmation": confirmation,
        "confidence": 1.0,
        "authority": authority,
        "producer_session_id": session,
    }


def started_state(session="producer-1"):
    state, diagnostics = reduce_battle_event(
        initial_battle_state(),
        domain_event("battle.started", session=session),
    )
    assert not diagnostics
    return state


class BattleStateReducerTests(unittest.TestCase):
    def test_initial_idle_state(self):
        state = initial_battle_state()
        self.assertEqual(state["schema_version"], BATTLE_STATE_SCHEMA_VERSION)
        self.assertEqual(state["battle_status"], "idle")
        self.assertEqual(state["turn"], 0)
        self.assertEqual(state["self_hand"], [])

    def test_initial_states_do_not_share_lists(self):
        first = initial_battle_state()
        second = initial_battle_state()
        first["self_hand"].append({"card_id": 1})
        self.assertEqual(second["self_hand"], [])

    def test_battle_started_enters_active(self):
        state, diagnostics = reduce_battle_event(
            initial_battle_state(), domain_event("battle.started")
        )
        self.assertEqual(state["battle_status"], "active")
        self.assertEqual(
            state["battle_started_at"], "2026-07-28T00:00:01+08:00"
        )
        self.assertEqual(diagnostics, [])

    def test_second_battle_started_clears_pending(self):
        state = started_state()
        state["self_pending_selection"] = [2, 3]
        restarted, diagnostics = reduce_battle_event(
            state, domain_event("battle.started", sequence=2)
        )
        self.assertEqual(restarted["self_pending_selection"], [])
        self.assertEqual(diagnostics[0]["code"], "battle_restarted")

    def test_second_battle_started_clears_previous_cards(self):
        state = started_state()
        state["self_hand"] = [{"card_key": "slot:1"}]
        restarted, _ = reduce_battle_event(
            state, domain_event("battle.started", sequence=2)
        )
        self.assertEqual(restarted["self_hand"], [])

    def test_new_session_battle_started_restarts_active_battle(self):
        state = started_state(session="producer-1")
        restarted, diagnostics = reduce_battle_event(
            state,
            domain_event(
                "battle.started",
                sequence=1,
                session="producer-2",
            ),
        )
        self.assertEqual(restarted["battle_status"], "active")
        self.assertEqual(
            restarted["last_producer_session_id"], "producer-2"
        )
        self.assertEqual(diagnostics[0]["code"], "battle_restarted")

    def test_cards_dealt_adds_six_cards(self):
        cards = [card(50 + index, index) for index in range(6)]
        state, diagnostics = reduce_battle_event(
            started_state(),
            domain_event(
                "hand.cards_dealt",
                payload={"cards": cards},
                sequence=2,
            ),
        )
        self.assertEqual(len(state["self_hand"]), 6)
        self.assertEqual(diagnostics, [])

    def test_replayed_cards_dealt_does_not_duplicate(self):
        event = domain_event(
            "hand.cards_dealt",
            payload={"cards": [card()]},
            sequence=2,
        )
        state, _ = reduce_battle_event(started_state(), event)
        replayed, diagnostics = reduce_battle_event(state, event)
        self.assertEqual(len(replayed["self_hand"]), 1)
        self.assertEqual(diagnostics[0]["code"], "duplicated_event")

    def test_same_card_id_different_slots_can_coexist(self):
        event = domain_event(
            "hand.cards_dealt",
            payload={"cards": [card(55, 1), card(55, 2)]},
            sequence=2,
        )
        state, _ = reduce_battle_event(started_state(), event)
        self.assertEqual(
            [value["card_key"] for value in state["self_hand"]],
            ["slot:1", "slot:2"],
        )

    def test_missing_slot_uses_source_sequence_and_index(self):
        event = domain_event(
            "hand.cards_dealt",
            payload={"cards": [card(55, None), card(55, None)]},
            sequence=7,
        )
        state, _ = reduce_battle_event(started_state(), event)
        self.assertEqual(
            [value["card_key"] for value in state["self_hand"]],
            ["card:55:7:0", "card:55:7:1"],
        )

    def test_event_card_is_stored_separately(self):
        state, _ = reduce_battle_event(
            started_state(),
            domain_event(
                "hand.event_card_received",
                payload={"card": card(67, 8)},
                sequence=2,
            ),
        )
        self.assertEqual(len(state["self_event_cards"]), 1)
        self.assertEqual(state["self_hand"], [])

    def test_card_drawn_adds_to_hand(self):
        state, _ = reduce_battle_event(
            started_state(),
            domain_event(
                "hand.card_drawn",
                payload={"card": card(31, 6)},
                sequence=2,
            ),
        )
        self.assertEqual(state["self_hand"][0]["card_id"], 31)

    def test_card_fields_are_preserved(self):
        original = card(31, 6, True)
        state, _ = reduce_battle_event(
            started_state(),
            domain_event(
                "hand.card_drawn",
                payload={"card": original},
                sequence=2,
            ),
        )
        stored = state["self_hand"][0]
        for field in (
            "card_id",
            "slot",
            "type",
            "rotate",
            "face1",
            "face2",
            "display_top",
            "display_bottom",
            "display_order_confirmation",
        ):
            self.assertEqual(stored[field], original[field])

    def test_seen_card_ids_are_unique_summaries(self):
        cards = [card(55, 1), card(55, 2)]
        state, _ = reduce_battle_event(
            started_state(),
            domain_event(
                "hand.cards_dealt",
                payload={"cards": cards},
                sequence=2,
            ),
        )
        self.assertEqual(state["seen_card_ids"], [55])

    def test_selection_selected_adds_pending_slot(self):
        state, _ = reduce_battle_event(
            started_state(),
            domain_event(
                "play.selection_changed",
                payload={
                    "slot": 4,
                    "selected": True,
                    "selection": "selected",
                },
                sequence=2,
                side="self",
            ),
        )
        self.assertEqual(state["self_pending_selection"], [4])

    def test_selection_selected_is_set_like(self):
        first = domain_event(
            "play.selection_changed",
            payload={"slot": 4, "selected": True, "selection": "selected"},
            sequence=2,
            side="self",
        )
        second = domain_event(
            "play.selection_changed",
            payload={"slot": 4, "selected": True, "selection": "selected"},
            sequence=3,
            side="self",
        )
        state, _ = reduce_battle_events(started_state(), [first, second])
        self.assertEqual(state["self_pending_selection"], [4])

    def test_selection_returned_removes_pending_slot(self):
        state = started_state()
        state["self_pending_selection"] = [4]
        state, _ = reduce_battle_event(
            state,
            domain_event(
                "play.selection_changed",
                payload={
                    "slot": 4,
                    "selected": False,
                    "selection": "returned",
                },
                sequence=2,
                side="self",
            ),
        )
        self.assertEqual(state["self_pending_selection"], [])

    def test_pending_selection_does_not_remove_hand(self):
        state = started_state()
        state["self_hand"] = [{"card_key": "slot:4", "card_id": 55}]
        new_state, _ = reduce_battle_event(
            state,
            domain_event(
                "play.selection_changed",
                payload={
                    "slot": 4,
                    "selected": True,
                    "selection": "selected",
                },
                sequence=2,
                side="self",
            ),
        )
        self.assertEqual(new_state["self_hand"], state["self_hand"])
        self.assertEqual(new_state["self_revealed_cards"], [])

    def test_self_reveal_removes_confirmed_hand_card(self):
        deal = domain_event(
            "hand.cards_dealt",
            payload={"cards": [card(55, 4), card(56, 5)]},
            sequence=2,
        )
        reveal = domain_event(
            "play.cards_revealed",
            payload={"cards": [card(55, 4)]},
            sequence=3,
            side="self",
        )
        state, _ = reduce_battle_events(started_state(), [deal, reveal])
        self.assertEqual(
            [value["card_id"] for value in state["self_hand"]], [56]
        )

    def test_self_reveal_adds_revealed_card(self):
        reveal = domain_event(
            "play.cards_revealed",
            payload={"cards": [card(55, 4)]},
            sequence=2,
            side="self",
        )
        state, _ = reduce_battle_event(started_state(), reveal)
        self.assertEqual(state["self_revealed_cards"][0]["card_id"], 55)

    def test_self_reveal_clears_corresponding_pending(self):
        state = started_state()
        state["self_pending_selection"] = [4]
        reveal = domain_event(
            "play.cards_revealed",
            payload={"cards": [card(55, 4)]},
            sequence=2,
            side="self",
        )
        state, _ = reduce_battle_event(state, reveal)
        self.assertEqual(state["self_pending_selection"], [])

    def test_authoritative_reveal_resolves_conflicting_pending(self):
        state = started_state()
        state["self_pending_selection"] = [4, 9]
        reveal = domain_event(
            "play.cards_revealed",
            payload={"cards": [card(55, 4)]},
            sequence=2,
            side="self",
        )
        state, diagnostics = reduce_battle_event(state, reveal)
        self.assertEqual(state["self_pending_selection"], [])
        self.assertIn(
            "conflict_resolved",
            [value["code"] for value in diagnostics],
        )

    def test_opponent_reveal_adds_opponent_card(self):
        reveal = domain_event(
            "play.cards_revealed",
            payload={"cards": [card(55, 4)]},
            sequence=2,
            side="opponent",
        )
        state, _ = reduce_battle_event(started_state(), reveal)
        self.assertEqual(
            state["opponent_revealed_cards"][0]["card_id"], 55
        )

    def test_opponent_reveal_clears_pending_slot(self):
        state = started_state()
        state["opponent_pending_slots"] = [4]
        reveal = domain_event(
            "play.cards_revealed",
            payload={"cards": [card(55, 4)]},
            sequence=2,
            side="opponent",
        )
        state, _ = reduce_battle_event(state, reveal)
        self.assertEqual(state["opponent_pending_slots"], [])

    def test_unknown_side_reveal_is_rejected(self):
        reveal = domain_event(
            "play.cards_revealed",
            payload={"cards": [card(55, 4)]},
            sequence=2,
            side="unknown",
        )
        state, diagnostics = reduce_battle_event(started_state(), reveal)
        self.assertEqual(state["self_revealed_cards"], [])
        self.assertEqual(
            diagnostics[0]["code"], "unknown_side_reveal"
        )

    def test_unconfirmed_reveal_is_rejected(self):
        reveal = domain_event(
            "play.cards_revealed",
            payload={"cards": [card(55, 4)]},
            sequence=2,
            side="self",
            confirmation="pending",
        )
        state, diagnostics = reduce_battle_event(started_state(), reveal)
        self.assertEqual(state["self_revealed_cards"], [])
        self.assertEqual(
            diagnostics[0]["code"], "confirmation_mismatch"
        )

    def test_phase_end_updates_phase(self):
        event = domain_event(
            "battle.phase_ended",
            payload={"phase": "move"},
            sequence=2,
        )
        state, _ = reduce_battle_event(started_state(), event)
        self.assertEqual(state["phase"], "move")

    def test_phase_end_preserves_pending_without_ownership_metadata(self):
        state = started_state()
        state["self_pending_selection"] = [4]
        event = domain_event(
            "battle.phase_ended",
            payload={"phase": "move"},
            sequence=2,
        )
        state, diagnostics = reduce_battle_event(state, event)
        self.assertEqual(state["self_pending_selection"], [4])
        self.assertEqual(
            diagnostics[0]["code"], "pending_selection_phase_unknown"
        )

    def test_turn_end_uses_payload_turn(self):
        event = domain_event(
            "battle.turn_ended",
            payload={"turn": 3},
            sequence=2,
        )
        state, _ = reduce_battle_event(started_state(), event)
        self.assertEqual(state["turn"], 3)

    def test_turn_end_without_payload_turn_increments(self):
        event = domain_event(
            "battle.turn_ended",
            payload={"turn": None},
            sequence=2,
        )
        state, _ = reduce_battle_event(started_state(), event)
        self.assertEqual(state["turn"], 1)

    def test_turn_end_replay_does_not_increment_twice(self):
        event = domain_event(
            "battle.turn_ended",
            payload={"turn": None},
            sequence=2,
        )
        state, _ = reduce_battle_event(started_state(), event)
        replayed, _ = reduce_battle_event(state, event)
        self.assertEqual(replayed["turn"], 1)

    def assert_outcome(self, outcome):
        event = domain_event(
            "battle.finished",
            payload={"outcome": outcome},
            sequence=2,
        )
        state, _ = reduce_battle_event(started_state(), event)
        self.assertEqual(state["battle_status"], "finished")
        self.assertEqual(state["outcome"], outcome)

    def test_result_win(self):
        self.assert_outcome("win")

    def test_result_lose(self):
        self.assert_outcome("lose")

    def test_result_draw(self):
        self.assert_outcome("draw")

    def test_finish_clears_pending(self):
        state = started_state()
        state["self_pending_selection"] = [4]
        state["opponent_pending_slots"] = [5]
        event = domain_event(
            "battle.finished",
            payload={"outcome": "win"},
            sequence=2,
        )
        state, _ = reduce_battle_event(state, event)
        self.assertEqual(state["self_pending_selection"], [])
        self.assertEqual(state["opponent_pending_slots"], [])

    def test_finish_preserves_revealed_cards(self):
        state = started_state()
        state["self_revealed_cards"] = [{"card_key": "slot:4"}]
        event = domain_event(
            "battle.finished",
            payload={"outcome": "win"},
            sequence=2,
        )
        state, _ = reduce_battle_event(state, event)
        self.assertEqual(
            state["self_revealed_cards"], [{"card_key": "slot:4"}]
        )

    def test_replayed_finish_is_idempotent(self):
        finish = domain_event(
            "battle.finished",
            payload={"outcome": "win"},
            sequence=2,
        )
        state, _ = reduce_battle_event(started_state(), finish)
        replayed, diagnostics = reduce_battle_event(state, finish)
        self.assertEqual(replayed["battle_status"], "finished")
        self.assertEqual(replayed["outcome"], "win")
        self.assertEqual(diagnostics[0]["code"], "duplicated_event")

    def test_late_event_after_finish_is_rejected(self):
        state, _ = reduce_battle_event(
            started_state(),
            domain_event(
                "battle.finished",
                payload={"outcome": "win"},
                sequence=2,
            ),
        )
        late = domain_event(
            "hand.card_drawn",
            payload={"card": card()},
            sequence=3,
        )
        state, diagnostics = reduce_battle_event(state, late)
        self.assertEqual(state["self_hand"], [])
        self.assertEqual(
            diagnostics[0]["code"], "late_event_after_finish"
        )

    def test_finished_state_accepts_new_battle_started(self):
        state, _ = reduce_battle_event(
            started_state(),
            domain_event(
                "battle.finished",
                payload={"outcome": "win"},
                sequence=2,
            ),
        )
        state, diagnostics = reduce_battle_event(
            state, domain_event("battle.started", sequence=3)
        )
        self.assertEqual(state["battle_status"], "active")
        self.assertIsNone(state["outcome"])
        self.assertEqual(diagnostics, [])

    def test_event_before_start_is_rejected(self):
        event = domain_event(
            "hand.card_drawn",
            payload={"card": card()},
            sequence=1,
        )
        state, diagnostics = reduce_battle_event(
            initial_battle_state(), event
        )
        self.assertEqual(state["self_hand"], [])
        self.assertEqual(
            diagnostics[0]["code"], "event_before_battle_start"
        )

    def test_duplicate_idempotency_uses_session_and_key(self):
        event = domain_event(
            "hand.card_drawn",
            payload={"card": card()},
            sequence=2,
        )
        state, _ = reduce_battle_event(started_state(), event)
        state, diagnostics = reduce_battle_event(state, event)
        self.assertEqual(diagnostics[0]["code"], "duplicated_event")
        self.assertEqual(
            state["applied_event_ids"].count(
                "producer-1:ws:2:0:hand.card_drawn"
            ),
            1,
        )

    def test_same_key_different_producer_session_is_distinct(self):
        first = domain_event(
            "hand.card_drawn",
            payload={"card": card(55, 4)},
            sequence=2,
            session="producer-1",
        )
        second = domain_event(
            "hand.card_drawn",
            payload={"card": card(56, 5)},
            sequence=2,
            session="producer-2",
        )
        state, diagnostics = reduce_battle_events(
            started_state(), [first, second]
        )
        self.assertEqual(len(state["self_hand"]), 2)
        self.assertNotIn(
            "duplicated_event", [value["code"] for value in diagnostics]
        )

    def test_last_source_metadata_tracks_last_processed_event(self):
        event = domain_event(
            "hand.card_drawn",
            payload={"card": card()},
            sequence=42,
            session="producer-2",
        )
        state, _ = reduce_battle_event(started_state(), event)
        self.assertEqual(state["last_source_sequence"], 42)
        self.assertEqual(
            state["last_producer_session_id"], "producer-2"
        )

    def test_wrapper_adds_session_without_mutating_event(self):
        event = domain_event("battle.started")
        del event["producer_session_id"]
        before = copy.deepcopy(event)
        wrapped = with_producer_session_id(event, "producer-x")
        self.assertEqual(event, before)
        self.assertEqual(wrapped["producer_session_id"], "producer-x")

    def test_wrapper_rejects_missing_session(self):
        with self.assertRaises(ValueError):
            with_producer_session_id({}, "")

    def test_malformed_domain_event_does_not_raise(self):
        for value in (None, [], {}, {"event_type": "battle.started"}):
            with self.subTest(value=value):
                state, diagnostics = reduce_battle_event(
                    initial_battle_state(), value
                )
                self.assertEqual(state["battle_status"], "idle")
                self.assertEqual(
                    diagnostics[-1]["code"], "invalid_domain_event"
                )

    def test_invalid_current_state_does_not_raise(self):
        state, diagnostics = reduce_battle_event(
            None, domain_event("battle.started")
        )
        self.assertEqual(state["battle_status"], "active")
        self.assertEqual(
            diagnostics[0]["code"], "invalid_current_state"
        )

    def test_restricted_visibility_is_rejected(self):
        event = domain_event(
            "hand.card_drawn",
            payload={"card": card()},
            sequence=2,
            visibility="restricted_opponent_hidden",
        )
        state, diagnostics = reduce_battle_event(started_state(), event)
        self.assertEqual(state["self_hand"], [])
        self.assertEqual(
            diagnostics[0]["code"], "restricted_visibility"
        )

    def test_fallback_authority_does_not_modify_confirmed_state(self):
        event = domain_event(
            "hand.card_drawn",
            payload={"card": card()},
            sequence=2,
            authority="fallback",
        )
        state, diagnostics = reduce_battle_event(started_state(), event)
        self.assertEqual(state["self_hand"], [])
        self.assertEqual(
            diagnostics[0]["code"], "fallback_authority_rejected"
        )

    def test_non_websocket_source_is_rejected(self):
        event = domain_event(
            "hand.card_drawn",
            payload={"card": card()},
            sequence=2,
        )
        event["source"] = "ocr"
        state, diagnostics = reduce_battle_event(started_state(), event)
        self.assertEqual(state["self_hand"], [])
        self.assertEqual(diagnostics[0]["code"], "unsupported_source")

    def test_provisional_confirmed_event_is_rejected(self):
        event = domain_event(
            "hand.card_drawn",
            payload={"card": card()},
            sequence=2,
            authority="provisional",
        )
        state, diagnostics = reduce_battle_event(started_state(), event)
        self.assertEqual(state["self_hand"], [])
        self.assertEqual(diagnostics[0]["code"], "authority_mismatch")

    def test_authoritative_pending_selection_is_rejected(self):
        event = domain_event(
            "play.selection_changed",
            payload={"slot": 4, "selected": True, "selection": "selected"},
            sequence=2,
            side="self",
            authority="authoritative",
        )
        state, diagnostics = reduce_battle_event(started_state(), event)
        self.assertEqual(state["self_pending_selection"], [])
        self.assertEqual(diagnostics[0]["code"], "authority_mismatch")

    def test_confirmed_selection_confirmation_is_rejected(self):
        event = domain_event(
            "play.selection_changed",
            payload={"slot": 4, "selected": True, "selection": "selected"},
            sequence=2,
            side="self",
            confirmation="confirmed",
        )
        state, diagnostics = reduce_battle_event(started_state(), event)
        self.assertEqual(state["self_pending_selection"], [])
        self.assertEqual(
            diagnostics[0]["code"], "confirmation_mismatch"
        )

    def test_diagnostic_schema_is_safe_and_minimal(self):
        event = domain_event(
            "play.cards_revealed",
            payload={"cards": [card()]},
            sequence=123,
            side="unknown",
        )
        _, diagnostics = reduce_battle_event(started_state(), event)
        self.assertEqual(
            diagnostics[0],
            {
                "code": "unknown_side_reveal",
                "event_type": "play.cards_revealed",
                "source_sequence": 123,
            },
        )

    def test_diagnostics_never_copy_secrets(self):
        event = domain_event(
            "hand.card_drawn",
            payload={
                "card": card(),
                "token": "secret",
                "room_id": "secret-room",
            },
            sequence=2,
            visibility="restricted_opponent_hidden",
        )
        _, diagnostics = reduce_battle_event(started_state(), event)
        serialized = json.dumps(diagnostics).lower()
        self.assertNotIn("secret", serialized)
        self.assertNotIn("token", serialized)
        self.assertNotIn("room_id", serialized)

    def test_invalid_card_payload_is_not_marked_applied(self):
        event = domain_event(
            "hand.card_drawn",
            payload={"card": {"card_id": 1}},
            sequence=2,
        )
        state, diagnostics = reduce_battle_event(started_state(), event)
        self.assertEqual(diagnostics[0]["code"], "invalid_payload")
        self.assertNotIn(
            "producer-1:ws:2:0:hand.card_drawn",
            state["applied_event_ids"],
        )

    def test_unknown_event_type_is_diagnostic_only(self):
        event = domain_event("battle.unknown", sequence=2)
        state, diagnostics = reduce_battle_event(started_state(), event)
        self.assertEqual(state["battle_status"], "active")
        self.assertEqual(
            diagnostics[0]["code"], "unsupported_event_type"
        )

    def test_reducer_does_not_mutate_state_or_event(self):
        state = started_state()
        event = domain_event(
            "hand.card_drawn",
            payload={"card": card()},
            sequence=2,
        )
        state_before = copy.deepcopy(state)
        event_before = copy.deepcopy(event)
        reduce_battle_event(state, event)
        self.assertEqual(state, state_before)
        self.assertEqual(event, event_before)

    def test_replay_is_deterministic(self):
        events = [
            domain_event(
                "hand.card_drawn",
                payload={"card": card()},
                sequence=2,
            ),
            domain_event(
                "battle.finished",
                payload={"outcome": "win"},
                sequence=3,
            ),
        ]
        first = reduce_battle_events(started_state(), events)
        second = reduce_battle_events(started_state(), copy.deepcopy(events))
        self.assertEqual(first, second)

    def test_module_has_no_io_persistence_or_transport_imports(self):
        source = (
            DETECTOR_DIR / "battle_state_reducer.py"
        ).read_text(encoding="utf-8")
        tree = ast.parse(source)
        imported = {
            node.names[0].name.split(".")[0]
            for node in ast.walk(tree)
            if isinstance(node, ast.Import)
        }
        imported.update(
            node.module.split(".")[0]
            for node in ast.walk(tree)
            if isinstance(node, ast.ImportFrom) and node.module
        )
        forbidden = {
            "asyncio",
            "indexeddb",
            "pathlib",
            "requests",
            "socket",
            "sqlite3",
            "urllib",
            "websockets",
        }
        self.assertTrue(imported.isdisjoint(forbidden))
        called_names = {
            node.func.id
            for node in ast.walk(tree)
            if isinstance(node, ast.Call)
            and isinstance(node.func, ast.Name)
        }
        self.assertTrue(
            called_names.isdisjoint(
                {"open", "connect", "fetch", "send", "urlopen"}
            )
        )

    @unittest.skipUnless(CORPUS_PATH.exists(), "local PvP corpus not present")
    def test_real_domain_event_corpus_replays_without_exception(self):
        observations = [
            json.loads(line)
            for line in CORPUS_PATH.read_text(
                encoding="utf-8"
            ).splitlines()
        ]
        events = []
        for observation in observations:
            for event in adapt_battle_observation(observation):
                events.append(
                    with_producer_session_id(event, "pvp-corpus-001")
                )
        state, diagnostics = reduce_battle_events(
            initial_battle_state(), events
        )
        self.assertIn(
            state["battle_status"], {"idle", "active", "finished"}
        )
        self.assertGreater(len(state["applied_event_ids"]), 0)
        self.assertFalse(
            any(
                value["code"] == "invalid_domain_event"
                for value in diagnostics
            )
        )


if __name__ == "__main__":
    unittest.main()
