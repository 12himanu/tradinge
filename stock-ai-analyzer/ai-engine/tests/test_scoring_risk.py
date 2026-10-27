"""Factor scoring and trade planning (P4A-14, P4A-15)."""

from __future__ import annotations

import pytest

from ai_engine.analysis import risk, scoring
from ai_engine.analysis.levels import LevelSet, LevelZone
from ai_engine.pipeline.config import EngineConfig
from ai_engine.schemas.analysis import AccountSettings, DealingRange, FeeSchedule, PatternResult, Position
from tests.fixtures import synthetic as syn

# --------------------------------------------------------------------------- factors


def test_ema_alignment_is_plus_one_for_a_perfect_stack() -> None:
    factor = scoring.ema_alignment({9: 110.0, 21: 105.0, 50: 100.0, 200: 90.0})
    assert factor.value == pytest.approx(1.0)


def test_ema_alignment_is_minus_one_when_reversed() -> None:
    factor = scoring.ema_alignment({9: 90.0, 21: 95.0, 50: 100.0, 200: 110.0})
    assert factor.value == pytest.approx(-1.0)


def test_ema_alignment_is_unavailable_with_one_ema() -> None:
    assert not scoring.ema_alignment({9: 100.0}).available


def test_rsi_above_seventy_is_context_not_a_sell_signal() -> None:
    factor = scoring.rsi_regime(78.0, None)
    assert factor.value is not None
    assert factor.value > 0
    assert "not a sell signal" in factor.reason


def test_bearish_divergence_overrides_a_strong_rsi() -> None:
    assert scoring.rsi_regime(78.0, "bearish").value == pytest.approx(-0.5)


def test_range_location_rewards_discount() -> None:
    discount = DealingRange(low=100, high=200, equilibrium=150, position=0.1, zone="discount")
    premium = DealingRange(low=100, high=200, equilibrium=150, position=0.9, zone="premium")
    assert scoring.range_location(discount).value == pytest.approx(0.8)
    assert scoring.range_location(premium).value == pytest.approx(-0.8)


def test_room_to_move_penalises_price_right_under_resistance() -> None:
    levels = LevelSet(
        supports=[LevelZone(low=90.0, high=92.0, touches=2, last_touch_index=5)],
        resistances=[LevelZone(low=100.2, high=101.0, touches=3, last_touch_index=9)],
    )
    factor = scoring.room_to_move(100.0, levels, atr_value=2.0)
    assert factor.value is not None
    assert factor.value < 0


def test_pattern_factor_is_signed_by_direction() -> None:
    bullish = PatternResult(
        category="classic", type="double_bottom", direction="bullish", status="confirmed", confidence=0.8
    )
    bearish = PatternResult(
        category="classic", type="double_top", direction="bearish", status="confirmed", confidence=0.8
    )
    assert scoring.pattern_factor([bullish]).value == pytest.approx(0.8)
    assert scoring.pattern_factor([bearish]).value == pytest.approx(-0.8)


# --------------------------------------------------------------------------- aggregation


def _factors(**values: float | None) -> dict[str, scoring.FactorInput]:
    return {key: scoring.FactorInput(value, f"{key} = {value}") for key, value in values.items()}


def test_unavailable_factors_are_dropped_and_weight_redistributed(engine_config: EngineConfig) -> None:
    breakdown = scoring.score(
        _factors(trend_ema_alignment=1.0, price_location=1.0, momentum_rsi=None),
        engine_config.scoring,
        adx_value=None,
        data_quality=1.0,
    )
    available = [factor for factor in breakdown.factors if factor.available]
    assert sum(factor.effective_weight for factor in available) == pytest.approx(1.0)
    assert "momentum_rsi" in breakdown.dropped_factors


def test_all_bullish_factors_produce_a_high_score(engine_config: EngineConfig) -> None:
    everything = dict.fromkeys(engine_config.scoring.factors, 1.0)
    breakdown = scoring.score(_factors(**everything), engine_config.scoring, adx_value=30.0, data_quality=1.0)
    assert breakdown.setup_score == 100
    assert breakdown.bias == "bullish"
    assert breakdown.evidence_agreement == pytest.approx(1.0)


def test_all_bearish_factors_produce_a_low_score(engine_config: EngineConfig) -> None:
    everything = dict.fromkeys(engine_config.scoring.factors, -1.0)
    breakdown = scoring.score(_factors(**everything), engine_config.scoring, adx_value=30.0, data_quality=1.0)
    assert breakdown.setup_score == 0
    assert breakdown.bias == "bearish"


def test_data_quality_pulls_the_score_toward_neutral(engine_config: EngineConfig) -> None:
    everything = dict.fromkeys(engine_config.scoring.factors, 1.0)
    full = scoring.score(_factors(**everything), engine_config.scoring, adx_value=None, data_quality=1.0)
    degraded = scoring.score(_factors(**everything), engine_config.scoring, adx_value=None, data_quality=0.85)
    assert degraded.setup_score < full.setup_score


