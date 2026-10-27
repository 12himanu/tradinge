from __future__ import annotations

import os

from fastapi import APIRouter

from ai_engine.api.deps import ActiveProfileDep, EngineVersionDep, OllamaDep, SettingsDep
from ai_engine.models.ollama import InstalledModel, OllamaClient, OllamaError
from ai_engine.models.registry import ModelProfile, resolve_status
from ai_engine.schemas.models import HealthResponse, LivenessResponse, ModelStatus, OllamaHealth
from ai_engine.settings import Settings

router = APIRouter(tags=["health"])


@router.get("/livez", response_model=LivenessResponse)
async def livez() -> LivenessResponse:
    """Process liveness only; never touches dependencies (used by the Docker healthcheck)."""
    return LivenessResponse()


@router.get("/health", response_model=HealthResponse)
async def health(
    settings: SettingsDep,
    ollama: OllamaDep,
    profile: ActiveProfileDep,
    engine_version: EngineVersionDep,
) -> HealthResponse:
    """Readiness: is Ollama reachable, are the profile's models installed, is storage readable."""
    ollama_health, models = await probe_models(ollama, profile, settings)
    storage_readable = settings.storage_root.is_dir() and os.access(settings.storage_root, os.R_OK)
    ready = ollama_health.reachable and storage_readable and all(m.status == "present" for m in models)
    return HealthResponse(
        status="ok" if ready else "degraded",
        engine_version=engine_version,
        model_profile=profile.name,
        ollama=ollama_health,
        models=models,
        storage_readable=storage_readable,
    )


async def probe_models(
    ollama: OllamaClient, profile: ModelProfile, settings: Settings
) -> tuple[OllamaHealth, list[ModelStatus]]:
    """Single fast probe (no retries) shared by /health and /v1/models."""
    timeout = settings.ollama_health_timeout_seconds
    installed: list[InstalledModel] | None
    try:
        version = await ollama.version(timeout_s=timeout, retry=False)
        installed = await ollama.list_models(timeout_s=timeout, retry=False)
        ollama_health = OllamaHealth(reachable=True, version=version)
    except OllamaError as exc:
        installed = None
        ollama_health = OllamaHealth(reachable=False, error=str(exc))
    return ollama_health, [resolve_status(spec, installed) for spec in profile.specs()]
