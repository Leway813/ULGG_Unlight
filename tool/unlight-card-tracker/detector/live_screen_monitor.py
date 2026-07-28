from __future__ import annotations

import argparse
import json
import time
import shutil
from datetime import datetime
from pathlib import Path
from threading import Event, Lock, Thread
from typing import Any

import cv2
import mss
import numpy as np
import uvicorn

import card_detection_api
from client_profile import (
    PROFILE_ID,
    REFERENCE_HEIGHT as PROFILE_REFERENCE_HEIGHT,
    REFERENCE_WIDTH as PROFILE_REFERENCE_WIDTH,
    verify_client_profile,
)
from event_schema import (
    EVENT_SOURCE,
    PRODUCER_VERSION,
    TEMPLATE_SET_VERSION,
    loading_observation_mode,
    new_loading_observed_event,
)
from event_store import EventStore
from server import RuntimeStatus, create_app

from public_card_count_detector import (
    PublicCardCountDetector,
)
from hand_detector import (
    HAND_DEBUG,
    HandDetector,
)
from site_detector import SiteDetector
from ok_button_detector import OkButtonDetector
from result_detector import ResultDetector

# 場地辨識診斷。
# 即使場地已鎖定，也會持續比對第一與第二信心度，
# 但不會因此修改正式鎖定結果。
SITE_DEBUG = False


ROOT = Path(__file__).resolve().parent
# 只輸出公牌牌堆數量診斷。
# 不會開啟手牌／出牌候選的大量 DEBUG。
PUBLIC_COUNT_DEBUG = True

REFERENCE_WIDTH = 848
REFERENCE_HEIGHT = 760


def create_site_detector(
    *,
    debug_images: bool = False,
) -> SiteDetector:
    return SiteDetector(
        template_dir=SITE_TEMPLATE_DIR,
        region=SITE_BACKGROUND_ROI,
        min_hybrid_raw_score=0.10,
        debug_images=debug_images,
    )


SITE_TEMPLATE_DIR = (
    ROOT.parent
    / "assets"
    / "sites"
)
# 場地背景辨識區域
# 場地辨識區域
# 使用標準化遊戲畫面 848 × 760 的左半部。
SITE_BACKGROUND_ROI = {
    "x": 0,
    "y": 63,
    "width": 424,
    "height": 429,
}

PLAYER_PLAY_ROI = {
    "x": 0,
    "y": 380,
    "width": 848,
    "height": 90,
}

# =========================================================
# OK／鎖鍊按鈕 DEBUG ROI
# 基準畫面：848 × 760
# =========================================================
OK_BUTTON_ROI = {
    "x": 590,
    "y": 665,
    "width": 95,
    "height": 75,
}
# =========================================================
# 對戰結果畫面 ROI
# 基準畫面：848 × 760
# =========================================================
RESULT_ROI = {
    "x": 339,
    "y": 217,
    "width": 169,
    "height": 113,
}

# =========================================================
# 對戰開始 Loading ROI
# 基準畫面：848 × 760
#
# 涵蓋中央玫瑰圖案與 Now Loading... 文字。
# =========================================================
LOADING_ROI = {
    "x": 350,
    "y": 330,
    "width": 160,
    "height": 145,
}

player_play_detector = HandDetector(
    region=PLAYER_PLAY_ROI,
    personal_template_dir=(
        ROOT
        / "templates"
        / "personal-events"
    ),
    expected_card_width=57,
    expected_card_height=90,

    # 我方出牌越往右累積偏移，
    # 先將卡距由 68px 縮回 1px。
    card_pitch=67,
    min_card_width=48,
    max_card_width=62,
    min_card_height=82,

    # 出牌區以中央牌組為準，
    # 不使用最大背景群組。
    cluster_mode="center",
    # 我方實際偵測到幾張就保留幾張，
    # 不依中心偏移擅自補左右外側卡片。
    infer_missing_outer_card=False,
)
print(
    "我方外側補牌：",
    player_play_detector.infer_missing_outer_card,
)


# =========================================================
# 對方出牌區 DEBUG ROI
# 基準畫面：848 × 760
# 以畫面中央向左右擴張，支援約 10 張出牌
# 高度與自身手牌一致：95px
# 格式：(x, y, width, height)
# =========================================================
OPPONENT_PLAY_ROI = {
    "x": 0,
    "y": 100,
    "width": 848,
    "height": 90,
}

# =========================================================
# 公牌剩餘數量 DEBUG ROI
# 基準畫面：848 × 760
# 格式：(x, y, width, height)
# 先抓大範圍，之後依輸出圖片手動微調
# =========================================================
PUBLIC_CARD_COUNT_ROI = {
    "x": 70,
    "y": 60,
    "width": 35,
    "height": 35,
}

PUBLIC_COUNT_TENS_ROI = (
    2,
    0,
    15,
    35,
)

PUBLIC_COUNT_ONES_ROI = (
    15,
    0,
    28,
    35,
)

def write_image_unicode(
    path: Path,
    image: np.ndarray,
) -> bool:
    if image is None or image.size == 0:
        return False

    path.parent.mkdir(
        parents=True,
        exist_ok=True,
    )

    suffix = path.suffix.lower()

    if suffix not in {
        ".jpg",
        ".jpeg",
        ".png",
        ".webp",
    }:
        suffix = ".jpg"

    success, encoded = cv2.imencode(
        suffix,
        image,
    )

    if not success:
        return False

    try:
        encoded.tofile(
            str(path)
        )
        return True

    except OSError:
        return False


def save_card_face_debug_crops(
    debug_dir: Path,
    filename_prefix: str,
    card_image: np.ndarray,
) -> None:
    """
    將公牌的四個辨識 ROI 分別輸出：
    - upper_digit
    - upper_icon
    - lower_icon
    - lower_digit
    """
    if (
        card_image is None
        or card_image.size == 0
    ):
        return

    upper_digit = (
        card_detection_api.crop_image(
            card_image,
            card_detection_api.UPPER_DIGIT_ROI,
        )
    )

    upper_icon = (
        card_detection_api.crop_image(
            card_image,
            card_detection_api.UPPER_ICON_ROI,
        )
    )

    lower_icon = (
        card_detection_api.crop_image(
            card_image,
            card_detection_api.LOWER_ICON_ROI,
        )
    )

    lower_digit = (
        card_detection_api.crop_image(
            card_image,
            card_detection_api.LOWER_DIGIT_ROI,
        )
    )

    write_image_unicode(
        debug_dir
        / f"{filename_prefix}_upper_digit.png",
        upper_digit,
    )

    write_image_unicode(
        debug_dir
        / f"{filename_prefix}_upper_icon.png",
        upper_icon,
    )

    write_image_unicode(
        debug_dir
        / f"{filename_prefix}_lower_icon.png",
        lower_icon,
    )

    write_image_unicode(
        debug_dir
        / f"{filename_prefix}_lower_digit.png",
        lower_digit,
    )


def clear_debug_card_dir(
    folder: Path,
) -> None:
    if not folder.exists():
        return

    for path in folder.glob("*.png"):
        try:
            path.unlink()
        except OSError:
            pass


state_lock = Lock()
stop_event = Event()

producer_event_store: EventStore | None = None
producer_session_id: str | None = None
api_runtime_status: RuntimeStatus | None = None
api_server: uvicorn.Server | None = None
api_server_thread: Thread | None = None
public_count_detector: (
    PublicCardCountDetector | None
) = None

opponent_hand_detector: (
    HandDetector | None
) = None

site_detector: (
    SiteDetector | None
) = None

ok_button_detector: (
    OkButtonDetector | None
) = None
result_detector: (
    ResultDetector | None
) = None

loading_detector: (
    ResultDetector | None
) = None

confirmed_site: str | None = None
confirmed_site_confidence: float = 0.0
confirmed_site_score_gap: float = 0.0

site_candidate: str | None = None
site_candidate_count: int = 0

# 最近一次正式場地切換時間。
# 使用 time.monotonic()，不受系統時間調整影響。
last_site_change_at: float = 0.0

# =========================================================
# 場地穩定確認設定
# =========================================================

# 程式剛啟動、尚未有正式場地時，
# 連續辨識相同 2 幀即可確認。
SITE_INITIAL_CONFIRM_COUNT = 2

# 已經有正式場地後，要切換成其他場地，
# 必須連續辨識相同 5 幀。
SITE_SWITCH_CONFIRM_COUNT = 5

# 正式切換場地後，短時間內不接受再次切換，
# 避免垃圾之街與誘惑森林等相似灰階場地來回跳動。
SITE_SWITCH_COOLDOWN_SECONDS = 5.0

opponent_hand_detector = HandDetector(
    region=OPPONENT_PLAY_ROI,
    personal_template_dir=(
        ROOT
        / "templates"
        / "personal-events"
    ),
    expected_card_width=57,
    expected_card_height=90,
    card_pitch=67,
    min_card_width=37,
    max_card_width=62,
    min_card_height=70,

    # 對方出牌同樣以中央牌組為準。
    cluster_mode="center",
)


def grab_screen(
    screen_capture: mss.MSS,
    monitor: dict[str, int],
) -> np.ndarray:
    screenshot = screen_capture.grab(
        monitor
    )

    image = np.asarray(
        screenshot
    )

    frame = cv2.cvtColor(
        image,
        cv2.COLOR_BGRA2BGR,
    )

    # 將玩家目前的遊戲畫面統一轉換為
    # 辨識器原始基準尺寸 848 × 760。
    if (
        frame.shape[1] != REFERENCE_WIDTH
        or frame.shape[0] != REFERENCE_HEIGHT
    ):
        frame = cv2.resize(
            frame,
            (
                REFERENCE_WIDTH,
                REFERENCE_HEIGHT,
            ),
            interpolation=cv2.INTER_CUBIC,
        )

    return frame

def crop_region(
    image: np.ndarray,
    region: dict[str, int],
) -> np.ndarray:
    x = int(region["x"])
    y = int(region["y"])
    width = int(region["width"])
    height = int(region["height"])

    image_height, image_width = image.shape[:2]

    x1 = max(
        0,
        min(x, image_width)
    )

    y1 = max(
        0,
        min(y, image_height)
    )

    x2 = max(
        0,
        min(x + width, image_width)
    )

    y2 = max(
        0,
        min(y + height, image_height)
    )

    if x2 <= x1 or y2 <= y1:
        return np.empty(
            (0, 0, 3),
            dtype=np.uint8
        )

    return image[
        y1:y2,
        x1:x2
    ].copy()


def classify_final_card_type(
    detector: HandDetector,
    card_image: np.ndarray,
) -> tuple[str, float]:
    public_method = getattr(
        detector,
        "classify_card_type",
        None,
    )

    if callable(public_method):
        return public_method(
            card_image
        )

    private_method = getattr(
        detector,
        "_classify_card_type",
        None,
    )

    if callable(private_method):
        return private_method(
            card_image
        )

    raise AttributeError(
        "HandDetector 缺少卡片類型辨識方法"
    )


CARD_BACK_TEMPLATE_DIR = (
    ROOT
    / "templates"
    / "card-back"
)

card_back_templates: list[
    np.ndarray
] = []


