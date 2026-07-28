"""Access-token authentication and init-data preservation tests."""

import asyncio
import logging
import sys
import urllib.parse
from collections.abc import Awaitable
from pathlib import Path
from typing import Any

import pytest
from fastapi import HTTPException
from pydantic import ValidationError

PROJECT_ROOT = Path(__file__).resolve().parents[1]
SOURCE_DIRECTORY = PROJECT_ROOT / "scripts"
sys.path.insert(0, str(SOURCE_DIRECTORY if SOURCE_DIRECTORY.is_dir() else PROJECT_ROOT))

import main  # noqa: E402
from config import Settings  # noqa: E402

SECRET = "x" * 32
MANDATORY_DATA = (
    "user=%7B%22id%22%3A1%7D&chat_instance=instance&chat_type=private"
    "&auth_date=123&signature=sig%2B%2F%3D&hash=hash-value"
)


class AvailableSlot:
    def __init__(self) -> None:
        self.released = False

    async def acquire(self) -> bool:
        return True

    def release(self) -> None:
        self.released = True


def run(coroutine: Awaitable[Any]) -> Any:
    return asyncio.run(coroutine)


def test_only_hardened_main_web_view_is_exposed() -> None:
    assert {route.path for route in main.app.routes} == {"/main_web_view"}


@pytest.mark.parametrize(
    "authorization,accepted",
    [
        (f"Bearer {SECRET}", True),
        (None, False),
        ("", False),
        ("Bearer wrong", False),
        (f"Basic {SECRET}", False),
    ],
)
def test_internal_bearer_authentication(
    authorization: str | None,
    accepted: bool,
) -> None:
    if accepted:
        assert run(main._authorize(authorization)) is None
        return

    with pytest.raises(HTTPException) as raised:
        run(main._authorize(authorization))

    assert raised.value.status_code == 401
    assert raised.value.headers == {"WWW-Authenticate": "Bearer"}


def test_configuration_requires_a_strong_internal_secret(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    monkeypatch.setenv("TELEGRAM_API_ID", "1")
    monkeypatch.setenv("TELEGRAM_API_HASH", "api-hash")
    monkeypatch.setenv("TELEGRAM_SESSION_NAME", "session_data/test")
    monkeypatch.setenv("PIXEL_WORLD_BOT_USERNAME_ALLOWLIST", "pixelworld")

    for secret in ("", "short"):
        monkeypatch.setenv("ACCESS_TOKEN_INTERNAL_SECRET", secret)
        with pytest.raises(ValidationError):
            Settings(_env_file=None)

    monkeypatch.setenv("ACCESS_TOKEN_INTERNAL_SECRET", SECRET)
    assert Settings(_env_file=None).internal_api_secret.get_secret_value() == SECRET


def test_extract_init_data_preserves_exact_signed_string() -> None:
    decoded = (
        "extra=first%26value&user=%7B%22id%22%3A1%7D&chat_instance=i%3D1"
        "&extra=second%2Bvalue&chat_type=private&auth_date=123"
        "&signature=sig%2B%2F%3D&hash=hash%26value&future_signed=x%3Dy"
    )
    encoded = urllib.parse.quote(decoded, safe="")
    url = (
        f"https://example.invalid/#tgWebAppData={encoded}"
        "&tgWebAppVersion=8.0&tgWebAppPlatform=android"
    )

    assert main._extract_init_data(url) == decoded


@pytest.mark.parametrize(
    "url",
    [
        "https://example.invalid/",
        "https://example.invalid/#tgWebAppData=",
        "https://example.invalid/#tgWebAppData=auth_date%3D123",
        "https://example.invalid/#tgWebAppData=user%3Dx%26broken",
        (
            "https://example.invalid/#tgWebAppData="
            + urllib.parse.quote(MANDATORY_DATA + "&hash=duplicate", safe="")
        ),
    ],
)
def test_extract_init_data_rejects_invalid_data(url: str) -> None:
    with pytest.raises(main.InvalidInitDataError):
        main._extract_init_data(url)


def test_allowed_endpoint_returns_exact_data(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    async def no_rate_limit() -> None:
        return None

    async def telegram_result(bot_username: str) -> str:
        assert bot_username == "pixelworld"
        return MANDATORY_DATA

    slot = AvailableSlot()
    monkeypatch.setattr(main.request_limiter, "acquire", no_rate_limit)
    monkeypatch.setattr(main, "telegram_slots", slot)
    monkeypatch.setattr(main, "get_main_web_view_data", telegram_result)

    assert run(main.main_web_view("@PixelWorld")) == {"decoded": MANDATORY_DATA}
    assert slot.released is True


def test_unlisted_bot_is_rejected_before_telegram(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    called = False

    async def telegram_result(_: str) -> str:
        nonlocal called
        called = True
        return MANDATORY_DATA

    monkeypatch.setattr(main, "get_main_web_view_data", telegram_result)

    with pytest.raises(HTTPException) as raised:
        run(main.main_web_view("other_bot"))

    assert raised.value.status_code == 403
    assert called is False


def test_failures_do_not_log_credentials(
    monkeypatch: pytest.MonkeyPatch,
    caplog: pytest.LogCaptureFixture,
) -> None:
    async def no_rate_limit() -> None:
        return None

    async def fail(_: str) -> str:
        raise main.InvalidInitDataError(
            "decoded-secret&signature=signature-secret&hash=hash-secret"
        )

    monkeypatch.setattr(main.request_limiter, "acquire", no_rate_limit)
    monkeypatch.setattr(main, "telegram_slots", AvailableSlot())
    monkeypatch.setattr(main, "get_main_web_view_data", fail)
    caplog.set_level(logging.WARNING, logger=main.__name__)

    with pytest.raises(HTTPException):
        run(main.main_web_view("pixelworld"))

    assert "decoded-secret" not in caplog.text
    assert "signature-secret" not in caplog.text
    assert "hash-secret" not in caplog.text
