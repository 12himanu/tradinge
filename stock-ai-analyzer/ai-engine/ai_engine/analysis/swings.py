"""Swing detection shared by the pattern, level and SMC analysis (docs/04 §5.4, §5.5).

Two degrees are produced: *internal* (fractal n = 2) for pattern geometry and *external*
(fractal n = 5) for market structure. An ATR zigzag filters noise when the series is choppy.
"""

from __future__ import annotations

from dataclasses import dataclass
from typing import Literal

import numpy as np

from ai_engine.analysis.series import FloatArray

PivotKind = Literal["high", "low"]
SwingLabel = Literal["HH", "HL", "LH", "LL"]


@dataclass(frozen=True, slots=True)
class Pivot:
    index: int
    price: float
    kind: PivotKind
    label: SwingLabel | None = None

    def with_label(self, label: SwingLabel) -> Pivot:
        return Pivot(self.index, self.price, self.kind, label)


def fractal_pivots(high: FloatArray, low: FloatArray, n: int = 2) -> list[Pivot]:
    """Bars whose high (low) is the extreme of the `n` bars on both sides.

    A tie on the left invalidates the pivot and a tie on the right does not, so a flat top
    yields exactly one pivot: the last bar of the plateau.
    """
    pivots: list[Pivot] = []
    size = high.size
    for i in range(n, size - n):
        window_left = slice(i - n, i)
        window_right = slice(i + 1, i + n + 1)
        if high[i] > np.max(high[window_left]) and high[i] >= np.max(high[window_right]):
            pivots.append(Pivot(i, float(high[i]), "high"))
        if low[i] < np.min(low[window_left]) and low[i] <= np.min(low[window_right]):
            pivots.append(Pivot(i, float(low[i]), "low"))
    return sorted(pivots, key=lambda pivot: pivot.index)


def alternate(pivots: list[Pivot]) -> list[Pivot]:
    """Force a strict high/low/high alternation, keeping the most extreme of each run."""
    ordered: list[Pivot] = []
    for pivot in pivots:
        if not ordered or ordered[-1].kind != pivot.kind:
            ordered.append(pivot)
            continue
        previous = ordered[-1]
        better = (pivot.price > previous.price) if pivot.kind == "high" else (pivot.price < previous.price)
        if better:
            ordered[-1] = pivot
    return ordered


def zigzag(high: FloatArray, low: FloatArray, atr_value: float, threshold: float = 1.5) -> list[Pivot]:
    """ATR zigzag: a reversal counts only once price moves `threshold` x ATR against the leg."""
    size = high.size
    if size == 0 or atr_value <= 0:
        return []
    move = threshold * atr_value

    pivots: list[Pivot] = []
    direction: PivotKind | None = None
    extreme_index = 0
    extreme_price = float(high[0])
    extreme_low_index = 0
    extreme_low_price = float(low[0])

    for i in range(1, size):
        if high[i] > extreme_price:
            extreme_price, extreme_index = float(high[i]), i
        if low[i] < extreme_low_price:
            extreme_low_price, extreme_low_index = float(low[i]), i

        if direction != "high" and extreme_price - float(low[i]) >= move and extreme_index < i:
            pivots.append(Pivot(extreme_index, extreme_price, "high"))
            direction = "high"
            extreme_low_price, extreme_low_index = float(low[i]), i
        elif direction != "low" and float(high[i]) - extreme_low_price >= move and extreme_low_index < i:
            pivots.append(Pivot(extreme_low_index, extreme_low_price, "low"))
            direction = "low"
            extreme_price, extreme_index = float(high[i]), i

    return alternate(sorted(pivots, key=lambda pivot: pivot.index))


def label_swings(pivots: list[Pivot]) -> list[Pivot]:
    """Label each pivot against the previous pivot of the same kind (HH, HL, LH, LL)."""
    labelled: list[Pivot] = []
    last_high: float | None = None
    last_low: float | None = None
    for pivot in pivots:
        label: SwingLabel | None = None
        if pivot.kind == "high":
            if last_high is not None:
                label = "HH" if pivot.price > last_high else "LH"
            last_high = pivot.price
        else:
            if last_low is not None:
                label = "HL" if pivot.price > last_low else "LL"
            last_low = pivot.price
        labelled.append(pivot.with_label(label) if label else pivot)
    return labelled


def highs(pivots: list[Pivot]) -> list[Pivot]:
    return [pivot for pivot in pivots if pivot.kind == "high"]


def lows(pivots: list[Pivot]) -> list[Pivot]:
    return [pivot for pivot in pivots if pivot.kind == "low"]


def last_pivot(pivots: list[Pivot], kind: PivotKind) -> Pivot | None:
    return next((pivot for pivot in reversed(pivots) if pivot.kind == kind), None)


@dataclass(frozen=True, slots=True)
class SwingSet:
    """Both degrees at once, so every consumer sees the same pivots."""

    internal: list[Pivot]
    external: list[Pivot]

    @property
    def labelled_external(self) -> list[Pivot]:
        return label_swings(self.external)


def detect(
    high: FloatArray,
    low: FloatArray,
    *,
    internal_n: int = 2,
    external_n: int = 5,
    atr_value: float | None = None,
    zigzag_threshold: float = 1.5,
) -> SwingSet:
    """Internal and external swings; the external set is zigzag-filtered when an ATR is known."""
    internal = alternate(fractal_pivots(high, low, internal_n))
    external = alternate(fractal_pivots(high, low, external_n))
    if atr_value and atr_value > 0:
        filtered = zigzag(high, low, atr_value, zigzag_threshold)
        if len(filtered) >= 2:
            external = _keep_confirmed(external, filtered, atr_value)
    return SwingSet(internal=internal, external=external)


def _keep_confirmed(external: list[Pivot], filtered: list[Pivot], atr_value: float) -> list[Pivot]:
    """Keep fractal pivots that the zigzag also saw; falls back to the fractals when it saw none."""
    tolerance = 0.25 * atr_value
    confirmed = [
        pivot
        for pivot in external
        if any(other.kind == pivot.kind and abs(other.price - pivot.price) <= tolerance for other in filtered)
    ]
    return confirmed if len(confirmed) >= 2 else external
