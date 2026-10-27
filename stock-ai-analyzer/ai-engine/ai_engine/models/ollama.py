from __future__ import annotations

import asyncio
from collections.abc import Sequence
from dataclasses import dataclass
from typing import Any, NotRequired, TypedDict

import httpx
import structlog

log = structlog.get_logger(__name__)


class OllamaError(Exception):
    """Ollama returned an error response."""


class OllamaUnavailableError(OllamaError):
    """Ollama could not be reached (not running, wrong host, network)."""


class OllamaModelNotFoundError(OllamaError):
    """The requested model is not installed; fix with `ollama pull <tag>`."""


class ChatMessage(TypedDict):
    role: str
    content: str
    images: NotRequired[list[str]]  # base64-encoded images for vision models


@dataclass(frozen=True, slots=True)
class InstalledModel:
    name: str
    digest: str
    size_bytes: int


@dataclass(frozen=True, slots=True)
class ChatResult:
    content: str
    model: str
    total_duration_ns: int
    load_duration_ns: int
    prompt_eval_count: int
    eval_count: int
    eval_duration_ns: int

    @property
    def tokens_per_second(self) -> float:
        if self.eval_duration_ns <= 0:
            return 0.0
        return self.eval_count / (self.eval_duration_ns / 1e9)


class OllamaClient:
    """Thin async client for the Ollama HTTP API with connection retries and typed errors."""

    def __init__(
        self,
        base_url: str,
        *,
        timeout_seconds: float,
        connect_retries: int = 3,
        retry_backoff_seconds: float = 0.5,
        transport: httpx.AsyncBaseTransport | None = None,
    ) -> None:
        self._connect_retries = connect_retries
        self._retry_backoff_seconds = retry_backoff_seconds
        self._client = httpx.AsyncClient(
            base_url=base_url.rstrip("/"),
            timeout=httpx.Timeout(timeout_seconds, connect=5.0),
            transport=transport,
        )

    async def aclose(self) -> None:
        await self._client.aclose()

    async def version(self, *, timeout_s: float | None = None, retry: bool = True) -> str:
        data = await self._request_json("GET", "/api/version", timeout_s=timeout_s, retry=retry)
        return str(data.get("version", "unknown"))

    async def list_models(
        self, *, timeout_s: float | None = None, retry: bool = True
    ) -> list[InstalledModel]:
        data = await self._request_json("GET", "/api/tags", timeout_s=timeout_s, retry=retry)
        return [
            InstalledModel(
                name=str(item["name"]),
                digest=str(item.get("digest", "")),
                size_bytes=int(item.get("size", 0)),
            )
            for item in data.get("models", [])
        ]

    async def chat(
        self,
        model: str,
        messages: Sequence[ChatMessage],
        *,
        response_format: dict[str, Any] | None = None,
        temperature: float = 0.0,
        think: bool | None = False,
        keep_alive: str | None = None,
        num_ctx: int | None = None,
    ) -> ChatResult:
        """Non-streaming chat. `response_format` is a JSON schema for structured output."""
        options: dict[str, Any] = {"temperature": temperature}
        if num_ctx is not None:
            options["num_ctx"] = num_ctx
        payload: dict[str, Any] = {
            "model": model,
            "messages": list(messages),
            "stream": False,
            "options": options,
        }
        if response_format is not None:
            payload["format"] = response_format
        if think is not None:
            payload["think"] = think
        if keep_alive is not None:
            payload["keep_alive"] = keep_alive

        data = await self._request_json("POST", "/api/chat", json=payload)
        message = data.get("message") or {}
        return ChatResult(
            content=str(message.get("content", "")),
            model=str(data.get("model", model)),
            total_duration_ns=int(data.get("total_duration", 0)),
            load_duration_ns=int(data.get("load_duration", 0)),
            prompt_eval_count=int(data.get("prompt_eval_count", 0)),
            eval_count=int(data.get("eval_count", 0)),
            eval_duration_ns=int(data.get("eval_duration", 0)),
        )

    async def _request_json(
        self,
        method: str,
        path: str,
        *,
        json: dict[str, Any] | None = None,
        timeout_s: float | None = None,
        retry: bool = True,
    ) -> dict[str, Any]:
        attempts = 1 + (self._connect_retries if retry else 0)
        request_timeout: httpx.Timeout | None = (
            httpx.Timeout(timeout_s, connect=min(timeout_s, 5.0)) if timeout_s is not None else None
        )
        last_error: Exception | None = None

        for attempt in range(1, attempts + 1):
            try:
                if request_timeout is None:
                    response = await self._client.request(method, path, json=json)
                else:
                    response = await self._client.request(method, path, json=json, timeout=request_timeout)
                break
            except (httpx.ConnectError, httpx.ConnectTimeout) as exc:
                last_error = exc
                log.warning("ollama.connect_failed", path=path, attempt=attempt, attempts=attempts)
                if attempt < attempts:
                    await asyncio.sleep(self._retry_backoff_seconds * attempt)
            except httpx.TimeoutException as exc:
                raise OllamaUnavailableError(f"Ollama timed out on {path}") from exc
        else:
            raise OllamaUnavailableError(f"Cannot reach Ollama at {self._client.base_url}") from last_error

        if response.status_code == httpx.codes.NOT_FOUND and "not found" in response.text.lower():
            raise OllamaModelNotFoundError(_error_text(response))
        if response.is_error:
            raise OllamaError(f"Ollama {path} failed ({response.status_code}): {_error_text(response)}")

        try:
            body = response.json()
        except ValueError as exc:
            raise OllamaError(f"Ollama {path} returned invalid JSON") from exc
        if not isinstance(body, dict):
            raise OllamaError(f"Ollama {path} returned unexpected payload")
        return body


def _error_text(response: httpx.Response) -> str:
    try:
        body = response.json()
    except ValueError:
        return response.text[:500]
    if isinstance(body, dict) and "error" in body:
        return str(body["error"])
    return response.text[:500]
