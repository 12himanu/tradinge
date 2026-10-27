"""Smart Money Concepts, implemented as the rule set in docs/04-ai-agent-workflow.md §5.5.

These are structural definitions applied to price alone. Nothing here reveals institutional
intent, and the framing note that says so travels with every result.
"""

from __future__ import annotations

from dataclasses import dataclass
from datetime import UTC, datetime

import numpy as np

from ai_engine.analysis.series import Series
from ai_engine.analysis.swings import Pivot, SwingSet, highs, label_swings, lows
from ai_engine.pipeline.config import SmcConfig
from ai_engine.schemas.analysis import (
    DealingRange,
    Direction,
    PatternResult,
    PatternStatus,
    SmcResult,
    SwingPoint,
)

EPOCH = datetime(1970, 1, 1, tzinfo=UTC)
"""Sort fallback for zones that somehow carry no start time."""


@dataclass(frozen=True, slots=True)
class SmcContext:
    series: Series
    swings: SwingSet
    atr: float
    config: SmcConfig

    @property
    def last_index(self) -> int:
        return len(self.series) - 1


def analyse(context: SmcContext) -> SmcResult:
    """Full SMC read: structure, BOS/CHoCH, order blocks, FVGs, liquidity and the dealing range."""
    labelled = label_swings(context.swings.external)
    structure, last_event, events = _structure(context, labelled)
    events.extend(_order_blocks(context, events))
    events.extend(_fair_value_gaps(context))
    events.extend(_liquidity(context, labelled))
    return SmcResult(
        structure=structure,
        last_event=last_event,
        swings=[_swing_point(context, pivot) for pivot in labelled],
        events=events[: context.config.max_zones_reported * 4],
        dealing_range=_dealing_range(context, labelled),
    )


def _swing_point(context: SmcContext, pivot: Pivot) -> SwingPoint:
    return SwingPoint(
        index=pivot.index,
        date=context.series.dates[pivot.index],
        price=round(pivot.price, 4),
        kind=pivot.kind,
        degree="external",
        label=pivot.label,
    )


# --------------------------------------------------------------------------- structure


def _structure(
    context: SmcContext, labelled: list[Pivot]
) -> tuple[Direction, str | None, list[PatternResult]]:
    """Walk the closes and record every break of structure and change of character."""
    close = context.series.close
    events: list[PatternResult] = []
    state: Direction = "neutral"
    last_event: str | None = None

    reference_high: Pivot | None = None
    reference_low: Pivot | None = None
    pivot_index = 0

    for index in range(len(context.series)):
        while pivot_index < len(labelled) and labelled[pivot_index].index <= index - 1:
            pivot = labelled[pivot_index]
            if pivot.kind == "high":
                reference_high = pivot
            else:
                reference_low = pivot
            pivot_index += 1

        price = float(close[index])
        if reference_high is not None and price > reference_high.price:
            bullish_break = state != "bearish"
            kind = "bos" if bullish_break else "choch"
            state = "bullish"
            last_event = f"{kind}_bullish"
            events.append(_structure_event(context, kind, "bullish", index, reference_high))
            reference_high = None
        elif reference_low is not None and price < reference_low.price:
            bearish_break = state != "bullish"
            kind = "bos" if bearish_break else "choch"
            state = "bearish"
            last_event = f"{kind}_bearish"
            events.append(_structure_event(context, kind, "bearish", index, reference_low))
            reference_low = None

    return state, last_event, events[-context.config.max_zones_reported :]


def _structure_event(
    context: SmcContext, kind: str, direction: Direction, index: int, level: Pivot
) -> PatternResult:
    return PatternResult(
        category="smc",
        type=kind,
        direction=direction,
        status="confirmed",
        confidence=0.8,
        start_time=context.series.dates[level.index],
        end_time=context.series.dates[index],
        price_low=round(level.price, 4),
        price_high=round(level.price, 4),
        key_points={"level": round(level.price, 4), "broken_at_index": index, "swing_index": level.index},
        confirmation_rule=f"Close {'above' if direction == 'bullish' else 'below'} {level.price:.2f}",
    )


# --------------------------------------------------------------------------- displacement and order blocks


def _displacement_indices(context: SmcContext) -> list[int]:
    """Bars whose range is >= 1.5 x ATR with a body filling at least 60% of it."""
    series, config = context.series, context.config
    if context.atr <= 0:
        return []
    ranges = series.high - series.low
    bodies = np.abs(series.close - series.open)
    with np.errstate(divide="ignore", invalid="ignore"):
        body_ratio = np.where(ranges > 0, bodies / ranges, 0.0)
    mask = (ranges >= config.displacement_atr * context.atr) & (body_ratio >= config.displacement_body_ratio)
    return [int(index) for index in np.flatnonzero(mask)]


