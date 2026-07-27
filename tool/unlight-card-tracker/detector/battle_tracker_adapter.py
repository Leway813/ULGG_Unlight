"""Pure adapter from safe battle observations to Tracker domain events.

This module deliberately owns no battle lifecycle state. It translates one
already-sanitized observation at a time. A later stateful Tracker processor
must decide whether duplicate starts, late frames after ``battle.finished``,
or other lifecycle transitions are valid for the current battle.

The emitted idempotency key is stable within one WebSocket producer stream.
Because the sniffer sequence restarts with the producer, durable consumers
must scope the key by the producer ``session_id`` when persistence is wired.
"""

from __future__ import annotations

from typing import Any, Callable, Mapping, Sequence


DOMAIN_EVENT_SCHEMA_VERSION = 1
BATTLE_OBSERVATION_SCHEMA_VERSION = 1

ALLOWED_VISIBILITIES = frozenset(
    {"public", "self_private", "opponent_revealed"}
)
_CONFIRMATIONS = frozenset({"pending", "confirmed"})
_PROTOCOL_SIDES = frozenset({"A", "B", "unknown"})
_RESOLVED_SIDES = frozenset({"self", "opponent", "unknown"})
_FACE_KINDS = frozenset(
    {"sword", "gun", "shield", "move", "special", "blank"}
)
_OUTCOMES = frozenset({"win", "lose", "draw"})

_FORBIDDEN_KEYS = frozenset(
    {
        "args",
        "args_summary",
        "auth",
        "raw",
        "room",
        "room_id",
        "session",
        "session_id",
        "session_target",
        "session_token",
        "steamid",
        "token",
        "url",
        "websocket_url",
    }
)

_EVENT_TYPES = {
    "battle_started": "battle.started",
    "cards_dealt": "hand.cards_dealt",
    "event_card_received": "hand.event_card_received",
    "card_drawn": "hand.card_drawn",
    "card_selection_changed": "play.selection_changed",
    "cards_revealed": "play.cards_revealed",
    "phase_ended": "battle.phase_ended",
    "turn_ended": "battle.turn_ended",
    "battle_finished": "battle.finished",
}

_SOURCE_EVENTS = {
    "battle_started": frozenset({"gameStart"}),
    "cards_dealt": frozenset({"drawPhase"}),
    "event_card_received": frozenset({"eventCard"}),
    "card_drawn": frozenset({"cardDraw"}),
    "card_selection_changed": frozenset({"cardclickedA", "cardclickedB"}),
    "cards_revealed": frozenset({"cardOpen", "cardOpen_A", "cardOpen_B"}),
    "phase_ended": frozenset({"endPhase"}),
    "turn_ended": frozenset({"endTurn"}),
    "battle_finished": frozenset({"result"}),
}


def _is_int(value: Any) -> bool:
    return isinstance(value, int) and not isinstance(value, bool)


def _contains_forbidden_key(value: Any) -> bool:
    if isinstance(value, Mapping):
        for key, nested in value.items():
            if isinstance(key, str) and key.lower() in _FORBIDDEN_KEYS:
                return True
            if _contains_forbidden_key(nested):
                return True
        return False
    if isinstance(value, Sequence) and not isinstance(
        value, (str, bytes, bytearray)
    ):
        return any(_contains_forbidden_key(item) for item in value)
    return False


def _project_face(value: Any) -> dict[str, Any] | None:
    if not isinstance(value, Mapping):
        return None
    kind = value.get("kind")
    amount = value.get("value")
    if kind not in _FACE_KINDS or not _is_int(amount) or amount < 0:
        return None
    if (kind == "blank") != (amount == 0):
        return None
    return {"kind": kind, "value": amount}


def _project_card(value: Any) -> dict[str, Any] | None:
    if not isinstance(value, Mapping):
        return None

    card_id = value.get("card_id")
    slot = value.get("slot")
    card_type = value.get("type")
    if not _is_int(card_id) or card_id < 0:
        return None
    if slot is not None and (not _is_int(slot) or slot < 0):
        return None
    if card_type is not None and not isinstance(card_type, str):
        return None
    if not all(
        isinstance(value.get(field), bool)
        for field in ("rotate", "used", "clicked")
    ):
        return None

    faces = {}
    for field in ("face1", "face2", "display_top", "display_bottom"):
        projected = _project_face(value.get(field))
        if projected is None:
            return None
        faces[field] = projected

    order_confirmation = value.get("display_order_confirmation")
    if not isinstance(order_confirmation, str) or not order_confirmation:
        return None

    return {
        "card_id": card_id,
        "slot": slot,
        "type": card_type,
        "rotate": value["rotate"],
        "used": value["used"],
        "clicked": value["clicked"],
        **faces,
        "display_order_confirmation": order_confirmation,
    }


def _project_cards_payload(payload: Mapping[str, Any]) -> dict[str, Any] | None:
    cards = payload.get("cards")
    if not isinstance(cards, list) or not cards:
        return None
    projected = [_project_card(card) for card in cards]
    if any(card is None for card in projected):
        return None
    return {"cards": projected}


def _project_card_payload(payload: Mapping[str, Any]) -> dict[str, Any] | None:
    card = _project_card(payload.get("card"))
    return {"card": card} if card is not None else None


