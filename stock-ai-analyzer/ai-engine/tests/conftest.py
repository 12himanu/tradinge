from __future__ import annotations

import time
from collections.abc import Callable
from pathlib import Path
from typing import Any

import httpx
import pytest
from fastapi import FastAPI
from fastapi.testclient import TestClient
from pydantic import SecretStr

from ai_engine.api.security import sign
from ai_engine.main import create_app
from ai_engine.models.ollama import OllamaClient
from ai_engine.pipeline.config import EngineConfig, load_engine_config
from ai_engine.settings import PROJECT_ROOT, Settings

TEST_SECRET = "test-secret-" + "x" * 32
DIGEST_VISION = "a" * 64
DIGEST_TEXT = "b" * 64

Handler = Callable[[httpx.Request], httpx.Response]


def make_settings(tmp_path: Path, **overrides: Any) -> Settings:
    values: dict[str, Any] = {
        "env": "testing",
        # No test may depend on a GPU being present, or on how fast it is.
        "analyst_enabled": False,
        "symbol_check_enabled": False,
        "hmac_secret": SecretStr(TEST_SECRET),
        "storage_root": tmp_path,
        "log_json": False,
        "log_level": "WARNING",
        "model_profile": "gpu_4gb",
    }
    values.update(overrides)
    return Settings(**values)


def make_ollama(handler: Handler) -> OllamaClient:
    return OllamaClient(
        "http://ollama.test",
        timeout_seconds=5,
        connect_retries=1,
        retry_backoff_seconds=0,
        transport=httpx.MockTransport(handler),
    )


def ollama_handler(models: list[dict[str, Any]] | None = None) -> Handler:
    """Fake Ollama that reports the given installed models."""
    installed = (
        models
        if models is not None
        else [
            {"name": "qwen2.5vl:3b", "digest": DIGEST_VISION, "size": 3_200_000_000},
            {"name": "qwen3:4b", "digest": DIGEST_TEXT, "size": 2_600_000_000},
        ]
    )

    def handler(request: httpx.Request) -> httpx.Response:
        if request.url.path == "/api/version":
            return httpx.Response(200, json={"version": "0.12.0"})
        if request.url.path == "/api/tags":
            return httpx.Response(200, json={"models": installed})
        return httpx.Response(404, json={"error": "unknown route"})

    return handler


def unreachable_handler(request: httpx.Request) -> httpx.Response:
    raise httpx.ConnectError("connection refused", request=request)


def signed_headers(
    body: bytes = b"", *, timestamp: int | None = None, secret: str = TEST_SECRET
) -> dict[str, str]:
    ts = str(timestamp if timestamp is not None else int(time.time()))
    return {"X-Timestamp": ts, "X-Signature": sign(secret, ts, body)}


@pytest.fixture
def app_factory(tmp_path: Path) -> Callable[..., FastAPI]:
    def factory(handler: Handler | None = None, **setting_overrides: Any) -> FastAPI:
        return create_app(
            settings=make_settings(tmp_path, **setting_overrides),
            ollama=make_ollama(handler or ollama_handler()),
        )

    return factory


@pytest.fixture
def client(app_factory: Callable[..., FastAPI]) -> TestClient:
    return TestClient(app_factory())


@pytest.fixture(scope="session")
def engine_config() -> EngineConfig:
    """The real `config/*.yaml`: the tolerances the tests assert against are the shipped ones."""
    return load_engine_config(PROJECT_ROOT / "config")