def _order_blocks(context: SmcContext, structure_events: list[PatternResult]) -> list[PatternResult]:
    """The last opposite-coloured candle before the displacement leg that broke structure."""
    series, config = context.series, context.config
    displacements = set(_displacement_indices(context))
    blocks: list[PatternResult] = []

    for event in structure_events:
        break_index = int(event.key_points.get("broken_at_index", -1))
        if break_index < 1:
            continue
        leg_start = max(0, break_index - config.order_block_lookback)
        displacement = next(
            (index for index in range(break_index, leg_start, -1) if index in displacements), break_index
        )
        bullish = event.direction == "bullish"
        origin = _last_opposite_candle(context, displacement, bullish=bullish, limit=leg_start)
        if origin is None:
            continue

        low = float(series.low[origin])
        high = float(series.high[origin])
        status = _mitigation_status(context, origin, low, high, bullish=bullish)
        blocks.append(
            PatternResult(
                category="smc",
                type="order_block",
                direction="bullish" if bullish else "bearish",
                status=status,
                confidence=0.6,
                start_time=series.dates[origin],
                end_time=series.dates[context.last_index],
                price_low=round(low, 4),
                price_high=round(high, 4),
                key_points={"origin_index": origin, "break_index": break_index},
                confirmation_rule="Price returning into the zone is the test",
                invalidation_price=round(low if bullish else high, 4),
            )
        )
    return _dedupe_zones(blocks, config.max_zones_reported)


def _last_opposite_candle(context: SmcContext, index: int, *, bullish: bool, limit: int) -> int | None:
    series = context.series
    for candidate in range(index, limit - 1, -1):
        down_candle = series.close[candidate] < series.open[candidate]
        if down_candle == bullish:
            return candidate
    return None


def _mitigation_status(
    context: SmcContext, origin: int, low: float, high: float, *, bullish: bool
) -> PatternStatus:
    """unmitigated -> mitigated once price returns -> invalidated once it closes through."""
    series = context.series
    after = slice(origin + 1, len(series))
    closes = series.close[after]
    if closes.size == 0:
        return "unmitigated"
    if bullish and np.any(closes < low):
        return "invalidated"
    if not bullish and np.any(closes > high):
        return "invalidated"
    touched = np.any(series.low[after] <= high) if bullish else np.any(series.high[after] >= low)
    return "mitigated" if touched else "unmitigated"


# --------------------------------------------------------------------------- fair value gaps


def _fair_value_gaps(context: SmcContext) -> list[PatternResult]:
    """Three-bar imbalance: bullish when low[i+1] > high[i-1]."""
    series, config = context.series, context.config
    minimum = config.fvg_min_size_atr * context.atr
    gaps: list[PatternResult] = []
    for i in range(1, len(series) - 1):
        bullish_gap = float(series.low[i + 1] - series.high[i - 1])
        bearish_gap = float(series.low[i - 1] - series.high[i + 1])
        if bullish_gap >= minimum:
            gaps.append(_fvg(context, i, float(series.high[i - 1]), float(series.low[i + 1]), True))
        elif bearish_gap >= minimum:
            gaps.append(_fvg(context, i, float(series.high[i + 1]), float(series.low[i - 1]), False))
    unmitigated = [gap for gap in gaps if gap.status != "mitigated"]
    return _dedupe_zones(unmitigated or gaps, config.max_zones_reported)


def _fvg(context: SmcContext, index: int, low: float, high: float, bullish: bool) -> PatternResult:
    series, config = context.series, context.config
    fill_level = low + (high - low) * config.fvg_mitigation_fill
    after = slice(index + 2, len(series))
    mitigated = (
        bool(np.any(series.low[after] <= fill_level))
        if bullish
        else bool(np.any(series.high[after] >= fill_level))
    )
    return PatternResult(
        category="smc",
        type="fvg",
        direction="bullish" if bullish else "bearish",
        status="mitigated" if mitigated else "unmitigated",
        confidence=0.55,
        start_time=series.dates[index - 1],
        end_time=series.dates[min(index + 1, context.last_index)],
        price_low=round(low, 4),
        price_high=round(high, 4),
        key_points={"middle_index": index, "fill_level": round(fill_level, 4)},
        confirmation_rule=f"{'Support' if bullish else 'Resistance'} while unfilled",
    )


# --------------------------------------------------------------------------- liquidity


