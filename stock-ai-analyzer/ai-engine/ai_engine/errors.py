from __future__ import annotations

from typing import Any


class EngineError(Exception):
    """Error returned to callers as {"error": {"code", "message", "details"}}."""

    def __init__(
        self,
        code: str,
        message: str,
        *,
        status_code: int = 500,
        details: dict[str, Any] | None = None,
    ) -> None:
        super().__init__(message)
        self.code = code
        self.message = message
        self.status_code = status_code
        self.details = details or {}


class ConfigurationError(Exception):
    """Invalid engine configuration; raised at startup so the service fails fast."""
