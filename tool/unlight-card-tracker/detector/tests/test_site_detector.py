from __future__ import annotations

import math
import tempfile
import unittest
from pathlib import Path

import cv2
import numpy as np

from site_detector import (
    SiteCandidateScore,
    SiteDetector,
)


REGION = {
    "x": 0,
    "y": 63,
    "width": 424,
    "height": 429,
}


def normalized_frame(
    roi: np.ndarray,
) -> np.ndarray:
    frame = np.zeros(
        (760, 848, 3),
        dtype=np.uint8,
    )
    frame[63:492, 0:424] = roi
    return frame


def template_source(
    frame: np.ndarray,
) -> np.ndarray:
    return cv2.resize(
        frame,
        (757, 677),
        interpolation=cv2.INTER_AREA,
    )


def write_template(
    directory: Path,
    site_key: str,
    frame: np.ndarray,
) -> None:
    success, encoded = cv2.imencode(
        ".png",
        template_source(frame),
    )
    if not success:
        raise RuntimeError("Unable to encode template")
    encoded.tofile(
        str(directory / f"{site_key}.png")
    )


def split_roi(
    orientation: str,
) -> np.ndarray:
    roi = np.zeros(
        (429, 424, 3),
        dtype=np.uint8,
    )
    if orientation == "vertical":
        roi[:, 212:] = 220
    elif orientation == "horizontal":
        roi[214:, :] = 220
    else:
        raise ValueError(orientation)
    return roi


def shape_roi(
    shape: str,
    intensity: int,
) -> np.ndarray:
    roi = np.zeros(
        (429, 424, 3),
        dtype=np.uint8,
    )
    if shape == "rectangle":
        cv2.rectangle(
            roi,
            (70, 80),
            (350, 330),
            (intensity,) * 3,
            thickness=18,
        )
    elif shape == "circle":
        cv2.circle(
            roi,
            (212, 214),
            120,
            (intensity,) * 3,
            thickness=18,
        )
    else:
        raise ValueError(shape)
    return roi