def load_card_back_templates() -> None:
    card_back_templates.clear()

    if not CARD_BACK_TEMPLATE_DIR.exists():
        return

    for path in CARD_BACK_TEMPLATE_DIR.glob(
        "*.png"
    ):
        image = cv2.imdecode(
            np.fromfile(
                str(path),
                dtype=np.uint8,
            ),
            cv2.IMREAD_COLOR,
        )

        if image is None:
            continue

        card_back_templates.append(
            image
        )


def is_card_back(
    card_image: np.ndarray,
    threshold: float = 0.82,
) -> bool:
    if (
        card_image is None
        or card_image.size == 0
        or not card_back_templates
    ):
        return False

    best_score = 0.0

    for template in card_back_templates:
        resized = cv2.resize(
            card_image,
            (
                template.shape[1],
                template.shape[0],
            ),
            interpolation=cv2.INTER_AREA,
        )

        result = cv2.matchTemplate(
            resized,
            template,
            cv2.TM_CCOEFF_NORMED,
        )

        score = float(
            result.max()
        )

        best_score = max(
            best_score,
            score,
        )

    return best_score >= threshold


def is_valid_play_card_crop(
    card_image: np.ndarray,
) -> bool:
    """
    驗證候選框頂端是否具有卡片的黑色標題列。

    用來排除人物、場地、特效與其他背景矩形。
    """
    if (
        card_image is None
        or card_image.size == 0
        or card_image.shape[0] < 70
        or card_image.shape[1] < 42
    ):
        return False

    # 候選框可能上下偏移 1～2px，
    # 避開最外側邊框後檢查標題列。
    top_end = min(
        16,
        card_image.shape[0],
    )

    top_area = card_image[
        2:top_end,
        :
    ]

    if top_area.size == 0:
        return False

    gray = cv2.cvtColor(
        top_area,
        cv2.COLOR_BGR2GRAY,
    )

    mean_brightness = float(
        gray.mean()
    )

    dark_ratio = float(
        np.mean(
            gray < 110
        )
    )

    very_dark_ratio = float(
        np.mean(
            gray < 65
        )
    )

    return (
        mean_brightness <= 120
        and dark_ratio >= 0.42
        and very_dark_ratio >= 0.12
    )

def build_card_recognition_debug_lines(
    label: str,
    card: dict[str, Any],
) -> list[str]:
    """
    建立卡片辨識 DEBUG 文字。

    不直接輸出，由 monitor_loop 統一放在
    正常辨識內容的最下方顯示。
    """
    if not HAND_DEBUG:
        return []

    if card.get("card_type") != "public_event":
        return []

    lines: list[str] = []

    for side_key, side_label in (
        ("upper", "上方"),
        ("lower", "下方"),
    ):
        side = card.get(
            side_key,
            {},
        )

        digit_confidence = float(
            side.get(
                "digit_confidence",
                0.0,
            )
        )

        icon_confidence = float(
            side.get(
                "icon_confidence",
                0.0,
            )
        )

        digit_debug = side.get(
            "digit_debug",
            {},
        )

        icon_debug = side.get(
            "icon_debug",
            {},
        )

        has_digit_debug = bool(
            digit_debug
        )

        has_icon_debug = bool(
            icon_debug
        )

        digit_gap = float(
            digit_debug.get(
                "score_gap",
                0.0,
            )
        )

        icon_gap = float(
            icon_debug.get(
                "score_gap",
                0.0,
            )
        )

        digit_suspicious = (
            has_digit_debug
            and (
                digit_confidence < 0.72
                or digit_gap < 0.08
            )
        )

        icon_suspicious = (
            has_icon_debug
            and (
                icon_confidence < 0.65
                or icon_gap < 0.06
            )
        )

        if digit_suspicious:
            lines.append(
                (
                    f"[DIGIT] {label} {side_label}｜"
                    f"結果={side.get('value')}｜"
                    f"最高={digit_confidence:.4f}｜"
                    f"第二={digit_debug.get('second_value')} "
                    f"({float(digit_debug.get('second_confidence', 0.0)):.4f})｜"
                    f"差距={digit_gap:.4f}｜"
                    f"模板={digit_debug.get('best_template')}｜"
                    f"第二模板={digit_debug.get('second_template')}｜"
                    f"ROI="
                    f"{digit_debug.get('roi_width')}x"
                    f"{digit_debug.get('roi_height')}"
                )
            )

        if icon_suspicious:
            lines.append(
                (
                    f"[ICON] {label} {side_label}｜"
                    f"結果={side.get('icon_type')}｜"
                    f"最高={icon_confidence:.4f}｜"
                    f"第二={icon_debug.get('second_type')} "
                    f"({float(icon_debug.get('second_confidence', 0.0)):.4f})｜"
                    f"差距={icon_gap:.4f}｜"
                    f"模板={icon_debug.get('best_template')}｜"
                    f"第二模板={icon_debug.get('second_template')}｜"
                    f"ROI="
                    f"{icon_debug.get('roi_width')}x"
                    f"{icon_debug.get('roi_height')}"
                )
            )

    return lines

def create_signature(
    cards: list[dict[str, Any]],
) -> tuple:
    """
    將卡片結果轉成可比較的簽名，
    用來判斷本次辨識結果是否有變化。
    """
    signature = []

    for card in cards:
        upper = card.get(
            "upper",
            {},
        )

        lower = card.get(
            "lower",
            {},
        )

        signature.append(
            (
                upper.get(
                    "icon_type"
                ),
                upper.get(
                    "value"
                ),
                lower.get(
                    "icon_type"
                ),
                lower.get(
                    "value"
                ),
                card.get(
                    "card_type"
                ),
            )
        )

    return tuple(
        signature
    )

def select_play_candidate_group(
    candidates: list[
        tuple[
            Any,
            np.ndarray,
            str,
            float,
        ]
    ],
    roi_width: int,
    expected_pitch: float = 68.0,
    pitch_tolerance: float = 12.0,
    center_tolerance: float = 18.0,
) -> list[
    tuple[
        Any,
        np.ndarray,
        str,
        float,
    ]
]:
    """
    從已通過卡面驗證的候選框中，
    選出間距規律且整組置中的連續牌組。

    優先順序：
    1. 整組中心接近 ROI 中央
    2. 相鄰距離接近 68px
    3. 張數較多
    """
    if not candidates:
        return []

    ordered = sorted(
        candidates,
        key=lambda item: (
            float(item[0].x)
            + float(item[0].width) / 2
        ),
    )

    roi_center = (
        roi_width / 2
    )

    best_group = []
    best_score = None

    candidate_count = len(
        ordered
    )

    for start_index in range(
        candidate_count
    ):
        for end_index in range(
            start_index + 1,
            candidate_count + 1,
        ):
            group = ordered[
                start_index:end_index
            ]

            centers = [
                (
                    float(item[0].x)
                    + float(item[0].width) / 2
                )
                for item in group
            ]

            if len(centers) >= 2:
                distances = [
                    centers[index]
                    - centers[index - 1]
                    for index in range(
                        1,
                        len(centers)
                    )
                ]

                if any(
                    abs(
                        distance
                        - expected_pitch
                    ) > pitch_tolerance
                    for distance in distances
                ):
                    continue

                pitch_error = (
                    sum(
                        abs(
                            distance
                            - expected_pitch
                        )
                        for distance in distances
                    )
                    / len(distances)
                )
            else:
                pitch_error = 0.0

            group_center = (
                centers[0]
                + centers[-1]
            ) / 2

            center_error = abs(
                group_center
                - roi_center
            )

            # 出牌組正常應置中。
            if (
                len(group) >= 2
                and center_error
                > center_tolerance
            ):
                continue

            detector_confidence = sum(
                float(item[0].confidence)
                for item in group
            ) / len(group)

            type_confidence = sum(
                float(item[3])
                for item in group
            ) / len(group)

            score = (
                len(group),
                -center_error,
                -pitch_error,
                detector_confidence
                + type_confidence,
            )

            if (
                best_score is None
                or score > best_score
            ):
                best_score = score
                best_group = group

    return best_group


def get_normalized_play_card_x(
    card_index: int,
    card_count: int,
    roi_width: int,
    card_width: int = 57,
    card_pitch: int = 68,
) -> int:
    """
    依照總張數計算置中的標準卡片位置。

    card_index 從 0 開始。
    """
    if card_count <= 0:
        return 0

    group_width = (
        card_width
        + (
            card_count - 1
        ) * card_pitch
    )

    group_left = int(
        round(
            (
                roi_width
                - group_width
            ) / 2
        )
    )

    return (
        group_left
        + card_index * card_pitch
    )


def collect_play_card_candidates(
    result: Any,
    detector: HandDetector,
    *,
    reject_card_back: bool,
) -> list[
    tuple[
        Any,
        np.ndarray,
        str,
        float,
    ]
]:
    """
    出牌區候選框處理流程：

    1. HandDetector 找候選框
    2. 限制中心接近出牌水平線
    3. 驗證頂部黑色標題列
    4. 驗證卡面類型
    5. 保留候選框自己的 x、y、width、height
    """
    roi = result.roi

    if (
        roi is None
        or roi.size == 0
    ):
        return []

    roi_height, roi_width = (
        roi.shape[:2]
    )

    expected_center_y = (
        roi_height / 2
    )

    candidates: list[
        tuple[
            Any,
            np.ndarray,
            str,
            float,
        ]
    ] = []

    for card in result.cards:
        card_x = int(card.x)
        card_y = int(card.y)
        card_width = int(card.width)
        card_height = int(card.height)
        detector_confidence = float(
            card.confidence
        )

        # 第一層：卡框尺寸。
        if not (
            44 <= card_width <= 66
            and 74 <= card_height <= 96
        ):
            continue

        card_center_y = (
            card_y
            + card_height / 2
        )

        # 第二層：中心必須靠近出牌水平線。
        if abs(
            card_center_y
            - expected_center_y
        ) > 11:
            continue

        crop_left = max(
            0,
            card_x,
        )

        crop_top = max(
            0,
            card_y,
        )

        crop_right = min(
            roi_width,
            card_x + card_width,
        )

        crop_bottom = min(
            roi_height,
            card_y + card_height,
        )

        if (
            crop_right <= crop_left
            or crop_bottom <= crop_top
        ):
            continue

        card_image = roi[
            crop_top:crop_bottom,
            crop_left:crop_right,
        ].copy()

        if card_image.size == 0:
            continue

        # 第三層：黑色卡片標題列。
        if not is_valid_play_card_crop(
            card_image
        ):
            continue

        # 敵方未翻開的牌背不列入正式出牌。
        if (
            reject_card_back
            and is_card_back(
                card_image
            )
        ):
            continue

        # 第四層：卡片類型。
        card_type, type_confidence = (
            classify_final_card_type(
                detector,
                card_image,
            )
        )

        if card_type not in {
            "public_event",
            "personal_event",
        }:
            continue



        candidates.append(
            (
                card,
                card_image,
                card_type,
                type_confidence,
            )
        )

    # 先依 X 排序。
    candidates.sort(
        key=lambda item: (
            int(item[0].x),
            -float(item[0].confidence),
        )
    )

    # 排除同一張卡被偵測出兩個相近框的情況。
    deduplicated: list[
        tuple[
            Any,
            np.ndarray,
            str,
            float,
        ]
    ] = []

    for candidate in candidates:
        card = candidate[0]

        if not deduplicated:
            deduplicated.append(
                candidate
            )
            continue

        previous = deduplicated[-1]
        previous_card = previous[0]

        current_center_x = (
            float(card.x)
            + float(card.width) / 2
        )

        previous_center_x = (
            float(previous_card.x)
            + float(previous_card.width) / 2
        )

        if abs(
            current_center_x
            - previous_center_x
        ) <= 10:
            current_score = (
                float(card.confidence)
                + float(candidate[3])
            )

            previous_score = (
                float(previous_card.confidence)
                + float(previous[3])
            )

            if current_score > previous_score:
                deduplicated[-1] = (
                    candidate
                )

            continue

        deduplicated.append(
            candidate
        )

    selected_group = (
        select_play_candidate_group(
            candidates=deduplicated,
            roi_width=roi_width,

            # 我方與敵方各自使用自己的 card_pitch。
            expected_pitch=float(
                detector.card_pitch
            ),

            pitch_tolerance=12.0,
            center_tolerance=18.0,
        )
    )

    return selected_group

