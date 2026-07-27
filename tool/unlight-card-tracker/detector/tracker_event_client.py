"""Bounded HTTP boundary for durable Tracker domain event submission."""

from __future__ import annotations

from typing import Any
from urllib.parse import urlsplit

import requests

from domain_event_schema import (
    SNIFFER_PRODUCER_VERSION,
    DomainEventValidationError,
    validate_domain_event,
)


RETRYABLE_STATUS_CODES = frozenset({502, 503, 504})


class TrackerEventClient:
    def __init__(
        self,
        base_url: str,
        *,
        producer_session_id: str,
        producer_instance: str,
        timeout: float = 2.0,
        max_attempts: int = 2,
        transport: Any = None,
    ) -> None:
        parsed = urlsplit(str(base_url))
        if (
            parsed.scheme not in {"http", "https"}
            or not parsed.netloc
            or parsed.username is not None
            or parsed.password is not None
            or parsed.query
            or parsed.fragment
        ):
            raise ValueError("tracker API URL must be a plain HTTP origin")
        if not producer_session_id:
            raise ValueError("producer_session_id is required")
        if not producer_instance:
            raise ValueError("producer_instance is required")
        if timeout <= 0:
            raise ValueError("timeout must be positive")
        if not 1 <= max_attempts <= 3:
            raise ValueError("max_attempts must be between 1 and 3")
        self.base_url = str(base_url).rstrip("/")
        self.producer_session_id = producer_session_id
        self.producer_instance = producer_instance
        self.timeout = float(timeout)
        self.max_attempts = max_attempts
        self.transport = transport or requests.Session()

    def register_session(self) -> dict[str, Any]:
        body = {
            "session_id": self.producer_session_id,
            "producer_type": "websocket_sniffer",
            "producer_instance": self.producer_instance,
            "producer_version": SNIFFER_PRODUCER_VERSION,
        }
        return self._post("/api/v1/sessions", body, event_type=None)

    def submit_event(self, event: Any) -> dict[str, Any]:
        normalized = validate_domain_event(
            event,
            session_id=self.producer_session_id,
        )
        return self._post(
            "/api/v1/events",
            {
                "session_id": self.producer_session_id,
                "event": normalized,
            },
            event_type=normalized["event_type"],
            source_sequence=normalized["source_sequence"],
        )

    def _post(
        self,
        path: str,
        body: dict[str, Any],
        *,
        event_type: str | None,
        source_sequence: int | None = None,
    ) -> dict[str, Any]:
        attempts = 0
        while attempts < self.max_attempts:
            attempts += 1
            try:
                response = self.transport.post(
                    self.base_url + path,
                    json=body,
                    timeout=self.timeout,
                )
            except (requests.Timeout, requests.ConnectionError) as error:
                code = (
                    "TIMEOUT"
                    if isinstance(error, requests.Timeout)
                    else "CONNECTION_ERROR"
                )
                if attempts < self.max_attempts:
                    continue
                return self._failure(
                    code,
                    attempts,
                    event_type,
                    source_sequence,
                )
            except requests.RequestException:
                return self._failure(
                    "HTTP_CLIENT_ERROR",
                    attempts,
                    event_type,
                    source_sequence,
                )

            status_code = int(response.status_code)
            data = self._safe_json(response)
            if 200 <= status_code < 300:
                status = (
                    data.get("status")
                    if isinstance(data, dict)
                    else None
                )
                return {
                    "ok": True,
                    "duplicate": status in {"duplicate", "existing"},
                    "status": status or "accepted",
                    "code": None,
                    "http_status": status_code,
                    "attempts": attempts,
                    "event_type": event_type,
                    "source_sequence": source_sequence,
                }
            if (
                status_code in RETRYABLE_STATUS_CODES
                and attempts < self.max_attempts
            ):
                continue
            code = self._response_error_code(data) or f"HTTP_{status_code}"
            return self._failure(
                code,
                attempts,
                event_type,
                source_sequence,
                http_status=status_code,
            )
        return self._failure(
            "RETRY_EXHAUSTED",
            attempts,
            event_type,
            source_sequence,
        )

    @staticmethod
    def _safe_json(response: Any) -> Any:
        try:
            return response.json()
        except (TypeError, ValueError):
            return None

    @staticmethod
    def _response_error_code(data: Any) -> str | None:
        if not isinstance(data, dict):
            return None
        error = data.get("error")
        if not isinstance(error, dict):
            return None
        code = error.get("code")
        return code if isinstance(code, str) else None

    @staticmethod
    def _failure(
        code: str,
        attempts: int,
        event_type: str | None,
        source_sequence: int | None,
        *,
        http_status: int | None = None,
    ) -> dict[str, Any]:
        return {
            "ok": False,
            "duplicate": False,
            "status": "failed",
            "code": code,
            "http_status": http_status,
            "attempts": attempts,
            "event_type": event_type,
            "source_sequence": source_sequence,
        }


__all__ = [
    "DomainEventValidationError",
    "TrackerEventClient",
]
