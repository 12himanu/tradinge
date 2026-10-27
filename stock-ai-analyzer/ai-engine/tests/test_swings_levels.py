"""Swings, pivots and level clustering (P4A-06, P4A-07)."""

from __future__ import annotations

import numpy as np
import pytest

from ai_engine.analysis import levels as level_analysis
from ai_engine.analysis import swings
from ai_engine.pipeline.config import EngineConfig
from tests.analysis_helpers import level_set
from tests.fixtures import synthetic as syn


def _v(values: list[float]) -> np.ndarray:
    return np.array(values, dtype=np.float64)


def test_fractal_pivot_needs_lower_bars_on_both_sides() -> None:
    high = _v([1, 2, 3, 2, 1, 2, 1])
    low = _v([1, 2, 3, 2, 1, 2, 1])
    pivots = swings.fractal_pivots(high, low, n=2)
    highs = [pivot.index for pivot in pivots if pivot.kind == "high"]
    assert highs == [2]


def test_pivots_alternate_after_filtering() -> None:
    pivots = [
        swings.Pivot(1, 10.0, "high"),
        swings.Pivot(3, 12.0, "high"),
        swings.Pivot(5, 4.0, "low"),
        swings.Pivot(7, 3.0, "low"),
    ]
    alternated = swings.alternate(pivots)
    assert [pivot.kind for pivot in alternated] == ["high", "low"]
    assert alternated[0].price == 12.0  # the higher high wins its run
    assert alternated[1].price == 3.0  # the lower low wins its run


def test_swing_labels_follow_the_previous_same_kind_pivot() -> None:
    labelled = swings.label_swings(
        [
            swings.Pivot(0, 10.0, "high"),
            swings.Pivot(2, 5.0, "low"),
            swings.Pivot(4, 12.0, "high"),
            swings.Pivot(6, 7.0, "low"),
            swings.Pivot(8, 11.0, "high"),
        ]
    )
    assert [pivot.label for pivot in labelled] == [None, None, "HH", "HL", "LH"]


def test_zigzag_ignores_moves_below_the_threshold() -> None:
    series = syn.trend(120, seed=4)
    noisy = swings.zigzag(series.high, series.low, atr_value=100.0, threshold=1.5)
    assert noisy == []  # a huge ATR means nothing is a real reversal


def test_uptrend_produces_higher_highs_and_higher_lows(engine_config: EngineConfig) -> None:
    series = syn.trend(200, seed=6, slope=1.5)
    detected = swings.detect(series.high, series.low, internal_n=2, external_n=5, atr_value=5.0)
    labels = [pivot.label for pivot in swings.label_swings(detected.external) if pivot.label]
    assert labels.count("HH") + labels.count("HL") > labels.count("LH") + labels.count("LL")


def test_levels_cluster_pivots_within_tolerance() -> None:
    pivots = [swings.Pivot(index, price, "high") for index, price in enumerate([100.0, 100.4, 108.0])]
    zones = level_analysis.cluster_pivots(pivots, atr_value=2.0, tolerance_atr=0.5)
    assert len(zones) == 2
    assert zones[0].touches == 2


def test_level_strength_rises_with_touches_and_recency(engine_config: EngineConfig) -> None:
    series = syn.channel(seed=11)
    levels = level_set(series, engine_config)
    zones = [*levels.supports, *levels.resistances]
    assert zones, "a channel must produce levels"
    assert all(0.0 <= zone.strength <= 1.0 for zone in zones)
    strongest = max(zones, key=lambda zone: zone.strength)
    weakest = min(zones, key=lambda zone: zone.strength)
    assert strongest.touches >= weakest.touches or strongest.last_touch_index >= weakest.last_touch_index


def test_supports_are_below_price_and_resistances_above(engine_config: EngineConfig) -> None:
    series = syn.ascending_triangle()
    levels = level_set(series, engine_config)
    price = series.last_close
    assert all(zone.high < price for zone in levels.supports)
    assert all(zone.low > price for zone in levels.resistances)


def test_nearest_zones_are_the_first_entries(engine_config: EngineConfig) -> None:
    series = syn.double_bottom()
    levels = level_set(series, engine_config)
    if len(levels.supports) > 1:
        assert levels.supports[0].high >= levels.supports[1].high
    if len(levels.resistances) > 1:
        assert levels.resistances[0].low <= levels.resistances[1].low


def test_overlapping_zones_merge() -> None:
    zones = [
        level_analysis.LevelZone(low=100.0, high=104.0, touches=2, last_touch_index=10),
        level_analysis.LevelZone(low=103.0, high=107.0, touches=1, last_touch_index=20),
    ]
    merged = level_analysis.merge_overlapping(zones)
    assert len(merged) == 1
    assert merged[0].low == 100.0
    assert merged[0].high == 107.0
    assert merged[0].touches == 3


def test_distance_is_zero_inside_a_zone() -> None:
    zone = level_analysis.LevelZone(low=100.0, high=110.0, touches=2, last_touch_index=5)
    assert level_analysis.distance_in_atr(105.0, zone, 2.0) == pytest.approx(0.0)
    assert level_analysis.distance_in_atr(114.0, zone, 2.0) == pytest.approx(2.0)
