"""End-to-end deterministic pipeline, evidence store and report (P4A-02, P4A-03, P4A-19)."""

from __future__ import annotations

import time

import pytest

from ai_engine.pipeline.config import EngineConfig
from ai_engine.pipeline.evidence import EvidenceStore
from ai_engine.pipeline.orchestrator import MIN_BARS_TIER_A, analyse
from tests.analysis_helpers import request_for
from tests.fixtures import synthetic as syn

# --------------------------------------------------------------------------- evidence store


def test_evidence_ids_are_sequential_and_stable() -> None:
    store = EvidenceStore()
    first = store.add("level", "support_1", "Support at 100", value=100.0, unit="price")
    second = store.add("level", "resistance_1", "Resistance at 120", value=120.0, unit="price")
    assert (first.id, second.id) == ("E1", "E2")
    assert store.id_for("support_1") == "E1"
    assert store.by_id("E2") is second
    assert store.allowed_values() == {100.0, 120.0}


def test_duplicate_evidence_keys_are_rejected() -> None:
    store = EvidenceStore()
    store.add("level", "support_1", "Support at 100")
    with pytest.raises(ValueError, match="already registered"):
        store.add("level", "support_1", "Support at 101")


def test_top_keeps_insertion_order_among_the_highest_weights() -> None:
    store = EvidenceStore()
    store.add("level", "a", "a", weight=0.1)
    store.add("level", "b", "b", weight=0.9)
    store.add("level", "c", "c", weight=0.5)
    assert [item.key for item in store.top(2)] == ["b", "c"]


# --------------------------------------------------------------------------- orchestrator


def test_tier_a_run_produces_a_complete_result(engine_config: EngineConfig) -> None:
    result = analyse(request_for(syn.ascending_triangle()), engine_config)

    assert result.tier == "A"
    assert result.status == "completed"
    assert result.setup_score is not None
    assert 0 <= result.setup_score <= 100
    assert result.decision.action in {"buy", "wait", "hold", "sell", "avoid"}
    assert result.indicators
    assert result.levels
    assert result.scenarios
    assert result.smc is not None
    assert result.evidence, "the analyst has nothing to cite without evidence"
    assert result.report_markdown
    assert result.engine_version == engine_config.engine_version


def test_every_scenario_pair_covers_both_directions(engine_config: EngineConfig) -> None:
    result = analyse(request_for(syn.double_bottom()), engine_config)
    names = {scenario.name for scenario in result.scenarios}
    assert names == {"bullish", "bearish"}
    for scenario in result.scenarios:
        assert scenario.trigger_price is not None
        assert scenario.invalidation is not None


def test_predictions_are_recorded_for_tier_a(engine_config: EngineConfig) -> None:
    result = analyse(request_for(syn.ascending_triangle()), engine_config)
    assert result.predictions
    bias = next(item for item in result.predictions if item.kind == "directional_bias")
    assert bias.horizon_bars == engine_config.style("swing").horizon_bars
    assert bias.as_of == result.as_of
    for setup in (item for item in result.predictions if item.kind == "trade_setup"):
        assert setup.stop_price is not None
        assert setup.entry_price is not None
        assert setup.stop_price < setup.entry_price < (setup.target_price_1 or float("inf"))


def test_too_few_bars_degrades_to_tier_c_without_numbers(engine_config: EngineConfig) -> None:
    short = syn.trend(MIN_BARS_TIER_A - 10, seed=4)
    result = analyse(request_for(short), engine_config)

    assert result.tier == "C"
    assert result.status == "partial"
    assert result.setup_score is None
    assert result.trade_plans == []
    assert result.predictions == []
    assert result.decision.limited is True
    assert result.warnings


def test_as_of_slices_the_series(engine_config: EngineConfig) -> None:
    series = syn.trend(150, seed=7)
    cutoff = series.dates[100]
    result = analyse(request_for(series, as_of=cutoff), engine_config)
    assert result.as_of == cutoff
    assert result.bar_count == 101


def test_holding_a_position_changes_the_decision_path(engine_config: EngineConfig) -> None:
    series = syn.trend(150, seed=7, slope=1.2)
    result = analyse(
        request_for(
            series,
            position={"status": "holding", "entry_price": float(series.close[100]), "stop_price": 1.0},
        ),
        engine_config,
    )
    assert result.decision.action in {"hold", "sell"}
    assert result.decision.rule_id in {2, 3, 4}