def test_adx_modulates_trend_factors_only(engine_config: EngineConfig) -> None:
    factors = _factors(trend_ema_alignment=1.0, momentum_rsi=1.0)
    trending = scoring.score(factors, engine_config.scoring, adx_value=30.0, data_quality=1.0)
    ranging = scoring.score(factors, engine_config.scoring, adx_value=15.0, data_quality=1.0)
    trend_weight = next(f.effective_weight for f in trending.factors if f.key == "trend_ema_alignment")
    range_weight = next(f.effective_weight for f in ranging.factors if f.key == "trend_ema_alignment")
    assert trend_weight > range_weight
    assert trending.adx_regime == "trending"
    assert ranging.adx_regime == "ranging"


# --------------------------------------------------------------------------- risk


def _inputs(engine_config: EngineConfig, **overrides: object) -> risk.RiskInputs:
    series = syn.trend(120, seed=8, slope=1.0)
    values: dict[str, object] = {
        "series": series,
        "levels": LevelSet(
            supports=[
                LevelZone(
                    low=series.last_close - 20, high=series.last_close - 15, touches=3, last_touch_index=100
                )
            ],
            resistances=[
                LevelZone(
                    low=series.last_close + 25, high=series.last_close + 30, touches=2, last_touch_index=110
                )
            ],
        ),
        "atr": 6.0,
        "style": engine_config.style("swing"),
        "risk_config": engine_config.styles.risk,
        "account": AccountSettings(equity=500_000, risk_pct=1.0, lot_size=10),
        "fees": FeeSchedule(buy_pct=0.4, sell_pct=0.4),
        "position": Position(),
        "smc_zones": [],
    }
    values.update(overrides)
    return risk.RiskInputs(**values)  # type: ignore[arg-type]


def test_plans_are_produced_with_stop_below_entry(engine_config: EngineConfig) -> None:
    plans = risk.build_plans(_inputs(engine_config))
    assert plans
    for plan in plans:
        assert plan.stop_price < plan.entry_price
        assert all(target > plan.entry_price for target in plan.targets)


def test_net_risk_reward_is_below_gross_when_fees_apply(engine_config: EngineConfig) -> None:
    plans = risk.build_plans(_inputs(engine_config))
    assert all(plan.rr_net < plan.rr_gross for plan in plans)


def test_position_size_respects_the_risk_budget_and_the_cap(engine_config: EngineConfig) -> None:
    inputs = _inputs(engine_config)
    for plan in risk.build_plans(inputs):
        risk_amount = plan.quantity * (plan.entry_price - plan.stop_price)
        assert risk_amount <= inputs.account.equity * inputs.account.risk_pct / 100 + plan.entry_price
        assert plan.position_pct <= inputs.account.max_position_pct + 0.01
        assert plan.quantity % inputs.account.lot_size == 0


def test_a_stop_wider_than_the_style_limit_is_not_viable(engine_config: EngineConfig) -> None:
    """A 10 x ATR stop must be rejected, with the reason stated."""
    series = syn.trend(120, seed=8, slope=1.0)
    inputs = _inputs(
        engine_config,
        levels=LevelSet(
            supports=[
                LevelZone(
                    low=series.last_close - 80, high=series.last_close - 70, touches=2, last_touch_index=60
                )
            ],
            resistances=[
                LevelZone(
                    low=series.last_close + 5, high=series.last_close + 8, touches=2, last_touch_index=110
                )
            ],
        ),
    )
    plans = risk.build_plans(inputs)
    assert plans
    rejected = [plan for plan in plans if not plan.viable]
    assert rejected
    assert any("ATR" in reason or "%" in reason for plan in rejected for reason in plan.reasons)


def test_a_non_viable_plan_names_the_price_that_would_fix_it(engine_config: EngineConfig) -> None:
    series = syn.trend(120, seed=8, slope=1.0)
    inputs = _inputs(
        engine_config,
        levels=LevelSet(
            supports=[
                LevelZone(
                    low=series.last_close - 12, high=series.last_close - 10, touches=2, last_touch_index=100
                )
            ],
            resistances=[
                LevelZone(
                    low=series.last_close + 6, high=series.last_close + 7, touches=2, last_touch_index=110
                )
            ],
        ),
    )
    for plan in risk.build_plans(inputs):
        if plan.viable or plan.viable_at_price is None:
            continue
        assert plan.stop_price < plan.viable_at_price < plan.targets[0]
        recomputed = (plan.targets[0] - plan.viable_at_price - 0.008 * plan.viable_at_price) / (
            plan.viable_at_price - plan.stop_price + 0.008 * plan.viable_at_price
        )
        assert recomputed == pytest.approx(engine_config.style("swing").min_rr_net, abs=0.05)


def test_trailing_stop_never_moves_below_the_original(engine_config: EngineConfig) -> None:
    series = syn.trend(120, seed=8, slope=1.0)
    inputs = _inputs(
        engine_config,
        position=Position(
            status="holding", entry_price=series.last_close - 20, stop_price=series.last_close - 30
        ),
        last_structural_low=series.last_close - 60,
    )
    assert risk.trailing_stop(inputs) == pytest.approx(series.last_close - 30)


def test_unrealized_r_uses_the_position_stop_as_the_unit(engine_config: EngineConfig) -> None:
    series = syn.trend(120, seed=8, slope=1.0)
    inputs = _inputs(
        engine_config,
        position=Position(
            status="holding", entry_price=series.last_close - 10, stop_price=series.last_close - 20
        ),
    )
    assert risk.unrealized_r(inputs) == pytest.approx(1.0, abs=0.01)