def _project_selection_payload(
    payload: Mapping[str, Any],
) -> dict[str, Any] | None:
    slot = payload.get("slot")
    selected = payload.get("selected")
    selection = payload.get("selection")
    if not _is_int(slot) or slot < 0 or not isinstance(selected, bool):
        return None
    expected = "selected" if selected else "returned"
    if selection != expected:
        return None
    return {
        "slot": slot,
        "selected": selected,
        "selection": selection,
    }


def _project_phase_payload(payload: Mapping[str, Any]) -> dict[str, Any] | None:
    phase = payload.get("phase")
    if phase is not None and not isinstance(phase, str):
        return None
    return {"phase": phase}


def _project_turn_payload(payload: Mapping[str, Any]) -> dict[str, Any] | None:
    turn = payload.get("turn")
    if turn is not None and (not _is_int(turn) or turn < 0):
        return None
    return {"turn": turn}


def _project_result_payload(payload: Mapping[str, Any]) -> dict[str, Any] | None:
    outcome = payload.get("outcome")
    return {"outcome": outcome} if outcome in _OUTCOMES else None


_PAYLOAD_PROJECTORS: dict[
    str, Callable[[Mapping[str, Any]], dict[str, Any] | None]
] = {
    "battle_started": lambda _payload: {},
    "cards_dealt": _project_cards_payload,
    "event_card_received": _project_card_payload,
    "card_drawn": _project_card_payload,
    "card_selection_changed": _project_selection_payload,
    "cards_revealed": _project_cards_payload,
    "phase_ended": _project_phase_payload,
    "turn_ended": _project_turn_payload,
    "battle_finished": _project_result_payload,
}


def _valid_semantics(
    observation_type: str,
    *,
    confirmation: str,
    visibility: str,
    resolved_side: str,
) -> bool:
    if observation_type == "card_selection_changed":
        return (
            confirmation == "pending"
            and visibility == "self_private"
            and resolved_side == "self"
        )

    if confirmation != "confirmed":
        return False

    if observation_type in {
        "cards_dealt",
        "event_card_received",
        "card_drawn",
    }:
        return (
            visibility == "self_private"
            and resolved_side in {"self", "unknown"}
        )

    if observation_type == "cards_revealed":
        return (
            resolved_side == "self"
            and visibility == "public"
        ) or (
            resolved_side == "opponent"
            and visibility == "opponent_revealed"
        )

    return visibility == "public"


def adapt_battle_observation(
    observation: Any,
) -> list[dict[str, Any]]:
    """Translate one safe observation into zero or one Tracker domain event.

    Invalid, unsupported, restricted, or side-ambiguous observations are
    conservatively ignored. The function never mutates its input and never
    performs I/O.
    """
    if not isinstance(observation, Mapping):
        return []
    if _contains_forbidden_key(observation):
        return []
    if observation.get("schema_version") != BATTLE_OBSERVATION_SCHEMA_VERSION:
        return []

    observation_type = observation.get("type")
    if observation_type not in _EVENT_TYPES:
        return []

    timestamp = observation.get("timestamp")
    sequence = observation.get("sequence")
    observation_index = observation.get("observation_index")
    source_event = observation.get("source_event")
    direction = observation.get("direction")
    protocol_side = observation.get("protocol_side")
    resolved_side = observation.get("resolved_side")
    visibility = observation.get("visibility")
    confirmation = observation.get("confirmation")
    payload = observation.get("payload")

    if not isinstance(timestamp, str) or not timestamp:
        return []
    if not _is_int(sequence) or sequence < 0:
        return []
    if not _is_int(observation_index) or observation_index < 0:
        return []
    if source_event not in _SOURCE_EVENTS[observation_type]:
        return []
    if direction not in {"received", "sent"}:
        return []
    if protocol_side not in _PROTOCOL_SIDES:
        return []
    if resolved_side not in _RESOLVED_SIDES:
        return []
    if visibility not in ALLOWED_VISIBILITIES:
        return []
    if confirmation not in _CONFIRMATIONS:
        return []
    if not isinstance(payload, Mapping):
        return []
    if not _valid_semantics(
        observation_type,
        confirmation=confirmation,
        visibility=visibility,
        resolved_side=resolved_side,
    ):
        return []

    projected_payload = _PAYLOAD_PROJECTORS[observation_type](payload)
    if projected_payload is None:
        return []

    event_type = _EVENT_TYPES[observation_type]
    return [
        {
            "domain_event_schema_version": DOMAIN_EVENT_SCHEMA_VERSION,
            "event_type": event_type,
            "payload": projected_payload,
            "source": "websocket",
            "source_event": source_event,
            "source_sequence": sequence,
            "source_observation_index": observation_index,
            "source_direction": direction,
            "idempotency_key": (
                f"ws:{sequence}:{observation_index}:{event_type}"
            ),
            "occurred_at": timestamp,
            "protocol_side": protocol_side,
            "resolved_side": resolved_side,
            "visibility": visibility,
            "confirmation": confirmation,
            "confidence": 1.0,
            "authority": (
                "authoritative"
                if confirmation == "confirmed"
                else "provisional"
            ),
        }
    ]
