"""Pure protocol parsing for observed UNLIGHT battle WebSocket events.

This module deliberately has no CDP, WebSocket, filesystem, tracker, or UI
dependencies. It converts already-decoded discovery records into conservative
protocol observations. It never infers that protocol side A or B is the local
player unless an explicit :class:`SideResolutionContext` is supplied.
"""

from dataclasses import dataclass
from enum import Enum
from typing import Any, Mapping, Sequence


class FaceKind(str, Enum):
    SWORD = "sword"
    GUN = "gun"
    SHIELD = "shield"
    MOVE = "move"
    SPECIAL = "special"
    BLANK = "blank"


class Visibility(str, Enum):
    PUBLIC = "public"
    SELF_PRIVATE = "self_private"
    OPPONENT_REVEALED = "opponent_revealed"
    RESTRICTED_OPPONENT_HIDDEN = "restricted_opponent_hidden"
    DIAGNOSTIC_ONLY = "diagnostic_only"


class Confirmation(str, Enum):
    PENDING = "pending"
    CONFIRMED = "confirmed"


DISPLAY_ORDER_CONFIRMATION = "provisional"

_FACE_FIELDS = (
    ("swd", FaceKind.SWORD),
    ("gun", FaceKind.GUN),
    ("shi", FaceKind.SHIELD),
    ("mov", FaceKind.MOVE),
    ("spe", FaceKind.SPECIAL),
)

_FACE_LABELS_ZH = {
    FaceKind.SWORD: "劍",
    FaceKind.GUN: "槍",
    FaceKind.SHIELD: "盾",
    FaceKind.MOVE: "移",
    FaceKind.SPECIAL: "特",
    FaceKind.BLANK: "空",
}

_SUPPORTED_MODES = {"pve", "pvp", "unknown"}
_PROTOCOL_SIDES = {"A", "B", "unknown"}
_OUTCOMES = {"win", "lose", "draw"}


@dataclass(frozen=True)
class CardFace:
    kind: FaceKind
    value: int

    def to_dict(self) -> dict[str, Any]:
        return {"kind": self.kind.value, "value": self.value}


@dataclass(frozen=True)
class BattleCard:
    card_id: int
    slot: int | None
    type: str | None
    rotate: bool
    used: bool
    clicked: bool
    face1: CardFace
    face2: CardFace
    display_top: CardFace
    display_bottom: CardFace
    display_order_confirmation: str = DISPLAY_ORDER_CONFIRMATION

    def to_dict(self) -> dict[str, Any]:
        return {
            "card_id": self.card_id,
            "slot": self.slot,
            "type": self.type,
            "rotate": self.rotate,
            "used": self.used,
            "clicked": self.clicked,
            "face1": self.face1.to_dict(),
            "face2": self.face2.to_dict(),
            "display_top": self.display_top.to_dict(),
            "display_bottom": self.display_bottom.to_dict(),
            "display_order_confirmation": self.display_order_confirmation,
        }


@dataclass(frozen=True)
class SideResolutionContext:
    local_side: str | None = None
    mode: str = "unknown"

    def __post_init__(self) -> None:
        if self.local_side not in {None, "A", "B"}:
            raise ValueError("local_side must be A, B, or None")
        if self.mode not in _SUPPORTED_MODES:
            raise ValueError("mode must be pve, pvp, or unknown")


def _is_integer(value: Any) -> bool:
    return isinstance(value, int) and not isinstance(value, bool)


def parse_card_face(card: Mapping[str, Any], face_number: int) -> CardFace:
    """Parse one numbered card face, returning blank for absent/invalid data."""
    if face_number not in {1, 2} or not isinstance(card, Mapping):
        return CardFace(FaceKind.BLANK, 0)

    for field, kind in _FACE_FIELDS:
        value = card.get(f"{field}{face_number}")
        if _is_integer(value) and value > 0:
            return CardFace(kind, value)
    return CardFace(FaceKind.BLANK, 0)


def parse_battle_card(value: Any) -> BattleCard | None:
    """Parse either a bare card dict or a ``{card, cardindex}`` wrapper."""
    if not isinstance(value, Mapping):
        return None

    wrapped_card = value.get("card")
    card = wrapped_card if isinstance(wrapped_card, Mapping) else value
    card_id = card.get("index")
    if not _is_integer(card_id):
        return None

    outer_slot = value.get("cardindex") if card is not value else None
    inner_slot = card.get("cardindex")
    slot_value = outer_slot if _is_integer(outer_slot) else inner_slot
    slot = slot_value if _is_integer(slot_value) else None

    face1 = parse_card_face(card, 1)
    face2 = parse_card_face(card, 2)
    rotate = card.get("rotate") is True

    # No captured rotate=True frame has been paired with an on-screen image.
    # Keep the contributor's proposed swap explicit but provisional.
    display_top, display_bottom = (
        (face2, face1) if rotate else (face1, face2)
    )
    card_type = card.get("type")

    return BattleCard(
        card_id=card_id,
        slot=slot,
        type=card_type if isinstance(card_type, str) else None,
        rotate=rotate,
        used=card.get("used") is True,
        clicked=card.get("clicked") is True,
        face1=face1,
        face2=face2,
        display_top=display_top,
        display_bottom=display_bottom,
    )


