"""Indicator correctness, including TA-Lib parity (P4A-05).

The parity test runs against every CSV in `tests/fixtures/csv/` (drop real NEPSE exports there)
and falls back to synthetic series so it still means something before that data exists.
"""

from __future__ import annotations

import csv
from pathlib import Path

import numpy as np
import pytest

from ai_engine.analysis import indicators as ind
from ai_engine.analysis.series import FloatArray, Series
from tests.fixtures import synthetic as syn

talib = pytest.importorskip("talib", reason="TA-Lib is only needed for the parity gate")

PARITY_TOLERANCE = 1e-6
CSV_DIR = Path(__file__).parent / "fixtures" / "csv"


def _real_series() -> list[Series]:
    """Real exports if the developer supplied them (docs/csv-format.md layout)."""
    series: list[Series] = []
    for path in sorted(CSV_DIR.glob("*.csv")):
        rows = list(csv.DictReader(path.read_text(encoding="utf-8-sig").splitlines()))
        if len(rows) < 60:
            continue
        columns = {name.lower().strip(): name for name in rows[0]}
        series.append(
            Series(
                symbol=path.stem.upper(),
                timeframe="1D",
                tier="A",
                dates=[],
                open=_column(rows, columns, "open"),
                high=_column(rows, columns, "high"),
                low=_column(rows, columns, "low"),
                close=_column(rows, columns, "close"),
                volume=_column(rows, columns, "volume") if "volume" in columns else None,
            )
        )
    return series


def _column(rows: list[dict[str, str]], columns: dict[str, str], name: str) -> FloatArray:
    return np.array([float(row[columns[name]].replace(",", "")) for row in rows], dtype=np.float64)


def parity_series() -> list[Series]:
    real = _real_series()
    if len(real) >= 3:
        return real
    return [*real, syn.trend(300, seed=21), syn.random_walk(400, seed=22), syn.channel(seed=23)]


@pytest.mark.parametrize("series", parity_series(), ids=lambda series: series.symbol)
def test_matches_talib(series: Series) -> None:
    """Every indicator agrees with TA-Lib to 1e-6 wherever both produce a value."""
    close, high, low = series.close, series.high, series.low
    values = ind.compute_all(series)

    _assert_parity("ema_21", values.ema[21], talib.EMA(close, 21))
    _assert_parity("ema_50", values.ema[50], talib.EMA(close, 50))
    _assert_parity("rsi_14", values.rsi, talib.RSI(close, 14))
    macd_line, signal_line, histogram = talib.MACD(close, 12, 26, 9)
    _assert_parity("macd", values.macd.macd, macd_line)
    _assert_parity("macd_signal", values.macd.signal, signal_line)
    _assert_parity("macd_hist", values.macd.histogram, histogram)
    _assert_parity("atr_14", values.atr, talib.ATR(high, low, close, 14))
    _assert_parity("adx_14", values.adx.adx, talib.ADX(high, low, close, 14))
    _assert_parity("plus_di", values.adx.plus_di, talib.PLUS_DI(high, low, close, 14))
    _assert_parity("minus_di", values.adx.minus_di, talib.MINUS_DI(high, low, close, 14))
    if series.volume is not None:
        _assert_parity("obv", ind.obv(close, series.volume), talib.OBV(close, series.volume))
        _assert_parity("volume_sma", ind.sma(series.volume, 20), talib.SMA(series.volume, 20))


def _assert_parity(name: str, ours: FloatArray, theirs: FloatArray) -> None:
    reference = np.asarray(theirs, dtype=np.float64)
    comparable = np.isfinite(ours) & np.isfinite(reference)
    assert comparable.any(), f"{name}: nothing to compare"
    # TA-Lib emits nothing before its own lookback; we may emit earlier (the MACD line does),
    # but we must never be silent where TA-Lib speaks.
    missing = np.isfinite(reference) & ~np.isfinite(ours)
    assert not missing.any(), f"{name}: missing {int(missing.sum())} values TA-Lib produced"
    error = float(np.max(np.abs(ours[comparable] - reference[comparable])))
    assert error <= PARITY_TOLERANCE, f"{name}: max difference {error:.3e}"


def test_warm_up_slots_are_nan() -> None:
    """A partially-converged indicator is worse than no indicator: warm-up stays NaN."""
    series = syn.trend(120, seed=5)
    values = ind.compute_all(series)
    assert np.all(np.isnan(values.ema[50][:49]))
    assert np.isfinite(values.ema[50][49])
    assert np.all(np.isnan(values.rsi[:14]))
    assert np.all(np.isnan(values.atr[:14]))


def test_ema_seed_is_the_sma() -> None:
    values = np.arange(1, 21, dtype=np.float64)
    result = ind.ema(values, 5)
    assert result[4] == pytest.approx(np.mean(values[:5]))
    assert result[5] == pytest.approx((values[5] - result[4]) * (2 / 6) + result[4])


def test_rsi_is_100_for_an_unbroken_advance() -> None:
    values = np.arange(1, 40, dtype=np.float64)
    assert ind.rsi(values, 14)[-1] == pytest.approx(100.0)


def test_obv_follows_the_sign_of_the_close_change() -> None:
    close = np.array([10.0, 11.0, 10.5, 10.5, 12.0])
    volume = np.array([100.0, 200.0, 50.0, 70.0, 300.0])
    assert list(ind.obv(close, volume)) == [100.0, 300.0, 250.0, 250.0, 550.0]


def test_unavailable_indicators_are_none_not_zero() -> None:
    """A short series must report nothing rather than a number nobody should trust."""
    series = syn.trend(25, seed=1)
    values = ind.compute_all(series)
    assert ind.last_valid(values.ema[200]) is None
    assert ind.last_valid(values.adx.adx) is None


def test_indicators_run_in_well_under_the_budget() -> None:
    """500 bars is the realistic upper bound for a chart; the whole set must be far below 2 s."""
    import time

    series = syn.random_walk(500, seed=3)
    started = time.perf_counter()
    ind.compute_all(series)
    assert time.perf_counter() - started < 0.5
