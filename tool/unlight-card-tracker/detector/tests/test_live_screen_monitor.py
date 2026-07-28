from __future__ import annotations

import unittest
from unittest.mock import patch

from live_screen_monitor import (
    create_site_detector,
)


class SiteDetectorConfigurationTest(unittest.TestCase):
    @patch("live_screen_monitor.SiteDetector")
    def test_site_image_debug_follows_debug_flag(
        self,
        site_detector,
    ) -> None:
        create_site_detector()
        self.assertFalse(
            site_detector.call_args.kwargs[
                "debug_images"
            ]
        )

        create_site_detector(debug_images=True)
        self.assertTrue(
            site_detector.call_args.kwargs[
                "debug_images"
            ]
        )


if __name__ == "__main__":
    unittest.main()
