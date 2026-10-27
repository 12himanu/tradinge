"""Deterministic factor scoring (docs/04-ai-agent-workflow.md §5.6).

Each factor is a number in [-1, 1]. Unavailable factors are dropped and their weight is
redistributed, trend factors are modulated by ADX, and the weighted sum becomes the 0-100 setup
score. Every weight is a documented prior from `config/scoring.yaml`, not a fitted truth.
"""

from __future__ import annotations

from dataclasses import dataclass, field

import numpy as np

from ai_engine.analysis.geometry import clamp
from ai_engine.analysis.levels import LevelSet
from ai_engine.pipeline.config import ScoringConfig
from ai_engine.schemas.analysis import (
    DealingRange,
    Direction,
    FactorScore,
    PatternResult,
    ScoreBreakdown,
)


@dataclass(frozen=True, slots=True)
class FactorInput:
    """One factor's value, or `None` when the data needed to judge it is missing."""

    value: float | None
    reason: str
    evidence_ids: list[str] = field(default_factory=list)

    @property
    def available(self) -> bool:
        return self.value is not None


# --------------------------------------------------------------------------- factor functions


def ema_alignment(ema_values: dict[int, float | None]) -> FactorInput:
    """Share of ordered pairs in the bullish stack 9 > 21 > 50 (> 200), mapped to [-1, 1]."""
    periods = [period for period in (9, 21, 50, 200) if ema_values.get(period) is not None]
    if len(periods) < 2:
        return FactorInput(None, "fewer than two EMAs available")
    pairs = [(fast, slow) for index, fast in enumerate(periods) for slow in periods[index + 1 :]]
    bullish = sum(1 for fast, slow in pairs if _value(ema_values, fast) > _value(ema_values, slow))
    fraction = bullish / len(pairs)
    stack = " > ".join(f"EMA {period}" for period in periods)
    return FactorInput(
        round(2 * fraction - 1, 4),
        f"{bullish} of {len(pairs)} EMA pairs in the bullish order ({stack})",
    )


def _value(values: dict[int, float | None], period: int) -> float:
    value = values[period]
    assert value is not None  # guarded by the caller
    return value


def price_location(close: float, ema_50: float | None, ema_200: float | None) -> FactorInput:
    """Where price sits relative to the two anchor EMAs."""
    references = [value for value in (ema_50, ema_200) if value is not None]
    if not references:
        return FactorInput(None, "EMA 50 and EMA 200 unavailable")
    above = sum(1 for value in references if close > value)
    value = 2 * (above / len(references)) - 1
    names = "EMA 50" if ema_200 is None else ("EMA 200" if ema_50 is None else "EMA 50 and EMA 200")
    where = "above" if value > 0 else "below" if value < 0 else "between"
    return FactorInput(round(value, 4), f"Close {where} {names}")


def market_structure(structure: Direction, last_event: str | None) -> FactorInput:
    """Bullish when the structure is making higher highs and the last break confirmed it."""
    if structure == "neutral" and last_event is None:
        return FactorInput(None, "no completed structure break in the window")
    base = 1.0 if structure == "bullish" else -1.0 if structure == "bearish" else 0.0
    if last_event is not None and last_event.startswith("choch"):
        base *= 0.6  # a change of character is a fresher, less-confirmed signal than a BOS
    event = last_event.replace("_", " ").upper() if last_event else "no break"
    return FactorInput(round(base, 4), f"Structure {structure}, last event {event}")


def rsi_regime(rsi_value: float | None, divergence: Direction | None) -> FactorInput:
    """Regime, not overbought/oversold: 40-50 holding is bullish, 50-60 failing is bearish."""
    if rsi_value is None:
        return FactorInput(None, "RSI needs 30 bars")
    if rsi_value >= 60:
        value, note = 0.7, "RSI in the bullish regime"
    elif rsi_value >= 50:
        value, note = 0.35, "RSI above the midline"
    elif rsi_value >= 40:
        value, note = -0.35, "RSI below the midline"
    else:
        value, note = -0.7, "RSI in the bearish regime"
    if divergence == "bearish":
        value = min(value, -0.5)
        note += ", bearish divergence against the last swing high"
    elif divergence == "bullish":
        value = max(value, 0.5)
        note += ", bullish divergence against the last swing low"
    context = " (above 70 is context in a strong trend, not a sell signal)" if rsi_value > 70 else ""
    return FactorInput(round(value, 4), f"{note} at {rsi_value:.1f}{context}")


