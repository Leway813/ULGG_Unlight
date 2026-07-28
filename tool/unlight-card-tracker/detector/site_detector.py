from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path
from typing import Dict, Optional

import cv2
import numpy as np


SITE_IMAGE_DEBUG = False
SITE_IMAGE_DEBUG_KEYS = {
    "end-village",
    "temptation-forest",
}
HISTOGRAM_WEIGHT = 0.20
GRAYSCALE_WEIGHT = 0.50
EDGE_WEIGHT = 0.30
CANNY_LOW_THRESHOLD = 50
CANNY_HIGH_THRESHOLD = 150


@dataclass
class SiteTemplateFeatures:
    cropped_roi: np.ndarray
    match_image: np.ndarray
    histogram: np.ndarray
    grayscale: np.ndarray
    edges: np.ndarray


@dataclass(frozen=True)
class SiteCandidateScore:
    site_key: str
    histogram_score: float
    grayscale_score: float
    edge_score: float
    hybrid_raw_score: float
    normalized_confidence: float


@dataclass
class SiteResult:
    site_key: Optional[str]
    confidence: float
    second_confidence: float = 0.0
    best_candidate_key: Optional[str] = None
    second_candidate_key: Optional[str] = None
    best_histogram_score: float = 0.0
    best_grayscale_score: float = 0.0
    best_edge_score: float = 0.0
    best_hybrid_raw_score: float = 0.0
    second_histogram_score: float = 0.0
    second_grayscale_score: float = 0.0
    second_edge_score: float = 0.0
    second_hybrid_raw_score: float = 0.0


