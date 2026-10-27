"""Talks to a real Ollama server. Run with RUN_OLLAMA_TESTS=1 (and ENGINE_OLLAMA_HOST if not localhost)."""

from __future__ import annotations

import os

import pytest

from ai_engine.models.ollama import OllamaClient

pytestmark = [
    pytest.mark.ollama,
    pytest.mark.anyio,
    pytest.mark.skipif(os.environ.get("RUN_OLLAMA_TESTS") != "1", reason="set RUN_OLLAMA_TESTS=1"),
]


@pytest.fixture
def anyio_backend() -> str:
    return "asyncio"


async def test_real_ollama_reports_version_and_models() -> None:
    client = OllamaClient(os.environ.get("ENGINE_OLLAMA_HOST", "http://localhost:11434"), timeout_seconds=10)
    try:
        assert await client.version()
        assert isinstance(await client.list_models(), list)
    finally:
        await client.aclose()
