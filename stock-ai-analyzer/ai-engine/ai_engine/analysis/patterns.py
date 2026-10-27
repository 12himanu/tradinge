"""Classic chart-pattern detection (docs/04-ai-agent-workflow.md §5.4).

Each detector is geometry first: it locates the shape in the swing pivots, checks the tolerances
from `config/patterns.yaml`, and only then scores confidence as
`0.5 x geometric fit + 0.25 x volume conformity + 0.25 x context`. Unconfirmed shapes are
reported as `forming` with confidence capped, because an unbroken pattern is a hypothesis.
"""

from __future__ import annotations

import math
from dataclasses import dataclass

import numpy as np

from ai_engine.analysis.geometry import LineFit, clamp, fit_line, fit_quadratic, fit_quality
from ai_engine.analysis.series import FloatArray, Series
from ai_engine.analysis.swings import Pivot, SwingSet, highs, lows
from ai_engine.pipeline.config import PatternGeometryConfig
from ai_engine.schemas.analysis import PatternResult, PatternStatus

MIN_CONTAINMENT = 0.85
"""Share of bars a trendline pair must enclose before it counts as a boundary, not just a fit."""

MAX_PATTERN_AGE_BARS = 30
"""A shape whose last pivot is older than this is history: it no longer describes the setup."""


@dataclass(frozen=True, slots=True)
class PatternContext:
    """Everything a detector needs, computed once per analysis."""

    series: Series
    swings: SwingSet
    atr: float
    config: PatternGeometryConfig
    volume_sma: FloatArray | None = None

    @property
    def last_index(self) -> int:
        return len(self.series) - 1

    def date_at(self, index: int) -> object:
        return self.series.dates[max(0, min(index, self.last_index))]


def detect_all(context: PatternContext) -> list[PatternResult]:
    """Run every classic detector and keep the patterns that clear the confidence floor."""
    if context.atr <= 0 or len(context.series) < 30:
        return []
    found: list[PatternResult] = []
    for detector in (
        detect_double_bottom,
        detect_double_top,
        detect_head_and_shoulders,
        detect_inverse_head_and_shoulders,
        detect_triangles,
        detect_flag,
        detect_wedge,
        detect_channel,
        detect_cup_and_handle,
    ):
        found.extend(detector(context))
    floor = context.config.min_confidence
    kept = [pattern for pattern in found if pattern.confidence >= floor]
    return sorted(kept, key=lambda pattern: (-pattern.confidence, pattern.type))


# --------------------------------------------------------------------------- shared helpers


def _confidence(
    context: PatternContext,
    *,
    geometry: float,
    volume: float,
    contextual: float,
    status: PatternStatus,
) -> float:
    score = 0.5 * clamp(geometry) + 0.25 * clamp(volume) + 0.25 * clamp(contextual)
    if status == "forming":
        score = min(score, context.config.forming_confidence_cap)
    return round(clamp(score), 3)


def _volume_ratio(context: PatternContext, index: int) -> float:
    """Volume at `index` relative to its 20-bar average; 1.0 when volume is unknown."""
    series, average = context.series, context.volume_sma
    if series.volume is None or average is None or index >= average.size:
        return 1.0
    mean = float(average[index])
    if not math.isfinite(mean) or mean <= 0:
        return 1.0
    return float(series.volume[index]) / mean


