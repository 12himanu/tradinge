"""Pattern agent: classic patterns, candlesticks near levels, and their evidence (docs/04 §5.4)."""

from __future__ import annotations

from dataclasses import dataclass

from ai_engine.analysis import candlesticks, patterns
from ai_engine.analysis.scoring import FactorInput, pattern_factor
from ai_engine.pipeline.context import AnalysisContext
from ai_engine.schemas.analysis import PatternResult


@dataclass(frozen=True, slots=True)
class PatternView:
    patterns: list[PatternResult]
    factor: FactorInput
    primary: PatternResult | None
    summary: str


def run(context: AnalysisContext) -> PatternView:
    """Detect patterns, register one evidence item each, and produce the pattern factor."""
    series, swings = context.series, context.swings
    if series is None or swings is None:
        raise ValueError("pattern agent needs a price series and swings")

    found: list[PatternResult] = []
    if context.atr > 0:
        pattern_context = patterns.PatternContext(
            series=series,
            swings=swings,
            atr=context.atr,
            config=context.config.patterns.patterns,
            volume_sma=None if context.indicators is None else context.indicators.volume_sma,
        )
        found = patterns.detect_all(pattern_context)
        found.extend(_candlesticks(context))

    for index, pattern in enumerate(found):
        pattern.evidence_id = _register(context, pattern, index).id

    primary = next((pattern for pattern in found if pattern.category == "classic"), None)
    factor = pattern_factor(found)
    if primary is not None and primary.evidence_id is not None:
        factor = FactorInput(factor.value, factor.reason, [primary.evidence_id])
    return PatternView(patterns=found, factor=factor, primary=primary, summary=_summary(primary))


def _candlesticks(context: AnalysisContext) -> list[PatternResult]:
    """Candlesticks are only meaningful at a level, so collect the zones first."""
    series = context.series
    levels = context.levels
    if series is None or levels is None:
        return []
    zones: list[tuple[float, float]] = [
        (zone.low, zone.high) for zone in (*levels.supports, *levels.resistances)
    ]
    if context.smc is not None:
        zones.extend(
            (event.price_low, event.price_high)
            for event in context.smc.events
            if event.price_low is not None and event.price_high is not None
        )
    return candlesticks.detect(series, zones, context.atr, context.config.patterns.candlesticks)


def _register(context: AnalysisContext, pattern: PatternResult, index: int):  # type: ignore[no-untyped-def]
    label = pattern.type.replace("_", " ")
    parts = [f"{label.capitalize()} ({pattern.status}), confidence {pattern.confidence:.0%}"]
    if pattern.confirmation_rule:
        parts.append(pattern.confirmation_rule.lower())
    if pattern.measured_target is not None:
        parts.append(f"measured target {pattern.measured_target:.2f}")
    if pattern.invalidation_price is not None:
        parts.append(f"invalid below {pattern.invalidation_price:.2f}")
    return context.evidence.add(
        "pattern",
        f"pattern_{pattern.type}_{index}",
        "; ".join(parts),
        value=pattern.measured_target,
        unit="price" if pattern.measured_target is not None else "none",
        source="pattern_geometry",
        weight=0.9 if pattern.category == "classic" else 0.4,
        as_of=pattern.end_time,
    )


def _summary(primary: PatternResult | None) -> str:
    if primary is None:
        return "No classic chart pattern met the confidence floor"
    parts = [
        f"Detected pattern: {primary.type.replace('_', ' ').capitalize()}",
        f"Confidence {primary.confidence:.0%}",
        f"Status: {primary.status}",
    ]
    if primary.confirmation_rule:
        parts.append(f"Confirmation: {primary.confirmation_rule.lower()}")
    if primary.measured_target is not None:
        parts.append(f"Target {primary.measured_target:.2f}")
    if primary.invalidation_price is not None:
        parts.append(f"Invalidation: {primary.invalidation_price:.2f}")
    return " · ".join(parts)
