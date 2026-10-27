from __future__ import annotations

import time
import uuid
from collections.abc import AsyncIterator, Awaitable, Callable
from contextlib import asynccontextmanager

import structlog
from fastapi import FastAPI, Request, Response
from fastapi.responses import JSONResponse

from ai_engine import __version__
from ai_engine.api import analyze, health, v1
from ai_engine.api.analyze import Slots
from ai_engine.errors import ConfigurationError, EngineError
from ai_engine.models.ollama import OllamaClient
from ai_engine.models.registry import load_profiles
from ai_engine.observability import configure_logging
from ai_engine.pipeline.config import load_engine_config
from ai_engine.schemas.models import ErrorBody, ErrorResponse
from ai_engine.settings import Settings, load_settings
from ai_engine.versioning import engine_version

log = structlog.get_logger(__name__)

_QUIET_PATHS = frozenset({"/livez"})


def create_app(settings: Settings | None = None, ollama: OllamaClient | None = None) -> FastAPI:
    """App factory (uvicorn runs it with --factory). Tests inject settings and a mocked Ollama client."""
    settings = settings or load_settings()
    configure_logging(settings.log_level, json=settings.log_json)

    profiles = load_profiles(settings.config_dir)
    if settings.model_profile not in profiles:
        raise ConfigurationError(
            f"Unknown model profile '{settings.model_profile}'. Available: {', '.join(sorted(profiles))}"
        )

    owns_client = ollama is None
    client = ollama or OllamaClient(
        settings.ollama_host,
        timeout_seconds=settings.ollama_timeout_seconds,
        connect_retries=settings.ollama_connect_retries,
    )

    @asynccontextmanager
    async def lifespan(_: FastAPI) -> AsyncIterator[None]:
        log.info(
            "engine.started",
            version=app.state.engine_version,
            model_profile=settings.model_profile,
            ollama_host=settings.ollama_host,
        )
        yield
        if owns_client:
            await client.aclose()

    app = FastAPI(title="Stock AI Analyzer Engine", version=__version__, lifespan=lifespan)
    app.state.settings = settings
    app.state.ollama = client
    app.state.profiles = profiles
    app.state.engine_version = engine_version(settings.config_dir)
    app.state.config = load_engine_config(settings.config_dir)
    app.state.analysis_slots = Slots(settings.max_concurrent_analyses)

    app.include_router(health.router)
    app.include_router(v1.router)
    app.include_router(analyze.router)

    @app.exception_handler(EngineError)
    async def handle_engine_error(_: Request, exc: EngineError) -> JSONResponse:
        body = ErrorResponse(error=ErrorBody(code=exc.code, message=exc.message, details=exc.details))
        return JSONResponse(status_code=exc.status_code, content=body.model_dump())

    @app.middleware("http")
    async def request_context(
        request: Request, call_next: Callable[[Request], Awaitable[Response]]
    ) -> Response:
        request_id = request.headers.get("X-Request-Id") or uuid.uuid4().hex
        structlog.contextvars.clear_contextvars()
        structlog.contextvars.bind_contextvars(request_id=request_id)
        started = time.perf_counter()
        response = await call_next(request)
        response.headers["X-Request-Id"] = request_id
        if request.url.path not in _QUIET_PATHS:
            log.info(
                "http.request",
                method=request.method,
                path=request.url.path,
                status=response.status_code,
                duration_ms=round((time.perf_counter() - started) * 1000, 1),
            )
        return response

    return app
