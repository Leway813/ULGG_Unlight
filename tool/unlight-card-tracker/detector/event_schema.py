from __future__ import annotations

from dataclasses import asdict, dataclass
from datetime import datetime, timezone
from typing import Any
from uuid import uuid4


EVENT_SCHEMA_VERSION = 1
PRODUCER_VERSION = "0.1.0"
TEMPLATE_SET_VERSION = "1.0.0"
EVENT_SOURCE = "detector"
LOADING_OBSERVED = "loading_observed"


def utc_now_iso() -> str:
    return (
        datetime.now(timezone.utc)
        .isoformat(timespec="milliseconds")
        .replace("+00:00", "Z")
    )


@dataclass(frozen=True)
class ObservationEvent:
    event_id: str
    session_id: str
    sequence: int | None
    event_type: str
    payload: dict[str, Any]
    confidence: float
    occurred_at: str
    source: str
    event_schema_version: int
    producer_version: str
    template_set_version: str

    def to_dict(self) -> dict[str, Any]:
        return asdict(self)


def new_observation_event(
    *,
    session_id: str,
    event_type: str,
    payload: dict[str, Any],
    confidence: float,
    occurred_at: str | None = None,
) -> ObservationEvent:
    if not session_id:
        raise ValueError("session_id is required")
    if not event_type:
        raise ValueError("event_type is required")
    if not isinstance(payload, dict):
        raise TypeError("payload must be a dict")

    normalized_confidence = float(confidence)
    if not 0.0 <= normalized_confidence <= 1.0:
        raise ValueError("confidence must be between 0 and 1")

    return ObservationEvent(
        event_id=str(uuid4()),
        session_id=session_id,
        sequence=None,
        event_type=event_type,
        payload=payload,
        confidence=normalized_confidence,
        occurred_at=occurred_at or utc_now_iso(),
        source=EVENT_SOURCE,
        event_schema_version=EVENT_SCHEMA_VERSION,
        producer_version=PRODUCER_VERSION,
        template_set_version=TEMPLATE_SET_VERSION,
    )


def new_loading_observed_event(
    *,
    session_id: str,
    is_loading: bool,
    confidence: float,
    observation_mode: str,
    occurred_at: str | None = None,
) -> ObservationEvent:
    if observation_mode not in {"initial_baseline", "change"}:
        raise ValueError("unsupported observation_mode")

    return new_observation_event(
        session_id=session_id,
        event_type=LOADING_OBSERVED,
        payload={
            "is_loading": bool(is_loading),
            "observation_mode": observation_mode,
        },
        confidence=confidence,
        occurred_at=occurred_at,
    )


def loading_observation_mode(
    previous_value: bool | None,
    current_value: bool,
) -> str | None:
    if previous_value is None:
        return "initial_baseline"
    if previous_value != current_value:
        return "change"
    return None
