"""Synthetic price series with known shapes (P4A-08).

Every generator returns a `Series` whose geometry is deliberate, so a detector either finds the
shape it was built for or has a bug. `random_walk` is the control: nothing should fire on it
often, and the false-positive rate is recorded by `tests/test_patterns_control.py`.
"""

from __future__ import annotations

from collections.abc import Sequence
from datetime import UTC, datetime, timedelta

import numpy as np

from ai_engine.analysis.series import FloatArray, Series

START = datetime(2025, 1, 1, tzinfo=UTC)
BASE_PRICE = 500.0


def _dates(count: int) -> list[datetime]:
    return [START + timedelta(days=index) for index in range(count)]


def path(anchors: Sequence[tuple[int, float]]) -> FloatArray:
    """Piecewise-linear interpolation through `(index, price)` anchors."""
    if len(anchors) < 2:
        raise ValueError("a path needs at least two anchors")
    xs = np.array([anchor[0] for anchor in anchors], dtype=np.float64)
    ys = np.array([anchor[1] for anchor in anchors], dtype=np.float64)
    grid = np.arange(int(xs[0]), int(xs[-1]) + 1, dtype=np.float64)
    return np.interp(grid, xs, ys).astype(np.float64)


def series_from_closes(
    closes: FloatArray,
    *,
    seed: int = 0,
    noise: float = 0.6,
    wick: float = 1.2,
    symbol: str = "SYN",
    volume: FloatArray | None = None,
) -> Series:
    """Wrap a close path in plausible OHLCV bars with deterministic noise."""
    rng = np.random.default_rng(seed)
    count = closes.size
    close = closes + rng.normal(0.0, noise, count)
    close = np.maximum(close, 1.0)
    open_ = np.empty(count, dtype=np.float64)
    open_[0] = close[0]
    open_[1:] = close[:-1]
    body_high = np.maximum(open_, close)
    body_low = np.minimum(open_, close)
    high = body_high + np.abs(rng.normal(0.0, wick, count))
    low = np.maximum(body_low - np.abs(rng.normal(0.0, wick, count)), 0.5)
    if volume is None:
        volume = rng.uniform(80_000, 120_000, count)
    return Series(
        symbol=symbol,
        timeframe="1D",
        tier="A",
        dates=_dates(count),
        open=open_,
        high=high,
        low=low,
        close=close,
        volume=volume.astype(np.float64),
    )


def _lead_in(bars: int, start: float, end: float) -> list[tuple[int, float]]:
    return [(0, start), (bars, end)]


def random_walk(count: int = 300, *, seed: int = 0, drift: float = 0.0, sigma: float = 4.0) -> Series:
    """Control series: no designed structure."""
    rng = np.random.default_rng(seed)
    steps = rng.normal(drift, sigma, count)
    closes = BASE_PRICE + np.cumsum(steps)
    closes = np.maximum(closes, 10.0)
    return series_from_closes(closes, seed=seed + 1, noise=0.4, wick=1.5, symbol="RW")


