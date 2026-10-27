"""Typed, validated loaders for `config/*.yaml` (P4A-04).

Configuration is read once per process and hashed into `engine_version`, so every stored
analysis can be reproduced with the exact tolerances that produced it.
"""

from __future__ import annotations

from dataclasses import dataclass
from functools import lru_cache
from pathlib import Path
from typing import Any

import yaml
from pydantic import BaseModel, ConfigDict, Field, ValidationError

from ai_engine.errors import ConfigurationError
from ai_engine.schemas.analysis import DataTier, Timeframe, TradingStyle
from ai_engine.versioning import engine_version


class _Strict(BaseModel):
    model_config = ConfigDict(extra="forbid")


# --------------------------------------------------------------------------- styles.yaml


class StyleProfile(_Strict):
    timeframes: list[Timeframe]
    horizon_bars: int = Field(gt=0)
    min_horizon_bars: int = Field(gt=0)
    stop_buffer_atr: float = Field(gt=0)
    max_stop_atr: float = Field(gt=0)
    max_stop_pct: float = Field(gt=0)
    min_rr_net: float = Field(gt=0)
    swing_lookback_bars: int = Field(gt=0)


class RiskConfig(_Strict):
    breakout_entry_atr_offset: float = Field(ge=0)
    pullback_max_distance_atr: float = Field(gt=0)
    market_entry_max_age_bars: int = Field(ge=0)
    market_entry_max_distance_atr: float = Field(gt=0)
    target_min_distance_atr: float = Field(ge=0)


class StylesConfig(_Strict):
    styles: dict[TradingStyle, StyleProfile]
    risk: RiskConfig


# --------------------------------------------------------------------------- scoring.yaml


class FactorConfig(_Strict):
    label: str
    weight: float = Field(ge=0)
    trend_type: bool = False


class AdxConfig(_Strict):
    trending_above: float
    ranging_below: float
    trend_factor_multiplier_trending: float = Field(gt=0)
    trend_factor_multiplier_ranging: float = Field(gt=0)


class BiasConfig(_Strict):
    bullish_at_or_above: int = Field(ge=0, le=100)
    bearish_at_or_below: int = Field(ge=0, le=100)


class DecisionConfig(_Strict):
    avoid_at_or_below: int = Field(ge=0, le=100)
    buy_at_or_above: int = Field(ge=0, le=100)
    sell_score_at_or_below: int = Field(ge=0, le=100)


class ScoringConfig(_Strict):
    version: int
    factors: dict[str, FactorConfig]
    adx: AdxConfig
    bias: BiasConfig
    decision: DecisionConfig
    tier_quality_cap: dict[DataTier, float]


# --------------------------------------------------------------------------- patterns.yaml


class SwingConfig(_Strict):
    internal_fractal: int = Field(ge=1)
    external_fractal: int = Field(ge=1)
    zigzag_atr_threshold: float = Field(gt=0)


class LevelConfig(_Strict):
    cluster_tolerance_atr: float = Field(gt=0)
    max_levels_per_side: int = Field(gt=0)
    recency_half_life_bars: float = Field(gt=0)
    min_touches: int = Field(ge=1)


class DoubleConfig(_Strict):
    max_pivot_difference_atr: float = Field(gt=0)
    min_bars_apart: int = Field(gt=0)
    max_bars_apart: int = Field(gt=0)
    min_neckline_distance_atr: float = Field(gt=0)


class HeadShouldersConfig(_Strict):
    min_head_prominence_atr: float = Field(gt=0)
    max_shoulder_difference_atr: float = Field(gt=0)
    max_bars_span: int = Field(gt=0)


class TriangleConfig(_Strict):
    min_touches: int = Field(ge=3)
    max_fit_residual_atr: float = Field(gt=0)
    max_slope_ratio_flat: float = Field(ge=0)
    min_bars: int = Field(gt=0)
    max_bars: int = Field(gt=0)


class FlagConfig(_Strict):
    min_pole_atr: float = Field(gt=0)
    max_pole_bars: int = Field(gt=0)
    min_flag_bars: int = Field(gt=0)
    max_flag_bars: int = Field(gt=0)
    max_retracement: float = Field(gt=0, lt=1)
    invalidation_retracement: float = Field(gt=0, lt=1)


