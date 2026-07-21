import asyncio
from telethon import TelegramClient
from config import settings


async def main():
    client = TelegramClient(
        settings.telegram_session_name,
        settings.telegram_api_id,
        settings.telegram_api_hash
    )

    await client.start(
        phone=lambda: settings.telegram_phone or input("Enter phone (+1234567890): "),
        code_callback=lambda: input("Enter code from Telegram: "),
        password=lambda: settings.telegram_password or input("Enter 2FA password (if any): ") or None
    )

    print(f"\nSession created: {settings.telegram_session_name}.session")
    await client.disconnect()


if __name__ == "__main__":
    asyncio.run(main())