def build_centered_play_crops(
    roi: np.ndarray,
    card_count: int,
    card_width: int = 57,
    card_pitch: int = 67,
    padding_x: int = 2,
) -> list[
    tuple[
        int,
        int,
        np.ndarray,
    ]
]:
    """
    依指定張數建立置中的我方出牌裁切。

    回傳：
    [
        (
            crop_left,
            crop_right,
            card_image,
        ),
        ...
    ]
    """
    if (
        roi is None
        or roi.size == 0
        or card_count <= 0
    ):
        return []

    roi_height, roi_width = (
        roi.shape[:2]
    )

    group_width = (
        card_width
        + (
            card_count - 1
        ) * card_pitch
    )

    group_left = int(
        round(
            (
                roi_width
                - group_width
            ) / 2
        )
    )

    crops: list[
        tuple[
            int,
            int,
            np.ndarray,
        ]
    ] = []

    for index in range(
        card_count
    ):
        normalized_left = (
            group_left
            + index * card_pitch
        )

        crop_left = max(
            0,
            normalized_left - padding_x,
        )

        crop_right = min(
            roi_width,
            normalized_left
            + card_width
            + padding_x,
        )

        card_image = roi[
            0:min(
                roi_height,
                90,
            ),
            crop_left:crop_right,
        ].copy()

        if card_image.size == 0:
            continue

        crops.append(
            (
                crop_left,
                crop_right,
                card_image,
            )
        )

    return crops

def score_player_play_crop(
    card_image: np.ndarray,
) -> float:
    """
    評估我方出牌裁切是否接近完整卡片。

    完整卡片上方通常會有：
    - 深色標題列
    - 橫向彩色細線
    - 足夠的飽和度與卡面細節
    """
    if (
        card_image is None
        or card_image.size == 0
        or card_image.shape[0] < 70
        or card_image.shape[1] < 45
    ):
        return -999.0

    hsv = cv2.cvtColor(
        card_image,
        cv2.COLOR_BGR2HSV,
    )

    gray = cv2.cvtColor(
        card_image,
        cv2.COLOR_BGR2GRAY,
    )

    top_hsv = hsv[
        0:min(
            9,
            hsv.shape[0],
        ),
        :,
    ]

    top_gray = gray[
        0:min(
            16,
            gray.shape[0],
        ),
        :,
    ]

    saturated_ratio = float(
        np.mean(
            (
                top_hsv[:, :, 1] > 80
            )
            & (
                top_hsv[:, :, 2] > 75
            )
        )
    )

    average_saturation = float(
        np.mean(
            top_hsv[:, :, 1]
        )
    ) / 255.0

    dark_ratio = float(
        np.mean(
            top_gray < 90
        )
    )

    edge_map = cv2.Canny(
        gray,
        40,
        120,
    )

    edge_ratio = float(
        np.mean(
            edge_map > 0
        )
    )

    return (
        saturated_ratio * 3.0
        + average_saturation
        + dark_ratio * 0.4
        + edge_ratio * 0.5
    )
def score_player_play_recognition(
    card_image: np.ndarray,
) -> tuple[
    int,
    float,
]:
    """
    使用實際卡面辨識結果評估裁切完整度。

    回傳：
    - recognized_field_count：
      成功辨識出的數字／圖示欄位數，最多 4。
    - confidence_score：
      四個欄位信心度總和。
    """
    if (
        card_image is None
        or card_image.size == 0
    ):
        return (
            0,
            0.0,
        )

    try:
        recognition = (
            card_detection_api
            .detect_public_card(
                card_image=card_image,
                card_index=0,
            )
        )

    except Exception:
        return (
            0,
            0.0,
        )

    recognized_field_count = 0
    confidence_score = 0.0

    for side_key in (
        "upper",
        "lower",
    ):
        side = recognition.get(
            side_key,
            {},
        )

        icon_type = side.get(
            "icon_type"
        )

        value = side.get(
            "value"
        )

        icon_confidence = float(
            side.get(
                "icon_confidence",
                0.0,
            )
        )

        digit_confidence = float(
            side.get(
                "digit_confidence",
                0.0,
            )
        )

        if icon_type is not None:
            recognized_field_count += 1

        if value is not None:
            recognized_field_count += 1

        confidence_score += (
            icon_confidence
            + digit_confidence
        )

    return (
        recognized_field_count,
        confidence_score,
    )


def detect_player_play_cards(
    frame: np.ndarray,
) -> tuple[
    list[dict[str, Any]],
    list[dict[str, Any]],
]:
    player_result = (
        player_play_detector.detect(
            frame
        )
    )


    valid_candidates = (
        collect_play_card_candidates(
            result=player_result,
            detector=player_play_detector,
            reject_card_back=False,
        )
    )

    player_cards: list[
        dict[str, Any]
    ] = []

    player_metadata: list[
        dict[str, Any]
    ] = []

    debug_dir = (
        ROOT
        / "debug_frames"
        / "player-play-cards"
    )

    card_count = len(
        valid_candidates
    )

    roi_height, roi_width = (
        player_result.roi.shape[:2]
    )

    # =====================================================
    # 我方出牌張數二次校正
    #
    # 原始候選可能嚴重誤判，例如：
    # 實際 2 張，初始偵測卻判成 5 張。
    #
    # 因此不只比較 N 與 N-1，
    # 而是測試 1～N 的所有置中排列。
    # =====================================================
    corrected_centered_crops: list[
        tuple[
            int,
            int,
            np.ndarray,
        ]
    ] | None = None

    original_card_count = (
        card_count
    )

    best_layout: list[
        tuple[
            int,
            int,
            np.ndarray,
        ]
    ] | None = None

    best_layout_count = (
        card_count
    )

    best_layout_score = None

    layout_debug_rows: list[
        tuple[
            int,
            int,
            float,
            float,
        ]
    ] = []

    if card_count >= 2:
        # 從原始張數一路測到 1 張。
        for test_count in range(
            card_count,
            0,
            -1,
        ):
            test_layout = (
                build_centered_play_crops(
                    roi=player_result.roi,
                    card_count=test_count,
                    card_width=57,
                    card_pitch=67,
                    padding_x=2,
                )
            )

            if not test_layout:
                continue

            crop_scores: list[float] = []

            # 通過基本外觀檢查的數量。
            visual_valid_count = 0

            # 至少辨識出 2 個欄位，
            # 才視為接近完整卡片。
            recognized_card_count = 0

            total_recognized_fields = 0
            total_recognition_confidence = 0.0

            for (
                _crop_left,
                _crop_right,
                card_image,
            ) in test_layout:
                crop_score = (
                    score_player_play_crop(
                        card_image
                    )
                )

                crop_scores.append(
                    crop_score
                )

                if is_valid_play_card_crop(
                    card_image
                ):
                    visual_valid_count += 1

                (
                    recognized_fields,
                    recognition_confidence,
                ) = score_player_play_recognition(
                    card_image
                )

                total_recognized_fields += (
                    recognized_fields
                )

                total_recognition_confidence += (
                    recognition_confidence
                )

                if recognized_fields >= 2:
                    recognized_card_count += 1

            average_score = (
                sum(
                    crop_scores
                )
                / len(
                    crop_scores
                )
                if crop_scores
                else -999.0
            )

            minimum_score = (
                min(
                    crop_scores
                )
                if crop_scores
                else -999.0
            )

            recognized_ratio = (
                recognized_card_count
                / len(
                    test_layout
                )
            )

            layout_debug_rows.append(
                (
                    test_count,
                    recognized_card_count,
                    recognized_ratio,
                    average_score,
                )
            )

            # 排列評分優先順序：
            #
            # 1. 能辨識成完整卡片的比例
            # 2. 完整卡片張數
            # 3. 成功辨識欄位總數
            # 4. 卡面辨識信心總和
            # 5. 基本影像品質
            #
            # 實際 2 張、錯判成 5 張時：
            # 5 張排列多半是半張卡／背景，recognized_ratio 低。
            # 2 張排列則兩張都能辨識，recognized_ratio 接近 1。
            layout_score = (
                recognized_ratio,
                recognized_card_count,
                total_recognized_fields,
                total_recognition_confidence,
                average_score,
                minimum_score,
                visual_valid_count,
            )

            if (
                best_layout_score is None
                or layout_score
                > best_layout_score
            ):
                best_layout_score = (
                    layout_score
                )

                best_layout = (
                    test_layout
                )

                best_layout_count = (
                    test_count
                )

        # 只有在張數真的減少時才套用校正排列。
        if (
            best_layout is not None
            and best_layout_count
            < original_card_count
        ):
            corrected_centered_crops = (
                best_layout
            )

            card_count = (
                best_layout_count
            )

            if HAND_DEBUG:
                print(
                    "[我方出牌排列評估] "
                    + "｜".join(
                        (
                            f"{count}張:"
                            f"可辨識{valid_count}/"
                            f"{count},"
                            f"比例={valid_ratio:.2f},"
                            f"平均={average_score:.4f}"
                        )
                        for (
                            count,
                            valid_count,
                            valid_ratio,
                            average_score,
                        ) in layout_debug_rows
                    )
                )

                print(
                    "[我方出牌張數校正] "
                    f"原張數={original_card_count}｜"
                    f"修正張數={card_count}"
                )

    if corrected_centered_crops is not None:
        player_crop_items = [
            (
                None,
                crop_left,
                crop_right,
                card_image,
            )
            for (
                crop_left,
                crop_right,
                card_image,
            ) in corrected_centered_crops
        ]

    else:
        player_crop_items = []

        for candidate in valid_candidates:
            card = candidate[0]

            detected_center_x = (
                float(card.x)
                + float(card.width) / 2
            )

            target_card_width = 57
            crop_padding_x = 2

            crop_left = int(
                round(
                    detected_center_x
                    - target_card_width / 2
                )
            ) - crop_padding_x

            crop_left = max(
                0,
                crop_left,
            )

            crop_right = min(
                roi_width,
                crop_left
                + target_card_width
                + crop_padding_x * 2,
            )

            crop_top = max(
                0,
                int(card.y),
            )

            crop_bottom = min(
                roi_height,
                crop_top + 90,
            )

            card_image = player_result.roi[
                crop_top:crop_bottom,
                crop_left:crop_right,
            ].copy()

            player_crop_items.append(
                (
                    card,
                    crop_left,
                    crop_right,
                    card_image,
                )
            )

    for slot_index, crop_item in enumerate(
        player_crop_items,
        start=1,
    ):
        (
            card,
            crop_left,
            crop_right,
            card_image,
        ) = crop_item

        crop_top = (
            int(card.y)
            if card is not None
            else 0
        )

        crop_bottom = min(
            roi_height,
            crop_top + 90,
        )

        if card_image.size == 0:
            continue

        final_card_type, final_confidence = (
            classify_final_card_type(
                player_play_detector,
                card_image,
            )
        )

        metadata = {
            "card_index": slot_index,
            "card_type": final_card_type,

            "classification_confidence": round(
                final_confidence,
                4,
            ),

            "detector_confidence": round(
                float(
                    card.confidence
                )
                if card is not None
                else 0.0,
                4,
            ),

            "x": crop_left,
            "y": crop_top,
            "width": crop_right - crop_left,
            "height": crop_bottom - crop_top,

            "detected_x": (
                int(
                    card.x
                )
                if card is not None
                else crop_left
            ),

            "detected_y": (
                int(
                    card.y
                )
                if card is not None
                else 0
            ),

            "skipped": False,
            "skip_reason": None,
        }

        write_image_unicode(
            debug_dir
            / (
                f"player_play_card_"
                f"{slot_index:02d}_full.png"
            ),
            card_image,
        )

        if final_card_type == "personal_event":
            player_cards.append(
                {
                    "card_index": slot_index,
                    "slot_index": slot_index,
                    "card_type": "personal_event",
                    "display": "事件卡",

                    "upper": {
                        "icon_type": None,
                        "value": None,
                    },

                    "lower": {
                        "icon_type": None,
                        "value": None,
                    },

                    "classification_confidence": round(
                        final_confidence,
                        4,
                    ),
                }
            )

            player_metadata.append(
                metadata
            )

            continue

        save_card_face_debug_crops(
            debug_dir=debug_dir,
            filename_prefix=(
                f"player_play_card_"
                f"{slot_index:02d}"
            ),
            card_image=card_image,
        )

        result = (
            card_detection_api
            .detect_public_card(
                card_image=card_image,
                card_index=slot_index,
            )
        )

        result["slot_index"] = (
            slot_index
        )

        result["card_type"] = (
            "public_event"
        )

        player_cards.append(
            result
        )

        player_metadata.append(
            metadata
        )


    return (
        player_cards,
        player_metadata,
    )