def macd_momentum(
    histogram: float | None, previous_histogram: float | None, macd_line: float | None
) -> FactorInput:
    """Sign and direction of the histogram, plus whether the line sits above zero."""
    if histogram is None:
        return FactorInput(None, "MACD needs 50 bars")
    rising = previous_histogram is not None and histogram > previous_histogram
    value = 0.5 if histogram > 0 else -0.5
    if rising and histogram > 0:
        value = 1.0
    elif not rising and histogram < 0:
        value = -1.0
    if macd_line is not None:
        value = clamp(value + (0.15 if macd_line > 0 else -0.15), -1.0, 1.0)
    direction = "rising" if rising else "falling"
    return FactorInput(
        round(value, 4),
        f"MACD histogram {histogram:+.3f} and {direction}"
        + (f", line {'above' if macd_line > 0 else 'below'} zero" if macd_line is not None else ""),
    )


def volume_confirmation(
    up_down_ratio: float | None, breakout_rvol: float | None, obv_divergence: Direction | None
) -> FactorInput:
    """Up-volume dominance and the relative volume behind the latest push."""
    if up_down_ratio is None:
        return FactorInput(None, "no volume data")
    value = clamp((up_down_ratio - 1.0), -1.0, 1.0)
    notes = [f"Up/down volume ratio {up_down_ratio:.2f} over 20 bars"]
    if breakout_rvol is not None:
        value = clamp(value + 0.3 * (breakout_rvol - 1.0), -1.0, 1.0)
        notes.append(f"latest bar RVOL {breakout_rvol:.2f}")
    if obv_divergence == "bearish":
        value = min(value, -0.3)
        notes.append("OBV diverging from price")
    elif obv_divergence == "bullish":
        value = max(value, 0.3)
        notes.append("OBV confirming the advance")
    return FactorInput(round(value, 4), "; ".join(notes))


def pattern_factor(patterns: list[PatternResult]) -> FactorInput:
    """The strongest confirmed classic pattern, signed by direction and scaled by confidence."""
    relevant = [
        pattern
        for pattern in patterns
        if pattern.category == "classic" and pattern.status in {"confirmed", "forming"}
    ]
    if not relevant:
        return FactorInput(None, "no classic pattern detected")
    best = max(relevant, key=lambda pattern: pattern.confidence)
    if best.direction == "neutral":
        return FactorInput(0.0, f"{_label(best.type)} is direction-neutral until it breaks")
    sign = 1.0 if best.direction == "bullish" else -1.0
    weight = best.confidence if best.status == "confirmed" else best.confidence * 0.6
    return FactorInput(
        round(sign * weight, 4),
        f"{_label(best.type)} ({best.status}) at {best.confidence:.0%} confidence",
    )


def _label(pattern_type: str) -> str:
    return pattern_type.replace("_", " ").capitalize()


def room_to_move(price: float, levels: LevelSet, atr_value: float) -> FactorInput:
    """Distance to the next resistance, in ATR units: a good chart at a bad price scores poorly."""
    if atr_value <= 0:
        return FactorInput(None, "ATR unavailable")
    resistance = levels.nearest_resistance
    support = levels.nearest_support
    if resistance is None and support is None:
        return FactorInput(None, "no levels identified")
    if resistance is None:
        return FactorInput(1.0, "No resistance left inside the window")

    distance = (resistance.low - price) / atr_value
    value = clamp((distance - 0.5) / 1.5, -1.0, 1.0)
    note = f"{distance:.1f} x ATR to resistance at {resistance.low:.2f}"
    if support is not None:
        downside = (price - support.high) / atr_value
        note += f", {downside:.1f} x ATR to support at {support.high:.2f}"
    return FactorInput(round(value, 4), note)


