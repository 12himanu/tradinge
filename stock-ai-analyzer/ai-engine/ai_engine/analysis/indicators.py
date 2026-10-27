"""Indicator mathematics (docs/04-ai-agent-workflow.md §5.3).

Conventions follow TA-Lib exactly so `tests/test_indicators_parity.py` can assert equality to
1e-6 on real data: EMA is seeded with an SMA, Wilder smoothing is used for RSI, ATR, DI and ADX,
and every warm-up slot is `NaN` rather than a partially-converged value.
"""

from __future__ import annotations

from dataclasses import dataclass

import numpy as np

from ai_engine.analysis.series import FloatArray, Series

# Minimum bars before a value is trustworthy (docs/04 §5.3).
MIN_BARS_RSI = 30
MIN_BARS_MACD = 50
MIN_BARS_ADX = 42
MIN_BARS_ATR = 20
MIN_BARS_VOLUME = 20

EMA_PERIODS = (9, 21, 50, 200)


def _empty(size: int) -> FloatArray:
    return np.full(size, np.nan, dtype=np.float64)


def sma(values: FloatArray, period: int) -> FloatArray:
    """Simple moving average; the first `period - 1` slots stay NaN."""
    out = _empty(values.size)
    if period <= 0 or values.size < period:
        return out
    cumulative = np.cumsum(np.insert(values, 0, 0.0))
    out[period - 1 :] = (cumulative[period:] - cumulative[:-period]) / period
    return out


def ema(values: FloatArray, period: int) -> FloatArray:
    """Exponential moving average, seeded with the SMA of the first `period` values."""
    return ema_from(values, period, period - 1)


def ema_from(values: FloatArray, period: int, start: int) -> FloatArray:
    """EMA whose seed is the SMA of the `period` values ending at `start`.

    TA-Lib places the seed at the start of the *requested output window*, not at the start of
    the data, which is why MACD's fast EMA differs from a plain EMA(12). Matching that here
    keeps parity exact.
    """
    out = _empty(values.size)
    if period <= 0 or start < period - 1 or values.size <= start:
        return out
    alpha = 2.0 / (period + 1.0)
    current = float(np.mean(values[start - period + 1 : start + 1]))
    out[start] = current
    for i in range(start + 1, values.size):
        current = (values[i] - current) * alpha + current
        out[i] = current
    return out


def _wilder(values: FloatArray, period: int, first_index: int) -> FloatArray:
    """Wilder smoothing seeded with the mean of `values[first_index - period + 1 : first_index + 1]`."""
    out = _empty(values.size)
    if values.size <= first_index:
        return out
    current = float(np.mean(values[first_index - period + 1 : first_index + 1]))
    out[first_index] = current
    for i in range(first_index + 1, values.size):
        current = (current * (period - 1) + values[i]) / period
        out[i] = current
    return out


def rsi(close: FloatArray, period: int = 14) -> FloatArray:
    """Relative Strength Index with Wilder smoothing; first value at index `period`."""
    out = _empty(close.size)
    if close.size <= period:
        return out
    delta = np.diff(close)
    gains = np.where(delta > 0, delta, 0.0)
    losses = np.where(delta < 0, -delta, 0.0)

    avg_gain = float(np.mean(gains[:period]))
    avg_loss = float(np.mean(losses[:period]))
    out[period] = _rsi_value(avg_gain, avg_loss)
    for i in range(period, delta.size):
        avg_gain = (avg_gain * (period - 1) + gains[i]) / period
        avg_loss = (avg_loss * (period - 1) + losses[i]) / period
        out[i + 1] = _rsi_value(avg_gain, avg_loss)
    return out


def _rsi_value(avg_gain: float, avg_loss: float) -> float:
    if avg_loss == 0.0:
        return 100.0 if avg_gain > 0.0 else 0.0
    return 100.0 - 100.0 / (1.0 + avg_gain / avg_loss)


@dataclass(frozen=True, slots=True)
class MacdArrays:
    macd: FloatArray
    signal: FloatArray
    histogram: FloatArray


def macd(close: FloatArray, fast: int = 12, slow: int = 26, signal_period: int = 9) -> MacdArrays:
    """MACD line, signal line and histogram.

    Both EMAs are seeded at `slow - 1` and the signal EMA at `slow + signal_period - 2`, which is
    where TA-Lib starts emitting values; the MACD line is kept from `slow - 1` for slope checks.
    """
    line_start = slow - 1
    line = ema_from(close, fast, line_start) - ema_from(close, slow, line_start)
    signal = ema_from(line, signal_period, line_start + signal_period - 1)
    return MacdArrays(macd=line, signal=signal, histogram=line - signal)


def true_range(high: FloatArray, low: FloatArray, close: FloatArray) -> FloatArray:
    """True range; index 0 is NaN because it has no previous close (TA-Lib convention)."""
    out = _empty(high.size)
    if high.size < 2:
        return out
    previous = close[:-1]
    out[1:] = np.maximum(
        high[1:] - low[1:], np.maximum(np.abs(high[1:] - previous), np.abs(low[1:] - previous))
    )
    return out