def detect_opponent_cards(
    frame: np.ndarray,
) -> tuple[
    list[dict[str, Any]],
    list[dict[str, Any]],
]:
    if opponent_hand_detector is None:
        return [], []

    opponent_result = (
        opponent_hand_detector.detect(
            frame
        )
    )

    valid_candidates = (
        collect_play_card_candidates(
            result=opponent_result,
            detector=opponent_hand_detector,
            reject_card_back=True,
        )
    )

    opponent_cards: list[
        dict[str, Any]
    ] = []

    opponent_metadata: list[
        dict[str, Any]
    ] = []

    debug_dir = (
        ROOT
        / "debug_frames"
        / "opponent-cards"
    )
    roi_height, roi_width = (
        opponent_result.roi.shape[:2]
    )

    # =====================================================
    # 對方出牌張數二次校正
    #
    # 深色背景可能讓 HandDetector 多抓或少抓候選。
    # 不直接相信 valid_candidates 的數量，
    # 而是測試 1～原始候選上限的所有置中排列。
    # =====================================================
    original_candidate_count = max(
        len(valid_candidates),
        len(opponent_result.cards),
    )

    corrected_opponent_crops: list[
        tuple[
            int,
            int,
            np.ndarray,
        ]
    ] = []

    best_layout_score = None
    best_layout_count = 0

    opponent_layout_debug_rows: list[
        tuple[
            int,
            int,
            float,
            float,
        ]
    ] = []

    for test_count in range(
        original_candidate_count,
        0,
        -1,
    ):
        test_layout = build_centered_play_crops(
            roi=opponent_result.roi,
            card_count=test_count,
            card_width=57,
            card_pitch=int(
                opponent_hand_detector.card_pitch
            ),
            padding_x=2,
        )

        if not test_layout:
            continue

        recognized_card_count = 0
        total_recognized_fields = 0
        total_recognition_confidence = 0.0
        visual_valid_count = 0
        crop_scores: list[float] = []

        for (
            _crop_left,
            _crop_right,
            card_image,
        ) in test_layout:
            if (
                card_image is None
                or card_image.size == 0
            ):
                continue

            # 對方尚未翻開的牌背不能算正式出牌。
            if is_card_back(
                card_image
            ):
                continue

            crop_score = score_player_play_crop(
                card_image
            )

            crop_scores.append(
                crop_score
            )

            if is_valid_play_card_crop(
                card_image
            ):
                visual_valid_count += 1

            (
                recognized_fields,
                recognition_confidence,
            ) = score_player_play_recognition(
                card_image
            )

            total_recognized_fields += (
                recognized_fields
            )

            total_recognition_confidence += (
                recognition_confidence
            )

            # 至少辨識出兩個卡面欄位，
            # 才視為接近完整卡片。
            if recognized_fields >= 2:
                recognized_card_count += 1

        recognized_ratio = (
            recognized_card_count
            / len(test_layout)
        )

        average_score = (
            sum(crop_scores)
            / len(crop_scores)
            if crop_scores
            else -999.0
        )

        opponent_layout_debug_rows.append(
            (
                test_count,
                recognized_card_count,
                recognized_ratio,
                average_score,
            )
        )

        layout_score = (
            recognized_ratio,
            recognized_card_count,
            total_recognized_fields,
            total_recognition_confidence,
            average_score,
            visual_valid_count,
        )

        if (
            best_layout_score is None
            or layout_score > best_layout_score
        ):
            best_layout_score = (
                layout_score
            )

            corrected_opponent_crops = (
                test_layout
            )

            best_layout_count = (
                test_count
            )

    card_count = (
        best_layout_count
    )

    if HAND_DEBUG:
        print(
            "[對方出牌排列評估] "
            + "｜".join(
                (
                    f"{count}張:"
                    f"可辨識{recognized_count}/"
                    f"{count},"
                    f"比例={recognized_ratio:.2f},"
                    f"平均={average_score:.4f}"
                )
                for (
                    count,
                    recognized_count,
                    recognized_ratio,
                    average_score,
                ) in opponent_layout_debug_rows
            )
        )

        print(
            "[對方出牌張數校正] "
            f"原候選上限={original_candidate_count}｜"
            f"修正張數={card_count}"
        )
    for slot_index, crop_item in enumerate(
        corrected_opponent_crops,
        start=1,
    ):
        (
            crop_left,
            crop_right,
            card_image,
        ) = crop_item

        crop_top = 0

        crop_bottom = min(
            roi_height,
            crop_top + 90,
        )

        if (
            card_image is None
            or card_image.size == 0
        ):
            continue

        if is_card_back(
            card_image
        ):
            continue

        if card_image.size == 0:
            continue

        if is_card_back(
            card_image
        ):
            continue

        final_card_type, final_confidence = (
            classify_final_card_type(
                opponent_hand_detector,
                card_image,
            )
        )

        metadata = {
            "card_index": slot_index,
            "card_type": final_card_type,

            "classification_confidence": round(
                final_confidence,
                4,
            ),

            "detector_confidence": 0.0,

            "x": crop_left,
            "y": crop_top,
            "width": crop_right - crop_left,
            "height": crop_bottom - crop_top,

            "detected_x": crop_left,
            "detected_y": crop_top,

            "skipped": False,
            "skip_reason": None,
        }

        write_image_unicode(
            debug_dir
            / (
                f"opponent_card_"
                f"{slot_index:02d}_full.png"
            ),
            card_image,
        )

        if final_card_type == "personal_event":
            opponent_cards.append(
                {
                    "card_index": slot_index,
                    "slot_index": slot_index,
                    "card_type": "personal_event",
                    "display": "事件卡",

                    "upper": {
                        "icon_type": None,
                        "value": None,
                    },

                    "lower": {
                        "icon_type": None,
                        "value": None,
                    },

                    "classification_confidence": round(
                        final_confidence,
                        4,
                    ),
                }
            )

            opponent_metadata.append(
                metadata
            )

            continue

        save_card_face_debug_crops(
            debug_dir=debug_dir,
            filename_prefix=(
                f"opponent_card_"
                f"{slot_index:02d}"
            ),
            card_image=card_image,
        )

        result = (
            card_detection_api
            .detect_public_card(
                card_image=card_image,
                card_index=slot_index,
            )
        )

        result["slot_index"] = (
            slot_index
        )

        result["card_type"] = (
            "public_event"
        )

        opponent_cards.append(
            result
        )

        opponent_metadata.append(
            metadata
        )


    return (
        opponent_cards,
        opponent_metadata,
    )

