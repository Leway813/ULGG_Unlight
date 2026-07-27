"""Validation and durable wrapping for safe Tracker domain events."""

from __future__ import annotations

from copy import deepcopy
from typing import Any, Mapping

from event_schema import ObservationEvent


DOMAIN_EVENT_REQUIRED_FIELDS = frozenset(
    {
        "domain_event_schema_version",
        "event_type",
        "payload",
        "source",
        "source_event",
        "source_sequence",
        "source_observation_index",
        "source_direction",
        "idempotency_key",
        "occurred_at",
        "protocol_side",
        "resolved_side",
        "visibility",
        "confirmation",
        "confidence",
        "authority",
        "producer_session_id",
    }
)
FORBIDDEN_DOMAIN_KEYS = frozenset(
    {
        "args",
        "args_summary",
        "auth",
        "authorization",
        "cdp_target_id",
        "player_name",
        "raw",
        "room",
        "room_id",
        "session_target",
        "session_token",
        "steam_id",
        "steamid",
        "token",
        "url",
        "websocket_url",
    }
)
ALLOWED_VISIBILITIES = frozenset(
    {"public", "self_private", "opponent_revealed"}
)
SNIFFER_PRODUCER_VERSION = "0.1.0"
DOMAIN_TEMPLATE_SET_VERSION = "not-applicable"


class DomainEventValidationError(ValueError):
    pass


def _contains_forbidden_key(value: Any) -> bool:
    if isinstance(value, Mapping):
        for key, child in value.items():
            normalized = str(key).lower().replace("-", "_")
            if normalized in FORBIDDEN_DOMAIN_KEYS:
                return True
            if _contains_forbidden_key(child):
                return True
    elif isinstance(value, (list, tuple)):
        return any(_contains_forbidden_key(child) for child in value)
    return False


def validate_domain_event(
    value: Any,
    *,
    session_id: str,
) -> dict[str, Any]:
    """Return a copied, validated domain event safe for persistence."""
    if not isinstance(value, Mapping):
        raise DomainEventValidationError("domain event must be an object")
    missing = DOMAIN_EVENT_REQUIRED_FIELDS - set(value)
    if missing:
        raise DomainEventValidationError(
            "missing domain event fields: " + ", ".join(sorted(missing))
        )
    if _contains_forbidden_key(value):
        raise DomainEventValidationError(
            "domain event contains a forbidden field"
        )
    if value.get("producer_session_id") != session_id:
        raise DomainEventValidationError("producer session mismatch")
    if value.get("source") != "websocket":
        raise DomainEventValidationError("unsupported domain event source")
    if value.get("visibility") not in ALLOWED_VISIBILITIES:
        raise DomainEventValidationError("unsupported event visibility")
    if not isinstance(value.get("payload"), Mapping):
        raise DomainEventValidationError("payload must be an object")
    if not isinstance(value.get("event_type"), str) or not value["event_type"]:
        raise DomainEventValidationError("event_type is required")
    if (
        not isinstance(value.get("idempotency_key"), str)
        or not value["idempotency_key"]
    ):
        raise DomainEventValidationError("idempotency_key is required")
    if not isinstance(value.get("occurred_at"), str) or not value["occurred_at"]:
        raise DomainEventValidationError("occurred_at is required")
    confidence = value.get("confidence")
    if (
        isinstance(confidence, bool)
        or not isinstance(confidence, (int, float))
        or not 0.0 <= float(confidence) <= 1.0
    ):
        raise DomainEventValidationError(
            "confidence must be between 0 and 1"
        )
    for field in ("source_sequence", "source_observation_index"):
        item = value.get(field)
        if isinstance(item, bool) or not isinstance(item, int) or item < 0:
            raise DomainEventValidationError(f"{field} must be non-negative")
    if value.get("domain_event_schema_version") != 1:
        raise DomainEventValidationError(
            "unsupported domain_event_schema_version"
        )
    return deepcopy(dict(value))


def domain_event_to_durable_event(
    value: Any,
    *,
    session_id: str,
) -> ObservationEvent:
    event = validate_domain_event(value, session_id=session_id)
    return ObservationEvent(
        event_id=event["idempotency_key"],
        session_id=session_id,
        sequence=None,
        event_type=event["event_type"],
        payload={"domain_event": event},
        confidence=float(event["confidence"]),
        occurred_at=event["occurred_at"],
        source="websocket",
        event_schema_version=event["domain_event_schema_version"],
        producer_version=SNIFFER_PRODUCER_VERSION,
        template_set_version=DOMAIN_TEMPLATE_SET_VERSION,
    )
