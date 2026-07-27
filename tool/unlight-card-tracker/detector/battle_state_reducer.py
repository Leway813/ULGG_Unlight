"""Pure, replayable state transitions for Tracker battle domain events.

This module performs no persistence or transport work. It does not decide how
events reach IndexedDB, how UI projections are rendered, or how OCR fallback
observations are interpreted. The caller must provide a stable
``producer_session_id`` before reduction.

``battle.phase_ended`` currently has no phase ownership metadata for pending
selections. The reducer therefore preserves pending selections and records a
diagnostic instead of guessing which provisional values should be cleared.
"""

from __future__ import annotations

from copy import deepcopy
from typing import Any, Mapping, Sequence


BATTLE_STATE_SCHEMA_VERSION = 1
DOMAIN_EVENT_SCHEMA_VERSION = 1

_BATTLE_STATUSES = frozenset({"idle", "active", "finished"})
_SUPPORTED_EVENT_TYPES = frozenset(
    {
        "battle.started",
        "hand.cards_dealt",
        "hand.event_card_received",
        "hand.card_drawn",
        "play.selection_changed",
        "play.cards_revealed",
        "battle.phase_ended",
        "battle.turn_ended",
        "battle.finished",
    }
)
_ALLOWED_VISIBILITIES = frozenset(
    {"public", "self_private", "opponent_revealed"}
)
_FACE_KINDS = frozenset(
    {"sword", "gun", "shield", "move", "special", "blank"}
)
_OUTCOMES = frozenset({"win", "lose", "draw"})


def initial_battle_state() -> dict[str, Any]:
    """Return a new canonical BattleState v1."""
    return {
        "schema_version": BATTLE_STATE_SCHEMA_VERSION,
        "battle_status": "idle",
        "battle_started_at": None,
        "battle_finished_at": None,
        "outcome": None,
        "turn": 0,
        "phase": None,
        "self_hand": [],
        "self_event_cards": [],
        "self_pending_selection": [],
        "self_revealed_cards": [],
        "opponent_pending_slots": [],
        "opponent_revealed_cards": [],
        "seen_card_ids": [],
        "last_source_sequence": None,
        "last_producer_session_id": None,
        "applied_event_ids": [],
        "diagnostics": [],
    }


def with_producer_session_id(
    domain_event: Mapping[str, Any],
    producer_session_id: str,
) -> dict[str, Any]:
    """Return a copied event carrying its caller-owned producer generation."""
    if not isinstance(domain_event, Mapping):
        raise TypeError("domain_event must be a mapping")
    if not isinstance(producer_session_id, str) or not producer_session_id:
        raise ValueError("producer_session_id is required")
    result = deepcopy(dict(domain_event))
    result["producer_session_id"] = producer_session_id
    return result


def _is_int(value: Any) -> bool:
    return isinstance(value, int) and not isinstance(value, bool)


def _valid_state(value: Any) -> bool:
    if not isinstance(value, Mapping):
        return False
    if value.get("schema_version") != BATTLE_STATE_SCHEMA_VERSION:
        return False
    if value.get("battle_status") not in _BATTLE_STATUSES:
        return False
    if not _is_int(value.get("turn")) or value["turn"] < 0:
        return False
    list_fields = (
        "self_hand",
        "self_event_cards",
        "self_pending_selection",
        "self_revealed_cards",
        "opponent_pending_slots",
        "opponent_revealed_cards",
        "seen_card_ids",
        "applied_event_ids",
        "diagnostics",
    )
    return all(isinstance(value.get(field), list) for field in list_fields)


def _safe_metadata(event: Any) -> tuple[str | None, int | None]:
    if not isinstance(event, Mapping):
        return None, None
    event_type = event.get("event_type")
    sequence = event.get("source_sequence")
    return (
        event_type if isinstance(event_type, str) else None,
        sequence if _is_int(sequence) and sequence >= 0 else None,
    )


def _diagnostic(event: Any, code: str) -> dict[str, Any]:
    event_type, source_sequence = _safe_metadata(event)
    return {
        "code": code,
        "event_type": event_type,
        "source_sequence": source_sequence,
    }


def _append_diagnostic(
    state: dict[str, Any],
    emitted: list[dict[str, Any]],
    event: Any,
    code: str,
) -> None:
    value = _diagnostic(event, code)
    state["diagnostics"].append(value)
    emitted.append(value)