def process_frame(
    frame: np.ndarray,
    skip_indices: set[int],
) -> dict[str, Any]:
    hand_detector = card_detection_api.hand_detector


    if hand_detector is None:
        raise RuntimeError(
            "HandDetector 尚未初始化"
        )

    hand_result = hand_detector.detect(
        frame
    )

    global confirmed_site
    global confirmed_site_confidence
    global confirmed_site_score_gap
    global site_candidate
    global site_candidate_count
    global last_site_change_at
    # 本幀是否正式確認場地切換。
    site_changed = False

    # 保留切換前的場地，供公牌資料 RESET 使用。
    previous_confirmed_site = confirmed_site
    site_debug_key: str | None = None
    site_debug_confidence = 0.0
    site_debug_second_confidence = 0.0
    site_debug_score_gap = 0.0

    # =========================================================
    # 場地辨識
    #
    # 每一幀持續辨識，不永久鎖定。
    # 新場地連續穩定出現 SITE_CONFIRM_COUNT 幀後，
    # 更新目前正式場地。
    # =========================================================
    if site_detector is not None:
        site_result = site_detector.detect(
            frame
        )

        detected_site = (
            site_result.site_key
        )

        detected_confidence = float(
            site_result.confidence
        )

        detected_second_confidence = float(
            site_result.second_confidence
        )

        detected_score_gap = (
            detected_confidence
            - detected_second_confidence
        )

        # DEBUG 顯示每一幀的即時辨識結果。
        site_debug_key = (
            detected_site
        )

        site_debug_confidence = (
            detected_confidence
        )

        site_debug_second_confidence = (
            detected_second_confidence
        )

        site_debug_score_gap = (
            detected_score_gap
        )

        current_time = time.monotonic()

        is_reliable = (
            detected_site is not None
            and detected_confidence >= 0.48
            and detected_score_gap >= 0.05
        )

        if is_reliable:
            # -------------------------------------------------
            # 本幀仍然辨識為目前正式場地
            # -------------------------------------------------
            if detected_site == confirmed_site:
                # 更新目前正式場地的信心度。
                confirmed_site_confidence = (
                    detected_confidence
                )

                confirmed_site_score_gap = (
                    detected_score_gap
                )

                # 原本正在累積的其他場地候選失效。
                site_candidate = None
                site_candidate_count = 0

            else:
                # ---------------------------------------------
                # 本幀辨識為另一個場地
                # ---------------------------------------------
                cooldown_active = (
                    confirmed_site is not None
                    and (
                        current_time
                        - last_site_change_at
                    ) < SITE_SWITCH_COOLDOWN_SECONDS
                )

                if cooldown_active:
                    # 剛完成場地切換時，
                    # 暫時忽略其他場地辨識結果。
                    site_candidate = None
                    site_candidate_count = 0

                else:
                    if detected_site == site_candidate:
                        site_candidate_count += 1
                    else:
                        site_candidate = (
                            detected_site
                        )

                        site_candidate_count = 1

                    # 尚未有正式場地時只需 2 幀；
                    # 已有場地後切換需連續 5 幀。
                    required_confirm_count = (
                        SITE_INITIAL_CONFIRM_COUNT
                        if confirmed_site is None
                        else SITE_SWITCH_CONFIRM_COUNT
                    )

                    if (
                        site_candidate_count
                        >= required_confirm_count
                    ):
                        old_site = confirmed_site

                        confirmed_site = (
                            detected_site
                        )

                        confirmed_site_confidence = (
                            detected_confidence
                        )

                        confirmed_site_score_gap = (
                            detected_score_gap
                        )

                        # 初次確認 None → 場地不算切換，
                        # 只有既有場地變成另一場地才需要 RESET。
                        site_changed = (
                            old_site is not None
                            and confirmed_site != old_site
                        )

                        if site_changed:
                            last_site_change_at = (
                                current_time
                            )

                        # 正式確認後清除候選，
                        # 避免下一幀重複觸發。
                        site_candidate = None
                        site_candidate_count = 0

        else:
            # 過場動畫或低信心畫面不立即清除正式場地，
            # 只清除尚未完成的新候選累積。
            site_candidate = None
            site_candidate_count = 0

        # 正式輸出使用最近一次穩定確認的場地。
        site_key = confirmed_site

        if confirmed_site is not None:
            site_confidence = (
                confirmed_site_confidence
            )

            site_score_gap = (
                confirmed_site_score_gap
            )
        else:
            site_confidence = (
                detected_confidence
            )

            site_score_gap = (
                detected_score_gap
            )

        site_second_confidence = (
            detected_second_confidence
        )

    else:
        site_key = None
        site_confidence = 0.0
        site_second_confidence = 0.0
        site_score_gap = 0.0

    (
        player_play_cards,
        player_play_detected_cards,
    ) = detect_player_play_cards(
        frame
    )

    if ok_button_detector is not None:
        ok_result = ok_button_detector.detect(
            frame
        )

        ok_state = ok_result.state
        ok_confidence = (
            ok_result.confidence
        )
        ok_second_confidence = (
            ok_result.second_confidence
        )
        ok_score_gap = (
            ok_result.score_gap
        )
        ok_best_template = (
            ok_result.best_template
        )
    else:
        ok_state = None
        ok_confidence = 0.0
        ok_second_confidence = 0.0
        ok_score_gap = 0.0
        ok_best_template = None

    player_play_locked = (
        ok_state == "locked"
    )
    # =========================================================
    # 對戰結果辨識
    # =========================================================
    if result_detector is not None:
        battle_result = result_detector.detect(
            frame
        )

        battle_result_state = (
            battle_result.state
        )

        battle_result_confidence = (
            battle_result.confidence
        )

        battle_result_second_confidence = (
            battle_result.second_confidence
        )

        battle_result_score_gap = (
            battle_result.score_gap
        )

        battle_result_best_template = (
            battle_result.best_template
        )

    else:
        battle_result_state = None
        battle_result_confidence = 0.0
        battle_result_second_confidence = 0.0
        battle_result_score_gap = 0.0
        battle_result_best_template = None

    (
        opponent_cards,
        opponent_detected_cards,
    ) = detect_opponent_cards(
        frame
    )

    public_count_image = crop_region(
        frame,
        PUBLIC_CARD_COUNT_ROI,
    )

    if public_count_detector is not None:
        public_count_result = (
            public_count_detector.detect(
                public_count_image
            )
        )
    else:
        public_count_result = None

    cards: list[dict[str, Any]] = []
    detected_cards: list[dict[str, Any]] = []

    for card_index, card in enumerate(
        hand_result.cards,
        start=1,
    ):
        # live-cards 最終裁切：
        # 左側向內縮 1px，右側向內縮 1px。
        live_card_x = max(
            0,
            card.x + 1,
        )

        live_card_right = min(
            hand_result.roi.shape[1],
            card.x + card.width - 4,
        )

        if live_card_right <= live_card_x:
            continue

        card_image = hand_result.roi[
            card.y:card.y + card.height,
            live_card_x:live_card_right,
        ].copy()

        metadata = {
            "card_index": card_index,
            "card_type": card.card_type,
            "classification_confidence": round(
                card.confidence,
                4,
            ),
            "x": live_card_x,
            "y": card.y,
            "width": live_card_right - live_card_x,
            "height": card.height,
            "skipped": False,
            "skip_reason": None,
        }


        debug_card_dir = (
            ROOT
            / "debug_frames"
            / "live-cards"
        )

        write_image_unicode(
            debug_card_dir
            / f"card_{card_index:02d}_full.png",
            card_image,
        )

        if card_image.size == 0:
            metadata["skipped"] = True
            metadata["skip_reason"] = "empty_crop"
            detected_cards.append(metadata)
            continue

        if card_index in skip_indices:
            metadata["skipped"] = True
            metadata["skip_reason"] = "manual_skip"
            detected_cards.append(metadata)
            continue

        final_card_type, final_confidence = (
            classify_final_card_type(
                hand_detector,
                card_image,
            )
        )

        metadata["card_type"] = (
            final_card_type
        )

        metadata["classification_confidence"] = round(
            final_confidence,
            4,
        )

        if final_card_type == "personal_event":
            event_result = {
                "card_index": card_index,
                "slot_index": card_index,
                "card_type": "personal_event",
                "display": "事件卡",
                "upper": {
                    "icon_type": None,
                    "value": None,
                },
                "lower": {
                    "icon_type": None,
                    "value": None,
                },
                "classification_confidence": round(
                    final_confidence,
                    4,
                ),
            }

            cards.append(
                event_result
            )

            detected_cards.append(
                metadata
            )

            continue
        if final_card_type != "public_event":
            metadata["skipped"] = True
            metadata["skip_reason"] = "unsupported_card_type"


            detected_cards.append(metadata)
            continue

        upper_digit = card_detection_api.crop_image(
            card_image,
            card_detection_api.UPPER_DIGIT_ROI,
        )

        upper_icon = card_detection_api.crop_image(
            card_image,
            card_detection_api.UPPER_ICON_ROI,
        )

        lower_icon = card_detection_api.crop_image(
            card_image,
            card_detection_api.LOWER_ICON_ROI,
        )



        lower_digit = card_detection_api.crop_image(
            card_image,
            card_detection_api.LOWER_DIGIT_ROI,
        )

        write_image_unicode(
            debug_card_dir
            / f"card_{card_index:02d}_upper_digit.png",
            upper_digit,
        )

        write_image_unicode(
            debug_card_dir
            / f"card_{card_index:02d}_upper_icon.png",
            upper_icon,
        )

        write_image_unicode(
            debug_card_dir
            / f"card_{card_index:02d}_lower_icon.png",
            lower_icon,
        )

        write_image_unicode(
            debug_card_dir
            / f"card_{card_index:02d}_lower_digit.png",
            lower_digit,
        )

        result = card_detection_api.detect_public_card(
            card_image=card_image,
            card_index=card_index,
        )

        # 補上卡片類型，供後續統計使用
        result["card_type"] = "public_event"
        result["slot_index"] = card_index

        cards.append(result)
        detected_cards.append(metadata)

    # =========================================================
    # Loading 畫面辨識
    # 僅作為提示，不作為必要流程條件。
    # =========================================================
    if loading_detector is not None:
        loading_result = (
            loading_detector.detect(
                frame
            )
        )

        is_loading = (
            loading_result.state
            == "loading"
        )

        loading_confidence = (
            loading_result.confidence
        )

        loading_second_confidence = (
            loading_result.second_confidence
        )

        loading_score_gap = (
            loading_result.score_gap
        )

        loading_best_template = (
            loading_result.best_template
        )

    else:
        is_loading = False
        loading_confidence = 0.0
        loading_second_confidence = 0.0
        loading_score_gap = 0.0
        loading_best_template = None

    personal_event_count = sum(
        1
        for item in detected_cards
        if item["card_type"] == "personal_event"
    )

    if public_count_result is None:
        public_deck_count = None
        public_deck_confidence = 0.0

        public_deck_tens = None
        public_deck_ones = None

        public_deck_tens_confidence = 0.0
        public_deck_ones_confidence = 0.0

        public_deck_count_mode = "unknown"
        public_deck_split_x = None
        public_deck_tens_debug: dict[str, Any] = {}
        public_deck_ones_debug: dict[str, Any] = {}

    else:
        public_deck_count = (
            public_count_result.value
        )

        public_deck_confidence = (
            public_count_result.confidence
        )

        public_deck_tens = (
            public_count_result.tens
        )

        public_deck_ones = (
            public_count_result.ones
        )

        public_deck_tens_confidence = (
            public_count_result.tens_confidence
        )

        public_deck_ones_confidence = (
            public_count_result.ones_confidence
        )
        public_deck_split_x = (
            public_count_result.split_x
        )
        public_deck_tens_debug = (
            public_count_result.tens_debug
        )

        public_deck_ones_debug = (
            public_count_result.ones_debug
        )

        if (
            public_deck_count is not None
            and public_deck_tens is not None
            and public_deck_ones is not None
        ):
            public_deck_count_mode = "double"

        elif (
            public_deck_count is not None
            and public_deck_tens is None
            and public_deck_ones is not None
        ):
            public_deck_count_mode = "single"

        else:
            public_deck_count_mode = "unknown"

    return {
        "success": True,
        "running": True,

        "site": site_key,

        "previous_site": (
            previous_confirmed_site
        ),

        "site_changed": (
            site_changed
        ),

        "site_candidate": (
            site_candidate
        ),

        "site_candidate_count": (
            site_candidate_count
        ),

        "site_switch_confirm_count": (
            SITE_SWITCH_CONFIRM_COUNT
        ),

        "public_cards_reset_required": (
            site_changed
        ),

        "site_confidence": round(
            site_confidence,
            4,
        ),

        "site_second_confidence": round(
            site_second_confidence,
            4,
        ),

        "site_score_gap": round(
            site_score_gap,
            4,
        ),
        "site_debug_key": (
            site_debug_key
        ),

        "site_debug_confidence": round(
            site_debug_confidence,
            4,
        ),

        "site_debug_second_confidence": round(
            site_debug_second_confidence,
            4,
        ),

        "site_debug_score_gap": round(
            site_debug_score_gap,
            4,
        ),
        "updated_at": datetime.now().isoformat(
            timespec="seconds"
        ),
        "screen": {
            "left": int(capture_region["left"]),
            "top": int(capture_region["top"]),
            "capture_width": int(
                capture_region["width"]
            ),
            "capture_height": int(
                capture_region["height"]
            ),
            "normalized_width": REFERENCE_WIDTH,
            "normalized_height": REFERENCE_HEIGHT,
        },
        "hand_count": len(hand_result.cards),

        # 手牌中的一般公牌數量
        "public_hand_count": sum(
            1
            for card in cards
            if card.get("card_type") == "public_event"
        ),

        "public_card_count": sum(
            1
            for card in cards
            if card.get("card_type") == "public_event"
        ),

        "personal_event_count": (
            personal_event_count
        ),

        # 中央公牌牌堆剩餘數量
        "public_deck_count": (
            public_deck_count
        ),

        "public_deck_count_confidence": round(
            public_deck_confidence,
            4,
        ),

        "public_deck_count_mode": (
            public_deck_count_mode
        ),
        "public_deck_split_x": (
            public_deck_split_x
        ),

        "public_deck_digits": {
            "tens": public_deck_tens,
            "ones": public_deck_ones,

            "tens_confidence": round(
                public_deck_tens_confidence,
                4,
            ),

            "ones_confidence": round(
                public_deck_ones_confidence,
                4,
            ),

            "tens_debug": (
                public_deck_tens_debug
            ),

            "ones_debug": (
                public_deck_ones_debug
            ),
        },

        "cards": cards,

        "ok_state": ok_state,
        "ok_locked": player_play_locked,
        "ok_confidence": round(
            ok_confidence,
            4,
        ),
        "ok_second_confidence": round(
            ok_second_confidence,
            4,
        ),
        "ok_score_gap": round(
            ok_score_gap,
            4,
        ),
        "ok_best_template": (
            ok_best_template
        ),
        "battle_result": (
            battle_result_state
        ),


        "battle_result_confidence": round(
            battle_result_confidence,
            4,
        ),

        "battle_result_second_confidence": round(
            battle_result_second_confidence,
            4,
        ),

        "battle_result_score_gap": round(
            battle_result_score_gap,
            4,
        ),

        "battle_result_best_template": (
            battle_result_best_template
        ),

        "is_loading": (
            is_loading
        ),

        "loading_confidence": round(
            loading_confidence,
            4,
        ),

        "loading_second_confidence": round(
            loading_second_confidence,
            4,
        ),

        "loading_score_gap": round(
            loading_score_gap,
            4,
        ),

        "loading_best_template": (
            loading_best_template
        ),

        "player_play_card_count": len(
            player_play_cards
        ),
        "player_play_cards": (
            player_play_cards
        ),
        "player_play_detected_cards": (
            player_play_detected_cards
        ),

        # 只有鎖鍊狀態才視為正式出牌
        "confirmed_player_play_cards": (
            player_play_cards
            if player_play_locked
            else []
        ),

        "opponent_card_count": len(
            opponent_cards
        ),

        "opponent_cards": (
            opponent_cards
        ),

        "opponent_detected_cards": (
            opponent_detected_cards
        ),

        "detected_cards": detected_cards,
    }


