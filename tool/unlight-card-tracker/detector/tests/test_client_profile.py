from __future__ import annotations

import hashlib
import tempfile
import unittest
from pathlib import Path

from client_profile import PROFILE_ID, verify_client_profile


class ClientProfileTest(unittest.TestCase):
    def test_accepts_matching_app_asar_hash(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "app.asar"
            path.write_bytes(b"custom-asar")
            expected = hashlib.sha256(b"custom-asar").hexdigest()

            status = verify_client_profile(path, expected)

        self.assertEqual(status.profile_id, PROFILE_ID)
        self.assertTrue(status.supported)
        self.assertIsNone(status.reason)

    def test_reports_hash_mismatch_without_raising(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "app.asar"
            path.write_bytes(b"unexpected")

            status = verify_client_profile(path, "0" * 64)

        self.assertFalse(status.supported)
        self.assertEqual(status.reason, "app_asar_hash_mismatch")


if __name__ == "__main__":
    unittest.main()