class WedgeConfig(_Strict):
    min_touches: int = Field(ge=3)
    max_fit_residual_atr: float = Field(gt=0)
    min_convergence_ratio: float = Field(gt=0, lt=1)
    min_bars: int = Field(gt=0)
    max_bars: int = Field(gt=0)


class ChannelConfig(_Strict):
    min_touches_per_side: int = Field(ge=2)
    max_slope_difference: float = Field(gt=0)
    max_fit_residual_atr: float = Field(gt=0)
    min_bars: int = Field(gt=0)


class CupHandleConfig(_Strict):
    min_bars: int = Field(gt=0)
    max_bars: int = Field(gt=0)
    min_depth_pct: float = Field(gt=0)
    max_depth_pct: float = Field(gt=0)
    min_fit_r2: float = Field(ge=0, le=1)
    max_handle_depth_ratio: float = Field(gt=0, le=1)
    max_handle_bars: int = Field(gt=0)


class PatternGeometryConfig(_Strict):
    min_confidence: float = Field(ge=0, le=1)
    forming_confidence_cap: float = Field(ge=0, le=1)
    double: DoubleConfig
    head_shoulders: HeadShouldersConfig
    triangle: TriangleConfig
    flag: FlagConfig
    wedge: WedgeConfig
    channel: ChannelConfig
    cup_handle: CupHandleConfig


class CandlestickConfig(_Strict):
    near_level_atr: float = Field(gt=0)
    min_body_ratio_engulfing: float = Field(gt=0, le=1)
    hammer_wick_ratio: float = Field(gt=0)
    doji_body_ratio: float = Field(gt=0, le=1)


class SmcConfig(_Strict):
    displacement_atr: float = Field(gt=0)
    displacement_body_ratio: float = Field(gt=0, le=1)
    fvg_min_size_atr: float = Field(gt=0)
    fvg_mitigation_fill: float = Field(gt=0, le=1)
    equal_level_tolerance_atr: float = Field(gt=0)
    min_equal_touches: int = Field(ge=2)
    order_block_lookback: int = Field(gt=0)
    premium_threshold: float = Field(gt=0, lt=1)
    discount_threshold: float = Field(gt=0, lt=1)
    max_zones_reported: int = Field(gt=0)


class PatternsConfig(_Strict):
    swings: SwingConfig
    levels: LevelConfig
    patterns: PatternGeometryConfig
    candlesticks: CandlestickConfig
    smc: SmcConfig


# --------------------------------------------------------------------------- bundle


@dataclass(frozen=True, slots=True)
class EngineConfig:
    """Everything the deterministic pipeline needs, plus the version it hashes to."""

    styles: StylesConfig
    scoring: ScoringConfig
    patterns: PatternsConfig
    engine_version: str

    def style(self, name: TradingStyle) -> StyleProfile:
        profile = self.styles.styles.get(name)
        if profile is None:
            raise ConfigurationError(f"No style profile configured for {name!r}")
        return profile


def _load[T: BaseModel](path: Path, model: type[T]) -> T:
    try:
        raw: Any = yaml.safe_load(path.read_text(encoding="utf-8"))
        return model.model_validate(raw)
    except (OSError, yaml.YAMLError, ValidationError) as exc:
        raise ConfigurationError(f"Invalid configuration in {path}: {exc}") from exc


def load_engine_config(config_dir: Path) -> EngineConfig:
    """Read and validate every analysis config file; raises `ConfigurationError` on any problem."""
    return EngineConfig(
        styles=_load(config_dir / "styles.yaml", StylesConfig),
        scoring=_load(config_dir / "scoring.yaml", ScoringConfig),
        patterns=_load(config_dir / "patterns.yaml", PatternsConfig),
        engine_version=engine_version(config_dir),
    )


@lru_cache(maxsize=4)
def cached_engine_config(config_dir: Path) -> EngineConfig:
    """Process-wide cache; the config is immutable for the lifetime of the process."""
    return load_engine_config(config_dir)
