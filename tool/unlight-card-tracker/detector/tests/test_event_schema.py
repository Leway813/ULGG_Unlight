from __future__ import annotations

import unittest

from event_schema import (
    EVENT_SCHEMA_VERSION,
    LOADING_OBSERVED,
    loading_observation_mode,
    new_loading_observed_event,
)


class LoadingObservationSchemaTest(unittest.TestCase):
    def test_builds_immutable_loading_baseline_envelope(self) -> None:
        event = new_loading_observed_event(
            session_id="session-1",
            is_loading=True,
            confidence=0.91,
            observation_mode="initial_baseline",
            occurred_at="2026-07-27T12:00:00.000Z",
        )

        self.assertEqual(event.event_type, LOADING_OBSERVED)
        self.assertEqual(event.sequence, None)
        self.assertEqual(event.event_schema_version, EVENT_SCHEMA_VERSION)
        self.assertEqual(
            event.payload,
            {
                "is_loading": True,
                "observation_mode": "initial_baseline",
            },
        )
        self.assertEqual(event.confidence, 0.91)

    def test_rejects_invalid_confidence(self) -> None:
        with self.assertRaises(ValueError):
            new_loading_observed_event(
                session_id="session-1",
                is_loading=False,
                confidence=1.1,
                observation_mode="change",
            )

    def test_loading_change_characterization(self) -> None:
        self.assertEqual(
            loading_observation_mode(None, False),
            "initial_baseline",
        )
        self.assertIsNone(
            loading_observation_mode(False, False)
        )
        self.assertEqual(
            loading_observation_mode(False, True),
            "change",
        )


if __name__ == "__main__":
    unittest.main()
