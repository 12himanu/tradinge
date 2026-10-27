from __future__ import annotations

from pathlib import Path
from typing import Literal

from pydantic import Field, SecretStr, field_validator
from pydantic_settings import BaseSettings, SettingsConfigDict

PACKAGE_ROOT = Path(__file__).resolve().parent
PROJECT_ROOT = PACKAGE_ROOT.parent

MIN_SECRET_LENGTH = 32


class Settings(BaseSettings):
    """Engine configuration, read from ENGINE_* environment variables (or ai-engine/.env)."""

    model_config = SettingsConfigDict(
        env_prefix="ENGINE_",
        env_file=".env",
        env_file_encoding="utf-8",
        extra="ignore",
    )

    env: Literal["local", "testing", "production"] = "local"

    hmac_secret: SecretStr
    hmac_max_skew_seconds: int = Field(default=300, ge=1)

    ollama_host: str = "http://localhost:11434"
    ollama_timeout_seconds: float = Field(default=300.0, gt=0)
    ollama_health_timeout_seconds: float = Field(default=2.0, gt=0)
    ollama_connect_retries: int = Field(default=3, ge=0)

    model_profile: str = "gpu_4gb"

    analyst_enabled: bool = True
    """Whether the analyst LLM writes the narrative.

    Off means every report is the template — the same verified facts, no model call. Tests turn it
    off so the suite never depends on a GPU, and it is the switch to reach for when Ollama is busy
    with something else.
    """
    analyst_timeout_seconds: float = Field(default=600.0, gt=0)
    """The analyst is the one long call in the pipeline; the NDJSON stream heartbeats meanwhile."""

    symbol_check_enabled: bool = True
    """Whether the vision model confirms the screenshot shows the symbol being analysed.

    On means one extra model load per analysis, which on the 4 GB profile costs the GPU a swap
    before the analyst runs. It buys the one guarantee the deterministic pipeline cannot give by
    itself: that the verified numbers belong to the chart the user uploaded. Off, the declared
    symbol is taken on trust — which is how a chart of one company gets analysed as another.
    """
    symbol_check_timeout_seconds: float = Field(default=180.0, gt=0)

    max_concurrent_analyses: int = Field(default=2, ge=1, le=16)
    """One GPU model is loaded at a time, so a small number here protects latency."""

    storage_root: Path = PROJECT_ROOT.parent / "storage"
    config_dir: Path = PROJECT_ROOT / "config"

    log_level: Literal["DEBUG", "INFO", "WARNING", "ERROR"] = "INFO"
    log_json: bool = True

    @field_validator("hmac_secret")
    @classmethod
    def _secret_is_strong(cls, value: SecretStr) -> SecretStr:
        if len(value.get_secret_value()) < MIN_SECRET_LENGTH:
            raise ValueError(f"must be at least {MIN_SECRET_LENGTH} characters")
        return value


def load_settings() -> Settings:
    """Build settings from the environment; fails fast if required values are missing."""
    return Settings()
