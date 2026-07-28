"""Validated settings for the access-token sidecar."""

from functools import cached_property

from pydantic import Field, SecretStr, field_validator
from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    """Fail-closed sidecar and Telegram account configuration."""

    model_config = SettingsConfigDict(env_file=".env", env_file_encoding="utf-8")

    telegram_api_id: int
    telegram_api_hash: str = Field(repr=False)
    telegram_phone: str | None = None
    telegram_password: str | None = Field(default=None, repr=False)
    telegram_session_name: str
    internal_api_secret: SecretStr = Field(
        validation_alias="ACCESS_TOKEN_INTERNAL_SECRET"
    )
    allowed_bot_usernames: str = Field(
        validation_alias="PIXEL_WORLD_BOT_USERNAME_ALLOWLIST"
    )
    max_concurrent_requests: int = Field(default=1, ge=1, le=4)
    requests_per_minute: int = Field(default=6, ge=1, le=60)
    concurrency_wait_seconds: float = Field(default=0.25, gt=0, le=5)
    transient_retry_after_seconds: int = Field(default=5, ge=1, le=60)

    @field_validator("internal_api_secret")
    @classmethod
    def validate_internal_api_secret(cls, value: SecretStr) -> SecretStr:
        """Reject weak or accidentally empty internal credentials."""
        if len(value.get_secret_value()) < 32:
            raise ValueError("internal_api_secret must contain at least 32 characters")
        return value

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

    @field_validator("allowed_bot_usernames")
    @classmethod
    def validate_allowed_bot_usernames(cls, value: str) -> str:
        """Fail startup when the explicit bot allowlist is empty."""
        if not any(username.strip().removeprefix("@") for username in value.split(",")):
            raise ValueError("allowed_bot_usernames must not be empty")
        return value

    @cached_property
    def allowed_bot_username_set(self) -> frozenset[str]:
        """Return normalized configured bot usernames."""
        usernames = frozenset(
            username.strip().removeprefix("@").lower()
            for username in self.allowed_bot_usernames.split(",")
            if username.strip()
        )
        return usernames


settings = Settings()
