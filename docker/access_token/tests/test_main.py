"""Security and init-data preservation tests for the access-token sidecar."""

import asyncio
import logging
import sys
import urllib.parse
from collections.abc import Awaitable, Callable
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
BOT = "pixelworld"
MANDATORY_DATA = (
    "user=%7B%22id%22%3A1%7D&chat_instance=instance&chat_type=private"
    "&auth_date=123&signature=sig%2B%2F%3D&hash=hash-value"
)


class AvailableSlot:
    """Non-blocking semaphore substitute that is independent of event loops."""

    def __init__(self) -> None:
        self.released = False

    async def acquire(self) -> bool:
        return True

    def release(self) -> None:
        self.released = True


async def no_rate_limit() -> None:
    return None


def run(coroutine: Awaitable[Any]) -> Any:
    """Run one isolated async test action."""
    return asyncio.run(coroutine)


def valid_settings_environment(monkeypatch: pytest.MonkeyPatch) -> None:
    """Arrange the minimum valid environment for a fresh Settings instance."""
    monkeypatch.setenv("TELEGRAM_API_ID", "1")
    monkeypatch.setenv("TELEGRAM_API_HASH", "api-hash")
    monkeypatch.setenv("TELEGRAM_SESSION_NAME", "session_data/test")
    monkeypatch.setenv("PIXEL_WORLD_BOT_USERNAME_ALLOWLIST", BOT)


def arrange_endpoint(
    monkeypatch: pytest.MonkeyPatch,
    action: Callable[[str], Awaitable[str]],
) -> AvailableSlot:
    """Arrange deterministic endpoint dependencies without Telegram or timing."""
    slot = AvailableSlot()
    monkeypatch.setattr(main.request_limiter, "acquire", no_rate_limit)
    monkeypatch.setattr(main, "telegram_slots", slot)
    monkeypatch.setattr(main, "get_main_web_view_data", action)
    return slot


# Objective: only the hardened operation is reachable; arbitrary URLs stay absent.
def test_only_main_web_view_operation_is_exposed() -> None:
    # Arrange
    expected_routes = {"/main_web_view"}

    # Act
    actual_routes = {route.path for route in main.app.routes}

    # Assert
    assert actual_routes == expected_routes
    assert "/web_view" not in actual_routes


# Objective: authenticate a caller holding the exact internal bearer secret.
def test_internal_bearer_auth_accepts_the_configured_secret() -> None:
    # Arrange
    authorization = f"Bearer {SECRET}"

    # Act
    result = run(main._authorize(authorization))

    # Assert
    assert result is None


@pytest.mark.parametrize(
    "authorization",
    [None, "", "Bearer wrong", f"Basic {SECRET}", f"Bearer {SECRET}extra"],
)
def test_internal_bearer_auth_rejects_missing_or_wrong_credentials(
    authorization: str | None,
) -> None:
    # Arrange
    expected_status = 401

    # Act
    with pytest.raises(HTTPException) as raised:
        run(main._authorize(authorization))

    # Assert
    assert raised.value.status_code == expected_status
    assert raised.value.headers == {"WWW-Authenticate": "Bearer"}


