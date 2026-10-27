from __future__ import annotations

import json

import httpx
import pytest

from ai_engine.models.ollama import (
    OllamaError,
    OllamaModelNotFoundError,
    OllamaUnavailableError,
)
from tests.conftest import make_ollama, ollama_handler

pytestmark = pytest.mark.anyio


@pytest.fixture
def anyio_backend() -> str:
    return "asyncio"


async def test_list_models_parses_tags() -> None:
    client = make_ollama(ollama_handler())
    models = await client.list_models()
    assert [m.name for m in models] == ["qwen2.5vl:3b", "qwen3:4b"]
    assert models[0].size_bytes == 3_200_000_000


async def test_connect_errors_are_retried_then_raised() -> None:
    calls = 0

    def handler(request: httpx.Request) -> httpx.Response:
        nonlocal calls
        calls += 1
        raise httpx.ConnectError("refused", request=request)

    client = make_ollama(handler)  # connect_retries=1 -> 2 attempts
    with pytest.raises(OllamaUnavailableError):
        await client.version()
    assert calls == 2


async def test_retry_can_be_disabled() -> None:
    calls = 0

    def handler(request: httpx.Request) -> httpx.Response:
        nonlocal calls
        calls += 1
        raise httpx.ConnectError("refused", request=request)

    with pytest.raises(OllamaUnavailableError):
        await make_ollama(handler).version(retry=False)
    assert calls == 1


async def test_transient_failure_recovers() -> None:
    attempts = 0

    def handler(request: httpx.Request) -> httpx.Response:
        nonlocal attempts
        attempts += 1
        if attempts == 1:
            raise httpx.ConnectError("refused", request=request)
        return httpx.Response(200, json={"version": "0.12.0"})

    assert await make_ollama(handler).version() == "0.12.0"


async def test_missing_model_raises_typed_error() -> None:
    def handler(request: httpx.Request) -> httpx.Response:
        return httpx.Response(404, json={"error": "model 'qwen3:4b' not found"})

    with pytest.raises(OllamaModelNotFoundError, match="qwen3:4b"):
        await make_ollama(handler).chat("qwen3:4b", [{"role": "user", "content": "hi"}])


async def test_server_error_raises_ollama_error() -> None:
    def handler(request: httpx.Request) -> httpx.Response:
        return httpx.Response(500, json={"error": "out of memory"})

    with pytest.raises(OllamaError, match="out of memory"):
        await make_ollama(handler).chat("qwen3:4b", [{"role": "user", "content": "hi"}])


async def test_chat_sends_structured_output_and_parses_metrics() -> None:
    captured: dict[str, object] = {}
    schema = {"type": "object", "properties": {"trend": {"type": "string"}}, "required": ["trend"]}

    def handler(request: httpx.Request) -> httpx.Response:
        captured.update(json.loads(request.content))
        return httpx.Response(
            200,
            json={
                "model": "qwen3:4b",
                "message": {"role": "assistant", "content": '{"trend":"up"}'},
                "total_duration": 3_000_000_000,
                "load_duration": 500_000_000,
                "prompt_eval_count": 40,
                "eval_count": 50,
                "eval_duration": 2_000_000_000,
            },
        )

    result = await make_ollama(handler).chat(
        "qwen3:4b",
        [{"role": "user", "content": "describe"}],
        response_format=schema,
        num_ctx=8192,
        keep_alive="5m",
    )

    assert captured["format"] == schema
    assert captured["stream"] is False
    assert captured["think"] is False
    assert captured["keep_alive"] == "5m"
    assert captured["options"] == {"temperature": 0.0, "num_ctx": 8192}
    assert result.content == '{"trend":"up"}'
    assert result.tokens_per_second == pytest.approx(25.0)