def _full_event_id(event: Mapping[str, Any]) -> str | None:
    producer_session_id = event.get("producer_session_id")
    idempotency_key = event.get("idempotency_key")
    if (
        not isinstance(producer_session_id, str)
        or not producer_session_id
        or not isinstance(idempotency_key, str)
        or not idempotency_key
    ):
        return None
    return f"{producer_session_id}:{idempotency_key}"


def _valid_envelope(event: Any) -> bool:
    if not isinstance(event, Mapping):
        return False
    if event.get("domain_event_schema_version") != DOMAIN_EVENT_SCHEMA_VERSION:
        return False
    if _full_event_id(event) is None:
        return False
    if not isinstance(event.get("event_type"), str):
        return False
    if not isinstance(event.get("payload"), Mapping):
        return False
    if not isinstance(event.get("source"), str):
        return False
    if not _is_int(event.get("source_sequence")):
        return False
    if event["source_sequence"] < 0:
        return False
    if not isinstance(event.get("occurred_at"), str) or not event["occurred_at"]:
        return False
    if not isinstance(event.get("confirmation"), str):
        return False
    if not isinstance(event.get("authority"), str):
        return False
    if not isinstance(event.get("visibility"), str):
        return False
    return True


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


def _project_card(
    value: Any,
    event: Mapping[str, Any],
    index: int,
) -> dict[str, Any] | None:
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

    faces: dict[str, dict[str, Any]] = {}
    for field in ("face1", "face2", "display_top", "display_bottom"):
        face = _project_face(value.get(field))
        if face is None:
            return None
        faces[field] = face

    display_confirmation = value.get("display_order_confirmation")
    if not isinstance(display_confirmation, str) or not display_confirmation:
        return None

    card_key = (
        f"slot:{slot}"
        if slot is not None
        else f"card:{card_id}:{event['source_sequence']}:{index}"
    )
    return {
        "card_key": card_key,
        "card_id": card_id,
        "slot": slot,
        "type": card_type,
        "rotate": value["rotate"],
        "used": value["used"],
        "clicked": value["clicked"],
        **faces,
        "display_order_confirmation": display_confirmation,
    }


def _cards_from_payload(
    event: Mapping[str, Any],
    field: str,
) -> list[dict[str, Any]] | None:
    payload = event["payload"]
    values = payload.get(field)
    if field == "card":
        values = [values]
    if not isinstance(values, list) or not values:
        return None
    cards = [_project_card(value, event, index) for index, value in enumerate(values)]
    if any(card is None for card in cards):
        return None
    return cards


def _remember_card_ids(state: dict[str, Any], cards: Sequence[Mapping[str, Any]]) -> None:
    for card in cards:
        card_id = card["card_id"]
        if card_id not in state["seen_card_ids"]:
            state["seen_card_ids"].append(card_id)


def _append_unique_cards(
    state: dict[str, Any],
    emitted: list[dict[str, Any]],
    event: Mapping[str, Any],
    zone: str,
    cards: Sequence[dict[str, Any]],
) -> None:
    existing_keys = {card["card_key"] for card in state[zone]}
    for card in cards:
        if card["card_key"] in existing_keys:
            _append_diagnostic(
                state,
                emitted,
                event,
                "card_identity_already_present",
            )
            continue
        state[zone].append(deepcopy(card))
        existing_keys.add(card["card_key"])
    _remember_card_ids(state, cards)


def _remove_confirmed_from_hand(
    state: dict[str, Any],
    emitted: list[dict[str, Any]],
    event: Mapping[str, Any],
    revealed_card: Mapping[str, Any],
) -> None:
    exact = [
        index
        for index, card in enumerate(state["self_hand"])
        if card.get("card_key") == revealed_card["card_key"]
    ]
    if len(exact) == 1:
        state["self_hand"].pop(exact[0])
        return

    by_card_id = [
        index
        for index, card in enumerate(state["self_hand"])
        if card.get("card_id") == revealed_card["card_id"]
    ]
    if len(by_card_id) == 1:
        state["self_hand"].pop(by_card_id[0])
        _append_diagnostic(
            state,
            emitted,
            event,
            "card_identity_fallback_resolved",
        )
    elif len(by_card_id) > 1:
        _append_diagnostic(
            state,
            emitted,
            event,
            "ambiguous_card_identity",
        )


def _mark_processed(state: dict[str, Any], event: Mapping[str, Any], event_id: str) -> None:
    state["applied_event_ids"].append(event_id)
    state["last_source_sequence"] = event["source_sequence"]
    state["last_producer_session_id"] = event["producer_session_id"]


