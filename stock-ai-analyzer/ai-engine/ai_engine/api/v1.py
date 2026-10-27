from __future__ import annotations

from fastapi import APIRouter, Depends

from ai_engine.api.deps import ActiveProfileDep, OllamaDep, ProfilesDep, SettingsDep
from ai_engine.api.health import probe_models
from ai_engine.api.security import verify_signature
from ai_engine.schemas.models import ModelsResponse

router = APIRouter(prefix="/v1", tags=["v1"], dependencies=[Depends(verify_signature)])


@router.get("/models", response_model=ModelsResponse)
async def models(
    settings: SettingsDep,
    ollama: OllamaDep,
    profiles: ProfilesDep,
    profile: ActiveProfileDep,
) -> ModelsResponse:
    ollama_health, statuses = await probe_models(ollama, profile, settings)
    return ModelsResponse(
        active_profile=profile.name,
        profiles=sorted(profiles),
        ollama=ollama_health,
        models=statuses,
    )
