"""Wiring the chart-identity check into the pipeline.

Kept out of the orchestrator for the same reason the analyst is: `analyse()` must run identically
with no model available, which is what every existing test does. No connection means no check.

A mismatch raises rather than warns. A warning on a report about the wrong company is still a
report about the wrong company, and the numbers in it are correct — which is exactly what makes it
dangerous to act on. The run stops, the error names both instruments, and the UI offers to relink
the chart to the right symbol.
"""

from __future__ import annotations

import structlog

from ai_engine.agents.chart_identity_agent import VisionConnection, read, verify
from ai_engine.errors import EngineError
from ai_engine.pipeline.context import AnalysisContext

log = structlog.get_logger(__name__)


def check(context: AnalysisContext, vision: VisionConnection | None) -> None:
    """Confirm the screenshot shows the instrument being analysed, or stop the run.

    Silence is not disagreement: an unreadable title, a model that is down, a name too generic to
    judge — all leave the analysis running, with a warning when there is something to say.
    """
    request = context.request
    if vision is None or request.image_path is None:
        # No stage is opened: a run that cannot check anything should not report a step it skipped.
        return

    if request.skip_symbol_check:
        # Said out loud, because a reader of the report should know a guarantee was waived and by
        # whom — not discover it in a trade.
        context.warn(
            f"The screenshot was not checked against {request.symbol or request.declared_symbol}: "
            "you confirmed this chart is that symbol."
        )
        return

    with context.stage("identity", 0.1):
        reading = read(vision, request.image_path)

        # Say so rather than pass silently: a check that did not run must not look like one that
        # passed, or the report claims a guarantee nobody made.
        if reading.unavailable:
            context.warn(
                "The screenshot was not checked against the symbol: the vision model could not be "
                "reached. This report assumes the chart is the symbol it was run for."
            )
            return

        verdict = verify(
            reading.identity,
            declared_symbol=request.symbol or request.declared_symbol,
            declared_name=request.company_name,
            universe=request.symbol_universe,
        )

    log.info(
        "identity.checked",
        status=verdict.status,
        read=verdict.read_label,
        declared=request.symbol or request.declared_symbol,
    )

    if verdict.is_mismatch:
        raise EngineError(
            "SYMBOL_MISMATCH",
            verdict.message,
            status_code=422,
            details={
                "read_symbol": verdict.read_label,
                "declared_symbol": request.symbol or request.declared_symbol,
            },
        )

    if verdict.message:
        context.warn(verdict.message)


__all__ = ["check"]
