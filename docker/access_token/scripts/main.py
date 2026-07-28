import json
import logging
import urllib.parse
from contextlib import asynccontextmanager

import uvicorn
from fastapi import FastAPI, HTTPException
from fastapi.responses import JSONResponse
from telethon import TelegramClient
from telethon.tl.functions.messages import RequestWebViewRequest, RequestMainWebViewRequest
from telethon.tl.types import InputUser

from config import settings
from web_app_data import extract_web_app_data

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

client = TelegramClient(
    settings.telegram_session_name,
    settings.telegram_api_id,
    settings.telegram_api_hash
)


async def get_web_view_data(
    bot_username: str,
    mini_app_url: str,
    chat_username: str | None = None,
    start_param: str | None = None
) -> dict | None:
    try:
        bot = await client.get_entity(bot_username)
        user_input = InputUser(user_id=bot.id, access_hash=bot.access_hash)

        if chat_username:
            peer = await client.get_input_entity(chat_username)
        else:
            peer = await client.get_input_entity(bot_username)

        kwargs = {
            "peer": peer,
            "bot": user_input,
            "url": mini_app_url,
            "platform": "android",
            "from_bot_menu": False
        }

        if start_param:
            kwargs["start_param"] = start_param

        result = await client(RequestWebViewRequest(**kwargs))

        raw_data, decoded = extract_web_app_data(result.url)

        return {
            "raw": raw_data,
            "decoded": decoded,
            "parsed": parse_params(decoded),
            "flat": parse_params_flat(decoded)
        }

    except Exception as e:
        logger.error(f"Error: {e}", exc_info=True)
        return None


async def get_main_web_view_data(
    bot_username: str,
    chat_username: str | None = None,
    start_param: str | None = None
) -> dict | None:
    try:
        bot = await client.get_entity(bot_username)
        user_input = InputUser(user_id=bot.id, access_hash=bot.access_hash)

        if chat_username:
            peer = await client.get_input_entity(chat_username)
        else:
            peer = await client.get_input_entity(bot_username)

        kwargs = {
            "peer": peer,
            "bot": user_input,
            "platform": "android"
        }

        if start_param:
            kwargs["start_param"] = start_param

        result = await client(RequestMainWebViewRequest(**kwargs))

        raw_data, decoded = extract_web_app_data(result.url)

        return {
            "raw": raw_data,
            "decoded": decoded,
            "parsed": parse_params(decoded),
            "flat": parse_params_flat(decoded)
        }

    except Exception as e:
        logger.error(f"Error: {e}", exc_info=True)
        return None


def parse_params(params_str: str) -> dict:
    result = {}

    for param in params_str.split('&'):
        if '=' not in param:
            continue

        key, value = param.split('=', 1)
        value = urllib.parse.unquote(value)

        if key == 'user':
            result[key] = json.loads(value)
        elif key == 'tgWebAppDefaultColors':
            result[key] = json.loads(value)
        else:
            result[key] = value

    return result


def parse_params_flat(params_str: str) -> dict:
    result = {}

    for param in params_str.split('&'):
        if '=' not in param:
            continue

        key, value = param.split('=', 1)
        value = urllib.parse.unquote(value)

        result[key] = value

    return result


@asynccontextmanager
async def lifespan(app: FastAPI):
    await client.start(phone=settings.telegram_phone, password=settings.telegram_password)
    yield
    await client.disconnect()


app = FastAPI(lifespan=lifespan)


@app.get("/web_view")
async def web_view(
    bot_username: str,
    mini_app_url: str,
    chat_username: str | None = None,
    start_param: str | None = None
):
    data = await get_web_view_data(bot_username, mini_app_url, chat_username, start_param)
    if not data:
        raise HTTPException(status_code=400, detail="Failed to get token")

    return JSONResponse(content=data)


@app.get("/main_web_view")
async def main_web_view(
    bot_username: str,
    chat_username: str | None = None,
    start_param: str | None = None
):
    data = await get_main_web_view_data(bot_username, chat_username, start_param)
    if not data:
        raise HTTPException(status_code=400, detail="Failed to get token")

    return JSONResponse(content=data)


if __name__ == "__main__":
    uvicorn.run(app, host="0.0.0.0", port=8000)
