from pydantic import Field, field_validator
from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    """Fail-closed sidecar and Telegram account configuration."""

    model_config = SettingsConfigDict(env_file=".env", env_file_encoding="utf-8")

    telegram_api_id: int
    telegram_api_hash: str = Field(repr=False)
    telegram_phone: str | None = None
    telegram_password: str | None = Field(default=None, repr=False)
    telegram_session_name: str
    max_concurrent_requests: int = Field(default=1, ge=1, le=4)
    requests_per_minute: int = Field(default=6, ge=1, le=60)
    concurrency_wait_seconds: float = Field(default=0.25, gt=0, le=5)
    transient_retry_after_seconds: int = Field(default=5, ge=1, le=60)

    @field_validator("telegram_session_name")
    @classmethod
    def validate_session_path(cls, value: str) -> str:
        """Require the session to live in the container's persisted volume."""
        if not value.startswith(
            ("/app/session_data/", "session_data/")
        ) or value.endswith("/"):
            raise ValueError(
                "telegram_session_name must be a file path under session_data"
            )
        return value

settings = Settings()
