import ast
import inspect
import unittest

import battle_protocol
from battle_protocol import (
    Confirmation,
    FaceKind,
    SideResolutionContext,
    Visibility,
    classify_visibility,
    format_card_face_zh,
    parse_battle_card,
    parse_card_face,
    parse_discovery_record,
    parse_protocol_event,
    resolve_side,
)


def card(
    *,
    card_id=4,
    face1=("swd", 1),
    face2=("shi", 1),
    rotate=False,
    clicked=False,
    used=False,
    cardindex=None,
):
    result = {
        "swd1": 0,
        "gun1": 0,
        "shi1": 0,
        "mov1": 0,
        "spe1": 0,
        "swd2": 0,
        "gun2": 0,
        "shi2": 0,
        "mov2": 0,
        "spe2": 0,
        "index": card_id,
        "type": "ac",
        "clicked": clicked,
        "used": used,
        "rotate": rotate,
        "cardindex": cardindex,
    }
    if face1 is not None:
        result[f"{face1[0]}1"] = face1[1]
    if face2 is not None:
        result[f"{face2[0]}2"] = face2[1]
    return result


def parse(event, args, **kwargs):
    return parse_protocol_event(
        event,
        args,
        direction=kwargs.pop("direction", "received"),
        sequence=kwargs.pop("sequence", 42),
        timestamp=kwargs.pop("timestamp", "2026-07-27T12:00:00+08:00"),
        **kwargs,
    )


class CardParserTests(unittest.TestCase):
    def test_face1_sword(self):
        face = parse_card_face(card(), 1)
        self.assertEqual(face.kind, FaceKind.SWORD)
        self.assertEqual(face.value, 1)

    def test_face2_shield(self):
        face = parse_card_face(card(), 2)
        self.assertEqual(face.kind, FaceKind.SHIELD)
        self.assertEqual(face.value, 1)

    def test_blank_face(self):
        face = parse_card_face(card(face1=None), 1)
        self.assertEqual(face.kind, FaceKind.BLANK)
        self.assertEqual(face.value, 0)
        self.assertEqual(format_card_face_zh(face), "空0")

    def test_card_index_and_cardindex_are_separate(self):
        parsed = parse_battle_card({"card": card(card_id=52), "cardindex": 5})
        self.assertEqual(parsed.card_id, 52)
        self.assertEqual(parsed.slot, 5)

    def test_rotate_false_keeps_face_order(self):
        parsed = parse_battle_card(card(rotate=False))
        self.assertEqual(parsed.display_top, parsed.face1)
        self.assertEqual(parsed.display_bottom, parsed.face2)

    def test_rotate_true_swap_is_explicitly_provisional(self):
        parsed = parse_battle_card(card(rotate=True))
        self.assertEqual(parsed.display_top, parsed.face2)
        self.assertEqual(parsed.display_bottom, parsed.face1)
        self.assertEqual(parsed.display_order_confirmation, "provisional")

    def test_malformed_card_returns_none_without_raising(self):
        self.assertIsNone(parse_battle_card(None))
        self.assertIsNone(parse_battle_card({"index": "4"}))


