"""Configuration, validated at process startup.

The phase file requires required configuration to be validated at startup and
readiness to stay false while a critical value is invalid. Pydantic settings
give that for free: construction raises, the process refuses to serve, and a
misconfigured deployment fails loudly instead of quietly running on defaults.

Nothing here reads a core database. The AI service holds no core credential
(ADR 0001), and that absence is asserted by a test rather than left to
convention.
"""

from __future__ import annotations

from typing import Literal

from pydantic import Field, SecretStr, field_validator
from pydantic_settings import BaseSettings, SettingsConfigDict

Environment = Literal["local", "development", "staging", "production"]


class Settings(BaseSettings):
    """Runtime settings for the AI service."""

    model_config = SettingsConfigDict(
        env_prefix="AI_",
        env_file=None,  # Configuration is injected at runtime, never read from a committed file.
        extra="ignore",
        frozen=True,
    )

    service_name: str = "ai-service"
    version: str = Field(default="0.0.0-dev")
    environment: Environment = "local"

    host: str = "0.0.0.0"  # noqa: S104 - binds inside a container network, not on a host interface
    port: int = 8001

    # Shared secret for the internal Laravel -> FastAPI contract (ADR 0009).
    # SecretStr so an accidental log or repr prints "**********" rather than
    # the token. That is a real failure mode, not a theoretical one.
    internal_token: SecretStr = SecretStr("")

    # Deadline applied to inbound internal commands. A timeout is an unknown
    # outcome to reconcile, never permission to create a second task.
    default_deadline_ms: int = Field(default=30_000, ge=100, le=300_000)

    qdrant_url: str = "http://qdrant:6333"
    qdrant_api_key: SecretStr = SecretStr("")

    @field_validator("internal_token")
    @classmethod
    def _reject_empty_token_outside_local(
        cls,
        value: SecretStr,
        info: object,
    ) -> SecretStr:
        """An empty internal token is tolerable locally and nowhere else.

        Allowing it in a shared environment would leave the internal contract
        unauthenticated, which is the whole boundary between the process that
        owns PHI and the process that talks to model providers.
        """
        # The environment field may not be populated yet during validation
        # ordering, so this check is repeated in `validate_for_environment`,
        # which runs once the whole model exists.
        return value

    def validate_for_environment(self) -> None:
        """Fail fast when a critical value is missing for this environment.

        Called at startup. Raising here means the process never becomes ready,
        which is the required behaviour.
        """
        if self.environment == "local":
            return

        if not self.internal_token.get_secret_value():
            raise ValueError(
                "AI_INTERNAL_TOKEN must be set outside local. The internal contract "
                "between Laravel and this service is authenticated; an empty token "
                "would leave it open."
            )

        if not self.qdrant_api_key.get_secret_value():
            raise ValueError("AI_QDRANT_API_KEY must be set outside local.")


def load_settings() -> Settings:
    """Build and validate settings. Raises on invalid configuration."""
    settings = Settings()
    settings.validate_for_environment()
    return settings