def atr(high: FloatArray, low: FloatArray, close: FloatArray, period: int = 14) -> FloatArray:
    """Average true range with Wilder smoothing; first value at index `period`."""
    tr = true_range(high, low, close)
    if tr.size <= period:
        return _empty(tr.size)
    return _wilder(tr, period, first_index=period)


@dataclass(frozen=True, slots=True)
class AdxArrays:
    adx: FloatArray
    plus_di: FloatArray
    minus_di: FloatArray


def adx(high: FloatArray, low: FloatArray, close: FloatArray, period: int = 14) -> AdxArrays:
    """Wilder's ADX with +DI and -DI. DI starts at index `period`, ADX at index `2 * period - 1`."""
    size = high.size
    empty = AdxArrays(_empty(size), _empty(size), _empty(size))
    if size <= period * 2:
        return empty

    up = high[1:] - high[:-1]
    down = low[:-1] - low[1:]
    plus_dm = np.zeros(size, dtype=np.float64)
    minus_dm = np.zeros(size, dtype=np.float64)
    plus_dm[1:] = np.where((up > down) & (up > 0), up, 0.0)
    minus_dm[1:] = np.where((down > up) & (down > 0), down, 0.0)

    tr = true_range(high, low, close)
    tr[0] = 0.0  # only used inside the seeded sums below
    smooth_tr = _wilder_sum(tr, period)
    smooth_plus = _wilder_sum(plus_dm, period)
    smooth_minus = _wilder_sum(minus_dm, period)

    with np.errstate(divide="ignore", invalid="ignore"):
        plus_di = np.where(smooth_tr > 0, 100.0 * smooth_plus / smooth_tr, np.nan)
        minus_di = np.where(smooth_tr > 0, 100.0 * smooth_minus / smooth_tr, np.nan)
        di_sum = plus_di + minus_di
        dx = np.where(di_sum > 0, 100.0 * np.abs(plus_di - minus_di) / di_sum, 0.0)
    dx = np.where(np.isnan(smooth_tr), np.nan, dx)

    adx_values = _empty(size)
    first_dx = period  # DX is defined from here on
    first_adx = first_dx + period - 1
    if size > first_adx:
        current = float(np.mean(dx[first_dx : first_dx + period]))
        adx_values[first_adx] = current
        for i in range(first_adx + 1, size):
            current = (current * (period - 1) + dx[i]) / period
            adx_values[i] = current
    return AdxArrays(adx=adx_values, plus_di=plus_di, minus_di=minus_di)


def _wilder_sum(values: FloatArray, period: int) -> FloatArray:
    """Wilder's running sum as TA-Lib seeds it: `period - 1` values, then one smoothing step."""
    out = _empty(values.size)
    if values.size <= period:
        return out
    current = float(np.sum(values[1:period]))
    for i in range(period, values.size):
        current = current - current / period + values[i]
        out[i] = current
    return out


def obv(close: FloatArray, volume: FloatArray) -> FloatArray:
    """On-balance volume; starts at the first bar's volume (TA-Lib convention)."""
    out = _empty(close.size)
    if close.size == 0:
        return out
    current = float(volume[0])
    out[0] = current
    for i in range(1, close.size):
        if close[i] > close[i - 1]:
            current += float(volume[i])
        elif close[i] < close[i - 1]:
            current -= float(volume[i])
        out[i] = current
    return out


def rvol(volume: FloatArray, period: int = 20) -> FloatArray:
    """Relative volume: volume divided by its own SMA."""
    average = sma(volume, period)
    with np.errstate(divide="ignore", invalid="ignore"):
        return np.where(average > 0, volume / average, np.nan)


def slope_per_atr(values: FloatArray, bars: int, atr_value: float) -> float | None:
    """Change over `bars` bars expressed in ATR units, so trends compare across instruments."""
    if atr_value <= 0 or values.size <= bars:
        return None
    end = values[-1]
    start = values[-1 - bars]
    if np.isnan(end) or np.isnan(start):
        return None
    return float((end - start) / atr_value)


def last_valid(values: FloatArray) -> float | None:
    """The most recent non-NaN value, or None when the indicator never warmed up."""
    if values.size == 0:
        return None
    finite = np.flatnonzero(np.isfinite(values))
    return float(values[finite[-1]]) if finite.size else None


@dataclass(frozen=True, slots=True)
class IndicatorSet:
    """Every indicator array for one series, computed once and shared by the agents."""

    ema: dict[int, FloatArray]
    rsi: FloatArray
    macd: MacdArrays
    adx: AdxArrays
    atr: FloatArray
    volume_sma: FloatArray | None
    rvol: FloatArray | None
    obv: FloatArray | None

    @property
    def last_atr(self) -> float | None:
        return last_valid(self.atr)


def compute_all(series: Series, *, atr_period: int = 14) -> IndicatorSet:
    """Compute the full indicator set. Unavailable indicators stay as all-NaN arrays."""
    close = series.close
    return IndicatorSet(
        ema={period: ema(close, period) for period in EMA_PERIODS},
        rsi=rsi(close),
        macd=macd(close),
        adx=adx(series.high, series.low, close),
        atr=atr(series.high, series.low, close, atr_period),
        volume_sma=sma(series.volume, 20) if series.volume is not None else None,
        rvol=rvol(series.volume, 20) if series.volume is not None else None,
        obv=obv(close, series.volume) if series.volume is not None else None,
    )
