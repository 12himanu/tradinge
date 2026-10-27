"""What the next day and the next week have actually looked like on this instrument.

A forecast is the thing this app refuses to invent. The setup score ranks a chart and is not a
probability; the analyst may not state a number that no stage computed. So the only honest answer
to "what happens next" is a measurement of what happened the other times, and that is all this
module produces: over the symbol's own history, the distribution of the N-bar move from a close.

Two numbers per horizon carry it:

* **How often it went up** — the share of N-bar moves that closed higher. On most NEPSE symbols
  this sits near half, which is the point: it is the baseline any signal has to beat, and a signal
  that does not beat it is not an edge.
* **Where most moves landed** — the 10th to 90th percentile of the N-bar move, as a price band
  around the last close. Not a target and not a limit: one move in five finished outside it, by
  construction.

Both are unconditional. They describe the instrument, not today's setup — conditioning on the
current signal is what `base_rates` does, with its own sample size attached. Reading the two
together is the whole point: the base rate says what this signal has been worth, and the
distribution says what a day on this stock is worth without any signal at all.

No look-ahead is possible here — every move is measured between two closes already in the history
— but the truncation rule from `base_rates` still applies: the last N bars start no move, because
the bar that would resolve them has not printed yet.
"""

from __future__ import annotations

import numpy as np

from ai_engine.analysis import base_rates as base_rate_analysis
from ai_engine.analysis.series import Series
from ai_engine.pipeline.config import CandlestickConfig
from ai_engine.schemas.analysis import BaseRate, HorizonOutlook, PatternResult

HORIZONS: tuple[tuple[int, str], ...] = ((1, "next trading day"), (5, "next week"))
"""The horizons the forecast is stated over. Five daily bars is a NEPSE trading week."""

MIN_SAMPLE = 60
"""Below this many completed moves the percentiles are shape-less, so nothing is reported.

Sixty is also `MIN_BARS_TIER_A`: a series too thin to analyse is too thin to measure.
"""


def compute(
    series: Series,
    *,
    candlesticks: CandlestickConfig | None = None,
    patterns: list[PatternResult] | None = None,
    horizons: tuple[tuple[int, str], ...] = HORIZONS,
    min_sample: int = MIN_SAMPLE,
) -> list[HorizonOutlook]:
    """Measure the N-bar move distribution for each horizon, on the full history given.

    `series` should be the whole import rather than the style window: a wider sample is the only
    thing that makes percentiles mean anything, and there is no window bias to worry about when
    the measurement is unconditional.

    With `candlesticks` and the patterns detected on the latest bars, each horizon also carries the
    base rates of the signals firing now, measured over that horizon.
    """
    if len(series) < 2:
        return []

    firing = _firing(patterns)

    close = series.close
    last_close = series.last_close
    outlooks: list[HorizonOutlook] = []

    for bars, label in horizons:
        if bars <= 0 or len(series) <= bars:
            continue

        # Every pair of closes `bars` apart. The last `bars` closes start nothing: their horizon
        # has not finished, and counting a partial move as a flat one would flatter the middle.
        moves = (close[bars:] / close[:-bars] - 1.0) * 100.0
        moves = moves[np.isfinite(moves)]
        sample = int(moves.size)

        rates = _base_rates(series, candlesticks, firing, bars)

        if sample < min_sample:
            outlooks.append(HorizonOutlook(bars=bars, label=label, sample=sample, base_rates=rates))
            continue

        low_pct, median_pct, high_pct = (float(value) for value in np.percentile(moves, [10, 50, 90]))

        outlooks.append(
            HorizonOutlook(
                bars=bars,
                label=label,
                sample=sample,
                up_rate=round(float(np.mean(moves > 0)), 4),
                median_move_pct=round(median_pct, 3),
                typical_move_pct=round(float(np.median(np.abs(moves))), 3),
                low_move_pct=round(low_pct, 3),
                high_move_pct=round(high_pct, 3),
                band_low=round(last_close * (1 + low_pct / 100), 4),
                band_high=round(last_close * (1 + high_pct / 100), 4),
                reference_price=round(last_close, 4),
                base_rates=rates,
            )
        )

    return outlooks


def _firing(patterns: list[PatternResult] | None) -> set[tuple[str, str]] | None:
    """The candlestick signals on the chart right now, as (type, direction) pairs.

    None means "nothing was passed", which is different from "nothing is firing": the first skips
    the base-rate pass, the second correctly produces none.
    """
    if patterns is None:
        return None
    return {
        (pattern.type, pattern.direction)
        for pattern in patterns
        if pattern.category == "candlestick" and pattern.direction != "neutral"
    }


def _base_rates(
    series: Series,
    config: CandlestickConfig | None,
    firing: set[tuple[str, str]] | None,
    horizon_bars: int,
) -> list[BaseRate]:
    """This horizon's base rates, narrowed to the signals actually on the chart."""
    if config is None or not firing:
        return []

    measured = base_rate_analysis.compute(series, config, horizon_bars=horizon_bars)

    return [rate for rate in measured if (rate.type, rate.direction) in firing]


__all__ = ["HORIZONS", "MIN_SAMPLE", "compute"]
