"""Shared builders for the analysis tests."""

from __future__ import annotations

from ai_engine.analysis import indicators as ind
from ai_engine.analysis import levels as level_analysis
from ai_engine.analysis import patterns, smc
from ai_engine.analysis.series import Series
from ai_engine.analysis.swings import SwingSet, detect
from ai_engine.pipeline.config import EngineConfig
from ai_engine.schemas.analysis import AccountSettings, AnalysisRequest, Bar


def swings_for(series: Series, config: EngineConfig, atr_value: float) -> SwingSet:
    swing_config = config.patterns.swings
    return detect(
        series.high,
        series.low,
        internal_n=swing_config.internal_fractal,
        external_n=swing_config.external_fractal,
        atr_value=atr_value,
        zigzag_threshold=swing_config.zigzag_atr_threshold,
    )


def pattern_context(series: Series, config: EngineConfig) -> patterns.PatternContext:
    values = ind.compute_all(series)
    atr_value = values.last_atr or 0.0
    return patterns.PatternContext(
        series=series,
        swings=swings_for(series, config, atr_value),
        atr=atr_value,
        config=config.patterns.patterns,
        volume_sma=values.volume_sma,
    )


def smc_context(series: Series, config: EngineConfig) -> smc.SmcContext:
    values = ind.compute_all(series)
    atr_value = values.last_atr or 0.0
    return smc.SmcContext(
        series=series,
        swings=swings_for(series, config, atr_value),
        atr=atr_value,
        config=config.patterns.smc,
    )


def level_set(series: Series, config: EngineConfig) -> level_analysis.LevelSet:
    values = ind.compute_all(series)
    atr_value = values.last_atr or 0.0
    swings = swings_for(series, config, atr_value)
    level_config = config.patterns.levels
    return level_analysis.build(
        series,
        [*swings.external, *swings.internal],
        atr_value,
        tolerance_atr=level_config.cluster_tolerance_atr,
        max_per_side=level_config.max_levels_per_side,
        recency_half_life=level_config.recency_half_life_bars,
        min_touches=level_config.min_touches,
        volume_sma=values.volume_sma,
    )


def request_for(series: Series, **overrides: object) -> AnalysisRequest:
    """An `AnalysisRequest` carrying the bars of a synthetic series."""
    bars = [
        Bar(
            date=series.dates[index],
            open=float(series.open[index]),
            high=float(series.high[index]),
            low=float(series.low[index]),
            close=float(series.close[index]),
            volume=None if series.volume is None else float(series.volume[index]),
        )
        for index in range(len(series))
    ]
    values: dict[str, object] = {
        "analysis_id": "test",
        "symbol": series.symbol,
        "timeframe": series.timeframe,
        "trading_style": "swing",
        "account": AccountSettings(equity=500_000),
        "bars": bars,
    }
    values.update(overrides)
    return AnalysisRequest(**values)
