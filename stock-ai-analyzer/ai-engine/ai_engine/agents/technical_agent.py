"""Technical agent: indicators, their signals, and the evidence behind them (docs/04 §5.3)."""

from __future__ import annotations

import math
from dataclasses import dataclass

import numpy as np

from ai_engine.analysis import indicators as ind
from ai_engine.analysis.scoring import FactorInput, ema_alignment, macd_momentum, price_location, rsi_regime
from ai_engine.analysis.scoring import volume_confirmation as volume_factor
from ai_engine.analysis.series import Series
from ai_engine.analysis.swings import Pivot, highs, lows
from ai_engine.pipeline.context import AnalysisContext
from ai_engine.schemas.analysis import Direction, IndicatorResult

MIN_BARS_BY_KEY = {
    "rsi_14": ind.MIN_BARS_RSI,
    "macd_12_26_9": ind.MIN_BARS_MACD,
    "adx_14": ind.MIN_BARS_ADX,
    "atr_14": ind.MIN_BARS_ATR,
    "rvol_20": ind.MIN_BARS_VOLUME,
}


@dataclass(frozen=True, slots=True)
class TechnicalView:
    """What the rest of the pipeline needs from the technical read."""

    indicators: list[IndicatorResult]
    factors: dict[str, FactorInput]
    summary: str
    atr_pct: float | None
    adx_value: float | None
    rsi_divergence: Direction | None


def run(context: AnalysisContext) -> TechnicalView:
    """Compute every indicator, register evidence and produce the factor inputs for scoring."""
    series = context.series
    values = context.indicators
    if series is None or values is None:
        raise ValueError("technical agent needs a price series and indicator set")

    bars = len(series)
    results: list[IndicatorResult] = []
    ema_last: dict[int, float | None] = {}

    for period in ind.EMA_PERIODS:
        last = ind.last_valid(values.ema[period])
        ema_last[period] = last
        key = f"ema_{period}"
        if last is None:
            results.append(
                IndicatorResult(
                    key=key,
                    name="ema",
                    params={"period": period},
                    status="unavailable",
                    unavailable_reason=f"needs {period} bars; {bars} available",
                )
            )
            continue
        signal: Direction = "bullish" if series.last_close > last else "bearish"
        item = context.evidence.add_indicator(
            key,
            f"EMA {period} at {last:.2f}, price {'above' if signal == 'bullish' else 'below'} it",
            round(last, 4),
            unit="price",
            source="indicators",
            weight=0.6,
        )
        note = None
        if bars < 2 * period:
            note = f"computed on {bars} bars; below the {2 * period} bars wanted for full precision"
            context.warn(f"EMA {period} {note}")
        results.append(
            IndicatorResult(
                key=key,
                name="ema",
                params={"period": period},
                value=round(last, 4),
                signal=signal,
                status="computed",
                unavailable_reason=note,
                evidence_id=item.id,
            )
        )

    rsi_value = _guarded(values.rsi, bars, ind.MIN_BARS_RSI)
    divergence = _rsi_divergence(context, values.rsi) if rsi_value is not None else None
    results.append(
        _simple_result(
            context,
            key="rsi_14",
            name="rsi",
            params={"period": 14},
            value=rsi_value,
            bars=bars,
            signal=_rsi_signal(rsi_value),
            text=None if rsi_value is None else f"RSI 14 at {rsi_value:.1f}",
            weight=0.7,
        )
    )

    histogram = _guarded(values.macd.histogram, bars, ind.MIN_BARS_MACD)
    previous_histogram = (
        float(values.macd.histogram[-2])
        if values.macd.histogram.size >= 2 and math.isfinite(float(values.macd.histogram[-2]))
        else None
    )
    macd_line = _guarded(values.macd.macd, bars, ind.MIN_BARS_MACD)
    signal_line = _guarded(values.macd.signal, bars, ind.MIN_BARS_MACD)
    macd_result = _simple_result(
        context,
        key="macd_12_26_9",
        name="macd",
        params={"fast": 12, "slow": 26, "signal": 9},
        value=macd_line,
        bars=bars,
        signal=("bullish" if histogram and histogram > 0 else "bearish" if histogram else None),
        text=None if macd_line is None else f"MACD line {macd_line:+.3f}, histogram {histogram:+.3f}",
        weight=0.6,
    )
    if macd_line is not None:
        macd_result.components = {
            "macd": round(macd_line, 6),
            "signal": round(signal_line or 0.0, 6),
            "hist": round(histogram or 0.0, 6),
        }
    results.append(macd_result)

    adx_value = _guarded(values.adx.adx, bars, ind.MIN_BARS_ADX)
    plus_di = _guarded(values.adx.plus_di, bars, ind.MIN_BARS_ADX)
    minus_di = _guarded(values.adx.minus_di, bars, ind.MIN_BARS_ADX)
    adx_result = _simple_result(
        context,
        key="adx_14",
        name="adx",
        params={"period": 14},
        value=adx_value,
        bars=bars,
        signal=("bullish" if plus_di and minus_di and plus_di > minus_di else "bearish" if plus_di else None),
        text=None if adx_value is None else f"ADX 14 at {adx_value:.1f} ({_regime_word(adx_value)})",
        weight=0.5,
    )
    if adx_value is not None:
        adx_result.components = {
            "adx": round(adx_value, 4),
            "plus_di": round(plus_di or 0.0, 4),
            "minus_di": round(minus_di or 0.0, 4),
        }
    results.append(adx_result)

    atr_value = _guarded(values.atr, bars, ind.MIN_BARS_ATR)
    atr_pct = None if atr_value is None else atr_value / series.last_close * 100
    results.append(
        _simple_result(
            context,
            key="atr_14",
            name="atr",
            params={"period": 14},
            value=atr_value,
            bars=bars,
            signal=None,
            text=None if atr_value is None else f"ATR 14 at {atr_value:.2f} ({atr_pct:.1f}% of price)",
            weight=0.5,
            unit="price",
        )
    )

    up_down_ratio, breakout_rvol, obv_divergence = _volume_signals(context, series, values)
    results.append(
        _simple_result(
            context,
            key="rvol_20",
            name="rvol",
            params={"period": 20},
            value=breakout_rvol,
            bars=bars,
            signal=None,
            text=(
                None
                if breakout_rvol is None
                else f"Latest bar traded at {breakout_rvol:.2f} x its 20-bar average volume"
            ),
            weight=0.6,
            unit="ratio",
        )
    )
    obv_last = None if values.obv is None else ind.last_valid(values.obv)
    results.append(
        IndicatorResult(
            key="obv",
            name="obv",
            params={},
            value=None if obv_last is None else round(obv_last, 2),
            status="computed" if obv_last is not None else "unavailable",
            unavailable_reason=None if obv_last is not None else "no volume data",
        )
    )

    factors = {
        "trend_ema_alignment": ema_alignment(ema_last),
        "price_location": price_location(series.last_close, ema_last.get(50), ema_last.get(200)),
        "momentum_rsi": rsi_regime(rsi_value, divergence),
        "momentum_macd": macd_momentum(histogram, previous_histogram, macd_line),
        "volume_confirmation": volume_factor(up_down_ratio, breakout_rvol, obv_divergence),
    }
    _attach_evidence(context, factors)

    return TechnicalView(
        indicators=results,
        factors=factors,
        summary=_summary(factors, atr_pct, adx_value),
        atr_pct=atr_pct,
        adx_value=adx_value,
        rsi_divergence=divergence,
    )