def format_card_face_zh(face: CardFace) -> str:
    """Format a face for diagnostics without changing the domain model."""
    return f"{_FACE_LABELS_ZH[face.kind]}{face.value}"


def resolve_side(
    protocol_side: str,
    context: SideResolutionContext | None = None,
) -> str:
    """Resolve protocol A/B to self/opponent only with explicit context."""
    if protocol_side not in {"A", "B"}:
        return "unknown"
    if context is None or context.local_side is None:
        return "unknown"
    return "self" if protocol_side == context.local_side else "opponent"


def _event_side(event_name: str) -> str:
    if event_name.endswith("_A") or event_name.endswith("A"):
        return "A"
    if event_name.endswith("_B") or event_name.endswith("B"):
        return "B"
    return "unknown"


def classify_visibility(
    observation: Mapping[str, Any],
    context: SideResolutionContext | None = None,
) -> str:
    """Classify whether an observation may be consumed outside diagnostics."""
    observation_type = observation.get("type")
    side = observation.get("side", "unknown")
    resolved_side = resolve_side(side, context)
    payload = observation.get("payload")
    payload = payload if isinstance(payload, Mapping) else {}

    if observation_type in {
        "battle_started",
        "phase_ended",
        "turn_ended",
        "battle_finished",
    }:
        return Visibility.PUBLIC.value

    if observation_type in {
        "cards_dealt",
        "event_card_received",
        "card_drawn",
    }:
        return Visibility.SELF_PRIVATE.value

    if observation_type == "cards_revealed":
        if resolved_side == "opponent":
            return Visibility.OPPONENT_REVEALED.value
        return Visibility.PUBLIC.value

    if observation_type == "character_state":
        if payload.get("main") is True:
            return Visibility.PUBLIC.value
        return Visibility.RESTRICTED_OPPONENT_HIDDEN.value

    if observation_type == "card_selection_changed":
        if resolved_side == "self":
            return Visibility.SELF_PRIVATE.value
        if resolved_side == "opponent":
            return Visibility.RESTRICTED_OPPONENT_HIDDEN.value
        return Visibility.DIAGNOSTIC_ONLY.value

    return Visibility.DIAGNOSTIC_ONLY.value


def _observation(
    observation_type: str,
    source_event: str,
    *,
    direction: str,
    sequence: int | None,
    timestamp: str | None,
    side: str = "unknown",
    confirmation: str = Confirmation.CONFIRMED.value,
    payload: Mapping[str, Any] | None = None,
    context: SideResolutionContext | None = None,
) -> dict[str, Any]:
    protocol_side = side if side in _PROTOCOL_SIDES else "unknown"
    base = {
        "type": observation_type,
        "source_event": source_event,
        "direction": direction if direction in {"received", "sent"} else "unknown",
        "sequence": sequence if _is_integer(sequence) else None,
        "timestamp": timestamp if isinstance(timestamp, str) else None,
        "side": protocol_side,
        "visibility": Visibility.DIAGNOSTIC_ONLY.value,
        "confirmation": confirmation,
        "payload": dict(payload or {}),
    }
    return {
        **base,
        "visibility": classify_visibility(base, context),
    }


def _card_list(value: Any) -> list[BattleCard]:
    if not isinstance(value, Sequence) or isinstance(value, (str, bytes)):
        return []
    cards = []
    for item in value:
        parsed = parse_battle_card(item)
        if parsed is not None:
            cards.append(parsed)
    return cards


def _parse_game_start(metadata: Mapping[str, Any], _args: list[Any]):
    return [_observation("battle_started", "gameStart", **metadata)]


def _parse_draw_phase(metadata: Mapping[str, Any], args: list[Any]):
    cards = _card_list(args[0]) if args else []
    if not cards:
        return []
    return [
        _observation(
            "cards_dealt",
            "drawPhase",
            payload={"cards": [card.to_dict() for card in cards]},
            **metadata,
        )
    ]


def _parse_event_card(metadata: Mapping[str, Any], args: list[Any]):
    card = parse_battle_card(args[0]) if args else None
    if card is None:
        return []
    return [
        _observation(
            "event_card_received",
            "eventCard",
            payload={"card": card.to_dict()},
            **metadata,
        )
    ]


def _parse_card_draw(metadata: Mapping[str, Any], args: list[Any]):
    if not args:
        return []
    candidates = args[0] if isinstance(args[0], list) else [args[0]]
    cards = _card_list(candidates)
    return [
        _observation(
            "card_drawn",
            "cardDraw",
            payload={"card": card.to_dict()},
            **metadata,
        )
        for card in cards
    ]


def _parse_card_clicked(
    event_name: str,
    metadata: Mapping[str, Any],
    args: list[Any],
):
    if not args or not _is_integer(args[0]):
        return []
    selected = args[1] if len(args) > 1 and isinstance(args[1], bool) else True
    return [
        _observation(
            "card_selection_changed",
            event_name,
            side=_event_side(event_name),
            confirmation=Confirmation.PENDING.value,
            payload={
                "slot": args[0],
                "selected": selected,
                "selection": "selected" if selected else "returned",
            },
            **metadata,
        )
    ]