def _liquidity(context: SmcContext, labelled: list[Pivot]) -> list[PatternResult]:
    """Equal highs and lows (resting liquidity) and the sweeps that took them."""
    config = context.config
    tolerance = config.equal_level_tolerance_atr * context.atr
    events: list[PatternResult] = []

    for kind, pivots in (("high", highs(labelled)), ("low", lows(labelled))):
        for group in _equal_groups(pivots, tolerance, config.min_equal_touches):
            level = float(np.mean([pivot.price for pivot in group]))
            events.append(
                PatternResult(
                    category="smc",
                    type="equal_highs" if kind == "high" else "equal_lows",
                    direction="bearish" if kind == "high" else "bullish",
                    status="confirmed",
                    confidence=0.5 + 0.1 * min(3, len(group) - 1),
                    start_time=context.series.dates[group[0].index],
                    end_time=context.series.dates[group[-1].index],
                    price_low=round(level - tolerance / 2, 4),
                    price_high=round(level + tolerance / 2, 4),
                    key_points={"touches": len(group), "level": round(level, 4)},
                    confirmation_rule=(
                        "Liquidity resting above" if kind == "high" else "Liquidity resting below"
                    ),
                )
            )
            sweep = _sweep(context, group[-1].index, level, took_highs=kind == "high")
            if sweep is not None:
                events.append(sweep)
    return events[: config.max_zones_reported * 2]


def _equal_groups(pivots: list[Pivot], tolerance: float, minimum: int) -> list[list[Pivot]]:
    groups: list[list[Pivot]] = []
    current: list[Pivot] = []
    for pivot in pivots:
        if current and abs(pivot.price - current[-1].price) <= tolerance:
            current.append(pivot)
            continue
        if len(current) >= minimum:
            groups.append(current)
        current = [pivot]
    if len(current) >= minimum:
        groups.append(current)
    return groups


def _sweep(context: SmcContext, after: int, level: float, *, took_highs: bool) -> PatternResult | None:
    """A wick beyond the level followed by a close back inside."""
    series = context.series
    for index in range(after + 1, len(series)):
        if took_highs and series.high[index] > level and series.close[index] < level:
            return _sweep_event(context, index, level, took_highs)
        if not took_highs and series.low[index] < level and series.close[index] > level:
            return _sweep_event(context, index, level, took_highs)
    return None


def _sweep_event(context: SmcContext, index: int, level: float, took_highs: bool) -> PatternResult:
    series = context.series
    extreme = float(series.high[index] if took_highs else series.low[index])
    return PatternResult(
        category="smc",
        type="liquidity_sweep",
        direction="bearish" if took_highs else "bullish",
        status="confirmed",
        confidence=0.6,
        start_time=series.dates[index],
        end_time=series.dates[index],
        price_low=round(min(level, extreme), 4),
        price_high=round(max(level, extreme), 4),
        key_points={"level": round(level, 4), "extreme": round(extreme, 4), "index": index},
        confirmation_rule="Wick through the level, close back inside",
    )


# --------------------------------------------------------------------------- dealing range


def _dealing_range(context: SmcContext, labelled: list[Pivot]) -> DealingRange | None:
    """From the last external swing low to the last external swing high."""
    config = context.config
    high_pivot = next((pivot for pivot in reversed(highs(labelled))), None)
    low_pivot = next((pivot for pivot in reversed(lows(labelled))), None)
    if high_pivot is None or low_pivot is None or high_pivot.price <= low_pivot.price:
        return None

    price = context.series.last_close
    span = high_pivot.price - low_pivot.price
    position = (price - low_pivot.price) / span
    clamped = max(0.0, min(1.0, position))
    zone = (
        "premium"
        if clamped >= config.premium_threshold
        else "discount"
        if clamped <= config.discount_threshold
        else "equilibrium"
    )
    return DealingRange(
        low=round(low_pivot.price, 4),
        high=round(high_pivot.price, 4),
        equilibrium=round((high_pivot.price + low_pivot.price) / 2, 4),
        position=round(clamped, 4),
        zone=zone,
    )


def _dedupe_zones(zones: list[PatternResult], limit: int) -> list[PatternResult]:
    """Keep the most recent non-overlapping zones."""
    kept: list[PatternResult] = []
    for zone in sorted(zones, key=lambda item: item.start_time or EPOCH, reverse=True):
        if any(_overlaps(zone, other) for other in kept):
            continue
        kept.append(zone)
        if len(kept) >= limit:
            break
    return kept


def _overlaps(first: PatternResult, second: PatternResult) -> bool:
    if None in (first.price_low, first.price_high, second.price_low, second.price_high):
        return False
    return not (first.price_high < second.price_low or second.price_high < first.price_low)  # type: ignore[operator]