def _guarded(values: np.ndarray, bars: int, minimum: int) -> float | None:
    """The last value, but only once enough bars exist for it to mean anything."""
    if bars < minimum:
        return None
    return ind.last_valid(values)


def _simple_result(
    context: AnalysisContext,
    *,
    key: str,
    name: str,
    params: dict[str, float],
    value: float | None,
    bars: int,
    signal: Direction | None,
    text: str | None,
    weight: float,
    unit: str = "none",
) -> IndicatorResult:
    if value is None or text is None:
        needed = MIN_BARS_BY_KEY.get(key)
        reason = f"needs {needed} bars; {bars} available" if needed else "not computable from this data"
        return IndicatorResult(
            key=key, name=name, params=params, status="unavailable", unavailable_reason=reason
        )
    item = context.evidence.add_indicator(
        key, text, round(value, 4), unit=unit, source="indicators", weight=weight
    )
    return IndicatorResult(
        key=key,
        name=name,
        params=params,
        value=round(value, 4),
        signal=signal,
        status="computed",
        evidence_id=item.id,
    )


def _rsi_signal(value: float | None) -> Direction | None:
    if value is None:
        return None
    return "bullish" if value >= 50 else "bearish"


def _regime_word(adx_value: float) -> str:
    if adx_value > 25:
        return "trending"
    return "ranging" if adx_value < 20 else "neither trending nor ranging clearly"