class SiteDetectorHybridTest(unittest.TestCase):
    def setUp(self) -> None:
        self.temporary_directory = (
            tempfile.TemporaryDirectory()
        )
        self.template_dir = Path(
            self.temporary_directory.name
        )

    def tearDown(self) -> None:
        self.temporary_directory.cleanup()

    def detector(self) -> SiteDetector:
        return SiteDetector(
            template_dir=self.template_dir,
            region=REGION,
            min_hybrid_raw_score=-1.0,
            debug_images=False,
        )

    def test_image_debug_is_opt_in(self) -> None:
        image = np.zeros(
            (4, 4, 3),
            dtype=np.uint8,
        )
        debug_output_dir = (
            self.template_dir / "debug_frames"
        )

        default_detector = SiteDetector(
            template_dir=self.template_dir,
            region=REGION,
        )
        default_detector.debug_output_dir = (
            debug_output_dir
        )
        default_detector._write_debug_image(
            "default.png",
            image,
        )

        self.assertFalse(default_detector.debug_images)
        self.assertFalse(debug_output_dir.exists())

        enabled_detector = SiteDetector(
            template_dir=self.template_dir,
            region=REGION,
            debug_images=True,
        )
        enabled_detector.debug_output_dir = (
            debug_output_dir
        )
        enabled_detector._write_debug_image(
            "enabled.png",
            image,
        )

        self.assertTrue(
            (debug_output_dir / "enabled.png").is_file()
        )

    @staticmethod
    def candidate(
        site_key: str,
        raw_score: float,
        normalized_confidence: float | None = None,
    ) -> SiteCandidateScore:
        if normalized_confidence is None:
            normalized_confidence = (
                raw_score + 1.0
            ) / 2.0
        return SiteCandidateScore(
            site_key=site_key,
            histogram_score=raw_score,
            grayscale_score=raw_score,
            edge_score=raw_score,
            hybrid_raw_score=raw_score,
            normalized_confidence=(
                normalized_confidence
            ),
        )

    def detector_with_candidates(
        self,
        *candidates: SiteCandidateScore,
    ) -> SiteDetector:
        detector = SiteDetector(
            template_dir=self.template_dir,
            region=REGION,
            min_hybrid_raw_score=0.10,
            debug_images=False,
        )
        by_key = {
            candidate.site_key: candidate
            for candidate in candidates
        }
        detector.templates = {
            site_key: object()
            for site_key in by_key
        }
        detector._score_candidate = (
            lambda site_key, _live, _template: (
                by_key[site_key]
            )
        )
        return detector

    def test_raw_acceptance_boundary(self) -> None:
        frame = normalized_frame(
            split_roi("vertical")
        )
        cases = (
            (0.09, None, 0.545),
            (0.10, "boundary-site", 0.55),
            (0.11, "boundary-site", 0.555),
        )

        for raw_score, expected_key, expected_confidence in cases:
            with self.subTest(raw_score=raw_score):
                detector = (
                    self.detector_with_candidates(
                        self.candidate(
                            "boundary-site",
                            raw_score,
                        )
                    )
                )
                result = detector.detect(frame)
                self.assertEqual(
                    result.site_key,
                    expected_key,
                )
                self.assertAlmostEqual(
                    result.confidence,
                    expected_confidence,
                    places=6,
                )

    def test_acceptance_and_ranking_use_raw_score(
        self,
    ) -> None:
        frame = normalized_frame(
            split_roi("vertical")
        )
        below = self.candidate(
            "display-high",
            0.09,
            normalized_confidence=0.99,
        )
        above = self.candidate(
            "raw-high",
            0.11,
            normalized_confidence=0.01,
        )

        below_result = (
            self.detector_with_candidates(
                below
            ).detect(frame)
        )
        ranked_result = (
            self.detector_with_candidates(
                below,
                above,
            ).detect(frame)
        )

        self.assertIsNone(below_result.site_key)
        self.assertEqual(
            ranked_result.best_candidate_key,
            "raw-high",
        )
        self.assertEqual(
            ranked_result.site_key,
            "raw-high",
        )
        self.assertEqual(
            ranked_result.confidence,
            0.01,
        )
        self.assertEqual(
            ranked_result.second_confidence,
            0.99,
        )

    def test_hybrid_uses_spatial_structure_when_histograms_match(
        self,
    ) -> None:
        vertical = normalized_frame(
            split_roi("vertical")
        )
        horizontal = normalized_frame(
            split_roi("horizontal")
        )
        write_template(
            self.template_dir,
            "vertical-site",
            vertical,
        )
        write_template(
            self.template_dir,
            "horizontal-site",
            horizontal,
        )

        result = self.detector().detect(vertical)

        self.assertEqual(
            result.site_key,
            "vertical-site",
        )
        self.assertAlmostEqual(
            result.best_histogram_score,
            result.second_histogram_score,
            places=3,
        )
        self.assertGreater(
            result.best_grayscale_score,
            result.second_grayscale_score,
        )
        self.assertGreater(
            result.best_edge_score,
            result.second_edge_score,
        )

    def test_brightness_change_preserves_shape_match(
        self,
    ) -> None:
        rectangle_template = normalized_frame(
            shape_roi("rectangle", 100)
        )
        circle_template = normalized_frame(
            shape_roi("circle", 100)
        )
        live_rectangle = normalized_frame(
            shape_roi("rectangle", 220)
        )
        write_template(
            self.template_dir,
            "rectangle-site",
            rectangle_template,
        )
        write_template(
            self.template_dir,
            "circle-site",
            circle_template,
        )

        result = self.detector().detect(
            live_rectangle
        )

        self.assertEqual(
            result.site_key,
            "rectangle-site",
        )
        self.assertGreater(
            result.best_grayscale_score,
            0.95,
        )
        self.assertGreater(
            result.best_edge_score,
            0.80,
        )

    def test_empty_edges_are_finite(self) -> None:
        black = normalized_frame(
            np.zeros(
                (429, 424, 3),
                dtype=np.uint8,
            )
        )
        gray = normalized_frame(
            np.full(
                (429, 424, 3),
                30,
                dtype=np.uint8,
            )
        )
        write_template(
            self.template_dir,
            "black-site",
            black,
        )
        write_template(
            self.template_dir,
            "gray-site",
            gray,
        )

        result = self.detector().detect(black)

        for value in (
            result.best_grayscale_score,
            result.best_edge_score,
            result.best_hybrid_raw_score,
            result.second_grayscale_score,
            result.second_edge_score,
            result.second_hybrid_raw_score,
            result.confidence,
            result.second_confidence,
        ):
            self.assertTrue(math.isfinite(value))
        self.assertEqual(
            result.best_edge_score,
            0.0,
        )
        self.assertEqual(
            SiteDetector._safe_score(math.nan),
            0.0,
        )
        self.assertEqual(
            SiteDetector._safe_score(math.inf),
            0.0,
        )

    def test_identical_image_ranks_first_and_exposes_gap(
        self,
    ) -> None:
        vertical = normalized_frame(
            split_roi("vertical")
        )
        horizontal = normalized_frame(
            split_roi("horizontal")
        )
        write_template(
            self.template_dir,
            "same-site",
            vertical,
        )
        write_template(
            self.template_dir,
            "other-site",
            horizontal,
        )

        result = self.detector().detect(vertical)

        self.assertEqual(
            result.best_candidate_key,
            "same-site",
        )
        self.assertEqual(
            result.second_candidate_key,
            "other-site",
        )
        self.assertGreaterEqual(
            result.best_hybrid_raw_score,
            result.second_hybrid_raw_score,
        )
        self.assertAlmostEqual(
            result.confidence,
            (
                result.best_hybrid_raw_score
                + 1.0
            ) / 2.0,
            places=6,
        )
        self.assertAlmostEqual(
            (
                result.confidence
                - result.second_confidence
            ),
            (
                result.best_hybrid_raw_score
                - result.second_hybrid_raw_score
            ) / 2.0,
            places=6,
        )
        self.assertGreaterEqual(
            result.confidence,
            0.0,
        )
        self.assertLessEqual(
            result.confidence,
            1.0,
        )

    def test_template_features_and_missing_directory_behavior(
        self,
    ) -> None:
        frame = normalized_frame(
            split_roi("vertical")
        )
        write_template(
            self.template_dir,
            "feature-site",
            frame,
        )
        detector = self.detector()
        features = detector.templates[
            "feature-site"
        ]

        self.assertEqual(
            features.cropped_roi.shape[:2],
            (429, 424),
        )
        self.assertEqual(
            features.match_image.shape[:2],
            (180, 320),
        )
        self.assertEqual(
            features.grayscale.shape,
            (180, 320),
        )
        self.assertEqual(
            features.edges.shape,
            (180, 320),
        )
        self.assertEqual(
            features.histogram.shape,
            (30, 32),
        )

        missing = SiteDetector(
            template_dir=(
                self.template_dir / "missing"
            ),
            region=REGION,
            min_hybrid_raw_score=-1.0,
            debug_images=False,
        )
        self.assertEqual(missing.templates, {})
        result = missing.detect(frame)
        self.assertIsNone(result.site_key)
        self.assertEqual(result.confidence, 0.0)


if __name__ == "__main__":
    unittest.main()
