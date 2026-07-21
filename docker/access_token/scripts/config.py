from pydantic_settings import BaseSettings


class Settings(BaseSettings):
    telegram_api_id: int
    telegram_api_hash: str
    telegram_phone: str | None = None
    telegram_password: str | None = None
    telegram_session_name: str = "session_name"

    class Config:
        env_file = ".env"
        env_file_encoding = "utf-8"


settings = Settings()
