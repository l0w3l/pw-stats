"""Deterministic test bootstrap for the access-token sidecar."""

import os
import sys
from pathlib import Path

SCRIPTS_DIRECTORY = Path(__file__).resolve().parents[1] / "scripts"
sys.path.insert(0, str(SCRIPTS_DIRECTORY))

os.environ.setdefault("TELEGRAM_API_ID", "1")
os.environ.setdefault("TELEGRAM_API_HASH", "test-api-hash")
os.environ.setdefault("TELEGRAM_SESSION_NAME", "session_data/test")
os.environ.setdefault("PIXEL_WORLD_BOT_USERNAME_ALLOWLIST", "pixelworld")
