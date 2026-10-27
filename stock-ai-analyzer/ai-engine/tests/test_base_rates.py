"""Base rates are a measurement, so the tests pin the measurement rules, not example numbers."""

from __future__ import annotations

from datetime import UTC, datetime, timedelta

import numpy as np
import pytest

from ai_engine.analysis import base_rates
from ai_engine.analysis.series import Series
from ai_engine.pipeline.config import CandlestickConfig
from ai_engine.schemas.analysis import BaseRate


def candlestick_config() -> CandlestickConfig:
    return CandlestickConfig(
        min_body_ratio_engulfing=0.6,
        hammer_wick_ratio=2.0,
        doji_body_ratio=0.1,
        near_level_atr=0.5,
    )


def series_from(bars: list[tuple[float, float, float, float]]) -> Series:
    """`bars` are (open, high, low, close), oldest first, one trading day apart."""
    start = datetime(2020, 1, 1, tzinfo=UTC)
    return Series(
        symbol="TEST",
        timeframe="1D",
        tier="A",
        dates=[start + timedelta(days=index) for index in range(len(bars))],
        open=np.array([bar[0] for bar in bars], dtype=float),
        high=np.array([bar[1] for bar in bars], dtype=float),
        low=np.array([bar[2] for bar in bars], dtype=float),
        close=np.array([bar[3] for bar in bars], dtype=float),
        volume=np.full(len(bars), 1000.0),
    )


def flat_bars(
    count: int, price: float = 100.0, spread: float = 1.0
) -> list[tuple[float, float, float, float]]:
    """Quiet bars that establish an ATR without producing patterns or reaching a barrier."""
    return [(price, price + spread, price - spread, price) for _ in range(count)]


def bullish_engulfing_pair(price: float = 100.0) -> list[tuple[float, float, float, float]]:
    """A down bar, then a bar whose body swallows it."""
    return [
        (price, price + 0.5, price - 3.0, price - 2.5),
        (price - 3.0, price + 4.0, price - 3.2, price + 3.5),
    ]


def rate_for(rates: list[BaseRate], signal_type: str) -> BaseRate | None:
    return next((rate for rate in rates if rate.type == signal_type), None)


def test_returns_nothing_when_the_history_is_shorter_than_the_horizon() -> None:
    rates = base_rates.compute(series_from(flat_bars(20)), candlestick_config(), horizon_bars=20)

    assert rates == []


def test_ignores_a_signal_type_below_the_minimum_sample() -> None:
    bars = flat_bars(60) + bullish_engulfing_pair() + flat_bars(40)

    rates = base_rates.compute(series_from(bars), candlestick_config(), horizon_bars=20, min_sample=5)

    assert rate_for(rates, "bullish_engulfing") is None


def test_counts_an_occurrence_that_reaches_the_favourable_barrier_first() -> None:
    # Quiet bars set ATR near 2.0, so the barrier is ~2.0 either side of the signal close.
    bars = flat_bars(60)
    for _ in range(6):
        bars += bullish_engulfing_pair()
        # A clean run up: the upper barrier is reached, the lower never is.
        bars += [(103.5, 112.0, 103.4, 111.0)]
        bars += flat_bars(24, price=111.0)

    rates = base_rates.compute(series_from(bars), candlestick_config(), horizon_bars=20)
    rate = rate_for(rates, "bullish_engulfing")

    assert rate is not None
    assert rate.direction == "bullish"
    assert rate.sample_size >= 5
    assert rate.favourable == rate.sample_size
    assert rate.favourable_rate == 1.0
    assert rate.adverse_rate == 0.0


def test_counts_an_occurrence_that_reaches_the_adverse_barrier_first() -> None:
    bars = flat_bars(60)
    for _ in range(6):
        bars += bullish_engulfing_pair()
        bars += [(103.5, 103.6, 94.0, 95.0)]
        bars += flat_bars(24, price=95.0)

    rates = base_rates.compute(series_from(bars), candlestick_config(), horizon_bars=20)
    rate = rate_for(rates, "bullish_engulfing")

    assert rate is not None
    assert rate.adverse == rate.sample_size
    assert rate.favourable_rate == 0.0


def test_a_bar_touching_both_barriers_counts_against_the_signal() -> None:
    """Daily bars cannot say which barrier came first, so the setup does not get the benefit."""
    bars = flat_bars(60)
    for _ in range(6):
        bars += bullish_engulfing_pair()
        # Reaches far above and far below within the one bar.
        bars += [(103.5, 115.0, 92.0, 100.0)]
        bars += flat_bars(24)

    rates = base_rates.compute(series_from(bars), candlestick_config(), horizon_bars=20)
    rate = rate_for(rates, "bullish_engulfing")

    assert rate is not None
    assert rate.favourable == 0
    assert rate.adverse == rate.sample_size


