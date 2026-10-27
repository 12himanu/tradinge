"""What happened last time, measured on this symbol's own history.

A setup score ranks a chart; it is not a probability, and the engine refuses to dress it up as one
until real outcomes have calibrated it. A base rate is the honest number available in the meantime:
every earlier occurrence of the same signal on the same instrument, resolved against the bars that
actually followed it.

The test is a two-barrier race, the same shape the outcome evaluator uses on live predictions:
from the close of the signal bar, does price travel one ATR in the signal's favour before it travels
one ATR against, within the style's horizon? Barriers are ATR-sized rather than a fixed percentage
so the question means the same thing on a 40-rupee stock and a 4,000-rupee one, and so the distance
matches how the engine already places stops.

Three rules keep the number honest:

* **No look-ahead.** The ATR that sizes the barriers is the one known at the signal bar, and only
  bars after it are walked.
* **No truncation bias.** An occurrence without a full horizon of bars after it is excluded, not
  counted as flat, so a signal that fired last week cannot pad the sample.
* **Ties lose.** A bar that touches both barriers is counted against the signal, matching the
  `ambiguous_bar` convention in outcome evaluation. Intrabar order is unknowable from daily data,
  and the conservative reading is the one that does not flatter the setup.

A base rate is a property of the history, not a forecast. It carries its sample size so a rate built
on nine occurrences can be read as the weak evidence it is.
"""

from __future__ import annotations

from dataclasses import dataclass

import numpy as np

from ai_engine.analysis import candlesticks
from ai_engine.analysis.indicators import atr
from ai_engine.analysis.series import FloatArray, Series
from ai_engine.pipeline.config import CandlestickConfig
from ai_engine.schemas.analysis import BaseRate, Direction

MIN_SAMPLE = 5
"""Below this many completed occurrences, a rate is noise and is not reported at all."""

BARRIER_ATR = 1.0
"""Barrier distance in ATR. One ATR is a move worth noticing and roughly a stop's width."""

ATR_PERIOD = 14


@dataclass(frozen=True, slots=True)
class _Occurrence:
    index: int
    direction: Direction


def compute(
    series: Series,
    config: CandlestickConfig,
    *,
    horizon_bars: int,
    barrier_atr: float = BARRIER_ATR,
    min_sample: int = MIN_SAMPLE,
) -> list[BaseRate]:
    """Base rates for every bar pattern that has ever fired on `series`.

    `series` is the full history, not the style window: the whole point is the sample the window
    throws away. Types that do not survive the horizon and sample-size rules are left out rather
    than reported thin.
    """
    if horizon_bars <= 0 or len(series) <= horizon_bars + ATR_PERIOD + 1:
        return []

    atr_values = atr(series.high, series.low, series.close, ATR_PERIOD)
    index_of = {date: position for position, date in enumerate(series.dates)}

    grouped: dict[tuple[str, str, Direction], list[_Occurrence]] = {}
    for signal in candlesticks.detect_history(series, config):
        # A doji says "indecision" with no side to be right about, so there is nothing to score.
        if signal.direction == "neutral" or signal.start_time is None:
            continue
        index = index_of.get(signal.start_time)
        if index is None:
            continue
        key = (signal.category, signal.type, signal.direction)
        grouped.setdefault(key, []).append(_Occurrence(index, signal.direction))

    rates = []
    for (category, signal_type, direction), occurrences in sorted(grouped.items()):
        rate = _rate(
            series,
            atr_values,
            occurrences,
            category=category,
            signal_type=signal_type,
            direction=direction,
            horizon_bars=horizon_bars,
            barrier_atr=barrier_atr,
            min_sample=min_sample,
        )
        if rate is not None:
            rates.append(rate)

    # Strongest evidence first: a high rate on a large sample should read before a lucky handful.
    rates.sort(key=lambda r: (r.favourable_rate, r.sample_size), reverse=True)
    return rates


def _rate(
    series: Series,
    atr_values: FloatArray,
    occurrences: list[_Occurrence],
    *,
    category: str,
    signal_type: str,
    direction: Direction,
    horizon_bars: int,
    barrier_atr: float,
    min_sample: int,
) -> BaseRate | None:
    last_testable = len(series) - horizon_bars - 1
    favourable = adverse = flat = 0
    barrier_pcts: list[float] = []
    bars_to_resolve: list[int] = []

    for occurrence in occurrences:
        index = occurrence.index
        # Excluded, not counted flat: there is no full horizon to judge it over yet.
        if index > last_testable or index >= atr_values.size:
            continue
        barrier = float(atr_values[index]) * barrier_atr
        entry = float(series.close[index])
        if not np.isfinite(barrier) or barrier <= 0 or entry <= 0:
            continue

        outcome, bars = _race(series, index, entry, barrier, direction, horizon_bars)
        if outcome == "favourable":
            favourable += 1
        elif outcome == "adverse":
            adverse += 1
        else:
            flat += 1
        if bars is not None:
            bars_to_resolve.append(bars)
        barrier_pcts.append(barrier / entry * 100.0)

    total = favourable + adverse + flat
    if total < min_sample:
        return None

    return BaseRate(
        category=category,
        type=signal_type,
        direction=direction,
        sample_size=total,
        favourable=favourable,
        adverse=adverse,
        unresolved=flat,
        favourable_rate=round(favourable / total, 4),
        adverse_rate=round(adverse / total, 4),
        unresolved_rate=round(flat / total, 4),
        horizon_bars=horizon_bars,
        barrier_atr=barrier_atr,
        median_barrier_pct=round(float(np.median(barrier_pcts)), 4) if barrier_pcts else None,
        median_bars_to_resolution=int(np.median(bars_to_resolve)) if bars_to_resolve else None,
    )


def _race(
    series: Series,
    index: int,
    entry: float,
    barrier: float,
    direction: Direction,
    horizon_bars: int,
) -> tuple[str, int | None]:
    """Which barrier price reached first, and after how many bars. Never reads bar `index` itself."""
    upper = entry + barrier
    lower = entry - barrier
    favourable_up = direction == "bullish"

    for offset in range(1, horizon_bars + 1):
        position = index + offset
        if position >= len(series):
            break
        hit_upper = float(series.high[position]) >= upper
        hit_lower = float(series.low[position]) <= lower

        if hit_upper and hit_lower:
            # Both in one bar: daily data cannot say which came first, so the signal does not
            # get the benefit of the doubt.
            return "adverse", offset
        if hit_upper:
            return ("favourable" if favourable_up else "adverse"), offset
        if hit_lower:
            return ("adverse" if favourable_up else "favourable"), offset

    return "unresolved", None