class SiteDetector:
    REFERENCE_WIDTH = 848
    REFERENCE_HEIGHT = 760
    def __init__(
        self,
        template_dir: Path,
        region: Dict[str, int],
        min_hybrid_raw_score: float = 0.10,
        debug_images: bool = SITE_IMAGE_DEBUG,
    ) -> None:
        self.region = region
        # 0.10 is the raw equivalent of the currently
        # deployed normalized confidence threshold 0.55.
        # It is not corpus-calibrated and must be tuned
        # again using real site captures.
        self.min_hybrid_raw_score = (
            min_hybrid_raw_score
        )
        self.debug_images = debug_images
        self.debug_output_dir = (
            Path(__file__).resolve().parent
            / "debug_frames"
        )

        self.templates = self._load_templates(
            template_dir
        )

    def _write_debug_image(
        self,
        filename: str,
        image: np.ndarray,
    ) -> None:
        if not self.debug_images:
            return

        try:
            self.debug_output_dir.mkdir(
                parents=True,
                exist_ok=True,
            )
            success, encoded = cv2.imencode(
                ".png",
                image,
            )
            if not success:
                raise OSError(
                    "cv2.imencode returned false"
                )
            encoded.tofile(
                str(
                    self.debug_output_dir
                    / filename
                )
            )
        except (OSError, cv2.error) as error:
            print(
                "[SITE IMAGE DEBUG] "
                f"無法寫入 {filename}: {error}"
            )

    def _read_image_unicode(
        self,
        path: Path
    ) -> Optional[np.ndarray]:

        try:
            data = np.fromfile(
                str(path),
                dtype=np.uint8
            )

            return cv2.imdecode(
                data,
                cv2.IMREAD_COLOR
            )

        except Exception:
            return None

    @staticmethod
    def _safe_score(
        value: float,
        default: float = 0.0,
    ) -> float:
        score = float(value)
        if not np.isfinite(score):
            return default
        return score

    @staticmethod
    def _normalized_confidence(
        raw_score: float,
    ) -> float:
        normalized = (
            SiteDetector._safe_score(raw_score)
            + 1.0
        ) / 2.0
        return float(
            np.clip(normalized, 0.0, 1.0)
        )

    @staticmethod
    def _histogram(
        match_image: np.ndarray,
    ) -> np.ndarray:
        hsv = cv2.cvtColor(
            match_image,
            cv2.COLOR_BGR2HSV,
        )
        histogram = cv2.calcHist(
            [hsv],
            [0, 1],
            None,
            [30, 32],
            [0, 180, 0, 256],
        )
        cv2.normalize(
            histogram,
            histogram,
            0,
            1,
            cv2.NORM_MINMAX,
        )
        return histogram

    @staticmethod
    def _grayscale(
        match_image: np.ndarray,
    ) -> np.ndarray:
        return cv2.cvtColor(
            match_image,
            cv2.COLOR_BGR2GRAY,
        )

    @staticmethod
    def _edges(
        grayscale: np.ndarray,
    ) -> np.ndarray:
        return cv2.Canny(
            grayscale,
            CANNY_LOW_THRESHOLD,
            CANNY_HIGH_THRESHOLD,
        )

    @classmethod
    def _spatial_correlation(
        cls,
        live_image: np.ndarray,
        template_image: np.ndarray,
    ) -> float:
        if (
            live_image.size == 0
            or template_image.size == 0
        ):
            return 0.0
        if (
            live_image.shape != template_image.shape
        ):
            raise ValueError(
                "Spatial score images must have "
                "identical dimensions"
            )

        if (
            float(np.std(live_image)) == 0.0
            or float(np.std(template_image)) == 0.0
        ):
            return 0.0

        result = cv2.matchTemplate(
            live_image,
            template_image,
            cv2.TM_CCOEFF_NORMED,
        )
        return cls._safe_score(
            result[0, 0]
        )

    @classmethod
    def _build_features(
        cls,
        cropped_roi: np.ndarray,
    ) -> SiteTemplateFeatures:
        match_image = cv2.resize(
            cropped_roi,
            (320, 180),
            interpolation=cv2.INTER_AREA,
        )
        grayscale = cls._grayscale(
            match_image
        )
        return SiteTemplateFeatures(
            cropped_roi=cropped_roi.copy(),
            match_image=match_image,
            histogram=cls._histogram(
                match_image
            ),
            grayscale=grayscale,
            edges=cls._edges(grayscale),
        )

    @classmethod
    def _score_candidate(
        cls,
        site_key: str,
        live_features: SiteTemplateFeatures,
        template_features: SiteTemplateFeatures,
    ) -> SiteCandidateScore:
        histogram_score = cls._safe_score(
            cv2.compareHist(
                live_features.histogram,
                template_features.histogram,
                cv2.HISTCMP_CORREL,
            )
        )
        grayscale_score = cls._spatial_correlation(
            live_features.grayscale,
            template_features.grayscale,
        )
        edge_score = cls._spatial_correlation(
            live_features.edges,
            template_features.edges,
        )
        hybrid_raw_score = (
            histogram_score * HISTOGRAM_WEIGHT
            + grayscale_score * GRAYSCALE_WEIGHT
            + edge_score * EDGE_WEIGHT
        )
        hybrid_raw_score = cls._safe_score(
            hybrid_raw_score
        )

        return SiteCandidateScore(
            site_key=site_key,
            histogram_score=histogram_score,
            grayscale_score=grayscale_score,
            edge_score=edge_score,
            hybrid_raw_score=hybrid_raw_score,
            normalized_confidence=(
                cls._normalized_confidence(
                    hybrid_raw_score
                )
            ),
        )


    def _load_templates(
        self,
        folder: Path
    ) -> Dict[str, SiteTemplateFeatures]:

        templates: Dict[
            str,
            SiteTemplateFeatures,
        ] = {}

        supported_extensions = {
            ".jpg",
            ".jpeg",
            ".png",
            ".webp",
        }

        if not folder.exists():
            print(
                f"場景模板資料夾不存在：{folder}"
            )
            return templates

        paths = [
            path
            for path in folder.iterdir()
            if (
                path.is_file()
                and path.suffix.lower()
                in supported_extensions
            )
        ]

        for path in sorted(paths):

            image = self._read_image_unicode(
                path
            )

            if image is None:
                print(
                    f"略過無法讀取的場景圖：{path}"
                )
                continue

            # 所有場地模板先統一成遊戲基準尺寸。
            image = cv2.resize(
                image,
                (
                    self.REFERENCE_WIDTH,
                    self.REFERENCE_HEIGHT,
                ),
                interpolation=cv2.INTER_AREA,
            )

            region_x = int(
                self.region["x"]
            )

            region_y = int(
                self.region["y"]
            )

            region_width = int(
                self.region["width"]
            )

            region_height = int(
                self.region["height"]
            )

            # 模板與即時畫面必須裁相同 ROI。
            image = image[
                region_y:region_y + region_height,
                region_x:region_x + region_width,
            ].copy()

            if image.size == 0:
                print(
                    f"略過場景 ROI 為空的模板：{path}"
                )
                continue

            if path.stem in SITE_IMAGE_DEBUG_KEYS:
                self._write_debug_image(
                    (
                        "site-template-"
                        f"{path.stem}-cropped-"
                        f"{image.shape[1]}x"
                        f"{image.shape[0]}.png"
                    ),
                    image,
                )

            features = self._build_features(
                image
            )

            if path.stem in SITE_IMAGE_DEBUG_KEYS:
                self._write_debug_image(
                    (
                        "site-template-"
                        f"{path.stem}-match-input-"
                        f"{features.match_image.shape[1]}x"
                        f"{features.match_image.shape[0]}.png"
                    ),
                    features.match_image,
                )

            templates[path.stem] = features

        print(
            f"場景模板位置：{folder}"
        )

        print(
            f"已載入 {len(templates)} 張場景模板"
        )

        return templates

    def detect(
        self,
        frame: np.ndarray
    ) -> SiteResult:

        x = int(self.region["x"])
        y = int(self.region["y"])
        width = int(self.region["width"])
        height = int(self.region["height"])

        roi = frame[
            y:y + height,
            x:x + width
        ]

        if roi.size == 0:
            return SiteResult(
                None,
                0.0,
                0.0
            )

        self._write_debug_image(
            (
                "site-live-roi-cropped-"
                f"{roi.shape[1]}x"
                f"{roi.shape[0]}.png"
            ),
            roi,
        )

        live_features = self._build_features(
            roi
        )

        self._write_debug_image(
            (
                "site-live-roi-match-input-"
                f"{live_features.match_image.shape[1]}x"
                f"{live_features.match_image.shape[0]}.png"
            ),
            live_features.match_image,
        )

        scores: list[SiteCandidateScore] = []

        for (
            site_key,
            template_features,
        ) in self.templates.items():
            scores.append(
                self._score_candidate(
                    site_key,
                    live_features,
                    template_features,
                )
            )

        if not scores:
            return SiteResult(
                None,
                0.0,
                0.0
            )

        scores.sort(
            key=lambda item: (
                item.hybrid_raw_score,
                item.site_key,
            ),
            reverse=True
        )

        best = scores[0]
        second = (
            scores[1]
            if len(scores) >= 2
            else None
        )

        # 除錯時才打開
        #print("\n場景候選信心度：")
        #for rank, (site_key, score) in enumerate(
        #    scores,
        #    start=1,
        #):
        #    print(
        #        f"  #{rank:02d} "
        #        f"{site_key:<30} "
        #        f"{score:.4f}"
        #    )

        #best_site, best_score = scores[0]

        #second_score = (
        #    scores[1][1]
        #    if len(scores) >= 2
        #    else 0.0
        #)

        #score_gap = (
        #    best_score
        #    - second_score
        #)

        #print("\n場景候選信心度：")

        #for rank, (site_key, score) in enumerate(
        #    scores,
        #    start=1,
        #):
        #    print(
        #        f"  #{rank:02d} "
        #        f"{site_key:<30} "
        #        f"{score:.4f}"
        #    )

        #print(
        #    "場景最高分："
        #    f"{best_site}={best_score:.4f}，"
        #    f"第二名={second_score:.4f}，"
        #    f"差距={score_gap:.4f}"
        #)

        second_confidence = (
            second.normalized_confidence
            if second is not None
            else 0.0
        )

        if self.debug_images:
            print(
                "[SITE HYBRID DEBUG] "
                f"best={best.site_key} "
                f"hist={best.histogram_score:.4f} "
                f"gray={best.grayscale_score:.4f} "
                f"edge={best.edge_score:.4f} "
                f"raw={best.hybrid_raw_score:.4f} "
                "confidence="
                f"{best.normalized_confidence:.4f}"
            )
            if second is not None:
                print(
                    "[SITE HYBRID DEBUG] "
                    f"second={second.site_key} "
                    f"hist={second.histogram_score:.4f} "
                    f"gray={second.grayscale_score:.4f} "
                    f"edge={second.edge_score:.4f} "
                    f"raw={second.hybrid_raw_score:.4f} "
                    "confidence="
                    f"{second.normalized_confidence:.4f} "
                    "gap="
                    f"{best.normalized_confidence - second.normalized_confidence:.4f}"
                )

        site_key = (
            best.site_key
            if (
                best.hybrid_raw_score
                >= self.min_hybrid_raw_score
            )
            else None
        )

        return SiteResult(
            site_key=site_key,
            confidence=best.normalized_confidence,
            second_confidence=second_confidence,
            best_candidate_key=best.site_key,
            second_candidate_key=(
                second.site_key
                if second is not None
                else None
            ),
            best_histogram_score=(
                best.histogram_score
            ),
            best_grayscale_score=(
                best.grayscale_score
            ),
            best_edge_score=best.edge_score,
            best_hybrid_raw_score=(
                best.hybrid_raw_score
            ),
            second_histogram_score=(
                second.histogram_score
                if second is not None
                else 0.0
            ),
            second_grayscale_score=(
                second.grayscale_score
                if second is not None
                else 0.0
            ),
            second_edge_score=(
                second.edge_score
                if second is not None
                else 0.0
            ),
            second_hybrid_raw_score=(
                second.hybrid_raw_score
                if second is not None
                else 0.0
            ),
        )