def monitor_loop(
    interval: float,
    skip_indices: set[int],
    debug: bool,
) -> None:
    previous_signature: tuple | None = None
    last_published_loading: bool | None = None

    with mss.MSS() as screen_capture:
        while not stop_event.is_set():
            started_at = time.perf_counter()

            try:
                frame = grab_screen(
                    screen_capture,
                    capture_region,
                )

                result = process_frame(
                    frame=frame,
                    skip_indices=skip_indices,
                )

                loading_mode = loading_observation_mode(
                    last_published_loading,
                    bool(result["is_loading"]),
                )
                if loading_mode is not None:
                    if (
                        producer_event_store is None
                        or producer_session_id is None
                    ):
                        raise RuntimeError(
                            "producer event store is not initialized"
                        )

                    producer_event_store.append_event(
                        new_loading_observed_event(
                            session_id=producer_session_id,
                            is_loading=bool(result["is_loading"]),
                            confidence=float(
                                result["loading_confidence"]
                            ),
                            observation_mode=loading_mode,
                        )
                    )
                    last_published_loading = bool(
                        result["is_loading"]
                    )

                if PUBLIC_COUNT_DEBUG:
                    public_digits_for_signature = result.get(
                        "public_deck_digits",
                        {},
                    )

                    tens_debug_for_signature = (
                        public_digits_for_signature.get(
                            "tens_debug",
                            {},
                        )
                    )

                    ones_debug_for_signature = (
                        public_digits_for_signature.get(
                            "ones_debug",
                            {},
                        )
                    )

                    public_count_debug_signature = (
                        round(
                            float(
                                result.get(
                                    "public_deck_count_confidence",
                                    0.0,
                                )
                            ),
                            2,
                        ),

                        public_digits_for_signature.get(
                            "tens"
                        ),

                        tens_debug_for_signature.get(
                            "second_value"
                        ),

                        round(
                            float(
                                tens_debug_for_signature.get(
                                    "score_gap",
                                    0.0,
                                )
                            ),
                            2,
                        ),

                        public_digits_for_signature.get(
                            "ones"
                        ),

                        ones_debug_for_signature.get(
                            "second_value"
                        ),

                        round(
                            float(
                                ones_debug_for_signature.get(
                                    "score_gap",
                                    0.0,
                                )
                            ),
                            2,
                        ),

                        result.get(
                            "public_deck_split_x"
                        ),
                    )
                else:
                    public_count_debug_signature = None

                signature = (
                    create_signature(
                        result["cards"]
                    ),
                    create_signature(
                        result["opponent_cards"]
                    ),
                    result["hand_count"],
                    result["public_hand_count"],
                    result["personal_event_count"],
                    result["public_deck_count"],
                    result["opponent_card_count"],
                    create_signature(
                        result["player_play_cards"]
                    ),
                    result["player_play_card_count"],
                    result["ok_state"],
                    result["site"],
                    result.get(
                        "site_changed",
                        False,
                    ),

                    result.get(
                        "site_candidate"
                    ),

                    result.get(
                        "site_candidate_count",
                        0,
                    ),
                    result.get(
                        "site_debug_key"
                    ),

                    round(
                        float(
                            result.get(
                                "site_debug_confidence",
                                0.0,
                            )
                        ),
                        2,
                    ),

                    round(
                        float(
                            result.get(
                                "site_debug_second_confidence",
                                0.0,
                            )
                        ),
                        2,
                    ),
                    result["battle_result"],

                    result.get(
                        "is_loading",
                        False,
                    ),

                    round(
                        float(
                            result.get(
                                "loading_confidence",
                                0.0,
                            )
                        ),
                        2,
                    ),

                    public_count_debug_signature,
                    )

                with state_lock:
                    card_detection_api.current_state.clear()
                    card_detection_api.current_state.update(
                        result
                    )

                if signature != previous_signature:
                    timestamp = result["updated_at"]

                    debug_lines: list[str] = []

                    public_deck_count = result.get(
                        "public_deck_count"
                    )

                    public_deck_text = (
                        str(public_deck_count)
                        if public_deck_count is not None
                        else "?"
                    )

                    site_text = (
                        result["site"]
                        if result["site"] is not None
                        else "?"
                    )
                    ok_state = result.get(
                        "ok_state"
                    )

                    ok_text_map = {
                        "unlocked": "OK",
                        "locked": "已鎖定",
                    }

                    ok_state_text = ok_text_map.get(
                        ok_state,
                        "未辨識",
                    )

                    ok_confidence = float(
                        result.get(
                            "ok_confidence",
                            0.0,
                        )
                    )
                    battle_result_text_map = {
                        "win": "勝利",
                        "lose": "敗北",
                        "timeout": "時間到",
                        "tie": "平手",
                        "loading": "載入中",
                    }

                    battle_result_state = result.get(
                        "battle_result"
                    )

                    if result.get(
                        "site_changed",
                        False,
                    ):
                        print(
                            "\n[場地切換] "
                            f"{result.get('previous_site')} "
                            f"→ {result.get('site')}，"
                            "公牌追蹤資料需要 RESET"
                        )

                    if result.get(
                        "is_loading",
                        False,
                    ):
                        battle_result_text = "載入中"

                        display_result_confidence = float(
                            result.get(
                                "loading_confidence",
                                0.0,
                            )
                        )

                    else:
                        battle_result_text = (
                            battle_result_text_map.get(
                                battle_result_state,
                                "進行中",
                            )
                        )

                        display_result_confidence = float(
                            result.get(
                                "battle_result_confidence",
                                0.0,
                            )
                        )

                    print(
                        f"\n[{timestamp}] "
                        f"場地：{site_text} "
                        f"({result['site_confidence']:.3f})，"
                        f"手牌 {result['hand_count']} 張"
                        f"（公牌 {result['public_hand_count']}／"
                        f"事件卡 {result['personal_event_count']}），"
                        f"公牌剩餘 {public_deck_text} 張，"
                        f"對方出牌 "
                        f"{result['opponent_card_count']} 張，"
                        f"按鈕：{ok_state_text} "
                        f"({ok_confidence:.3f})，"
                        f"結果：{battle_result_text} "
                        f"({display_result_confidence:.3f})"
                    )
                    player_play_cards = result.get(
                        "player_play_cards",
                        [],
                    )

                    confirmed_player_play_cards = result.get(
                        "confirmed_player_play_cards",
                        [],
                    )

                    if result["ok_locked"]:
                        confirmed_public_count = sum(
                            1
                            for card in confirmed_player_play_cards
                            if card.get("card_type") == "public_event"
                        )

                        confirmed_event_count = sum(
                            1
                            for card in confirmed_player_play_cards
                            if card.get("card_type") == "personal_event"
                        )

                        print(
                            f"\n我方正式出牌 "
                            f"{len(confirmed_player_play_cards)} 張"
                            f"（公牌 {confirmed_public_count}／"
                            f"事件卡 {confirmed_event_count}，"
                            f"已鎖定）："
                        )

                        for card in confirmed_player_play_cards:
                            card_label = (
                                f"player_play_"
                                f"{card['slot_index']:02d}"
                            )

                            print(
                                f"{card_label}："
                                f"{card['display']}"
                            )

                            debug_lines.extend(
                                build_card_recognition_debug_lines(
                                    card_label,
                                    card,
                                )
                            )

                    elif player_play_cards:
                        player_public_count = sum(
                            1
                            for card in player_play_cards
                            if card.get("card_type") == "public_event"
                        )

                        player_event_count = sum(
                            1
                            for card in player_play_cards
                            if card.get("card_type") == "personal_event"
                        )

                        print(
                            f"\n我方出牌區暫存 "
                            f"{len(player_play_cards)} 張"
                            f"（公牌 {player_public_count}／"
                            f"事件卡 {player_event_count}，"
                            f"尚未鎖定，可收回）："
                        )

                        for card in player_play_cards:
                            card_label = (
                                f"player_play_"
                                f"{card['slot_index']:02d}"
                            )

                            print(
                                f"{card_label}："
                                f"{card['display']}"
                            )

                            debug_lines.extend(
                                build_card_recognition_debug_lines(
                                    card_label,
                                    card,
                                )
                            )

                    opponent_cards = result.get(
                        "opponent_cards",
                        [],
                    )

                    if opponent_cards:
                        opponent_public_count = sum(
                            1
                            for card in opponent_cards
                            if card.get("card_type") == "public_event"
                        )

                        opponent_event_count = sum(
                            1
                            for card in opponent_cards
                            if card.get("card_type") == "personal_event"
                        )

                        print(
                            f"\n對方出牌 "
                            f"{len(opponent_cards)} 張"
                            f"（公牌 {opponent_public_count}／"
                            f"事件卡 {opponent_event_count}）："
                        )

                        for card in opponent_cards:
                            card_label = (
                                f"opponent_"
                                f"{card['slot_index']:02d}"
                            )

                            print(
                                f"{card_label}："
                                f"{card['display']}"
                            )

                            debug_lines.extend(
                                build_card_recognition_debug_lines(
                                    card_label,
                                    card,
                                )
                            )

                    for card in result["cards"]:
                        card_label = (
                            f"card_"
                            f"{card['card_index']:02d}"
                        )

                        print(
                            f"{card_label}："
                            f"{card['display']}"
                        )

                        debug_lines.extend(
                            build_card_recognition_debug_lines(
                                card_label,
                                card,
                            )
                        )
                    # -----------------------------------------
                    # 公牌牌堆數量 DEBUG
                    # 使用獨立開關，不需要開啟 HAND_DEBUG。
                    # -----------------------------------------
                    if PUBLIC_COUNT_DEBUG:
                        public_digits = result.get(
                            "public_deck_digits",
                            {},
                        )

                        public_debug_value = result.get(
                            "public_deck_count"
                        )

                        public_debug_text = (
                            str(public_debug_value)
                            if public_debug_value is not None
                            else "?"
                        )

                        public_mode_map = {
                            "double": "兩位數",
                            "single": "單位數",
                            "unknown": "未辨識",
                        }

                        public_mode_text = (
                            public_mode_map.get(
                                result.get(
                                    "public_deck_count_mode",
                                    "unknown",
                                ),
                                "未辨識",
                            )
                        )

                        tens_debug = public_digits.get(
                            "tens_debug",
                            {},
                        )

                        ones_debug = public_digits.get(
                            "ones_debug",
                            {},
                        )

                        # -----------------------------------------
                        # 場地辨識 DEBUG
                        # -----------------------------------------
                        if SITE_DEBUG:
                            print("\n[SITE DEBUG]")

                            print(
                                "場地辨識｜"
                                f"目前場地={result.get('site')}｜"
                                f"目前第一={result.get('site_debug_key')}｜"
                                f"第一信心="
                                f"{float(result.get('site_debug_confidence', 0.0)):.4f}｜"
                                f"第二信心="
                                f"{float(result.get('site_debug_second_confidence', 0.0)):.4f}｜"
                                f"分差="
                                f"{float(result.get('site_debug_score_gap', 0.0)):.4f}｜"
                                f"切換候選={result.get('site_candidate')}｜"
                                f"累積="
                                f"{result.get('site_candidate_count', 0)}/"
                                f"{result.get('site_switch_confirm_count', 0)}"
                            )

                        print("\n[PUBLIC COUNT DEBUG]")

                        print(
                            "公牌數量｜"
                            f"結果={public_debug_text}｜"
                            f"模式={public_mode_text}｜"
                            f"切線={result.get('public_deck_split_x')}｜"
                            f"總信心="
                            f"{float(result.get('public_deck_count_confidence', 0.0)):.4f}"
                        )

                        print(
                            "十位｜"
                            f"結果={public_digits.get('tens')}｜"
                            f"最高={tens_debug.get('best_value')} "
                            f"({float(tens_debug.get('best_confidence', 0.0)):.4f})｜"
                            f"第二={tens_debug.get('second_value')} "
                            f"({float(tens_debug.get('second_confidence', 0.0)):.4f})｜"
                            f"差距={float(tens_debug.get('score_gap', 0.0)):.4f}｜"
                            f"模板={tens_debug.get('best_template')}｜"
                            f"第二模板={tens_debug.get('second_template')}｜"
                            f"ROI="
                            f"{tens_debug.get('roi_width')}x"
                            f"{tens_debug.get('roi_height')}"
                        )

                        print(
                            "個位｜"
                            f"結果={public_digits.get('ones')}｜"
                            f"最高={ones_debug.get('best_value')} "
                            f"({float(ones_debug.get('best_confidence', 0.0)):.4f})｜"
                            f"第二={ones_debug.get('second_value')} "
                            f"({float(ones_debug.get('second_confidence', 0.0)):.4f})｜"
                            f"差距={float(ones_debug.get('score_gap', 0.0)):.4f}｜"
                            f"模板={ones_debug.get('best_template')}｜"
                            f"第二模板={ones_debug.get('second_template')}｜"
                            f"ROI="
                            f"{ones_debug.get('roi_width')}x"
                            f"{ones_debug.get('roi_height')}"
                        )

                    # -----------------------------------------
                    # 卡片／候選 DEBUG
                    # 仍由 HAND_DEBUG 控制。
                    # -----------------------------------------
                    if HAND_DEBUG:
                        print("\n[HAND DEBUG INFO]")

                        print(
                            "候選數量｜"
                            f"我方出牌={result['player_play_card_count']}｜"
                            f"敵方出牌={result['opponent_card_count']}｜"
                            f"手牌={result['hand_count']}"
                        )

                        if debug_lines:
                            for debug_line in debug_lines:
                                print(debug_line)
                        else:
                            print(
                                "目前沒有低信心或分差過小的卡片。"
                            )

                    previous_signature = signature

                if debug:
                    debug_dir = (
                        ROOT
                        / "debug_frames"
                    )
                    battle_result_image = crop_region(
                        frame,
                        RESULT_ROI,
                    )

                    loading_image = crop_region(
                        frame,
                        LOADING_ROI,
                    )

                    loading_path = (
                        debug_dir
                        / "battle-loading-area.png"
                    )

                    write_image_unicode(
                        loading_path,
                        loading_image,
                    )

                    battle_result_path = (
                        debug_dir
                        / "battle-result-area.png"
                    )

                    write_image_unicode(
                        battle_result_path,
                        battle_result_image,
                    )



                    # 場地辨識實際使用的 ROI
                    site_background_image = crop_region(
                        frame,
                        SITE_BACKGROUND_ROI,
                    )

                    site_background_path = (
                        debug_dir
                        / "site-background-area.png"
                    )

                    site_saved = write_image_unicode(
                        site_background_path,
                        site_background_image,
                    )

                    if not site_saved:
                        print(
                            "場地 ROI 圖片儲存失敗："
                            f"{site_background_path}"
                        )

                    # 完整標準化遊戲畫面
                    live_screen_path = (
                        debug_dir
                        / "live-screen.jpg"
                    )
                    # OK／鎖鍊按鈕區域
                    ok_button_image = crop_region(
                        frame,
                        OK_BUTTON_ROI,
                    )

                    ok_button_path = (
                        debug_dir
                        / "ok-button-area.png"
                    )

                    saved = write_image_unicode(
                        ok_button_path,
                        ok_button_image,
                    )

                    if not saved:
                        print(
                            "OK 按鈕區域儲存失敗："
                            f"{ok_button_path}"
                        )

                    saved = write_image_unicode(
                        live_screen_path,
                        frame,
                    )

                    if not saved:
                        print(
                            f"除錯圖片儲存失敗："
                            f"{live_screen_path}"
                        )

                    # 我方出牌區
                    player_play_image = crop_region(
                        frame,
                        PLAYER_PLAY_ROI,
                    )

                    player_play_path = (
                        debug_dir
                        / "player-play-area.png"
                    )

                    saved = write_image_unicode(
                        player_play_path,
                        player_play_image,
                    )

                    if not saved:
                        print(
                            "我方出牌區儲存失敗："
                            f"{player_play_path}"
                        )

                    # 對方出牌區
                    opponent_play_image = crop_region(
                        frame,
                        OPPONENT_PLAY_ROI,
                    )

                    opponent_play_path = (
                        debug_dir
                        / "opponent-play-area.png"
                    )

                    saved = write_image_unicode(
                        opponent_play_path,
                        opponent_play_image,
                    )

                    if not saved:
                        print(
                            "對方出牌區儲存失敗："
                            f"{opponent_play_path}"
                        )

                    # 公牌剩餘數量區域
                    public_card_count_image = crop_region(
                        frame,
                        PUBLIC_CARD_COUNT_ROI,
                    )

                    public_card_count_path = (
                        debug_dir
                        / "public-card-count-area.png"
                    )

                    dynamic_split_x = result.get(
                        "public_deck_split_x"
                    )

                    if dynamic_split_x is None:
                        dynamic_split_x = 15

                    dynamic_split_x = max(
                        3,
                        min(
                            int(dynamic_split_x),
                            25,
                        ),
                    )

                    public_count_tens = (
                        public_card_count_image[
                            0:35,
                            2:dynamic_split_x,
                        ].copy()
                    )

                    public_count_ones = (
                        public_card_count_image[
                            0:35,
                            dynamic_split_x:28,
                        ].copy()
                    )

                    write_image_unicode(
                        debug_dir
                        / "public-card-count-tens.png",
                        public_count_tens,
                    )

                    write_image_unicode(
                        debug_dir
                        / "public-card-count-ones.png",
                        public_count_ones,
                    )

                    saved = write_image_unicode(
                        public_card_count_path,
                        public_card_count_image,
                    )

                    if not saved:
                        print(
                            "公牌數量區域儲存失敗："
                            f"{public_card_count_path}"
                        )

                    saved = write_image_unicode(
                        opponent_play_path,
                        opponent_play_image,
                    )

                    if not saved:
                        print(
                            f"對方出牌區儲存失敗："
                            f"{opponent_play_path}"
                        )

                    # 在完整畫面畫出 ROI 框線
                    overlay = frame.copy()

                    # Loading ROI
                    loading_x = int(
                        LOADING_ROI["x"]
                    )

                    loading_y = int(
                        LOADING_ROI["y"]
                    )

                    loading_width = int(
                        LOADING_ROI["width"]
                    )

                    loading_height = int(
                        LOADING_ROI["height"]
                    )

                    cv2.rectangle(
                        overlay,
                        (
                            loading_x,
                            loading_y,
                        ),
                        (
                            loading_x + loading_width,
                            loading_y + loading_height,
                        ),
                        (0, 165, 255),
                        2,
                    )

                    cv2.putText(
                        overlay,
                        "loading area",
                        (
                            loading_x,
                            max(
                                15,
                                loading_y - 6,
                            ),
                        ),
                        cv2.FONT_HERSHEY_SIMPLEX,
                        0.45,
                        (0, 165, 255),
                        1,
                        cv2.LINE_AA,
                    )
                    ok_x = int(
                        OK_BUTTON_ROI["x"]
                    )
                    ok_y = int(
                        OK_BUTTON_ROI["y"]
                    )
                    ok_width = int(
                        OK_BUTTON_ROI["width"]
                    )
                    ok_height = int(
                        OK_BUTTON_ROI["height"]
                    )

                    cv2.rectangle(
                        overlay,
                        (ok_x, ok_y),
                        (
                            ok_x + ok_width,
                            ok_y + ok_height,
                        ),
                        (0, 0, 255),
                        2,
                    )

                    cv2.putText(
                        overlay,
                        "OK / locked",
                        (
                            ok_x,
                            max(15, ok_y - 6),
                        ),
                        cv2.FONT_HERSHEY_SIMPLEX,
                        0.45,
                        (0, 0, 255),
                        1,
                        cv2.LINE_AA,
                    )

                    count_x = int(
                        PUBLIC_CARD_COUNT_ROI["x"]
                    )

                    count_y = int(
                        PUBLIC_CARD_COUNT_ROI["y"]
                    )

                    count_width = int(
                        PUBLIC_CARD_COUNT_ROI["width"]
                    )

                    count_height = int(
                        PUBLIC_CARD_COUNT_ROI["height"]
                    )

                    cv2.rectangle(
                        overlay,
                        (
                            count_x,
                            count_y,
                        ),
                        (
                            count_x + count_width,
                            count_y + count_height,
                        ),
                        (255, 0, 255),
                        2,
                    )

                    cv2.putText(
                        overlay,
                        "public card count",
                        (
                            count_x,
                            max(
                                15,
                                count_y - 6,
                            ),
                        ),
                        cv2.FONT_HERSHEY_SIMPLEX,
                        0.45,
                        (255, 0, 255),
                        1,
                        cv2.LINE_AA,
                    )

                    player_x = int(
                        PLAYER_PLAY_ROI["x"]
                    )
                    player_y = int(
                        PLAYER_PLAY_ROI["y"]
                    )
                    player_width = int(
                        PLAYER_PLAY_ROI["width"]
                    )
                    player_height = int(
                        PLAYER_PLAY_ROI["height"]
                    )

                    cv2.rectangle(
                        overlay,
                        (
                            player_x,
                            player_y,
                        ),
                        (
                            player_x + player_width,
                            player_y + player_height,
                        ),
                        (255, 255, 0),
                        2,
                    )

                    cv2.putText(
                        overlay,
                        "player play area",
                        (
                            player_x,
                            max(
                                15,
                                player_y - 6,
                            ),
                        ),
                        cv2.FONT_HERSHEY_SIMPLEX,
                        0.45,
                        (255, 255, 0),
                        1,
                        cv2.LINE_AA,
                    )

                    opponent_x = int(
                        OPPONENT_PLAY_ROI["x"]
                    )

                    opponent_y = int(
                        OPPONENT_PLAY_ROI["y"]
                    )

                    opponent_width = int(
                        OPPONENT_PLAY_ROI["width"]
                    )

                    opponent_height = int(
                        OPPONENT_PLAY_ROI["height"]
                    )

                    cv2.rectangle(
                        overlay,
                        (
                            opponent_x,
                            opponent_y,
                        ),
                        (
                            opponent_x + opponent_width,
                            opponent_y + opponent_height,
                        ),
                        (0, 255, 255),
                        2,
                    )

                    cv2.putText(
                        overlay,
                        "opponent play area",
                        (
                            opponent_x,
                            max(
                                15,
                                opponent_y - 6,
                            ),
                        ),
                        cv2.FONT_HERSHEY_SIMPLEX,
                        0.45,
                        (0, 255, 255),
                        1,
                        cv2.LINE_AA,
                    )

                    overlay_path = (
                        debug_dir
                        / "live-screen-roi-debug.png"
                    )

                    write_image_unicode(
                        overlay_path,
                        overlay,
                    )



            except Exception as error:
                with state_lock:
                    card_detection_api.current_state.clear()
                    card_detection_api.current_state.update(
                        {
                            "success": False,
                            "running": True,
                            "updated_at": datetime.now().isoformat(
                                timespec="seconds"
                            ),
                            "error": str(error),
                            "hand_count": 0,
                            "public_card_count": 0,
                            "cards": [],
                            "detected_cards": [],
                        }
                    )

                print(
                    f"\n辨識錯誤：{error}"
                )

            elapsed = (
                time.perf_counter()
                - started_at
            )

            wait_seconds = max(
                0.05,
                interval - elapsed,
            )

            stop_event.wait(
                wait_seconds
            )


