"""Candlestick patterns, reported only near a level (docs/04-ai-agent-workflow.md §5.4).

A hammer in the middle of nowhere is noise. These are only emitted when the bar sits within
`near_level_atr` x ATR of a support, resistance, order block or fair value gap.
"""

from __future__ import annotations

from ai_engine.analysis.series import Series
from ai_engine.pipeline.config import CandlestickConfig
from ai_engine.schemas.analysis import PatternResult

LOOKBACK_BARS = 3
"""Only the last few bars matter: an engulfing bar from last month is not a signal."""


def detect(
    series: Series,
    levels: list[tuple[float, float]],
    atr_value: float,
    config: CandlestickConfig,
) -> list[PatternResult]:
    """Candlestick patterns on the most recent bars that sit near one of `levels`."""
    if atr_value <= 0 or len(series) < LOOKBACK_BARS + 1:
        return []
    results: list[PatternResult] = []
    last = len(series) - 1
    for index in range(max(1, last - LOOKBACK_BARS + 1), last + 1):
        if not _near_level(series, index, levels, atr_value * config.near_level_atr):
            continue
        results.extend(_bar_patterns(series, index, config))
    return results


def detect_history(series: Series, config: CandlestickConfig) -> list[PatternResult]:
    """Every bar pattern in the whole series, without the near-a-level filter.

    `detect` answers "what is forming now, somewhere that matters", so it looks at recent bars and
    keeps only those sitting on a level. Base rates need the opposite: every past occurrence of the
    pattern itself, on equal terms. The levels of a year ago are not reconstructible without
    look-ahead, so the historical question is deliberately the simpler one — what this bar shape has
    been worth on this instrument — and it is labelled that way wherever it is shown.
    """
    if len(series) < 2:
        return []
    found: list[PatternResult] = []
    for index in range(1, len(series)):
        found.extend(_bar_patterns(series, index, config))
    return found


def _near_level(series: Series, index: int, levels: list[tuple[float, float]], tolerance: float) -> bool:
    low, high = float(series.low[index]), float(series.high[index])
    for zone_low, zone_high in levels:
        if low - tolerance <= zone_high and high + tolerance >= zone_low:
            return True
    return False


def _bar_patterns(series: Series, index: int, config: CandlestickConfig) -> list[PatternResult]:
    open_, close = float(series.open[index]), float(series.close[index])
    high, low = float(series.high[index]), float(series.low[index])
    body = abs(close - open_)
    total = high - low
    if total <= 0:
        return []

    previous_open, previous_close = float(series.open[index - 1]), float(series.close[index - 1])
    previous_body = abs(previous_close - previous_open)
    upper_wick = high - max(open_, close)
    lower_wick = min(open_, close) - low
    date = series.dates[index]
    found: list[PatternResult] = []

    def emit(name: str, direction: str, confidence: float) -> None:
        found.append(
            PatternResult(
                category="candlestick",
                type=name,
                direction=direction,
                status="confirmed",
                confidence=round(min(1.0, confidence), 3),
                start_time=date,
                end_time=date,
                price_low=low,
                price_high=high,
                key_points={"body": round(body, 4), "range": round(total, 4)},
                confirmation_rule="Reported because the bar formed at a level",
            )
        )

    if body / total >= config.min_body_ratio_engulfing and previous_body > 0:
        after_down_bar = previous_close < previous_open
        engulfs_down = after_down_bar and close >= previous_open and open_ <= previous_close
        engulfs_up = not after_down_bar and close <= previous_open and open_ >= previous_close
        if close > open_ and engulfs_down:
            emit("bullish_engulfing", "bullish", 0.5 + body / total / 2)
        if close < open_ and engulfs_up:
            emit("bearish_engulfing", "bearish", 0.5 + body / total / 2)

    if body > 0 and lower_wick >= config.hammer_wick_ratio * body and upper_wick <= body:
        emit("hammer", "bullish", 0.45 + lower_wick / total / 2)
    if body > 0 and upper_wick >= config.hammer_wick_ratio * body and lower_wick <= body:
        emit("shooting_star", "bearish", 0.45 + upper_wick / total / 2)
    if body / total <= config.doji_body_ratio:
        emit("doji", "neutral", 0.4)
    return found
