"""`POST /v1/analyze` — the streaming analysis endpoint (P4D-01).

The response is NDJSON: one JSON object per line, flushed as the pipeline advances, so Laravel can
show progress instead of staring at a socket for minutes.

    {"event": "accepted", "analysis_id": "...", "engine_version": "..."}
    {"event": "stage", "stage": "indicators", "progress": 0.2, "elapsed_ms": 12}
    {"event": "result", "data": { ...AnalysisResult... }}
    {"event": "error", "error": {"code": "...", "message": "...", "details": {}}}

A run holds one of a fixed number of slots; when they are all busy the request is refused with 503
and a `Retry-After` rather than queued, because the caller (Horizon) already has a retry policy.
"""

from __future__ import annotations

import asyncio
import json
import time
from collections.abc import AsyncIterator
from pathlib import Path

import structlog
from fastapi import APIRouter, Depends, Request
from fastapi.responses import StreamingResponse

from ai_engine.agents.analyst_agent import DEFAULT_NUM_CTX, AnalystConnection
from ai_engine.agents.chart_identity_agent import VisionConnection
from ai_engine.api.deps import ActiveProfileDep, ConfigDep, SettingsDep
from ai_engine.api.security import verify_signature
from ai_engine.errors import EngineError
from ai_engine.pipeline.config import EngineConfig
from ai_engine.pipeline.orchestrator import analyse
from ai_engine.schemas.analysis import AnalysisRequest
from ai_engine.settings import Settings

log = structlog.get_logger(__name__)

router = APIRouter(prefix="/v1", tags=["analysis"], dependencies=[Depends(verify_signature)])

NDJSON_MEDIA_TYPE = "application/x-ndjson"
RETRY_AFTER_SECONDS = 30
QUEUE_POLL_SECONDS = 0.05

HEARTBEAT_SECONDS = 15.0
"""How long the stream may be silent before it says it is still alive.

Well under Laravel's ENGINE_STREAM_READ_TIMEOUT (120 s), because the analyst stage holds the GPU
for minutes with nothing to report and a quiet socket reads as a dead one.
"""


class Slots:
    """A counted set of concurrent runs, with a non-blocking acquire so the API can answer 503."""

    def __init__(self, limit: int) -> None:
        self._limit = limit
        self._in_use = 0

    def try_acquire(self) -> bool:
        if self._in_use >= self._limit:
            return False
        self._in_use += 1
        return True

    def release(self) -> None:
        self._in_use = max(0, self._in_use - 1)

    @property
    def in_use(self) -> int:
        return self._in_use

    @property
    def limit(self) -> int:
        return self._limit


def _slots(request: Request) -> Slots:
    slots: Slots = request.app.state.analysis_slots
    return slots


@router.post("/analyze")
async def analyze(
    request: Request,
    payload: AnalysisRequest,
    settings: SettingsDep,
    config: ConfigDep,
    profile: ActiveProfileDep,
) -> StreamingResponse:
    """Run one analysis, streaming progress. The connection stays open for the whole run."""
    _guard_image_path(payload, settings)

    slots = _slots(request)
    if not slots.try_acquire():
        raise EngineError(
            "ENGINE_BUSY",
            f"All {slots.limit} analysis slots are in use.",
            status_code=503,
            details={"retry_after_seconds": RETRY_AFTER_SECONDS},
        )

    # The analyst writes the prose; the numbers are already fixed by the time it runs. An
    # unreachable model costs the narrative, never the analysis.
    analyst = (
        AnalystConnection(
            base_url=settings.ollama_host,
            model=profile.text.tag,
            num_ctx=profile.text.num_ctx or DEFAULT_NUM_CTX,
            timeout_seconds=settings.analyst_timeout_seconds,
        )
        if settings.analyst_enabled
        else None
    )

    # Reads the chart's title and refuses the run if it belongs to another instrument. Needs the
    # image, so a request without one skips it.
    vision = (
        VisionConnection(
            base_url=settings.ollama_host,
            model=profile.vision.tag,
            storage_root=settings.storage_root,
            num_ctx=profile.vision.num_ctx,
            timeout_seconds=settings.symbol_check_timeout_seconds,
        )
        if settings.symbol_check_enabled and payload.image_path is not None
        else None
    )

    return StreamingResponse(
        _run(request, payload, config, slots, analyst, vision),
        media_type=NDJSON_MEDIA_TYPE,
        headers={
            "Cache-Control": "no-store",
            # Proxies must not buffer, or the progress events arrive all at once at the end.
            "X-Accel-Buffering": "no",
        },
    )


