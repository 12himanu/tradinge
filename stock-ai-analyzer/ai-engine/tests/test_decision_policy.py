"""One test per row of the decision table, plus precedence (P4A-17, docs/04 §6)."""

from __future__ import annotations

import pytest

from ai_engine.analysis.decision_policy import PolicyInputs, decide
from ai_engine.pipeline.config import EngineConfig
from ai_engine.schemas.analysis import TradePlan


def plan(*, viable: bool = True, trigger_met: bool = False, **overrides: object) -> TradePlan:
    values: dict[str, object] = {
        "direction": "long",
        "entry_type": "breakout",
        "entry_price": 560.0,
        "trigger_type": "close_above",
        "trigger_price": 558.0,
        "stop_price": 520.0,
        "targets": [620.0, 650.0],
        "risk_pct": 7.14,
        "reward_pct": 10.71,
        "rr_gross": 1.5,
        "rr_net": 2.4 if viable else 1.1,
        "quantity": 100,
        "position_value": 56_000.0,
        "position_pct": 11.2,
        "viable": viable,
        "trigger_met": trigger_met,
        "reasons": [] if viable else ["Net R:R 1.10 is below the 2.0 minimum for this style"],
        "viable_at_price": None if viable else 548.0,
    }
    values.update(overrides)
    return TradePlan(**values)


def inputs(**overrides: object) -> PolicyInputs:
    values: dict[str, object] = {
        "tier": "A",
        "symbol_resolved": True,
        "holding": False,
        "setup_score": 50,
        "plans": [],
        "last_close": 550.0,
    }
    values.update(overrides)
    return PolicyInputs(**values)  # type: ignore[arg-type]


def test_row_1_tier_c_limits_the_decision(engine_config: EngineConfig) -> None:
    decision = decide(inputs(tier="C", setup_score=None), engine_config.scoring)
    assert (decision.action, decision.rule_id, decision.limited) == ("wait", 1, True)


def test_row_1_unresolved_symbol_while_holding_becomes_hold(engine_config: EngineConfig) -> None:
    decision = decide(inputs(symbol_resolved=False, holding=True), engine_config.scoring)
    assert (decision.action, decision.rule_id, decision.limited) == ("hold", 1, True)


def test_row_2_close_below_the_position_stop_sells(engine_config: EngineConfig) -> None:
    decision = decide(
        inputs(holding=True, position_stop=560.0, last_close=550.0, setup_score=75),
        engine_config.scoring,
    )
    assert (decision.action, decision.rule_id) == ("sell", 2)
    assert decision.invalidation == 560.0


def test_row_2_scenario_invalidation_also_sells(engine_config: EngineConfig) -> None:
    decision = decide(
        inputs(holding=True, scenario_invalidation=555.0, last_close=550.0, setup_score=75),
        engine_config.scoring,
    )
    assert (decision.action, decision.rule_id) == ("sell", 2)


def test_row_3_bearish_choch_with_a_weak_score_sells(engine_config: EngineConfig) -> None:
    decision = decide(
        inputs(holding=True, structure_event="choch_bearish", setup_score=35), engine_config.scoring
    )
    assert (decision.action, decision.rule_id) == ("sell", 3)


def test_row_3_does_not_fire_when_the_score_holds_up(engine_config: EngineConfig) -> None:
    decision = decide(
        inputs(holding=True, structure_event="choch_bearish", setup_score=55), engine_config.scoring
    )
    assert (decision.action, decision.rule_id) == ("hold", 4)


def test_row_4_holding_otherwise_holds_with_a_trailing_stop(engine_config: EngineConfig) -> None:
    decision = decide(inputs(holding=True, setup_score=65, trailing_stop=530.0), engine_config.scoring)
    assert (decision.action, decision.rule_id, decision.invalidation) == ("hold", 4, 530.0)
    assert "530.00" in decision.conditions[0]


def test_row_5_weak_score_is_avoid(engine_config: EngineConfig) -> None:
    decision = decide(inputs(setup_score=38), engine_config.scoring)
    assert (decision.action, decision.rule_id) == ("avoid", 5)


def test_row_6_strong_score_with_a_met_trigger_is_buy(engine_config: EngineConfig) -> None:
    decision = decide(
        inputs(setup_score=72, plans=[plan(viable=True, trigger_met=True)]), engine_config.scoring
    )
    assert (decision.action, decision.rule_id, decision.invalidation) == ("buy", 6, 520.0)


def test_row_7_strong_score_without_a_trigger_waits(engine_config: EngineConfig) -> None:
    decision = decide(
        inputs(setup_score=72, plans=[plan(viable=True, trigger_met=False)]), engine_config.scoring
    )
    assert (decision.action, decision.rule_id) == ("wait", 7)
    assert "558.00" in decision.conditions[0]


def test_row_8_good_chart_poor_price_names_the_price(engine_config: EngineConfig) -> None:
    decision = decide(inputs(setup_score=72, plans=[plan(viable=False)]), engine_config.scoring)
    assert (decision.action, decision.rule_id) == ("wait", 8)
    assert any("548.00" in condition for condition in decision.conditions)


def test_row_9_middling_score_has_no_edge(engine_config: EngineConfig) -> None:
    decision = decide(inputs(setup_score=52), engine_config.scoring)
    assert (decision.action, decision.rule_id) == ("wait", 9)


@pytest.mark.parametrize(
    ("case", "expected_rule"),
    [
        # Tier C beats everything, even a stopped-out position.
        ({"tier": "C", "holding": True, "position_stop": 600.0, "setup_score": 20}, 1),
        # A breached stop beats a bearish CHoCH.
        ({"holding": True, "position_stop": 600.0, "structure_event": "choch_bearish", "setup_score": 20}, 2),
        # Holding beats the avoid rule: exits are handled by rows 2 and 3, not row 5.
        ({"holding": True, "setup_score": 10}, 4),
        # A met trigger beats a pending one when both plans are viable.
        (
            {"setup_score": 80, "plans": [plan(viable=True), plan(viable=True, trigger_met=True)]},
            6,
        ),
    ],
)
def test_precedence(case: dict[str, object], expected_rule: int, engine_config: EngineConfig) -> None:
    assert decide(inputs(**case), engine_config.scoring).rule_id == expected_rule


def test_every_decision_explains_itself(engine_config: EngineConfig) -> None:
    """No silent verdicts: each decision carries a rationale, and waits say what to watch."""
    cases = [
        inputs(tier="C", setup_score=None),
        inputs(setup_score=30),
        inputs(setup_score=52),
        inputs(setup_score=75, plans=[plan(viable=True, trigger_met=True)]),
        inputs(holding=True, setup_score=60, trailing_stop=500.0),
    ]
    for case in cases:
        decision = decide(case, engine_config.scoring)
        assert decision.rationale
        if decision.action in {"wait", "hold"}:
            assert decision.conditions