def _start_battle(
    previous: Mapping[str, Any],
    event: Mapping[str, Any],
) -> dict[str, Any]:
    state = initial_battle_state()
    state["applied_event_ids"] = deepcopy(previous["applied_event_ids"])
    state["diagnostics"] = deepcopy(previous["diagnostics"])
    state["battle_status"] = "active"
    state["battle_started_at"] = event["occurred_at"]
    return state


def reduce_battle_event(
    current_state: Any,
    domain_event: Any,
) -> tuple[dict[str, Any], list[dict[str, Any]]]:
    """Apply one domain event without mutating either input value."""
    diagnostics: list[dict[str, Any]] = []
    if _valid_state(current_state):
        state = deepcopy(dict(current_state))
    else:
        state = initial_battle_state()
        _append_diagnostic(
            state,
            diagnostics,
            domain_event,
            "invalid_current_state",
        )

    if not _valid_envelope(domain_event):
        _append_diagnostic(
            state,
            diagnostics,
            domain_event,
            "invalid_domain_event",
        )
        return state, diagnostics

    event = deepcopy(dict(domain_event))
    event_id = _full_event_id(event)
    assert event_id is not None

    if event_id in state["applied_event_ids"]:
        _append_diagnostic(
            state,
            diagnostics,
            event,
            "duplicated_event",
        )
        return state, diagnostics

    event_type = event["event_type"]
    visibility = event["visibility"]
    authority = event["authority"]

    if event["source"] != "websocket":
        _mark_processed(state, event, event_id)
        _append_diagnostic(
            state,
            diagnostics,
            event,
            "unsupported_source",
        )
        return state, diagnostics

    if visibility not in _ALLOWED_VISIBILITIES:
        _mark_processed(state, event, event_id)
        _append_diagnostic(
            state,
            diagnostics,
            event,
            "restricted_visibility",
        )
        return state, diagnostics

    if authority == "fallback":
        _mark_processed(state, event, event_id)
        _append_diagnostic(
            state,
            diagnostics,
            event,
            "fallback_authority_rejected",
        )
        return state, diagnostics
    if authority not in {"authoritative", "provisional"}:
        _mark_processed(state, event, event_id)
        _append_diagnostic(
            state,
            diagnostics,
            event,
            "unsupported_authority",
        )
        return state, diagnostics

    expected_authority = (
        "provisional"
        if event_type == "play.selection_changed"
        else "authoritative"
    )
    if authority != expected_authority:
        _mark_processed(state, event, event_id)
        _append_diagnostic(
            state,
            diagnostics,
            event,
            "authority_mismatch",
        )
        return state, diagnostics

    expected_confirmation = (
        "pending"
        if event_type == "play.selection_changed"
        else "confirmed"
    )
    if event["confirmation"] != expected_confirmation:
        _mark_processed(state, event, event_id)
        _append_diagnostic(
            state,
            diagnostics,
            event,
            "confirmation_mismatch",
        )
        return state, diagnostics

    if event_type not in _SUPPORTED_EVENT_TYPES:
        _mark_processed(state, event, event_id)
        _append_diagnostic(
            state,
            diagnostics,
            event,
            "unsupported_event_type",
        )
        return state, diagnostics

    if event_type == "battle.started":
        restarted = state["battle_status"] == "active"
        state = _start_battle(state, event)
        _mark_processed(state, event, event_id)
        if restarted:
            _append_diagnostic(
                state,
                diagnostics,
                event,
                "battle_restarted",
            )
        return state, diagnostics

    if state["battle_status"] == "idle":
        _mark_processed(state, event, event_id)
        _append_diagnostic(
            state,
            diagnostics,
            event,
            "event_before_battle_start",
        )
        return state, diagnostics

    if state["battle_status"] == "finished":
        _mark_processed(state, event, event_id)
        _append_diagnostic(
            state,
            diagnostics,
            event,
            "late_event_after_finish",
        )
        return state, diagnostics

    if event_type == "hand.cards_dealt":
        cards = _cards_from_payload(event, "cards")
        if cards is None:
            _append_diagnostic(state, diagnostics, event, "invalid_payload")
            return state, diagnostics
        _append_unique_cards(
            state, diagnostics, event, "self_hand", cards
        )

    elif event_type == "hand.event_card_received":
        cards = _cards_from_payload(event, "card")
        if cards is None:
            _append_diagnostic(state, diagnostics, event, "invalid_payload")
            return state, diagnostics
        _append_unique_cards(
            state, diagnostics, event, "self_event_cards", cards
        )

    elif event_type == "hand.card_drawn":
        cards = _cards_from_payload(event, "card")
        if cards is None:
            _append_diagnostic(state, diagnostics, event, "invalid_payload")
            return state, diagnostics
        _append_unique_cards(
            state, diagnostics, event, "self_hand", cards
        )

    elif event_type == "play.selection_changed":
        payload = event["payload"]
        slot = payload.get("slot")
        selected = payload.get("selected")
        selection = payload.get("selection")
        if (
            not _is_int(slot)
            or slot < 0
            or not isinstance(selected, bool)
            or selection != ("selected" if selected else "returned")
            or event.get("confirmation") != "pending"
            or event.get("resolved_side") != "self"
        ):
            _append_diagnostic(state, diagnostics, event, "invalid_payload")
            return state, diagnostics
        if selected and slot not in state["self_pending_selection"]:
            state["self_pending_selection"].append(slot)
        if not selected:
            state["self_pending_selection"] = [
                value
                for value in state["self_pending_selection"]
                if value != slot
            ]

    elif event_type == "play.cards_revealed":
        if event.get("confirmation") != "confirmed":
            _append_diagnostic(state, diagnostics, event, "invalid_payload")
            return state, diagnostics
        cards = _cards_from_payload(event, "cards")
        if cards is None:
            _append_diagnostic(state, diagnostics, event, "invalid_payload")
            return state, diagnostics
        side = event.get("resolved_side")
        if side not in {"self", "opponent"}:
            _mark_processed(state, event, event_id)
            _append_diagnostic(
                state,
                diagnostics,
                event,
                "unknown_side_reveal",
            )
            return state, diagnostics

        slots = {card["slot"] for card in cards if card["slot"] is not None}
        if side == "self":
            remaining = [
                slot
                for slot in state["self_pending_selection"]
                if slot not in slots
            ]
            if remaining:
                _append_diagnostic(
                    state,
                    diagnostics,
                    event,
                    "conflict_resolved",
                )
            state["self_pending_selection"] = []
            _append_unique_cards(
                state,
                diagnostics,
                event,
                "self_revealed_cards",
                cards,
            )
            for revealed_card in cards:
                _remove_confirmed_from_hand(
                    state, diagnostics, event, revealed_card
                )
        else:
            remaining = [
                slot
                for slot in state["opponent_pending_slots"]
                if slot not in slots
            ]
            if remaining:
                _append_diagnostic(
                    state,
                    diagnostics,
                    event,
                    "conflict_resolved",
                )
            state["opponent_pending_slots"] = []
            _append_unique_cards(
                state,
                diagnostics,
                event,
                "opponent_revealed_cards",
                cards,
            )

    elif event_type == "battle.phase_ended":
        phase = event["payload"].get("phase")
        if phase is not None and not isinstance(phase, str):
            _append_diagnostic(state, diagnostics, event, "invalid_payload")
            return state, diagnostics
        state["phase"] = phase
        if (
            state["self_pending_selection"]
            or state["opponent_pending_slots"]
        ):
            _append_diagnostic(
                state,
                diagnostics,
                event,
                "pending_selection_phase_unknown",
            )

    elif event_type == "battle.turn_ended":
        turn = event["payload"].get("turn")
        if turn is None:
            state["turn"] += 1
        elif _is_int(turn) and turn >= 0:
            state["turn"] = turn
        else:
            _append_diagnostic(state, diagnostics, event, "invalid_payload")
            return state, diagnostics

    elif event_type == "battle.finished":
        outcome = event["payload"].get("outcome")
        if outcome not in _OUTCOMES:
            _append_diagnostic(state, diagnostics, event, "invalid_payload")
            return state, diagnostics
        state["battle_status"] = "finished"
        state["battle_finished_at"] = event["occurred_at"]
        state["outcome"] = outcome
        state["self_pending_selection"] = []
        state["opponent_pending_slots"] = []

    _mark_processed(state, event, event_id)
    return state, diagnostics


def reduce_battle_events(
    current_state: Any,
    domain_events: Sequence[Any],
) -> tuple[dict[str, Any], list[dict[str, Any]]]:
    """Replay domain events in order through :func:`reduce_battle_event`."""
    state = deepcopy(current_state)
    diagnostics: list[dict[str, Any]] = []
    for event in domain_events:
        state, emitted = reduce_battle_event(state, event)
        diagnostics.extend(emitted)
    return state, diagnostics