def test_an_occurrence_that_reaches_neither_barrier_is_unresolved_not_a_loss() -> None:
    bars = flat_bars(60)
    for _ in range(6):
        bars += bullish_engulfing_pair()
        bars += flat_bars(25, price=103.5, spread=0.2)

    rates = base_rates.compute(series_from(bars), candlestick_config(), horizon_bars=20)
    rate = rate_for(rates, "bullish_engulfing")

    assert rate is not None
    assert rate.unresolved == rate.sample_size
    assert rate.favourable == 0
    assert rate.adverse == 0


def test_excludes_occurrences_without_a_full_horizon_after_them() -> None:
    """A signal that fired last week has not had time to be right or wrong, so it is not counted."""
    resolved = flat_bars(60)
    for _ in range(6):
        resolved += bullish_engulfing_pair()
        resolved += [(103.5, 112.0, 103.4, 111.0)]
        resolved += flat_bars(24, price=111.0)

    truncated = resolved + bullish_engulfing_pair() + flat_bars(3, price=103.5)

    settled = rate_for(
        base_rates.compute(series_from(resolved), candlestick_config(), horizon_bars=20), "bullish_engulfing"
    )
    with_recent = rate_for(
        base_rates.compute(series_from(truncated), candlestick_config(), horizon_bars=20), "bullish_engulfing"
    )

    assert settled is not None
    assert with_recent is not None
    assert with_recent.sample_size == settled.sample_size


def test_does_not_read_the_signal_bar_itself() -> None:
    """The signal bar's own range must not decide the outcome, or every wide bar would 'win'."""
    bars = flat_bars(60)
    for _ in range(6):
        # The engulfing bar's own high runs well past the upper barrier, but every bar after it is
        # flat. Body/range stays above the engulfing threshold so the pattern is actually detected.
        bars += [(100.0, 100.5, 97.0, 97.5), (97.0, 110.0, 96.9, 105.0)]
        bars += flat_bars(25, price=105.0, spread=0.2)

    rates = base_rates.compute(series_from(bars), candlestick_config(), horizon_bars=20)
    rate = rate_for(rates, "bullish_engulfing")

    assert rate is not None
    assert rate.favourable == 0
    assert rate.unresolved == rate.sample_size


def test_a_bearish_signal_is_favourable_when_price_falls() -> None:
    bars = flat_bars(60)
    for _ in range(6):
        # An up bar, then a bar whose body swallows it downward.
        bars += [(100.0, 103.0, 99.5, 102.5), (103.0, 103.2, 96.0, 96.5)]
        bars += [(96.5, 96.6, 90.0, 91.0)]
        bars += flat_bars(24, price=91.0)

    rates = base_rates.compute(series_from(bars), candlestick_config(), horizon_bars=20)
    rate = rate_for(rates, "bearish_engulfing")

    assert rate is not None
    assert rate.direction == "bearish"
    assert rate.favourable == rate.sample_size


def test_rates_sum_to_one_and_counts_sum_to_the_sample() -> None:
    bars = flat_bars(60)
    for index in range(10):
        bars += bullish_engulfing_pair()
        bars += [(103.5, 112.0, 103.4, 111.0)] if index % 2 else [(103.5, 103.6, 94.0, 95.0)]
        bars += flat_bars(24)

    rates = base_rates.compute(series_from(bars), candlestick_config(), horizon_bars=20)

    assert rates
    for rate in rates:
        assert rate.favourable + rate.adverse + rate.unresolved == rate.sample_size
        assert rate.favourable_rate + rate.adverse_rate + rate.unresolved_rate == pytest.approx(1.0, abs=1e-3)


def test_reports_the_barrier_as_a_percentage_of_price() -> None:
    bars = flat_bars(60)
    for _ in range(6):
        bars += bullish_engulfing_pair()
        bars += [(103.5, 112.0, 103.4, 111.0)]
        bars += flat_bars(24, price=111.0)

    rate = rate_for(
        base_rates.compute(series_from(bars), candlestick_config(), horizon_bars=20), "bullish_engulfing"
    )

    assert rate is not None
    assert rate.median_barrier_pct is not None
    assert 0 < rate.median_barrier_pct < 50
    assert rate.barrier_atr == base_rates.BARRIER_ATR


def test_a_doji_is_not_scored_because_it_has_no_side() -> None:
    bars = flat_bars(60)
    for _ in range(10):
        bars += [(100.0, 104.0, 96.0, 100.02)]
        bars += flat_bars(24)

    rates = base_rates.compute(series_from(bars), candlestick_config(), horizon_bars=20)

    assert rate_for(rates, "doji") is None
