"""Pattern detection against known shapes and random-walk controls (P4A-08 to P4A-11)."""

from __future__ import annotations

import pytest

from ai_engine.analysis import patterns
from ai_engine.pipeline.config import EngineConfig
from tests.analysis_helpers import pattern_context
from tests.fixtures import synthetic as syn

CONTROL_RUNS = 40
"""Random-walk series used to measure the false-positive rate (exit gate for 4A)."""


@pytest.mark.parametrize("shape", sorted(syn.ALL_SHAPES))
def test_detector_finds_its_own_shape(shape: str, engine_config: EngineConfig) -> None:
    series = syn.ALL_SHAPES[shape]()
    found = patterns.detect_all(pattern_context(series, engine_config))
    types = {pattern.type for pattern in found}
    assert shape in types, f"{shape} not detected; found {sorted(types)}"


@pytest.mark.parametrize("shape", ["double_bottom", "ascending_triangle", "bull_flag", "cup_and_handle"])
def test_bullish_shapes_carry_a_target_and_an_invalidation(shape: str, engine_config: EngineConfig) -> None:
    """A pattern without an invalidation price is an opinion, not a setup."""
    series = syn.ALL_SHAPES[shape]()
    found = patterns.detect_all(pattern_context(series, engine_config))
    pattern = next(item for item in found if item.type == shape)
    assert pattern.measured_target is not None
    assert pattern.invalidation_price is not None
    assert pattern.confirmation_rule


def test_forming_patterns_are_capped(engine_config: EngineConfig) -> None:
    cap = engine_config.patterns.patterns.forming_confidence_cap
    for factory in syn.ALL_SHAPES.values():
        found = patterns.detect_all(pattern_context(factory(), engine_config))
        for pattern in found:
            if pattern.status == "forming":
                assert pattern.confidence <= cap


def test_false_positive_rate_on_random_walks(engine_config: EngineConfig, record_property) -> None:  # type: ignore[no-untyped-def]
    """Controls with no designed structure: the rate is recorded and held below 1 per series."""
    hits: dict[str, int] = {}
    for seed in range(CONTROL_RUNS):
        found = patterns.detect_all(pattern_context(syn.random_walk(300, seed=seed), engine_config))
        for pattern in found:
            hits[pattern.type] = hits.get(pattern.type, 0) + 1

    total = sum(hits.values())
    rate = total / CONTROL_RUNS
    record_property("random_walk_false_positive_rate", rate)
    record_property("random_walk_false_positives_by_type", dict(sorted(hits.items())))
    assert rate <= 1.0, f"{rate:.2f} patterns per random-walk series: {hits}"


def test_nothing_fires_without_enough_bars(engine_config: EngineConfig) -> None:
    series = syn.trend(25, seed=9)
    assert patterns.detect_all(pattern_context(series, engine_config)) == []


def test_candlesticks_only_appear_near_levels(engine_config: EngineConfig) -> None:
    from ai_engine.analysis import candlesticks
    from ai_engine.analysis import indicators as ind

    series = syn.double_bottom()
    atr_value = ind.compute_all(series).last_atr or 0.0
    config = engine_config.patterns.candlesticks

    far_away = candlesticks.detect(series, [(1.0, 2.0)], atr_value, config)
    assert far_away == []

    at_price = float(series.close[-1])
    near = candlesticks.detect(series, [(at_price - atr_value, at_price + atr_value)], atr_value, config)
    assert all(pattern.category == "candlestick" for pattern in near)