def _rsi_divergence(context: AnalysisContext, rsi_values: np.ndarray) -> Direction | None:
    """Compare the last two swing highs (and lows) in price against RSI at those bars."""
    swings = context.swings
    if swings is None:
        return None

    def at(pivots: list[Pivot]) -> tuple[Pivot, Pivot] | None:
        recent = [pivot for pivot in pivots if math.isfinite(float(rsi_values[pivot.index]))]
        return (recent[-2], recent[-1]) if len(recent) >= 2 else None

    high_pair = at(highs(swings.internal))
    if high_pair is not None:
        first, second = high_pair
        if second.price > first.price and rsi_values[second.index] < rsi_values[first.index]:
            context.evidence.add(
                "indicator",
                "rsi_divergence_bearish",
                f"Price made a higher high at {second.price:.2f} while RSI made a lower high",
                source="indicators",
                weight=0.8,
            )
            return "bearish"

    low_pair = at(lows(swings.internal))
    if low_pair is not None:
        first, second = low_pair
        if second.price < first.price and rsi_values[second.index] > rsi_values[first.index]:
            context.evidence.add(
                "indicator",
                "rsi_divergence_bullish",
                f"Price made a lower low at {second.price:.2f} while RSI made a higher low",
                source="indicators",
                weight=0.8,
            )
            return "bullish"
    return None


def _volume_signals(
    context: AnalysisContext, series: Series, values: ind.IndicatorSet
) -> tuple[float | None, float | None, Direction | None]:
    """Up/down volume ratio over 20 bars, the latest RVOL, and OBV-versus-price divergence."""
    if series.volume is None or not series.has_volume:
        context.warn("No volume data: volume confirmation was dropped from the score")
        return None, None, None

    window = min(20, len(series) - 1)
    if window < 5:
        return None, None, None
    changes = np.diff(series.close[-(window + 1) :])
    volumes = series.volume[-window:]
    up_volume = float(np.sum(volumes[changes > 0]))
    down_volume = float(np.sum(volumes[changes < 0]))
    ratio = up_volume / down_volume if down_volume > 0 else (2.0 if up_volume > 0 else None)
    if ratio is not None:
        context.evidence.add(
            "volume",
            "up_down_volume_ratio",
            f"Up-day volume is {ratio:.2f} x down-day volume over the last {window} bars",
            value=round(ratio, 4),
            unit="ratio",
            source="indicators",
            weight=0.7,
        )

    breakout_rvol = None
    if values.rvol is not None:
        last = ind.last_valid(values.rvol)
        breakout_rvol = None if last is None else round(last, 4)

    divergence: Direction | None = None
    if values.obv is not None and len(series) > window:
        obv_change = float(values.obv[-1] - values.obv[-window])
        price_change = float(series.close[-1] - series.close[-window])
        if price_change > 0 and obv_change < 0:
            divergence = "bearish"
        elif price_change < 0 and obv_change > 0:
            divergence = "bullish"
    return ratio, breakout_rvol, divergence


def _attach_evidence(context: AnalysisContext, factors: dict[str, FactorInput]) -> None:
    """Point each factor at the evidence a reader would want to check it against."""
    mapping = {
        "trend_ema_alignment": ["ema_9", "ema_21", "ema_50", "ema_200"],
        "price_location": ["ema_50", "ema_200"],
        "momentum_rsi": ["rsi_14", "rsi_divergence_bearish", "rsi_divergence_bullish"],
        "momentum_macd": ["macd_12_26_9"],
        "volume_confirmation": ["up_down_volume_ratio", "rvol_20"],
    }
    for key, evidence_keys in mapping.items():
        factor = factors.get(key)
        if factor is None:
            continue
        factors[key] = FactorInput(factor.value, factor.reason, context.evidence.ids_for(*evidence_keys))


def _summary(factors: dict[str, FactorInput], atr_pct: float | None, adx_value: float | None) -> str:
    """The one-line brief-format summary from docs/04 §5.3."""
    trend = _word(factors["trend_ema_alignment"].value, ("Bearish", "Mixed", "Bullish"))
    momentum = _word(factors["momentum_macd"].value, ("Negative", "Flat", "Positive"))
    volume = _word(factors["volume_confirmation"].value, ("Distributing", "Neutral", "Confirming"))
    if atr_pct is None:
        volatility = "unknown"
    else:
        band = "High" if atr_pct > 4 else "Normal" if atr_pct > 1.5 else "Low"
        volatility = f"{band} (ATR {atr_pct:.1f}%)"
    regime = "unknown" if adx_value is None else _regime_word(adx_value)
    return (
        f"Trend: {trend} · Momentum: {momentum} · Volume: {volume} · "
        f"Volatility: {volatility} · Regime: {regime}"
    )


def _word(value: float | None, words: tuple[str, str, str]) -> str:
    if value is None:
        return "Unknown"
    if value > 0.25:
        return words[2]
    if value < -0.25:
        return words[0]
    return words[1]
