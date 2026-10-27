from __future__ import annotations

from typing import Annotated, cast

from fastapi import Depends, Request

from ai_engine.models.ollama import OllamaClient
from ai_engine.models.registry import ModelProfile
from ai_engine.pipeline.config import EngineConfig
from ai_engine.settings import Settings


def get_settings(request: Request) -> Settings:
    return cast(Settings, request.app.state.settings)


def get_ollama(request: Request) -> OllamaClient:
    return cast(OllamaClient, request.app.state.ollama)


def get_profiles(request: Request) -> dict[str, ModelProfile]:
    return cast(dict[str, ModelProfile], request.app.state.profiles)


def get_active_profile(request: Request) -> ModelProfile:
    settings = get_settings(request)
    return get_profiles(request)[settings.model_profile]


def get_engine_version(request: Request) -> str:
    return cast(str, request.app.state.engine_version)


def get_config(request: Request) -> EngineConfig:
    """Validated analysis configuration, loaded once at startup."""
    return cast(EngineConfig, request.app.state.config)


SettingsDep = Annotated[Settings, Depends(get_settings)]
OllamaDep = Annotated[OllamaClient, Depends(get_ollama)]
ProfilesDep = Annotated[dict[str, ModelProfile], Depends(get_profiles)]
ActiveProfileDep = Annotated[ModelProfile, Depends(get_active_profile)]
EngineVersionDep = Annotated[str, Depends(get_engine_version)]
ConfigDep = Annotated[EngineConfig, Depends(get_config)]