def _parse_card_open(
    event_name: str,
    metadata: Mapping[str, Any],
    args: list[Any],
):
    entries = args[0] if args and isinstance(args[0], list) else []
    cards = _card_list(entries)
    if not cards:
        return []
    return [
        _observation(
            "cards_revealed",
            event_name,
            side=_event_side(event_name),
            payload={"cards": [card.to_dict() for card in cards]},
            **metadata,
        )
    ]


def _safe_character_payload(character: Mapping[str, Any]) -> dict[str, Any]:
    payload = {
        "slot": character.get("chara_index")
        if _is_integer(character.get("chara_index"))
        else None,
        "chara_id": character.get("chara")
        if isinstance(character.get("chara"), str)
        else None,
        "chara_card_index": character.get("charaIndex")
        if _is_integer(character.get("charaIndex"))
        else None,
        "main": character.get("main") is True,
        "known": character.get("known") is True,
        "hp": character.get("hp") if _is_integer(character.get("hp")) else None,
        "hp_max": character.get("hp_max")
        if _is_integer(character.get("hp_max"))
        else None,
        "atk": character.get("atk") if _is_integer(character.get("atk")) else None,
        "def": character.get("def") if _is_integer(character.get("def")) else None,
        "level": character.get("level")
        if _is_integer(character.get("level"))
        else None,
        "state": list(character.get("state"))
        if isinstance(character.get("state"), list)
        else [],
        "passive": list(character.get("passive"))
        if isinstance(character.get("passive"), list)
        else [],
    }
    return payload


def _parse_character_state(
    event_name: str,
    metadata: Mapping[str, Any],
    args: list[Any],
):
    observations = []
    for character in args:
        if not isinstance(character, Mapping) or not character.get("chara"):
            continue
        observations.append(
            _observation(
                "character_state",
                event_name,
                side=_event_side(event_name),
                payload=_safe_character_payload(character),
                **metadata,
            )
        )
    return observations


def _parse_end_phase(metadata: Mapping[str, Any], args: list[Any]):
    phase = args[0] if args and isinstance(args[0], str) else None
    return [
        _observation(
            "phase_ended",
            "endPhase",
            payload={"phase": phase},
            **metadata,
        )
    ]


def _parse_end_turn(metadata: Mapping[str, Any], args: list[Any]):
    turn = args[0] if args and _is_integer(args[0]) else None
    return [
        _observation(
            "turn_ended",
            "endTurn",
            payload={"turn": turn},
            **metadata,
        )
    ]


def _parse_result(metadata: Mapping[str, Any], args: list[Any]):
    outcome = args[0].lower() if args and isinstance(args[0], str) else None
    if outcome not in _OUTCOMES:
        return []
    return [
        _observation(
            "battle_finished",
            "result",
            payload={"outcome": outcome},
            **metadata,
        )
    ]


def parse_protocol_event(
    event_name: str,
    args: Any,
    *,
    direction: str = "received",
    sequence: int | None = None,
    timestamp: str | None = None,
    context: SideResolutionContext | None = None,
) -> list[dict[str, Any]]:
    """Parse one decoded protocol event into zero or more observations."""
    if not isinstance(event_name, str):
        return []
    event_args = list(args) if isinstance(args, list) else []
    metadata = {
        "direction": direction,
        "sequence": sequence,
        "timestamp": timestamp,
        "context": context,
    }

    if event_name == "gameStart":
        return _parse_game_start(metadata, event_args)
    if event_name == "drawPhase":
        return _parse_draw_phase(metadata, event_args)
    if event_name == "eventCard":
        return _parse_event_card(metadata, event_args)
    if event_name == "cardDraw":
        return _parse_card_draw(metadata, event_args)
    if event_name in {"cardclickedA", "cardclickedB"}:
        return _parse_card_clicked(event_name, metadata, event_args)
    if event_name in {"cardOpen", "cardOpen_A", "cardOpen_B"}:
        return _parse_card_open(event_name, metadata, event_args)
    if event_name in {"chara_A", "chara_B"}:
        return _parse_character_state(event_name, metadata, event_args)
    if event_name == "endPhase":
        return _parse_end_phase(metadata, event_args)
    if event_name == "endTurn":
        return _parse_end_turn(metadata, event_args)
    if event_name == "result":
        return _parse_result(metadata, event_args)

    # addArray, deleteDraw*, duel_standby, outbound card/I_am_ok, and all
    # unknown events deliberately remain diagnostics-only outside this parser.
    return []


def parse_discovery_record(
    record: Any,
    context: SideResolutionContext | None = None,
) -> list[dict[str, Any]]:
    """Parse a redacted discovery JSON object without copying raw metadata."""
    if not isinstance(record, Mapping):
        return []
    return parse_protocol_event(
        record.get("event"),
        record.get("args_summary"),
        direction=record.get("direction"),
        sequence=record.get("sequence"),
        timestamp=record.get("timestamp"),
        context=context,
    )
