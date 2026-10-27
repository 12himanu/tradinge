"""Smart Money Concepts structure, zones and liquidity (P4A-12, P4A-13)."""

from __future__ import annotations

import numpy as np

from ai_engine.analysis import smc
from ai_engine.pipeline.config import EngineConfig
from tests.analysis_helpers import smc_context
from tests.fixtures import synthetic as syn


def test_uptrend_reads_as_bullish_structure(engine_config: EngineConfig) -> None:
    result = smc.analyse(smc_context(syn.trend(200, seed=2, slope=1.5), engine_config))
    assert result.structure == "bullish"
    assert result.last_event is not None
    assert result.last_event.endswith("bullish")


def test_downtrend_reads_as_bearish_structure(engine_config: EngineConfig) -> None:
    result = smc.analyse(smc_context(syn.trend(200, seed=3, slope=-1.5), engine_config))
    assert result.structure == "bearish"


def test_structure_break_never_precedes_its_swing(engine_config: EngineConfig) -> None:
    """A break of structure is a close beyond an *earlier* swing, never a later one."""
    for factory in (syn.double_bottom, syn.head_and_shoulders, syn.channel):
        result = smc.analyse(smc_context(factory(), engine_config))
        for event in result.events:
            if event.type in {"bos", "choch"}:
                assert event.key_points["swing_index"] < event.key_points["broken_at_index"]


def test_framing_note_travels_with_every_result(engine_config: EngineConfig) -> None:
    result = smc.analyse(smc_context(syn.bull_flag(), engine_config))
    assert "do not reveal actual" in result.framing_note


def test_fair_value_gaps_respect_the_minimum_size(engine_config: EngineConfig) -> None:
    context = smc_context(syn.bull_flag(), engine_config)
    minimum = engine_config.patterns.smc.fvg_min_size_atr * context.atr
    result = smc.analyse(context)
    for gap in (event for event in result.events if event.type == "fvg"):
        assert gap.price_high is not None
        assert gap.price_low is not None
        assert gap.price_high - gap.price_low >= minimum - 1e-9


def test_order_blocks_are_dated_before_the_break_they_caused(engine_config: EngineConfig) -> None:
    result = smc.analyse(smc_context(syn.bull_flag(), engine_config))
    for block in (event for event in result.events if event.type == "order_block"):
        assert block.key_points["origin_index"] <= block.key_points["break_index"]


def test_dealing_range_places_price_between_zero_and_one(engine_config: EngineConfig) -> None:
    for factory in (syn.double_bottom, syn.ascending_triangle, syn.falling_wedge):
        result = smc.analyse(smc_context(factory(), engine_config))
        if result.dealing_range is None:
            continue
        assert 0.0 <= result.dealing_range.position <= 1.0
        assert result.dealing_range.low < result.dealing_range.high
        assert result.dealing_range.zone in {"discount", "equilibrium", "premium"}


def test_equal_highs_are_found_on_a_flat_top(engine_config: EngineConfig) -> None:
    result = smc.analyse(smc_context(syn.ascending_triangle(), engine_config))
    assert any(event.type == "equal_highs" for event in result.events)


def test_displacement_requires_range_and_body(engine_config: EngineConfig) -> None:
    context = smc_context(syn.bull_flag(), engine_config)
    config = engine_config.patterns.smc
    for index in smc._displacement_indices(context):
        series = context.series
        bar_range = float(series.high[index] - series.low[index])
        body = float(np.abs(series.close[index] - series.open[index]))
        assert bar_range >= config.displacement_atr * context.atr
        assert body >= config.displacement_body_ratio * bar_range
