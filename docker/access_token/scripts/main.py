"""Private API for obtaining Pixel World Telegram Mini App init data."""

import asyncio
import logging
import time
import urllib.parse
from collections import deque
from collections.abc import AsyncIterator
from contextlib import asynccontextmanager
from typing import Final

import uvicorn
from fastapi import FastAPI, HTTPException, Query
from telethon import TelegramClient, errors
from telethon.tl.functions.messages import RequestMainWebViewRequest
from telethon.tl.types import InputUser

from config import settings

logging.basicConfig(level=logging.INFO)
logging.getLogger("telethon").setLevel(logging.WARNING)
logger = logging.getLogger(__name__)

MANDATORY_INIT_DATA_FIELDS: Final[frozenset[str]] = frozenset(
    {"user", "chat_instance", "chat_type", "auth_date", "signature", "hash"}
)

client: TelegramClient | None = None


class InvalidInitDataError(ValueError):
    """Raised when Telegram returns malformed or incomplete init data."""


class TelegramSessionUnavailableError(RuntimeError):
    """Raised when the persisted Telegram session is not connected."""


class RequestRateLimiter:
    """An in-process fixed-window limiter protecting the Telegram account."""

    def __init__(self, requests_per_minute: int) -> None:
        self._limit = requests_per_minute
        self._requests: deque[float] = deque()
        self._lock = asyncio.Lock()

    async def acquire(self) -> int | None:
        """Record a request or return the number of seconds before retrying."""
        async with self._lock:
            now = time.monotonic()
            while self._requests and now - self._requests[0] >= 60:
                self._requests.popleft()

            if len(self._requests) >= self._limit:
                return max(1, int(61 - (now - self._requests[0])))

            self._requests.append(now)
            return None


request_limiter = RequestRateLimiter(settings.requests_per_minute)
telegram_slots = asyncio.Semaphore(settings.max_concurrent_requests)


def _extract_init_data(url: str) -> str:
    """Extract and validate the exact decoded Telegram init-data query string."""
    fragment = urllib.parse.urlsplit(url).fragment
    encoded = next(
        (
            part.removeprefix("tgWebAppData=")
            for part in fragment.split("&")
            if part.startswith("tgWebAppData=")
        ),
        None,
    )
    if not encoded:
        raise InvalidInitDataError("Telegram response did not contain init data.")

    decoded = urllib.parse.unquote(encoded)
    try:
        pairs = urllib.parse.parse_qsl(
            decoded,
            keep_blank_values=True,
            strict_parsing=True,
        )
    except ValueError as exception:
        raise InvalidInitDataError("Telegram init data is malformed.") from exception

    values: dict[str, list[str]] = {}
    for key, value in pairs:
        values.setdefault(key, []).append(value)

    if any(
        len(values.get(field, [])) != 1 or not values[field][0]
        for field in MANDATORY_INIT_DATA_FIELDS
    ):
        raise InvalidInitDataError("Telegram init data is missing auth fields.")

    return decoded


async def get_main_web_view_data(bot_username: str) -> str:
    """Request Pixel World init data from Telegram and preserve it exactly."""
    if client is None:
        raise TelegramSessionUnavailableError("Telegram client is not connected.")

    bot = await client.get_entity(bot_username)
    user_input = InputUser(user_id=bot.id, access_hash=bot.access_hash)
    peer = await client.get_input_entity(bot_username)
    result = await client(
        RequestMainWebViewRequest(peer=peer, bot=user_input, platform="android")
    )
    return _extract_init_data(result.url)


@asynccontextmanager
async def lifespan(_: FastAPI) -> AsyncIterator[None]:
    """Connect the persisted Telegram session for the process lifetime."""
    global client
    client = TelegramClient(
        settings.telegram_session_name,
        settings.telegram_api_id,
        settings.telegram_api_hash,
    )
    await client.connect()
    if not await client.is_user_authorized():
        await client.disconnect()
        client = None
        raise RuntimeError(
            "Telegram session is not authorized; run the offline authentication helper."
        )
    try:
        yield
    finally:
        await client.disconnect()
        client = None


app = FastAPI(
    lifespan=lifespan,
    docs_url=None,
    redoc_url=None,
    openapi_url=None,
)


@app.get("/main_web_view")
async def main_web_view(
    bot_username: str = Query(min_length=1, max_length=64),
) -> dict[str, str]:
    """Return exact init data for the requested Telegram Mini App bot."""
    normalized_username = bot_username.removeprefix("@").lower()

    retry_after = await request_limiter.acquire()
    if retry_after is not None:
        raise HTTPException(
            status_code=429,
            detail="Request rate exceeded.",
            headers={"Retry-After": str(retry_after)},
        )

    try:
        await asyncio.wait_for(
            telegram_slots.acquire(), timeout=settings.concurrency_wait_seconds
        )
    except TimeoutError as exception:
        raise HTTPException(
            status_code=429,
            detail="Telegram request already in progress.",
            headers={"Retry-After": "1"},
        ) from exception

    try:
        decoded = await get_main_web_view_data(normalized_username)
    except errors.FloodWaitError as exception:
        raise HTTPException(
            status_code=429,
            detail="Telegram rate limit reached.",
            headers={"Retry-After": str(max(1, exception.seconds))},
        ) from exception
    except (
        TimeoutError,
        ConnectionError,
        OSError,
        errors.ServerError,
        TelegramSessionUnavailableError,
    ) as exception:
        logger.warning("Transient Telegram failure (%s).", type(exception).__name__)
        raise HTTPException(
            status_code=503,
            detail="Telegram is temporarily unavailable.",
            headers={"Retry-After": str(settings.transient_retry_after_seconds)},
        ) from exception
    except (InvalidInitDataError, ValueError, TypeError) as exception:
        logger.warning("Invalid Telegram response (%s).", type(exception).__name__)
        raise HTTPException(
            status_code=422, detail="Invalid Telegram data."
        ) from exception
    except errors.RPCError as exception:
        logger.warning("Telegram RPC failure (%s).", type(exception).__name__)
        raise HTTPException(
            status_code=502, detail="Telegram request failed."
        ) from exception
    finally:
        telegram_slots.release()

    return {"decoded": decoded}


if __name__ == "__main__":
    uvicorn.run(app, host="0.0.0.0", port=8000)
