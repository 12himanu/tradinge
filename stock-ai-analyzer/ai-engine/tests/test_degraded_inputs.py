"""What happens when data is missing.

Most of the product's honesty lives in these branches: no volume, too few bars for an
indicator, no levels, no structure. Each must degrade to "unavailable" with a reason rather
than to a number nobody should trust.
"""

from __future__ import annotations

import numpy as np
import pytest

from ai_engine.agents import technical_agent
from ai_engine.analysis import scoring
from ai_engine.analysis.levels import LevelSet, LevelZone
from ai_engine.analysis.series import Series
from ai_engine.pipeline.config import EngineConfig
from ai_engine.pipeline.orchestrator import analyse
from tests.analysis_helpers import request_for
from tests.fixtures import synthetic as syn


def without_volume(series: Series) -> Series:
    return Series(
        symbol=series.symbol,
        timeframe=series.timeframe,
        tier=series.tier,
        dates=series.dates,
        open=series.open,
        high=series.high,
        low=series.low,
        close=series.close,
        volume=None,
    )


# --------------------------------------------------------------------------- factors


def test_macd_is_unavailable_without_enough_bars() -> None:
    factor = scoring.macd_momentum(None, None, None)
    assert not factor.available
    assert "50 bars" in factor.reason


def test_macd_histogram_direction_changes_the_sign() -> None:
    rising = scoring.macd_momentum(0.5, 0.2, 1.0)
    falling = scoring.macd_momentum(-0.5, -0.2, -1.0)
    assert rising.value is not None
    assert falling.value is not None
    assert rising.value > 0
    assert falling.value < 0


def test_volume_factor_is_unavailable_without_volume() -> None:
    factor = scoring.volume_confirmation(None, None, None)
    assert not factor.available
    assert "volume" in factor.reason


def test_obv_divergence_caps_the_volume_factor() -> None:
    strong = scoring.volume_confirmation(2.0, 2.0, None)
    diverging = scoring.volume_confirmation(2.0, 2.0, "bearish")
    assert strong.value is not None
    assert diverging.value is not None
    assert diverging.value < strong.value


def test_price_location_works_with_only_one_ema() -> None:
    factor = scoring.price_location(120.0, 100.0, None)
    assert factor.value == pytest.approx(1.0)
    assert "EMA 50" in factor.reason
    assert not scoring.price_location(120.0, None, None).available


def test_market_structure_discounts_a_change_of_character() -> None:
    bos = scoring.market_structure("bullish", "bos_bullish")
    choch = scoring.market_structure("bullish", "choch_bullish")
    assert bos.value is not None
    assert choch.value is not None
    assert choch.value < bos.value
    assert not scoring.market_structure("neutral", None).available


def test_room_to_move_is_unavailable_without_levels_or_atr() -> None:
    empty = LevelSet(supports=[], resistances=[])
    assert not scoring.room_to_move(100.0, empty, 2.0).available
    assert not scoring.room_to_move(100.0, empty, 0.0).available


def test_clear_sky_above_scores_full_room() -> None:
    levels = LevelSet(
        supports=[LevelZone(low=90.0, high=92.0, touches=2, last_touch_index=5)], resistances=[]
    )
    assert scoring.room_to_move(100.0, levels, 2.0).value == pytest.approx(1.0)


def test_pattern_factor_is_neutral_for_a_direction_neutral_shape() -> None:
    from ai_engine.schemas.analysis import PatternResult

    triangle = PatternResult(
        category="classic",
        type="symmetrical_triangle",
        direction="neutral",
        status="forming",
        confidence=0.6,
    )
    assert scoring.pattern_factor([triangle]).value == pytest.approx(0.0)


def test_scoring_with_nothing_available_returns_a_neutral_score(engine_config: EngineConfig) -> None:
    breakdown = scoring.score({}, engine_config.scoring, adx_value=None, data_quality=1.0)
    assert breakdown.setup_score == 50
    assert breakdown.bias == "neutral"
    assert breakdown.evidence_agreement == 0.0
    assert len(breakdown.dropped_factors) == len(engine_config.scoring.factors)


# --------------------------------------------------------------------------- pipeline


def test_analysis_without_volume_drops_the_volume_factor(engine_config: EngineConfig) -> None:
    result = analyse(request_for(without_volume(syn.ascending_triangle())), engine_config)

    assert result.score is not None
    assert "volume_confirmation" in result.score.dropped_factors
    assert any("volume" in warning.lower() for warning in result.warnings)
    obv = next(indicator for indicator in result.indicators if indicator.key == "obv")
    assert obv.status == "unavailable"
    assert result.setup_score is not None  # the rest of the analysis still stands


def test_short_history_marks_long_indicators_unavailable_with_a_reason(
    engine_config: EngineConfig,
) -> None:
    result = analyse(request_for(syn.trend(70, seed=15)), engine_config)
    ema_200 = next(indicator for indicator in result.indicators if indicator.key == "ema_200")
    assert ema_200.status == "unavailable"
    assert "70 available" in (ema_200.unavailable_reason or "")
    assert ema_200.value is None


def test_low_precision_indicators_are_flagged_not_hidden(engine_config: EngineConfig) -> None:
    """An EMA 50 computed on 93 bars is usable but should say it is thin."""
    result = analyse(request_for(syn.trend(93, seed=16)), engine_config)
    ema_50 = next(indicator for indicator in result.indicators if indicator.key == "ema_50")
    assert ema_50.status == "computed"
    assert "precision" in (ema_50.unavailable_reason or "")


def test_technical_summary_reports_unknowns_rather_than_guessing(engine_config: EngineConfig) -> None:
    factors = {
        "trend_ema_alignment": scoring.FactorInput(None, "n/a"),
        "momentum_macd": scoring.FactorInput(None, "n/a"),
        "volume_confirmation": scoring.FactorInput(None, "n/a"),
    }
    summary = technical_agent._summary(factors, None, None)
    assert summary.count("Unknown") == 3
    assert "Volatility: unknown" in summary
    assert "Regime: unknown" in summary


def test_flat_series_has_no_true_range_and_is_refused(engine_config: EngineConfig) -> None:
    """A series with no movement cannot produce an ATR, and nothing downstream is meaningful."""
    from ai_engine.errors import EngineError

    count = 80
    flat = Series(
        symbol="FLAT",
        timeframe="1D",
        tier="A",
        dates=syn.random_walk(count, seed=1).dates,
        open=np.full(count, 100.0),
        high=np.full(count, 100.0),
        low=np.full(count, 100.0),
        close=np.full(count, 100.0),
        volume=np.full(count, 1000.0),
    )
    with pytest.raises(EngineError, match="ATR"):
        analyse(request_for(flat), engine_config)