def _guard_image_path(payload: AnalysisRequest, settings: Settings) -> None:
    """An image path must resolve inside the storage root: the caller never names arbitrary files."""
    if payload.image_path is None:
        return

    root = settings.storage_root.resolve()
    given = Path(payload.image_path)
    candidate = (given if given.is_absolute() else root / given).resolve()

    if not candidate.is_relative_to(root):
        raise EngineError(
            "INVALID_IMAGE_PATH", "The image path must be inside the storage root.", status_code=422
        )
    if not candidate.is_file():
        raise EngineError("IMAGE_NOT_FOUND", f"No such image: {payload.image_path}", status_code=422)


async def _run(
    request: Request,
    payload: AnalysisRequest,
    config: EngineConfig,
    slots: Slots,
    analyst: AnalystConnection | None = None,
    vision: VisionConnection | None = None,
) -> AsyncIterator[bytes]:
    """Drive the synchronous pipeline in a worker thread and stream its events."""
    events: asyncio.Queue[dict[str, object]] = asyncio.Queue()
    loop = asyncio.get_running_loop()
    started = time.perf_counter()

    def on_progress(stage: str, progress: float) -> None:
        loop.call_soon_threadsafe(
            events.put_nowait,
            {
                "event": "stage",
                "stage": stage,
                "progress": round(progress, 3),
                "elapsed_ms": int((time.perf_counter() - started) * 1000),
            },
        )

    def work() -> dict[str, object]:
        result = analyse(payload, config, progress=on_progress, analyst=analyst, vision=vision)
        return {"event": "result", "data": result.model_dump(mode="json")}

    try:
        yield _line(
            {
                "event": "accepted",
                "analysis_id": payload.analysis_id,
                "engine_version": config.engine_version,
            }
        )

        future = loop.run_in_executor(None, work)
        last_sent = time.perf_counter()
        try:
            while not future.done():
                # A disconnected client means nobody is reading the result. The worker thread
                # cannot be killed mid-flight, but the deterministic stages finish in under a
                # second, so we stop streaming and let it fall off the executor.
                if await request.is_disconnected():
                    log.info("analysis.cancelled", analysis_id=payload.analysis_id)
                    return
                sent = False
                async for line in _drain(events, wait=QUEUE_POLL_SECONDS):
                    yield line
                    sent = True

                if sent:
                    last_sent = time.perf_counter()
                elif time.perf_counter() - last_sent >= HEARTBEAT_SECONDS:
                    # The analyst stage can hold the GPU for minutes with nothing to report, and a
                    # silent socket is indistinguishable from a dead one: Laravel closes the stream
                    # after ENGINE_STREAM_READ_TIMEOUT. This says "still working" and nothing more.
                    yield _line(
                        {"event": "heartbeat", "elapsed_ms": int((time.perf_counter() - started) * 1000)}
                    )
                    last_sent = time.perf_counter()

            async for line in _drain(events, wait=0):
                yield line
            yield _line(await future)

        except EngineError as exc:
            log.warning("analysis.failed", analysis_id=payload.analysis_id, code=exc.code)
            yield _error(exc.code, exc.message, exc.details)
        except Exception as exc:
            # The stream must always end with an event; a bare traceback would leave Laravel waiting.
            log.exception("analysis.crashed", analysis_id=payload.analysis_id)
            yield _error("ENGINE_ERROR", str(exc), {})
    finally:
        slots.release()


async def _drain(events: asyncio.Queue[dict[str, object]], *, wait: float) -> AsyncIterator[bytes]:
    """Yield whatever progress events are waiting, blocking at most `wait` seconds for the first."""
    if wait > 0:
        try:
            yield _line(await asyncio.wait_for(events.get(), timeout=wait))
        except TimeoutError:
            return
    while not events.empty():
        yield _line(events.get_nowait())


def _error(code: str, message: str, details: dict[str, object]) -> bytes:
    return _line({"event": "error", "error": {"code": code, "message": message, "details": details}})


def _line(event: dict[str, object]) -> bytes:
    return json.dumps(event, separators=(",", ":"), default=str).encode() + b"\n"