# Objective: startup accepts a strong explicitly configured internal secret.
def test_configuration_accepts_a_strong_internal_secret(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    # Arrange
    valid_settings_environment(monkeypatch)
    monkeypatch.setenv("ACCESS_TOKEN_INTERNAL_SECRET", SECRET)

    # Act
    configured = Settings(_env_file=None)

    # Assert
    assert configured.internal_api_secret.get_secret_value() == SECRET


@pytest.mark.parametrize("secret", [None, "", "short-secret"])
def test_configuration_fails_closed_for_missing_or_short_internal_secret(
    monkeypatch: pytest.MonkeyPatch,
    secret: str | None,
) -> None:
    # Arrange
    valid_settings_environment(monkeypatch)
    if secret is None:
        monkeypatch.delenv("ACCESS_TOKEN_INTERNAL_SECRET", raising=False)
    else:
        monkeypatch.setenv("ACCESS_TOKEN_INTERNAL_SECRET", secret)

    # Act
    with pytest.raises(ValidationError) as raised:
        Settings(_env_file=None)

    # Assert
    assert "secret" in str(raised.value).lower()


# Objective: normalize only explicitly listed bot usernames.
def test_bot_allowlist_normalizes_explicit_entries(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    # Arrange
    valid_settings_environment(monkeypatch)
    monkeypatch.setenv("ACCESS_TOKEN_INTERNAL_SECRET", SECRET)
    monkeypatch.setenv(
        "PIXEL_WORLD_BOT_USERNAME_ALLOWLIST", " @PixelWorld,Other_Bot "
    )

    # Act
    configured = Settings(_env_file=None)

    # Assert
    assert configured.allowed_bot_username_set == frozenset(
        {"pixelworld", "other_bot"}
    )


def test_bot_allowlist_rejects_unlisted_bot_before_telegram(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    # Arrange
    called = False

    async def unexpected_call(_: str) -> str:
        nonlocal called
        called = True
        return MANDATORY_DATA

    monkeypatch.setattr(main, "get_main_web_view_data", unexpected_call)

    # Act
    with pytest.raises(HTTPException) as raised:
        run(main.main_web_view("pixelworld_evil"))

    # Assert
    assert raised.value.status_code == 403
    assert called is False


# Objective: preserve Telegram's signed decoded bytes rather than rebuilding fields.
def test_extract_init_data_preserves_order_reserved_characters_and_duplicates() -> None:
    # Arrange
    decoded = (
        "extra=first%26value&user=%7B%22id%22%3A1%7D&chat_instance=i%3D1"
        "&extra=second%2Bvalue&chat_type=private&auth_date=123"
        "&signature=sig%2B%2F%3D&hash=hash%26value&future_signed=x%3Dy"
    )
    encoded = urllib.parse.quote(decoded, safe="")
    credential_url = (
        f"https://example.invalid/path#tgWebAppData={encoded}"
        "&tgWebAppVersion=8.0&tgWebAppPlatform=android"
    )

    # Act
    result = main._extract_init_data(credential_url)

    # Assert
    assert result == decoded


@pytest.mark.parametrize(
    "credential_url",
    [
        "https://example.invalid/no-fragment",
        "https://example.invalid/#tgWebAppData=",
        "https://example.invalid/#tgWebAppData=auth_date%3D123",
        "https://example.invalid/#tgWebAppData=user%3Dx%26broken",
        (
            "https://example.invalid/#tgWebAppData="
            + urllib.parse.quote(MANDATORY_DATA + "&hash=duplicate", safe="")
        ),
    ],
)
def test_extract_init_data_rejects_malformed_or_ambiguous_data(
    credential_url: str,
) -> None:
    # Arrange
    expected_error = main.InvalidInitDataError

    # Act
    with pytest.raises(expected_error) as raised:
        main._extract_init_data(credential_url)

    # Assert
    assert str(raised.value)


# Objective: a valid allowed request succeeds with exact init data and no live session.
def test_main_web_view_returns_mocked_exact_data(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    # Arrange
    async def success(bot_username: str) -> str:
        assert bot_username == BOT
        return MANDATORY_DATA

    slot = arrange_endpoint(monkeypatch, success)

    # Act
    response = run(main.main_web_view("@PixelWorld"))

    # Assert
    assert response == {"decoded": MANDATORY_DATA}
    assert slot.released is True


def test_transient_network_failure_is_retryable(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    # Arrange
    async def failure(_: str) -> str:
        raise ConnectionError("network down")

    slot = arrange_endpoint(monkeypatch, failure)

    # Act
    with pytest.raises(HTTPException) as raised:
        run(main.main_web_view(BOT))

    # Assert
    assert raised.value.status_code == 503
    assert raised.value.headers == {"Retry-After": "5"}
    assert slot.released is True


def test_disconnected_session_is_retryable_without_a_live_telegram_session(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    # Arrange
    slot = AvailableSlot()
    monkeypatch.setattr(main.request_limiter, "acquire", no_rate_limit)
    monkeypatch.setattr(main, "telegram_slots", slot)
    monkeypatch.setattr(main, "client", None)

    # Act
    with pytest.raises(HTTPException) as raised:
        run(main.main_web_view(BOT))

    # Assert
    assert raised.value.status_code == 503
    assert raised.value.headers == {"Retry-After": "5"}
    assert slot.released is True


def test_flood_wait_is_retryable_with_telegram_delay(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    # Arrange
    class FloodWait(Exception):
        seconds = 17

    async def failure(_: str) -> str:
        raise FloodWait()

    monkeypatch.setattr(main.errors, "FloodWaitError", FloodWait)
    slot = arrange_endpoint(monkeypatch, failure)

    # Act
    with pytest.raises(HTTPException) as raised:
        run(main.main_web_view(BOT))

    # Assert
    assert raised.value.status_code == 429
    assert raised.value.headers == {"Retry-After": "17"}
    assert slot.released is True


def test_permanent_invalid_init_data_is_not_reported_as_transient(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    # Arrange
    async def failure(_: str) -> str:
        raise main.InvalidInitDataError("invalid input")

    arrange_endpoint(monkeypatch, failure)

    # Act
    with pytest.raises(HTTPException) as raised:
        run(main.main_web_view(BOT))

    # Assert
    assert raised.value.status_code == 422
    assert raised.value.headers is None


@pytest.mark.parametrize(
    "failure",
    [
        ConnectionError(
            "https://example.invalid/#tgWebAppData=decoded-secret&hash=hash-secret"
        ),
        main.InvalidInitDataError(
            "decoded-secret&signature=signature-secret&hash=hash-secret"
        ),
    ],
)
def test_logs_never_contain_telegram_credentials(
    monkeypatch: pytest.MonkeyPatch,
    caplog: pytest.LogCaptureFixture,
    failure: Exception,
) -> None:
    # Arrange
    async def fail_with_secret(_: str) -> str:
        raise failure

    arrange_endpoint(monkeypatch, fail_with_secret)
    caplog.set_level(logging.WARNING, logger=main.__name__)

    # Act
    with pytest.raises(HTTPException):
        run(main.main_web_view(BOT))

    # Assert
    logs = caplog.text
    assert "https://" not in logs
    assert "decoded-secret" not in logs
    assert "hash-secret" not in logs
    assert "signature-secret" not in logs