def parse_skip(
    raw_value: str,
) -> set[int]:
    result: set[int] = set()

    for item in raw_value.split(","):
        item = item.strip()

        if item.isdigit():
            result.add(int(item))

    return result


capture_region: dict[str, int] = {}

def main() -> None:
    global capture_region
    global public_count_detector
    global opponent_hand_detector
    global site_detector
    global ok_button_detector
    global result_detector
    global loading_detector
    global producer_event_store
    global producer_session_id
    global api_runtime_status
    global api_server
    global api_server_thread

    parser = argparse.ArgumentParser(
        description="UL.GG 即時螢幕卡片辨識測試"
    )

    parser.add_argument(
        "--left",
        type=int,
        required=True,
        help="擷取區域左上角 X",
    )

    parser.add_argument(
        "--top",
        type=int,
        required=True,
        help="擷取區域左上角 Y",
    )

    parser.add_argument(
        "--width",
        type=int,
        required=True,
        help="擷取寬度",
    )

    parser.add_argument(
        "--height",
        type=int,
        required=True,
        help="擷取高度",
    )

    parser.add_argument(
        "--interval",
        type=float,
        default=0.8,
        help="辨識間隔秒數",
    )

    parser.add_argument(
        "--skip",
        type=str,
        default="",
        help="手動略過卡號，例如 4 或 4,7",
    )

    parser.add_argument(
        "--debug",
        action="store_true",
        help="持續保存目前擷取畫面",
    )

    parser.add_argument(
        "--database",
        type=Path,
        default=None,
        help="SQLite event log 路徑",
    )

    parser.add_argument(
        "--port",
        type=int,
        default=8765,
        help="localhost API port",
    )

    args = parser.parse_args()

    capture_region = {
        "left": args.left,
        "top": args.top,
        "width": args.width,
        "height": args.height,
    }

    skip_indices = parse_skip(
        args.skip
    )

    profile_status = verify_client_profile()
    producer_event_store = EventStore(args.database)
    producer_event_store.initialize()
    session = producer_event_store.start_session(
        producer_version=PRODUCER_VERSION,
        source=EVENT_SOURCE,
        client_profile=PROFILE_ID,
        app_asar_hash=profile_status.actual_app_asar_sha256,
        template_set_version=TEMPLATE_SET_VERSION,
        reference_width=PROFILE_REFERENCE_WIDTH,
        reference_height=PROFILE_REFERENCE_HEIGHT,
    )
    producer_session_id = session["session_id"]
    api_runtime_status = RuntimeStatus()

    if not profile_status.supported:
        api_runtime_status.update(
            detector="paused",
            error=profile_status.reason,
        )

    api_app = create_app(
        event_store=producer_event_store,
        client_profile=profile_status,
        runtime_status=api_runtime_status,
    )
    api_server = uvicorn.Server(
        uvicorn.Config(
            api_app,
            host="127.0.0.1",
            port=args.port,
            log_level="info",
        )
    )
    api_server_thread = Thread(
        target=api_server.run,
        name="ulgg-api-server",
        daemon=True,
    )
    api_server_thread.start()

    for _ in range(100):
        if api_server.started:
            break
        if not api_server_thread.is_alive():
            raise RuntimeError(
                "FastAPI/Uvicorn failed to start"
            )
        time.sleep(0.05)
    else:
        raise RuntimeError(
            "FastAPI/Uvicorn startup timed out"
        )

    print(
        "Tracker UI："
        f"http://127.0.0.1:{args.port}/tracker/"
    )
    print(
        "Tracker API："
        f"http://127.0.0.1:{args.port}/api/v1/health"
    )
    print(f"Producer session：{producer_session_id}")

    if not profile_status.supported:
        print(
            "自動偵測已暫停："
            f"{profile_status.reason}"
        )
        while (
            api_server_thread.is_alive()
            and not stop_event.is_set()
        ):
            time.sleep(0.5)
        return

    # 程式啟動時清除上一輪 DEBUG，
    # 執行期間不再每幀刪除，方便保留最後辨識結果。
    clear_debug_card_dir(
        ROOT
        / "debug_frames"
        / "player-play-cards"
    )

    clear_debug_card_dir(
        ROOT
        / "debug_frames"
        / "opponent-cards"
    )

    load_card_back_templates()
    public_count_detector = (
        PublicCardCountDetector(
            template_dir=(
                ROOT
                / "templates"
                / "public-card-count-digits"
            ),
            single_template_dir=(
                ROOT
                / "templates"
                / "public-card-count-single"
            ),
            threshold=0.60,
            digit_left_x=2,
            split_x=15,
            digit_right_x=28,
        )
    )
    site_detector = create_site_detector(
        debug_images=args.debug,
    )
    site_roi_x = int(
    SITE_BACKGROUND_ROI["x"]
    )

    site_roi_y = int(
        SITE_BACKGROUND_ROI["y"]
    )

    site_roi_width = int(
        SITE_BACKGROUND_ROI["width"]
    )

    site_roi_height = int(
        SITE_BACKGROUND_ROI["height"]
    )

    print()
    print("場地辨識 ROI：")
    print(
        f"  x={site_roi_x}, "
        f"y={site_roi_y}, "
        f"width={site_roi_width}, "
        f"height={site_roi_height}"
    )
    print(
        "  右下角："
        f"x={site_roi_x + site_roi_width}, "
        f"y={site_roi_y + site_roi_height}"
    )
    print(
        "  標準畫面："
        f"{REFERENCE_WIDTH} × "
        f"{REFERENCE_HEIGHT}"
    )

    ok_button_detector = OkButtonDetector(
        template_dir=(
            ROOT
            / "templates"
            / "ok-button"
        ),
        region=OK_BUTTON_ROI,
        threshold=0.62,
        min_score_gap=0.08,
    )
    result_detector = ResultDetector(
        template_dir=(
            ROOT
            / "templates"
            / "battle-result"
        ),
        region=RESULT_ROI,
        threshold=0.62,
        min_score_gap=0.06,
    )

    loading_detector = ResultDetector(
        template_dir=(
            ROOT
            / "templates"
            / "battle-loading"
        ),
        region=LOADING_ROI,
        threshold=0.62,
        min_score_gap=0.04,
    )
    api_runtime_status.update(detector="ready")

    # 直接執行 FastAPI lifespan，載入所有模板與辨識器。
    import asyncio

    async def run() -> None:
        async with card_detection_api.lifespan(
            card_detection_api.app
        ):
            thread = Thread(
                target=monitor_loop,
                kwargs={
                    "interval": args.interval,
                    "skip_indices": skip_indices,
                    "debug": args.debug,
                },
                daemon=True,
            )

            thread.start()

            print("即時螢幕辨識已啟動")
            print(
                "擷取範圍："
                f"{capture_region}"
            )
            print("按 Ctrl+C 停止")

            try:
                while not stop_event.is_set():
                    await asyncio.sleep(1)

            except asyncio.CancelledError:
                stop_event.set()

            finally:
                stop_event.set()
                thread.join(timeout=3)

    asyncio.run(run())


if __name__ == "__main__":
    terminal_status = "completed"
    try:
        main()
    except KeyboardInterrupt:
        stop_event.set()
        print("\n即時螢幕辨識已停止")
    except Exception as error:
        terminal_status = "failed"
        stop_event.set()
        if api_runtime_status is not None:
            api_runtime_status.update(
                detector="failed",
                error=str(error),
            )
        print(f"\n啟動失敗：{error}")

        try:
            while (
                api_server_thread is not None
                and api_server_thread.is_alive()
            ):
                time.sleep(0.5)
        except KeyboardInterrupt:
            pass
    finally:
        stop_event.set()
        if api_server is not None:
            api_server.should_exit = True
        if api_server_thread is not None:
            api_server_thread.join(timeout=5)
        if (
            producer_event_store is not None
            and producer_session_id is not None
        ):
            try:
                producer_event_store.finish_session(
                    producer_session_id,
                    status=terminal_status,
                )
            except Exception as finish_error:
                print(
                    "無法結束 producer session："
                    f"{finish_error}"
                )
