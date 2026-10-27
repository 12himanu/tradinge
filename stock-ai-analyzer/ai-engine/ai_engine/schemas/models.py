from __future__ import annotations

from typing import Any, Literal

from pydantic import BaseModel, Field

ModelStatusValue = Literal["present", "missing", "digest_mismatch", "unknown"]


class ModelStatus(BaseModel):
    role: Literal["vision", "text"]
    tag: str
    expected_digest: str | None
    installed_digest: str | None
    status: ModelStatusValue


class OllamaHealth(BaseModel):
    reachable: bool
    version: str | None = None
    error: str | None = None


class HealthResponse(BaseModel):
    status: Literal["ok", "degraded"]
    engine_version: str
    model_profile: str
    ollama: OllamaHealth
    models: list[ModelStatus]
    storage_readable: bool


class LivenessResponse(BaseModel):
    status: Literal["alive"] = "alive"


class ModelsResponse(BaseModel):
    active_profile: str
    profiles: list[str]
    ollama: OllamaHealth
    models: list[ModelStatus]


class ErrorBody(BaseModel):
    code: str
    message: str
    details: dict[str, Any] = Field(default_factory=dict)


class ErrorResponse(BaseModel):
    error: ErrorBody