def trend(count: int = 200, *, seed: int = 0, slope: float = 1.2, leg_bars: int = 14) -> Series:
    """A staircase trend: advancing legs separated by ~35% pullbacks.

    A perfectly monotonic ramp has no swing points at all, so it would exercise none of the
    structure code; real trends breathe, and so does this one.
    """
    anchors: list[tuple[int, float]] = [(0, BASE_PRICE)]
    price = BASE_PRICE
    index = 0
    while index < count:
        leg = slope * leg_bars
        index += leg_bars
        price += leg
        anchors.append((index, price))
        index += max(3, leg_bars // 2)
        price -= 0.35 * leg
        anchors.append((index, price))
    return series_from_closes(path(anchors)[:count], seed=seed, symbol="TREND")


def double_bottom(*, seed: int = 1, depth: float = 60.0, gap: int = 24) -> Series:
    """Downtrend, two equal lows `gap` bars apart, then a close above the neckline."""
    low = BASE_PRICE - depth
    neckline = BASE_PRICE - depth * 0.35
    anchors = [
        (0, BASE_PRICE + 30),
        (30, BASE_PRICE),
        (50, low),
        (50 + gap // 2, neckline),
        (50 + gap, low + 1.0),
        (50 + gap + 18, neckline + 12),
    ]
    return series_from_closes(path(anchors), seed=seed, symbol="DBOT")


def double_top(*, seed: int = 2, height: float = 60.0, gap: int = 24) -> Series:
    high = BASE_PRICE + height
    neckline = BASE_PRICE + height * 0.35
    anchors = [
        (0, BASE_PRICE - 30),
        (30, BASE_PRICE),
        (50, high),
        (50 + gap // 2, neckline),
        (50 + gap, high - 1.0),
        (50 + gap + 18, neckline - 12),
    ]
    return series_from_closes(path(anchors), seed=seed, symbol="DTOP")


def head_and_shoulders(*, seed: int = 3) -> Series:
    neckline = BASE_PRICE
    shoulder = BASE_PRICE + 40
    head = BASE_PRICE + 75
    anchors = [
        (0, BASE_PRICE - 40),
        (25, neckline),
        (40, shoulder),
        (52, neckline + 2),
        (70, head),
        (85, neckline - 1),
        (100, shoulder - 2),
        (118, neckline - 25),
    ]
    return series_from_closes(path(anchors), seed=seed, symbol="HNS")


def inverse_head_and_shoulders(*, seed: int = 4) -> Series:
    neckline = BASE_PRICE
    shoulder = BASE_PRICE - 40
    head = BASE_PRICE - 75
    anchors = [
        (0, BASE_PRICE + 40),
        (25, neckline),
        (40, shoulder),
        (52, neckline - 2),
        (70, head),
        (85, neckline + 1),
        (100, shoulder + 2),
        (118, neckline + 25),
    ]
    return series_from_closes(path(anchors), seed=seed, symbol="IHNS")


def ascending_triangle(*, seed: int = 5) -> Series:
    """Flat highs at 560 with rising lows, then a breakout."""
    top = BASE_PRICE + 60
    anchors = [
        (0, BASE_PRICE - 20),
        (18, top),
        (32, BASE_PRICE),
        (46, top - 1),
        (60, BASE_PRICE + 20),
        (74, top - 1),
        (88, BASE_PRICE + 38),
        (100, top + 1),
        (112, top + 30),
    ]
    return series_from_closes(path(anchors), seed=seed, symbol="ASCT")


def descending_triangle(*, seed: int = 6) -> Series:
    bottom = BASE_PRICE - 60
    anchors = [
        (0, BASE_PRICE + 20),
        (18, bottom),
        (32, BASE_PRICE),
        (46, bottom + 1),
        (60, BASE_PRICE - 20),
        (74, bottom + 1),
        (88, BASE_PRICE - 38),
        (100, bottom - 1),
        (112, bottom - 30),
    ]
    return series_from_closes(path(anchors), seed=seed, symbol="DESCT")


def symmetrical_triangle(*, seed: int = 7) -> Series:
    anchors = [
        (0, BASE_PRICE),
        (16, BASE_PRICE + 60),
        (30, BASE_PRICE - 55),
        (44, BASE_PRICE + 42),
        (58, BASE_PRICE - 36),
        (72, BASE_PRICE + 24),
        (86, BASE_PRICE - 18),
        (100, BASE_PRICE + 10),
        (112, BASE_PRICE + 55),
    ]
    return series_from_closes(path(anchors), seed=seed, symbol="SYMT")


def bull_flag(*, seed: int = 8) -> Series:
    """A sharp pole, a shallow drifting-down flag, then a breakout."""
    pole_top = BASE_PRICE + 90
    anchors = [
        (0, BASE_PRICE - 20),
        (20, BASE_PRICE),
        (28, pole_top),
        (40, pole_top - 28),
        (46, pole_top - 20),
        (52, pole_top - 34),
        (60, pole_top + 6),
        (72, pole_top + 45),
    ]
    return series_from_closes(path(anchors), seed=seed, symbol="FLAG")


def rising_wedge(*, seed: int = 9) -> Series:
    """Both boundaries rise while the range narrows; breaks down at the end."""
    anchors = [
        (0, BASE_PRICE),
        (12, BASE_PRICE + 50),
        (24, BASE_PRICE + 12),
        (36, BASE_PRICE + 62),
        (48, BASE_PRICE + 32),
        (60, BASE_PRICE + 72),
        (72, BASE_PRICE + 50),
        (84, BASE_PRICE + 78),
        (96, BASE_PRICE + 62),
        (108, BASE_PRICE + 20),
    ]
    return series_from_closes(path(anchors), seed=seed, symbol="RWEDGE")


def falling_wedge(*, seed: int = 10) -> Series:
    anchors = [
        (0, BASE_PRICE),
        (12, BASE_PRICE - 50),
        (24, BASE_PRICE - 12),
        (36, BASE_PRICE - 62),
        (48, BASE_PRICE - 32),
        (60, BASE_PRICE - 72),
        (72, BASE_PRICE - 50),
        (84, BASE_PRICE - 78),
        (96, BASE_PRICE - 62),
        (108, BASE_PRICE - 20),
    ]
    return series_from_closes(path(anchors), seed=seed, symbol="FWEDGE")


def channel(*, seed: int = 11, slope: float = 0.9) -> Series:
    """Parallel rising boundaries with four touches a side."""
    anchors: list[tuple[int, float]] = [(0, BASE_PRICE)]
    amplitude = 35.0
    for leg in range(1, 9):
        index = leg * 14
        offset = amplitude if leg % 2 else -amplitude
        anchors.append((index, BASE_PRICE + slope * index + offset))
    return series_from_closes(path(anchors), seed=seed, symbol="CHAN")


def cup_and_handle(*, seed: int = 12, depth_pct: float = 22.0) -> Series:
    """Rounded base of `depth_pct`, then a shallow handle and a breakout."""
    rim = BASE_PRICE
    depth = rim * depth_pct / 100.0
    cup_bars = 70
    xs = np.linspace(-1.0, 1.0, cup_bars)
    cup = rim - depth * (1.0 - xs**2)
    lead = np.linspace(rim - 40, rim, 20)
    handle_low = rim - depth * 0.28
    handle = np.concatenate([np.linspace(rim, handle_low, 8), np.linspace(handle_low, rim + 2, 8)])
    breakout = np.linspace(rim + 2, rim + depth * 0.6, 12)
    closes = np.concatenate([lead, cup, handle, breakout]).astype(np.float64)
    return series_from_closes(closes, seed=seed, noise=0.4, wick=0.9, symbol="CUP")


ALL_SHAPES = {
    "double_bottom": double_bottom,
    "double_top": double_top,
    "head_and_shoulders": head_and_shoulders,
    "inverse_head_and_shoulders": inverse_head_and_shoulders,
    "ascending_triangle": ascending_triangle,
    "descending_triangle": descending_triangle,
    "symmetrical_triangle": symmetrical_triangle,
    "bull_flag": bull_flag,
    "rising_wedge": rising_wedge,
    "falling_wedge": falling_wedge,
    "channel": channel,
    "cup_and_handle": cup_and_handle,
}