def test_every_cited_evidence_id_exists(engine_config: EngineConfig) -> None:
    """Nothing may point at an evidence id the store never issued."""
    result = analyse(request_for(syn.bull_flag()), engine_config)
    known = {item.id for item in result.evidence}
    cited = {
        *(level.evidence_id for level in result.levels),
        *(pattern.evidence_id for pattern in result.patterns),
        *(plan.evidence_id for plan in result.trade_plans),
        *(indicator.evidence_id for indicator in result.indicators),
        *(item for factor in (result.score.factors if result.score else []) for item in factor.evidence_ids),
    }
    assert {item for item in cited if item is not None} <= known


def test_report_contains_the_decision_and_the_disclaimer(engine_config: EngineConfig) -> None:
    result = analyse(request_for(syn.double_bottom()), engine_config)
    report = result.report_markdown or ""
    assert result.decision.action.upper() in report
    assert "not investment advice" in report
    assert "do not reveal actual" in report  # the SMC framing note
    assert "uncalibrated" in report


def test_report_quotes_no_number_that_is_not_in_the_evidence(engine_config: EngineConfig) -> None:
    """The template is the grounding baseline for Phase 4C: every price it prints is known."""
    result = analyse(request_for(syn.ascending_triangle()), engine_config)
    allowed = {
        round(value, 2)
        for item in result.evidence
        for value in (item.value, item.value_high)
        if value is not None
    }
    for price in _prices_in(result.report_markdown or ""):
        assert price in allowed or _is_derived(price, result), f"{price} is not grounded in evidence"


def _prices_in(report: str) -> set[float]:
    import re

    values: set[float] = set()
    # The sign matters: a factor contributing -0.07 is not the same fact as +0.07.
    for match in re.finditer(r"-?\b\d{1,3}(?:,\d{3})*\.\d{2}\b", report):
        values.add(round(float(match.group().replace(",", "")), 2))
    return values


def _is_derived(price: float, result) -> bool:  # type: ignore[no-untyped-def]
    """Percentages, ratios and sizing are computed from evidence rather than stored as evidence."""
    derived: set[float] = set()
    for plan in result.trade_plans:
        derived.update(
            {
                plan.risk_pct,
                plan.reward_pct,
                plan.rr_gross,
                plan.rr_net,
                plan.position_value,
                plan.position_pct,
            }
        )
    for level in result.levels:
        derived.update({level.price_low, level.price_high, level.strength})
    for pattern in result.patterns:
        derived.update(
            {
                value
                for value in (
                    pattern.price_low,
                    pattern.price_high,
                    pattern.measured_target,
                    pattern.invalidation_price,
                )
                if value is not None
            }
        )
    for indicator in result.indicators:
        if indicator.value is not None:
            derived.add(indicator.value)
    return round(price, 2) in {round(value, 2) for value in derived}


def test_deterministic_run_is_well_inside_the_two_second_budget(engine_config: EngineConfig) -> None:
    """docs/04 §12 budgets 2 s for the deterministic stages on 500 bars."""
    series = syn.random_walk(500, seed=12)
    request = request_for(series)
    started = time.perf_counter()
    result = analyse(request, engine_config)
    elapsed = time.perf_counter() - started
    assert elapsed < 2.0, f"{elapsed:.2f}s for 500 bars"
    assert sum(timing.duration_ms for timing in result.timings) < 2000


def test_progress_callback_reports_each_stage(engine_config: EngineConfig) -> None:
    seen: list[tuple[str, float]] = []
    analyse(request_for(syn.double_bottom()), engine_config, progress=lambda *args: seen.append(args))
    stages = [name for name, _ in seen]
    assert stages == [
        "indicators",
        "structure",
        "technical",
        "smc",
        "patterns",
        "base_rates",
        "scoring",
        "risk",
        "analyst",
        "report",
    ]
    assert [fraction for _, fraction in seen] == sorted(fraction for _, fraction in seen)


def test_the_same_input_gives_the_same_result(engine_config: EngineConfig) -> None:
    request = request_for(syn.ascending_triangle())
    first = analyse(request, engine_config)
    second = analyse(request, engine_config)
    assert first.model_dump(exclude={"timings"}) == second.model_dump(exclude={"timings"})
