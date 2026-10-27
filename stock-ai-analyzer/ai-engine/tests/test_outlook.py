"""The measured next-day and next-week distributions behind the forecast page.

These are the only forward-looking numbers the app states, so what they must never become is a
prediction dressed as a measurement: every assertion here is about a property of the history.
"""

from __future__ import annotations

from datetime import UTC, datetime, timedelta

import numpy as np
import pytest

from ai_engine.analysis import outlook
from ai_engine.analysis.series import Series
from ai_engine.pipeline.config import EngineConfig
from ai_engine.schemas.analysis import PatternResult


def series_from(closes: list[float]) -> Series:
    """A series with these closes, one trading day apart. Only the closes matter here."""
    values = np.array(closes, dtype=np.float64)
    start = datetime(2020, 1, 1, tzinfo=UTC)
    return Series(
        symbol="TEST",
        timeframe="1D",
        tier="A",
        dates=[start + timedelta(days=index) for index in range(len(closes))],
        open=values,
        high=values * 1.01,
        low=values * 0.99,
        close=values,
        volume=np.full(len(closes), 1000.0),
    )


def test_a_steady_one_percent_rise_reads_as_one_every_time() -> None:
    closes = [100.0 * 1.01**day for day in range(200)]

    day, week = outlook.compute(series_from(closes))

    assert day.bars == 1
    assert week.bars == 5
    assert day.up_rate == 1.0
    assert day.median_move_pct == pytest.approx(1.0, abs=0.01)
    # Five bars of the same compounding, not five times the daily move.
    assert week.median_move_pct == pytest.approx(5.101, abs=0.01)


def test_the_band_brackets_the_last_close_in_price() -> None:
    rng = np.random.default_rng(7)
    closes = list(100 * np.exp(np.cumsum(rng.normal(0, 0.01, 400))))

    day = outlook.compute(series_from(closes))[0]

    assert day.band_low is not None
    assert day.band_high is not None
    assert day.reference_price is not None
    assert day.reference_price == pytest.approx(closes[-1], abs=0.01)
    assert day.band_low < day.reference_price < day.band_high
    # The band is the 10th-90th percentile: a fifth of the moves are outside it by construction.
    assert day.low_move_pct is not None
    assert day.low_move_pct < 0
    assert day.high_move_pct is not None
    assert day.high_move_pct > 0


def test_a_flat_history_has_no_direction_to_report() -> None:
    day = outlook.compute(series_from([100.0] * 120))[0]

    assert day.up_rate == 0.0  # not a single move closed higher
    assert day.median_move_pct == 0.0
    assert day.typical_move_pct == 0.0


def test_too_short_a_history_reports_the_sample_and_no_numbers() -> None:
    """Better to say "measured on 9 moves" than to draw percentiles through nine points."""
    day = outlook.compute(series_from([100.0 + day for day in range(10)]))[0]

    assert day.sample == 9
    assert day.up_rate is None
    assert day.band_low is None


def test_the_unfinished_tail_starts_no_move() -> None:
    """The last N closes have no bar to resolve against, so they are not in the sample."""
    closes = [100.0 + index for index in range(300)]

    day, week = outlook.compute(series_from(closes))

    assert day.sample == 299
    assert week.sample == 295


def test_a_series_of_one_bar_measures_nothing() -> None:
    assert outlook.compute(series_from([100.0])) == []


def engulfing_history(bars: int = 600) -> Series:
    """A random walk with a bullish engulfing pair printed every tenth bar."""
    rng = np.random.default_rng(5)
    rows: list[tuple[float, float, float, float]] = []
    price = 100.0
    for index in range(bars):
        if index % 10 == 8:  # a small red bar,
            open_, close = price + 1.0, price - 1.0
        elif index % 10 == 9:  # then a bigger green one that swallows it
            open_, close = price - 1.6, price + 1.8
        else:
            open_, close = price, price + float(rng.normal(0, 0.8))
        rows.append((open_, max(open_, close) + 0.3, min(open_, close) - 0.3, close))
        price = close

    start = datetime(2020, 1, 1, tzinfo=UTC)
    return Series(
        symbol="TEST",
        timeframe="1D",
        tier="A",
        dates=[start + timedelta(days=index) for index in range(bars)],
        open=np.array([row[0] for row in rows]),
        high=np.array([row[1] for row in rows]),
        low=np.array([row[2] for row in rows]),
        close=np.array([row[3] for row in rows]),
        volume=np.full(bars, 1000.0),
    )


def test_base_rates_are_measured_over_the_horizon_and_only_for_live_signals(
    engine_config: EngineConfig,
) -> None:
    """ "Within twenty bars" is not an answer to "tomorrow", so each horizon measures its own."""
    firing = [
        PatternResult(
            category="candlestick",
            type="bullish_engulfing",
            direction="bullish",
            status="confirmed",
            confidence=0.6,
        ),
        # Not a candlestick: a chart pattern has no per-bar base rate to look up.
        PatternResult(
            category="classic", type="triangle", direction="bullish", status="forming", confidence=0.5
        ),
    ]

    horizons = outlook.compute(
        engulfing_history(), candlesticks=engine_config.patterns.candlesticks, patterns=firing
    )

    assert [horizon.bars for horizon in horizons] == [1, 5]
    for horizon in horizons:
        assert horizon.base_rates, "the live signal has a history and should have been measured"
        # Each horizon answers its own question, and only about what is on the chart now.
        assert all(rate.horizon_bars == horizon.bars for rate in horizon.base_rates)
        assert all(rate.type == "bullish_engulfing" for rate in horizon.base_rates)


def test_a_signal_that_is_not_firing_is_not_reported(engine_config: EngineConfig) -> None:
    """The history holds bearish engulfings too; nobody asked about them."""
    firing = [
        PatternResult(
            category="candlestick",
            type="bullish_engulfing",
            direction="bullish",
            status="confirmed",
            confidence=0.6,
        )
    ]

    horizons = outlook.compute(
        engulfing_history(), candlesticks=engine_config.patterns.candlesticks, patterns=firing
    )

    assert all(rate.direction == "bullish" for horizon in horizons for rate in horizon.base_rates)


def test_without_the_patterns_no_base_rates_are_attached(engine_config: EngineConfig) -> None:
    closes = [100.0 + index * 0.5 for index in range(300)]

    horizons = outlook.compute(series_from(closes), candlesticks=engine_config.patterns.candlesticks)

    assert all(horizon.base_rates == [] for horizon in horizons)
