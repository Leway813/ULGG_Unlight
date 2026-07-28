# -*- mode: python ; coding: utf-8 -*-

from pathlib import Path


detector_root = Path(SPECPATH)
tracker_root = detector_root.parent
tracker_files = [
    "control.html",
    "field-decks.js",
    "tracker.js",
    "tracker-db.js",
    "tracker-api.js",
    "tracker-domain-reducer.js",
    "observation-poller.js",
]
datas = [
    (str(tracker_root / name), "tracker")
    for name in tracker_files
]
datas.append((str(tracker_root / "assets"), "tracker/assets"))

hiddenimports = [
    "tracker_api_server",
    "unlight_websocket_sniffer",
    "uvicorn.logging",
    "uvicorn.loops.auto",
    "uvicorn.protocols.http.auto",
    "uvicorn.protocols.websockets.auto",
    "uvicorn.lifespan.on",
]

a = Analysis(
    ["tracker_launcher.py"],
    pathex=[str(detector_root)],
    binaries=[],
    datas=datas,
    hiddenimports=hiddenimports,
    hookspath=[],
    hooksconfig={},
    runtime_hooks=[],
    excludes=[
        "mss",
        "cv2",
        "numpy",
        "PIL",
    ],
    noarchive=False,
    optimize=0,
)
pyz = PYZ(a.pure)

exe = EXE(
    pyz,
    a.scripts,
    [],
    exclude_binaries=True,
    name="UnlightTrackerLauncher",
    debug=False,
    bootloader_ignore_signals=False,
    strip=False,
    upx=True,
    console=True,
)

coll = COLLECT(
    exe,
    a.binaries,
    a.datas,
    strip=False,
    upx=True,
    upx_exclude=[],
    name="UnlightTrackerLauncher",
)
