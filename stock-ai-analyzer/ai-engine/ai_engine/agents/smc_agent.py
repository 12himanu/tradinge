"""SMC agent: structure, zones and the range-location factor (docs/04 §5.5)."""

from __future__ import annotations

from dataclasses import dataclass

from ai_engine.analysis import smc
from ai_engine.analysis.scoring import FactorInput, market_structure, range_location
from ai_engine.pipeline.context import AnalysisContext
from ai_engine.schemas.analysis import SmcResult


@dataclass(frozen=True, slots=True)
class SmcView:
    result: SmcResult
    factors: dict[str, FactorInput]
    summary: str


def run(context: AnalysisContext) -> SmcView:
    series, swings = context.series, context.swings
    if series is None or swings is None:
        raise ValueError("SMC agent needs a price series and swings")

    result = smc.analyse(
        smc.SmcContext(series=series, swings=swings, atr=context.atr, config=context.config.patterns.smc)
    )

    structure_item = context.evidence.add(
        "structure",
        "market_structure",
        _structure_text(result),
        source="smc",
        weight=0.9,
    )
    for index, event in enumerate(result.events):
        event.evidence_id = _register_event(context, event, index)

    range_ids: list[str] = []
    if result.dealing_range is not None:
        item = context.evidence.add(
            "smc",
            "dealing_range",
            (
                f"Dealing range {result.dealing_range.low:.2f} to {result.dealing_range.high:.2f}, "
                f"equilibrium {result.dealing_range.equilibrium:.2f}; price is in the "
                f"{result.dealing_range.zone}"
            ),
            value=result.dealing_range.low,
            value_high=result.dealing_range.high,
            unit="price",
            source="smc",
            weight=0.7,
        )
        range_ids = [item.id]

    factors = {
        "market_structure": FactorInput(
            market_structure(result.structure, result.last_event).value,
            market_structure(result.structure, result.last_event).reason,
            [structure_item.id],
        ),
        "range_location": FactorInput(
            range_location(result.dealing_range).value,
            range_location(result.dealing_range).reason,
            range_ids,
        ),
    }
    return SmcView(result=result, factors=factors, summary=_summary(result))


def _structure_text(result: SmcResult) -> str:
    labels = [point.label for point in result.swings if point.label]
    sequence = " → ".join(labels[-4:]) if labels else "no labelled swings"
    event = result.last_event.replace("_", " ").upper() if result.last_event else "none"
    return f"Market structure {result.structure}; recent swings {sequence}; last structural event {event}"


def _register_event(context: AnalysisContext, event, index: int) -> str:  # type: ignore[no-untyped-def]
    label = event.type.replace("_", " ")
    if event.price_low is not None and event.price_high is not None and event.price_low != event.price_high:
        where = f"{event.price_low:.2f}-{event.price_high:.2f}"
    else:
        where = f"{event.price_low:.2f}" if event.price_low is not None else "n/a"
    item = context.evidence.add(
        "smc",
        f"smc_{event.type}_{index}",
        f"{label.capitalize()} ({event.direction}, {event.status}) at {where}",
        value=event.price_low,
        value_high=event.price_high,
        unit="price",
        source="smc",
        weight=0.6,
        as_of=event.end_time,
    )
    return item.id


def _summary(result: SmcResult) -> str:
    counts: dict[str, int] = {}
    for event in result.events:
        counts[event.type] = counts.get(event.type, 0) + 1
    zones = ", ".join(f"{count} x {name.replace('_', ' ')}" for name, count in sorted(counts.items()))
    location = (
        f"price in the {result.dealing_range.zone} of the range"
        if result.dealing_range is not None
        else "no dealing range"
    )
    return f"Structure {result.structure}; {location}" + (f"; {zones}" if zones else "")