class EventParserTests(unittest.TestCase):
    def test_game_start(self):
        observation = parse("gameStart", [])[0]
        self.assertEqual(observation["type"], "battle_started")
        self.assertEqual(observation["visibility"], Visibility.PUBLIC.value)

    def test_draw_phase_parses_multiple_cards(self):
        observation = parse(
            "drawPhase",
            [[card(card_id=1), card(card_id=2)], 2],
        )[0]
        self.assertEqual(observation["type"], "cards_dealt")
        self.assertEqual(
            [item["card_id"] for item in observation["payload"]["cards"]],
            [1, 2],
        )

    def test_event_card_parses_single_dict(self):
        observation = parse("eventCard", [card(card_id=67), 1])[0]
        self.assertEqual(observation["type"], "event_card_received")
        self.assertEqual(observation["payload"]["card"]["card_id"], 67)

    def test_card_draw_parses_one_card(self):
        observation = parse("cardDraw", [card(card_id=31)])[0]
        self.assertEqual(observation["type"], "card_drawn")
        self.assertEqual(observation["payload"]["card"]["card_id"], 31)

    def test_card_clicked_true_is_pending_selected(self):
        observation = parse("cardclickedA", [3, True, False])[0]
        self.assertEqual(observation["confirmation"], Confirmation.PENDING.value)
        self.assertEqual(observation["payload"]["slot"], 3)
        self.assertEqual(observation["payload"]["selection"], "selected")

    def test_card_clicked_false_is_pending_returned(self):
        observation = parse("cardclickedB", [4, False, False])[0]
        self.assertEqual(observation["confirmation"], Confirmation.PENDING.value)
        self.assertEqual(observation["payload"]["selection"], "returned")

    def test_card_clicked_does_not_invent_card_identity(self):
        observation = parse("cardclickedA", [3, True, False])[0]
        self.assertNotIn("card_id", observation["payload"])
        self.assertNotIn("card", observation["payload"])

    def test_card_open_a_parses_multiple_confirmed_cards(self):
        observation = parse(
            "cardOpen_A",
            [[
                {"card": card(card_id=52), "cardindex": 5},
                {"card": card(card_id=19), "cardindex": 2},
            ]],
        )[0]
        self.assertEqual(observation["side"], "A")
        self.assertEqual(observation["confirmation"], "confirmed")
        self.assertEqual(len(observation["payload"]["cards"]), 2)

    def test_card_open_b_parses_multiple_confirmed_cards(self):
        observation = parse(
            "cardOpen_B",
            [[
                {"card": card(card_id=2), "cardindex": 1},
                {"card": card(card_id=3), "cardindex": 4},
            ]],
        )[0]
        self.assertEqual(observation["side"], "B")
        self.assertEqual(observation["confirmation"], "confirmed")
        self.assertEqual(len(observation["payload"]["cards"]), 2)

    def test_empty_card_open_is_a_noop(self):
        self.assertEqual(parse("cardOpen_A", [[]]), [])
        self.assertEqual(parse("cardOpen", []), [])

    def test_malformed_card_inside_event_does_not_raise(self):
        self.assertEqual(parse("eventCard", [{"index": "bad"}, 1]), [])
        self.assertEqual(parse("cardOpen_B", [[None, {"bad": True}]]), [])

    def test_chara_state_is_whitelisted(self):
        observation = parse(
            "chara_A",
            [{
                "chara": "cc041",
                "charaIndex": 409,
                "chara_index": 0,
                "main": True,
                "known": True,
                "hp": 12,
                "hp_max": 12,
                "atk": 8,
                "def": 10,
                "level": 5,
                "state": [],
                "passive": [],
                "room_id": "must-not-copy",
                "session_token": "must-not-copy",
            }],
        )[0]
        self.assertEqual(observation["type"], "character_state")
        self.assertEqual(observation["payload"]["chara_id"], "cc041")
        self.assertNotIn("room_id", observation["payload"])
        self.assertNotIn("session_token", observation["payload"])

    def test_hidden_reserve_character_is_not_public(self):
        observation = parse(
            "chara_B",
            [{
                "chara": "cc043",
                "charaIndex": 428,
                "chara_index": 1,
                "main": False,
                "known": False,
                "hp": 9,
                "hp_max": 9,
            }],
        )[0]
        self.assertEqual(
            observation["visibility"],
            Visibility.RESTRICTED_OPPONENT_HIDDEN.value,
        )

    def test_result_win(self):
        observation = parse("result", ["win", 34, 70])[0]
        self.assertEqual(observation["payload"]["outcome"], "win")

    def test_result_lose(self):
        observation = parse("result", ["lose"])[0]
        self.assertEqual(observation["payload"]["outcome"], "lose")

    def test_result_draw(self):
        observation = parse("result", ["draw"])[0]
        self.assertEqual(observation["payload"]["outcome"], "draw")

    def test_phase_and_turn_end(self):
        phase = parse("endPhase", ["draw"])[0]
        turn = parse("endTurn", [6])[0]
        self.assertEqual(phase["payload"], {"phase": "draw"})
        self.assertEqual(turn["payload"], {"turn": 6})

    def test_unknown_event_returns_empty(self):
        self.assertEqual(parse("futureEvent", [{"anything": True}]), [])

    def test_add_array_is_not_promoted(self):
        self.assertEqual(parse("addArray", [3]), [])

    def test_delete_draw_b_does_not_change_hand(self):
        self.assertEqual(parse("deleteDraw_B", [1]), [])

    def test_duel_standby_does_not_expose_opponent_loadout(self):
        observations = parse(
            "duel_standby",
            [{
                "room_playerBdeck": {
                    "chara": ["cc001"],
                    "weapon": [99],
                    "eventIndex": [1, 2, 3],
                }
            }],
        )
        self.assertEqual(observations, [])

    def test_discovery_record_does_not_copy_token_or_room(self):
        observations = parse_discovery_record({
            "event": "gameStart",
            "args_summary": [],
            "direction": "received",
            "sequence": 1,
            "timestamp": "2026-07-27T12:00:00+08:00",
            "session_token": "secret",
            "room_id": "secret-room",
        })
        serialized = repr(observations)
        self.assertNotIn("secret-room", serialized)
        self.assertNotIn("session_token", serialized)
        self.assertNotIn("room_id", serialized)