def range_location(dealing_range: DealingRange | None) -> FactorInput:
    """Discount is good for longs, premium is not: f = 1 - 2p."""
    if dealing_range is None:
        return FactorInput(None, "no dealing range established")
    value = 1.0 - 2.0 * dealing_range.position
    return FactorInput(
        round(value, 4),
        f"Price at {dealing_range.position:.0%} of the dealing range ({dealing_range.zone})",
    )


# --------------------------------------------------------------------------- aggregation


def score(
    factors: dict[str, FactorInput],
    config: ScoringConfig,
    *,
    adx_value: float | None,
    data_quality: float,
) -> ScoreBreakdown:
    """Combine factors into the setup score, honouring redistribution and ADX modulation."""
    available = {key: value for key, value in factors.items() if value.available and key in config.factors}
    dropped = sorted(key for key in config.factors if key not in available)

    regime = _adx_regime(adx_value, config)
    weights: dict[str, float] = {}
    for key in available:
        weight = config.factors[key].weight
        if config.factors[key].trend_type:
            if regime == "trending":
                weight *= config.adx.trend_factor_multiplier_trending
            elif regime == "ranging":
                weight *= config.adx.trend_factor_multiplier_ranging
        weights[key] = weight

    total_weight = sum(weights.values())
    scores: list[FactorScore] = []
    raw = 0.0
    for key, factor in available.items():
        effective = weights[key] / total_weight if total_weight > 0 else 0.0
        value = factor.value or 0.0
        contribution = effective * value
        raw += contribution
        scores.append(
            FactorScore(
                key=key,
                label=config.factors[key].label,
                value=value,
                weight=round(config.factors[key].weight, 4),
                effective_weight=round(effective, 4),
                contribution=round(contribution, 4),
                available=True,
                reason=factor.reason,
                evidence_ids=factor.evidence_ids,
            )
        )
    for key in dropped:
        missing = factors.get(key)
        scores.append(
            FactorScore(
                key=key,
                label=config.factors[key].label,
                value=0.0,
                weight=round(config.factors[key].weight, 4),
                effective_weight=0.0,
                contribution=0.0,
                available=False,
                reason=missing.reason if missing else "not computed",
                evidence_ids=[],
            )
        )

    effective_score = clamp(raw * data_quality, -1.0, 1.0)
    setup_score = round(50 + 50 * effective_score)
    bias: Direction = (
        "bullish"
        if setup_score >= config.bias.bullish_at_or_above
        else "bearish"
        if setup_score <= config.bias.bearish_at_or_below
        else "neutral"
    )
    return ScoreBreakdown(
        setup_score=max(0, min(100, setup_score)),
        raw_score=round(clamp(raw, -1.0, 1.0), 4),
        effective_score=round(effective_score, 4),
        bias=bias,
        data_quality=round(data_quality, 3),
        evidence_agreement=_agreement(scores, bias),
        factors=sorted(scores, key=lambda item: -abs(item.contribution)),
        dropped_factors=dropped,
        adx_regime=regime,
    )


def _adx_regime(adx_value: float | None, config: ScoringConfig) -> str:
    if adx_value is None:
        return "neutral"
    if adx_value > config.adx.trending_above:
        return "trending"
    if adx_value < config.adx.ranging_below:
        return "ranging"
    return "neutral"


def _agreement(scores: list[FactorScore], bias: Direction) -> float:
    """Share of the weight that points the same way as the bias."""
    active = [item for item in scores if item.available and item.value != 0]
    total = sum(item.effective_weight for item in active)
    if total <= 0 or bias == "neutral":
        return 0.0
    wanted = 1.0 if bias == "bullish" else -1.0
    agreeing = sum(item.effective_weight for item in active if np.sign(item.value) == wanted)
    return round(agreeing / total, 3)
