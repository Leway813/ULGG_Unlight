from __future__ import annotations

import hashlib
from dataclasses import asdict, dataclass
from pathlib import Path


PROFILE_ID = "steam_custom_asar_v1"
APP_ASAR_PATH = Path(
    r"C:\Program Files (x86)\Steam\steamapps\common"
    r"\UNLIGHTRevive\win-unpacked\resources\app.asar"
)
EXPECTED_APP_ASAR_SHA256 = (
    "4D1946D552E7B0697BD3BAB0F152587B"
    "FEBF4EF78EEC8E86190D7152765F3262"
)
REFERENCE_WIDTH = 848
REFERENCE_HEIGHT = 760


@dataclass(frozen=True)
class ClientProfileStatus:
    profile_id: str
    supported: bool
    app_asar_path: str
    expected_app_asar_sha256: str
    actual_app_asar_sha256: str | None
    reason: str | None

    def to_dict(self) -> dict[str, object]:
        return asdict(self)


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as file:
        for chunk in iter(lambda: file.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest().upper()


def verify_client_profile(
    app_asar_path: Path = APP_ASAR_PATH,
    expected_sha256: str = EXPECTED_APP_ASAR_SHA256,
) -> ClientProfileStatus:
    if not app_asar_path.is_file():
        return ClientProfileStatus(
            profile_id=PROFILE_ID,
            supported=False,
            app_asar_path=str(app_asar_path),
            expected_app_asar_sha256=expected_sha256,
            actual_app_asar_sha256=None,
            reason="app_asar_not_found",
        )

    try:
        actual_hash = sha256_file(app_asar_path)
    except OSError as error:
        return ClientProfileStatus(
            profile_id=PROFILE_ID,
            supported=False,
            app_asar_path=str(app_asar_path),
            expected_app_asar_sha256=expected_sha256,
            actual_app_asar_sha256=None,
            reason=f"app_asar_unreadable:{error}",
        )

    supported = actual_hash == expected_sha256.upper()
    return ClientProfileStatus(
        profile_id=PROFILE_ID,
        supported=supported,
        app_asar_path=str(app_asar_path),
        expected_app_asar_sha256=expected_sha256.upper(),
        actual_app_asar_sha256=actual_hash,
        reason=None if supported else "app_asar_hash_mismatch",
    )