def _volume_contraction(context: PatternContext, start: int, end: int) -> float:
    """1.0 when volume dried up through the base and expanded on the last bar."""
    if context.series.volume is None or end <= start:
        return 0.5
    window = context.series.volume[start : end + 1]
    if window.size < 4:
        return 0.5
    first_half = float(np.mean(window[: window.size // 2]))
    second_half = float(np.mean(window[window.size // 2 :]))
    if first_half <= 0:
        return 0.5
    contraction = clamp(1.0 - second_half / first_half + 0.5)
    breakout = clamp((_volume_ratio(context, end) - 1.0) / 0.5)
    return clamp(0.5 * contraction + 0.5 * breakout)


def _prior_trend(context: PatternContext, start: int, bars: int = 20) -> float:
    """Signed strength of the move into the pattern, in ATR units (positive = uptrend)."""
    close = context.series.close
    begin = max(0, start - bars)
    if begin == start or context.atr <= 0:
        return 0.0
    return float((close[start] - close[begin]) / context.atr)


def _closed_above(context: PatternContext, level: float, after: int) -> int | None:
    close = context.series.close
    for index in range(after + 1, close.size):
        if close[index] > level:
            return index
    return None


def _closed_below(context: PatternContext, level: float, after: int) -> int | None:
    close = context.series.close
    for index in range(after + 1, close.size):
        if close[index] < level:
            return index
    return None


def _pair_geometry(difference: float, tolerance: float) -> float:
    """How well a measurement sits inside its tolerance: 1.0 when exact, 0.3 at the edge.

    A pattern at the edge of tolerance is still a real pattern, just a scruffy one, so the
    geometric term decays to 0.3 rather than to zero.
    """
    if tolerance <= 0:
        return 0.0
    return clamp(1.0 - 0.7 * difference / tolerance, low=0.0, high=1.0)


def _containment(
    context: PatternContext, upper: LineFit, lower: LineFit, start: int, end: int, tolerance_atr: float
) -> float:
    """Share of bars inside the fitted boundaries; a real channel or triangle contains price."""
    if end <= start:
        return 0.0
    tolerance = tolerance_atr * context.atr
    high, low = context.series.high, context.series.low
    inside = 0
    for index in range(start, end + 1):
        if high[index] <= upper.at(index) + tolerance and low[index] >= lower.at(index) - tolerance:
            inside += 1
    return inside / (end - start + 1)


def _extreme_between(context: PatternContext, start: int, end: int, *, kind: str) -> Pivot | None:
    """The highest high (or lowest low) strictly between two pivots: the neckline of the shape."""
    if end - start < 2:
        return None
    if kind == "high":
        window = context.series.high[start + 1 : end]
        offset = int(np.argmax(window))
    else:
        window = context.series.low[start + 1 : end]
        offset = int(np.argmin(window))
    index = start + 1 + offset
    return Pivot(index, float(window[offset]), "high" if kind == "high" else "low")


# --------------------------------------------------------------------------- double bottom / top


def detect_double_bottom(context: PatternContext) -> list[PatternResult]:
    config = context.config.double
    pivots = context.swings.external
    candidates = lows(pivots)
    results: list[PatternResult] = []

    for first, second in _recent_pairs(candidates):
        if context.last_index - second.index > MAX_PATTERN_AGE_BARS:
            continue
        gap = second.index - first.index
        if not config.min_bars_apart <= gap <= config.max_bars_apart:
            continue
        if abs(first.price - second.price) > config.max_pivot_difference_atr * context.atr:
            continue
        peak = _extreme_between(context, first.index, second.index, kind="high")
        if peak is None:
            continue
        depth = peak.price - max(first.price, second.price)
        if depth < config.min_neckline_distance_atr * context.atr:
            continue
        if _prior_trend(context, first.index) > -0.5:
            continue

        neckline = peak.price
        lowest = min(first.price, second.price)
        breakout = _closed_above(context, neckline, second.index)
        status: PatternStatus = "confirmed" if breakout is not None else "forming"
        if context.series.close[-1] < lowest:
            status = "failed"
        end_index = breakout if breakout is not None else context.last_index

        results.append(
            PatternResult(
                category="classic",
                type="double_bottom",
                direction="bullish",
                status=status,
                confidence=_confidence(
                    context,
                    geometry=_pair_geometry(
                        abs(first.price - second.price), config.max_pivot_difference_atr * context.atr
                    ),
                    volume=_volume_contraction(context, first.index, end_index),
                    contextual=clamp(abs(_prior_trend(context, first.index)) / 3.0),
                    status=status,
                ),
                start_time=context.series.dates[first.index],
                end_time=context.series.dates[end_index],
                price_low=lowest,
                price_high=neckline,
                key_points={
                    "first_low": round(first.price, 4),
                    "second_low": round(second.price, 4),
                    "neckline": round(neckline, 4),
                    "first_low_index": first.index,
                    "second_low_index": second.index,
                },
                confirmation_rule=f"Close above {neckline:.2f}",
                measured_target=round(neckline + (neckline - lowest), 4),
                invalidation_price=round(lowest, 4),
            )
        )
    return results[-1:]


def detect_double_top(context: PatternContext) -> list[PatternResult]:
    config = context.config.double
    pivots = context.swings.external
    candidates = highs(pivots)
    results: list[PatternResult] = []

    for first, second in _recent_pairs(candidates):
        if context.last_index - second.index > MAX_PATTERN_AGE_BARS:
            continue
        gap = second.index - first.index
        if not config.min_bars_apart <= gap <= config.max_bars_apart:
            continue
        if abs(first.price - second.price) > config.max_pivot_difference_atr * context.atr:
            continue
        trough = _extreme_between(context, first.index, second.index, kind="low")
        if trough is None:
            continue
        depth = min(first.price, second.price) - trough.price
        if depth < config.min_neckline_distance_atr * context.atr:
            continue
        if _prior_trend(context, first.index) < 0.5:
            continue

        neckline = trough.price
        highest = max(first.price, second.price)
        breakdown = _closed_below(context, neckline, second.index)
        status: PatternStatus = "confirmed" if breakdown is not None else "forming"
        if context.series.close[-1] > highest:
            status = "failed"
        end_index = breakdown if breakdown is not None else context.last_index

        results.append(
            PatternResult(
                category="classic",
                type="double_top",
                direction="bearish",
                status=status,
                confidence=_confidence(
                    context,
                    geometry=_pair_geometry(
                        abs(first.price - second.price), config.max_pivot_difference_atr * context.atr
                    ),
                    volume=_volume_contraction(context, first.index, end_index),
                    contextual=clamp(abs(_prior_trend(context, first.index)) / 3.0),
                    status=status,
                ),
                start_time=context.series.dates[first.index],
                end_time=context.series.dates[end_index],
                price_low=neckline,
                price_high=highest,
                key_points={
                    "first_high": round(first.price, 4),
                    "second_high": round(second.price, 4),
                    "neckline": round(neckline, 4),
                    "first_high_index": first.index,
                    "second_high_index": second.index,
                },
                confirmation_rule=f"Close below {neckline:.2f}",
                measured_target=round(neckline - (highest - neckline), 4),
                invalidation_price=round(highest, 4),
            )
        )
    return results[-1:]


def _recent_pairs(pivots: list[Pivot], limit: int = 6) -> list[tuple[Pivot, Pivot]]:
    """Consecutive-and-skip pairs among the most recent pivots, oldest pair first."""
    recent = pivots[-limit:]
    return [(recent[i], recent[j]) for i in range(len(recent)) for j in range(i + 1, len(recent))]


# --------------------------------------------------------------------------- head and shoulders


def detect_head_and_shoulders(context: PatternContext) -> list[PatternResult]:
    return _head_shoulders(context, inverse=False)


def detect_inverse_head_and_shoulders(context: PatternContext) -> list[PatternResult]:
    return _head_shoulders(context, inverse=True)


def _head_shoulders(context: PatternContext, *, inverse: bool) -> list[PatternResult]:
    config = context.config.head_shoulders
    pivots = context.swings.external
    extremes = lows(pivots) if inverse else highs(pivots)
    if len(extremes) < 3:
        return []

    for left, head, right in _triples(extremes[-7:]):
        if right.index - left.index > config.max_bars_span:
            continue
        if context.last_index - right.index > MAX_PATTERN_AGE_BARS:
            continue
        shoulders = (min if inverse else max)(left.price, right.price)
        prominence = shoulders - head.price if inverse else head.price - shoulders
        if prominence < config.min_head_prominence_atr * context.atr:
            continue
        if abs(left.price - right.price) > config.max_shoulder_difference_atr * context.atr:
            continue

        first_trough = _extreme_between(context, left.index, head.index, kind="high" if inverse else "low")
        second_trough = _extreme_between(context, head.index, right.index, kind="high" if inverse else "low")
        if first_trough is None or second_trough is None:
            continue

        neckline_fit = fit_line(
            [first_trough.index, second_trough.index], [first_trough.price, second_trough.price]
        )
        if neckline_fit is None:
            continue
        neckline_now = neckline_fit.at(context.last_index)
        neckline_at_head = neckline_fit.at(head.index)

        break_index = (
            _closed_above(context, neckline_now, right.index)
            if inverse
            else _closed_below(context, neckline_now, right.index)
        )
        status: PatternStatus = "confirmed" if break_index is not None else "forming"
        end_index = break_index if break_index is not None else context.last_index
        depth = abs(head.price - neckline_at_head)
        target = neckline_now + depth if inverse else neckline_now - depth

        shoulder_symmetry = _pair_geometry(
            abs(left.price - right.price), config.max_shoulder_difference_atr * context.atr
        )
        prior = _prior_trend(context, left.index)
        contextual = clamp(abs(prior) / 3.0) if (prior < 0) == inverse else 0.2

        return [
            PatternResult(
                category="classic",
                type="inverse_head_and_shoulders" if inverse else "head_and_shoulders",
                direction="bullish" if inverse else "bearish",
                status=status,
                confidence=_confidence(
                    context,
                    geometry=shoulder_symmetry,
                    volume=_volume_contraction(context, left.index, end_index),
                    contextual=contextual,
                    status=status,
                ),
                start_time=context.series.dates[left.index],
                end_time=context.series.dates[end_index],
                price_low=min(head.price, neckline_now),
                price_high=max(head.price, neckline_now),
                key_points={
                    "left_shoulder": round(left.price, 4),
                    "head": round(head.price, 4),
                    "right_shoulder": round(right.price, 4),
                    "neckline_slope": round(neckline_fit.slope, 6),
                    "neckline_price": round(neckline_now, 4),
                },
                confirmation_rule=(
                    f"Close {'above' if inverse else 'below'} the neckline at {neckline_now:.2f}"
                ),
                measured_target=round(target, 4),
                invalidation_price=round(right.price, 4),
            )
        ]
    return []


def _triples(pivots: list[Pivot]) -> list[tuple[Pivot, Pivot, Pivot]]:
    """Consecutive triples, most recent first."""
    return [(pivots[i], pivots[i + 1], pivots[i + 2]) for i in range(len(pivots) - 3, -1, -1) if i >= 0]


# --------------------------------------------------------------------------- triangles


def detect_triangles(context: PatternContext) -> list[PatternResult]:
    config = context.config.triangle
    window = _boundary_window(context, config.min_bars, config.max_bars, config.min_touches)
    if window is None:
        return []
    upper, lower, upper_pivots, lower_pivots, start, end = window

    span = float(end - start)
    upper_rise = upper.span(start, end)
    lower_rise = lower.span(start, end)
    flat_limit = config.max_slope_ratio_flat * context.atr
    upper_flat = abs(upper_rise) <= flat_limit
    lower_flat = abs(lower_rise) <= flat_limit

    if upper_flat and lower_rise > flat_limit:
        kind, direction = "ascending_triangle", "bullish"
    elif lower_flat and upper_rise < -flat_limit:
        kind, direction = "descending_triangle", "bearish"
    elif upper_rise < -flat_limit and lower_rise > flat_limit:
        kind, direction = "symmetrical_triangle", "neutral"
    else:
        return []

    start_width = upper.at(start) - lower.at(start)
    end_width = upper.at(end) - lower.at(end)
    if start_width <= 0 or end_width >= start_width:
        return []

    boundary = upper.at(context.last_index)
    floor_price = lower.at(context.last_index)
    break_up = _closed_above(context, boundary, end)
    break_down = _closed_below(context, floor_price, end)
    status: PatternStatus = "confirmed" if (break_up or break_down) else "forming"
    apex_x = _apex(upper, lower)
    height = start_width

    target = boundary + height if direction != "bearish" else floor_price - height
    if break_down is not None and break_up is None:
        target = floor_price - height

    containment = _containment(context, upper, lower, start, end, config.max_fit_residual_atr)
    if containment < MIN_CONTAINMENT:
        return []
    geometry = containment * (
        0.5 * fit_quality(upper, context.atr, config.max_fit_residual_atr)
        + 0.5 * fit_quality(lower, context.atr, config.max_fit_residual_atr)
    )
    return [
        PatternResult(
            category="classic",
            type=kind,
            direction=direction,
            status=status,
            confidence=_confidence(
                context,
                geometry=geometry,
                volume=_volume_contraction(context, start, context.last_index),
                contextual=clamp(abs(_prior_trend(context, start)) / 3.0),
                status=status,
            ),
            start_time=context.series.dates[start],
            end_time=context.series.dates[context.last_index],
            price_low=round(floor_price, 4),
            price_high=round(boundary, 4),
            key_points={
                "upper_slope": round(upper.slope, 6),
                "upper_intercept": round(upper.intercept, 4),
                "lower_slope": round(lower.slope, 6),
                "lower_intercept": round(lower.intercept, 4),
                "upper_touches": len(upper_pivots),
                "lower_touches": len(lower_pivots),
                "apex_index": None if apex_x is None else round(apex_x, 1),
                "bars": round(span, 0),
            },
            confirmation_rule=(
                f"Close above {boundary:.2f}" if direction != "bearish" else f"Close below {floor_price:.2f}"
            ),
            measured_target=round(target, 4),
            invalidation_price=round(floor_price if direction != "bearish" else boundary, 4),
        )
    ]


def _apex(upper: LineFit, lower: LineFit) -> float | None:
    denominator = upper.slope - lower.slope
    if abs(denominator) < 1e-9:
        return None
    return (lower.intercept - upper.intercept) / denominator


def _boundary_window(
    context: PatternContext, min_bars: int, max_bars: int, min_touches: int
) -> tuple[LineFit, LineFit, list[Pivot], list[Pivot], int, int] | None:
    """Fit upper and lower trendlines to the most recent pivots inside the allowed window."""
    pivots = context.swings.internal
    last = context.last_index
    window_start = max(0, last - max_bars)
    inside = [pivot for pivot in pivots if pivot.index >= window_start]
    upper_pivots, lower_pivots = highs(inside), lows(inside)
    if len(upper_pivots) < 2 or len(lower_pivots) < 2:
        return None
    if len(upper_pivots) + len(lower_pivots) < min_touches:
        return None

    start = min(upper_pivots[0].index, lower_pivots[0].index)
    end = max(upper_pivots[-1].index, lower_pivots[-1].index)
    if end - start < min_bars:
        return None

    upper = fit_line([pivot.index for pivot in upper_pivots], [pivot.price for pivot in upper_pivots])
    lower = fit_line([pivot.index for pivot in lower_pivots], [pivot.price for pivot in lower_pivots])
    if upper is None or lower is None:
        return None
    return upper, lower, upper_pivots, lower_pivots, start, end


# --------------------------------------------------------------------------- flags


def detect_flag(context: PatternContext) -> list[PatternResult]:
    """A pole, then a shallow counter-drift. The flag may still be forming or already broken out."""
    config = context.config.flag
    close = context.series.close
    last = context.last_index
    atr = context.atr
    best: PatternResult | None = None

    # The consolidation does not have to reach the last bar: a flag that broke out a few bars ago
    # is still the pattern that explains the move.
    for flag_end in range(last, max(config.min_flag_bars, last - config.max_flag_bars) - 1, -1):
        for flag_bars in range(config.max_flag_bars, config.min_flag_bars - 1, -1):
            flag_start = flag_end - flag_bars
            if flag_start - config.max_pole_bars < 0:
                continue
            pole_start = _pole_start(context, flag_start, config.max_pole_bars, config.min_pole_atr * atr)
            if pole_start is None:
                continue

            pole_low = float(close[pole_start])
            pole_high = float(np.max(context.series.high[flag_start : flag_end + 1]))
            pole_size = float(close[flag_start]) - pole_low
            if pole_size <= 0:
                continue
            window_low = float(np.min(context.series.low[flag_start : flag_end + 1]))
            retracement = (pole_high - window_low) / pole_size
            if retracement > config.max_retracement:
                continue

            indices = list(range(flag_start, flag_end + 1))
            upper = fit_line(indices, list(context.series.high[flag_start : flag_end + 1]))
            if upper is None or upper.slope > 0:
                continue

            breakout = next(
                (i for i in range(flag_end + 1, last + 1) if close[i] > upper.at(i)),
                None,
            )
            status: PatternStatus = "confirmed" if breakout is not None else "forming"
            if status == "forming" and flag_end != last:
                continue  # an old, never-resolved flag is not news
            boundary = upper.at(breakout if breakout is not None else last)
            if close[last] < pole_high - config.invalidation_retracement * pole_size:
                continue

            candidate = PatternResult(
                category="classic",
                type="bull_flag",
                direction="bullish",
                status=status,
                confidence=_confidence(
                    context,
                    geometry=_pair_geometry(retracement, config.max_retracement),
                    volume=_volume_contraction(context, flag_start, flag_end),
                    contextual=clamp(pole_size / (atr * config.min_pole_atr * 2)),
                    status=status,
                ),
                start_time=context.series.dates[pole_start],
                end_time=context.series.dates[flag_end],
                price_low=round(window_low, 4),
                price_high=round(pole_high, 4),
                key_points={
                    "pole_start": round(pole_low, 4),
                    "pole_top": round(pole_high, 4),
                    "pole_bars": flag_start - pole_start,
                    "flag_bars": flag_bars,
                    "retracement": round(retracement, 3),
                    "boundary": round(boundary, 4),
                },
                confirmation_rule=f"Close above {boundary:.2f}",
                measured_target=round(boundary + pole_size, 4),
                invalidation_price=round(pole_high - config.invalidation_retracement * pole_size, 4),
            )
            if best is None or candidate.confidence > best.confidence:
                best = candidate
    return [best] if best else []


def _pole_start(context: PatternContext, flag_start: int, max_bars: int, min_move: float) -> int | None:
    """The bar that gives the *largest* qualifying advance into `flag_start`.

    Taking the shortest qualifying move instead would understate the pole, and every later
    ratio (retracement, measured target) is a fraction of the pole.
    """
    close = context.series.close
    first = flag_start - max_bars
    if first < 0:
        return None
    window = close[first : flag_start - 2]
    if window.size == 0:
        return None
    start = first + int(np.argmin(window))
    return start if close[flag_start] - close[start] >= min_move else None


# --------------------------------------------------------------------------- wedges and channels


def detect_wedge(context: PatternContext) -> list[PatternResult]:
    config = context.config.wedge
    window = _boundary_window(context, config.min_bars, config.max_bars, config.min_touches)
    if window is None:
        return []
    upper, lower, upper_pivots, lower_pivots, start, end = window

    if upper.slope * lower.slope <= 0:
        return []
    start_width = upper.at(start) - lower.at(start)
    end_width = upper.at(end) - lower.at(end)
    if start_width <= 0 or end_width <= 0:
        return []
    if end_width > (1.0 - config.min_convergence_ratio) * start_width:
        return []

    rising = upper.slope > 0
    kind = "rising_wedge" if rising else "falling_wedge"
    direction = "bearish" if rising else "bullish"
    boundary = lower.at(context.last_index) if rising else upper.at(context.last_index)
    break_index = _closed_below(context, boundary, end) if rising else _closed_above(context, boundary, end)
    status: PatternStatus = "confirmed" if break_index is not None else "forming"
    target = boundary - start_width if rising else boundary + start_width

    containment = _containment(context, upper, lower, start, end, config.max_fit_residual_atr)
    if containment < MIN_CONTAINMENT:
        return []
    geometry = containment * (
        0.5 * fit_quality(upper, context.atr, config.max_fit_residual_atr)
        + 0.5 * fit_quality(lower, context.atr, config.max_fit_residual_atr)
    )
    return [
        PatternResult(
            category="classic",
            type=kind,
            direction=direction,
            status=status,
            confidence=_confidence(
                context,
                geometry=geometry,
                volume=_volume_contraction(context, start, context.last_index),
                contextual=clamp(abs(_prior_trend(context, start)) / 3.0),
                status=status,
            ),
            start_time=context.series.dates[start],
            end_time=context.series.dates[context.last_index],
            price_low=round(lower.at(context.last_index), 4),
            price_high=round(upper.at(context.last_index), 4),
            key_points={
                "upper_slope": round(upper.slope, 6),
                "lower_slope": round(lower.slope, 6),
                "start_width": round(start_width, 4),
                "end_width": round(end_width, 4),
                "upper_touches": len(upper_pivots),
                "lower_touches": len(lower_pivots),
            },
            confirmation_rule=(f"Close {'below' if rising else 'above'} {boundary:.2f}"),
            measured_target=round(target, 4),
            invalidation_price=round(
                upper.at(context.last_index) if rising else lower.at(context.last_index), 4
            ),
        )
    ]


def detect_channel(context: PatternContext) -> list[PatternResult]:
    config = context.config.channel
    window = _boundary_window(context, config.min_bars, 250, config.min_touches_per_side * 2)
    if window is None:
        return []
    upper, lower, upper_pivots, lower_pivots, start, end = window
    if len(upper_pivots) < config.min_touches_per_side or len(lower_pivots) < config.min_touches_per_side:
        return []

    scale = max(abs(upper.slope), abs(lower.slope))
    if scale <= 0:
        return []
    if abs(upper.slope - lower.slope) / scale > config.max_slope_difference:
        return []

    top = upper.at(context.last_index)
    bottom = lower.at(context.last_index)
    if top <= bottom:
        return []
    direction = "bullish" if upper.slope > 0 else "bearish" if upper.slope < 0 else "neutral"
    containment = _containment(context, upper, lower, start, end, config.max_fit_residual_atr)
    if containment < MIN_CONTAINMENT:
        return []
    geometry = containment * (
        0.5 * fit_quality(upper, context.atr, config.max_fit_residual_atr)
        + 0.5 * fit_quality(lower, context.atr, config.max_fit_residual_atr)
    )
    close = context.series.close[-1]
    return [
        PatternResult(
            category="classic",
            type="channel",
            direction=direction,
            status="forming",
            confidence=_confidence(
                context,
                geometry=geometry,
                volume=0.5,
                contextual=clamp(abs(_prior_trend(context, start)) / 3.0),
                status="forming",
            ),
            start_time=context.series.dates[start],
            end_time=context.series.dates[context.last_index],
            price_low=round(bottom, 4),
            price_high=round(top, 4),
            key_points={
                "upper_slope": round(upper.slope, 6),
                "lower_slope": round(lower.slope, 6),
                "upper_touches": len(upper_pivots),
                "lower_touches": len(lower_pivots),
                "position": round((close - bottom) / (top - bottom), 3),
            },
            confirmation_rule="Context only: a channel is a container, not a signal",
            measured_target=round(top if direction == "bullish" else bottom, 4),
            invalidation_price=round(bottom if direction == "bullish" else top, 4),
        )
    ]


# --------------------------------------------------------------------------- cup and handle


def detect_cup_and_handle(context: PatternContext) -> list[PatternResult]:
    config = context.config.cup_handle
    series = context.series
    last = context.last_index
    best: PatternResult | None = None

    for handle_bars in range(6, config.max_handle_bars + 1, 2):
        handle_start = last - handle_bars
        for cup_bars in range(config.min_bars, config.max_bars + 1, 5):
            cup_start = handle_start - cup_bars
            if cup_start < 0:
                break
            cup_slice = slice(cup_start, handle_start + 1)
            xs = np.arange(cup_start, handle_start + 1, dtype=np.float64)
            fit = fit_quadratic(xs, series.close[cup_slice])
            if fit is None or fit.a <= 0 or fit.r2 < config.min_fit_r2:
                continue

            rim = float(max(series.close[cup_start], series.close[handle_start]))
            base = float(np.min(series.low[cup_slice]))
            depth_pct = (rim - base) / rim * 100.0
            if not config.min_depth_pct <= depth_pct <= config.max_depth_pct:
                continue

            handle_low = float(np.min(series.low[handle_start : last + 1]))
            handle_high = float(np.max(series.high[handle_start : last + 1]))
            handle_depth = rim - handle_low
            if handle_depth > config.max_handle_depth_ratio * (rim - base):
                continue
            if handle_low < rim - (rim - base) / 2:
                continue

            status: PatternStatus = "confirmed" if series.close[last] > handle_high else "forming"
            candidate = PatternResult(
                category="classic",
                type="cup_and_handle",
                direction="bullish",
                status=status,
                confidence=_confidence(
                    context,
                    geometry=clamp((fit.r2 - config.min_fit_r2) / (1.0 - config.min_fit_r2)),
                    volume=_volume_contraction(context, cup_start, last),
                    contextual=clamp(depth_pct / config.max_depth_pct),
                    status=status,
                ),
                start_time=series.dates[cup_start],
                end_time=series.dates[last],
                price_low=round(base, 4),
                price_high=round(rim, 4),
                key_points={
                    "rim": round(rim, 4),
                    "base": round(base, 4),
                    "depth_pct": round(depth_pct, 2),
                    "cup_bars": cup_bars,
                    "handle_bars": handle_bars,
                    "handle_low": round(handle_low, 4),
                    "handle_high": round(handle_high, 4),
                    "fit_r2": round(fit.r2, 4),
                },
                confirmation_rule=f"Close above the handle high at {handle_high:.2f}",
                measured_target=round(handle_high + (rim - base), 4),
                invalidation_price=round(handle_low, 4),
            )
            if best is None or candidate.confidence > best.confidence:
                best = candidate
    return [best] if best else []