class SideAndVisibilityTests(unittest.TestCase):
    def test_unresolved_pve_and_pvp_sides_remain_unknown(self):
        for mode in ("pve", "pvp"):
            context = SideResolutionContext(mode=mode)
            self.assertEqual(resolve_side("A", context), "unknown")
            self.assertEqual(resolve_side("B", context), "unknown")

    def test_explicit_local_side_resolves_self_and_opponent(self):
        context = SideResolutionContext(local_side="B", mode="pvp")
        self.assertEqual(resolve_side("B", context), "self")
        self.assertEqual(resolve_side("A", context), "opponent")

    def test_revealed_opponent_cards_are_marked_revealed(self):
        context = SideResolutionContext(local_side="A", mode="pvp")
        observation = parse(
            "cardOpen_B",
            [[{"card": card(), "cardindex": 4}]],
            context=context,
        )[0]
        self.assertEqual(
            observation["visibility"],
            Visibility.OPPONENT_REVEALED.value,
        )

    def test_unresolved_pending_selection_is_diagnostic_only(self):
        observation = parse("cardclickedA", [1, True, False])[0]
        self.assertEqual(
            classify_visibility(observation),
            Visibility.DIAGNOSTIC_ONLY.value,
        )


class PurityBoundaryTests(unittest.TestCase):
    def test_parser_has_no_io_or_game_transport_calls(self):
        tree = ast.parse(inspect.getsource(battle_protocol))
        forbidden_imports = {
            "asyncio",
            "pathlib",
            "requests",
            "socket",
            "subprocess",
            "threading",
            "websocket",
            "websockets",
        }
        forbidden_calls = {"open", "send", "send_json", "write", "write_text"}

        imports = {
            alias.name.split(".")[0]
            for node in ast.walk(tree)
            if isinstance(node, ast.Import)
            for alias in node.names
        }
        imports.update(
            node.module.split(".")[0]
            for node in ast.walk(tree)
            if isinstance(node, ast.ImportFrom) and node.module
        )
        calls = {
            node.func.id
            for node in ast.walk(tree)
            if isinstance(node, ast.Call) and isinstance(node.func, ast.Name)
        }
        calls.update(
            node.func.attr
            for node in ast.walk(tree)
            if isinstance(node, ast.Call) and isinstance(node.func, ast.Attribute)
        )

        self.assertFalse(imports & forbidden_imports)
        self.assertFalse(calls & forbidden_calls)


if __name__ == "__main__":
    unittest.main()
