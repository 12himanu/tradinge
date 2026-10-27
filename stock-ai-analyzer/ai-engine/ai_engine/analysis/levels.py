"""Support and resistance zones built from swing pivots (P4A-07).

Pivots within 0.5 x ATR of each other are one zone. A zone's strength combines how often price
respected it, how recently, and how much volume traded there.
"""

from __future__ import annotations

import math
from dataclasses import dataclass, field
from datetime import datetime

import numpy as np

from ai_engine.analysis.series import FloatArray, Series
from ai_engine.analysis.swings import Pivot


@dataclass(slots=True)
class LevelZone:
    """A price band that acted as support or resistance."""

    low: float
    high: float
    touches: int
    last_touch_index: int
    strength: float = 0.0
    volume_weight: float = 0.0
    pivot_indices: list[int] = field(default_factory=list)

    @property
    def mid(self) -> float:
        return (self.low + self.high) / 2

    def contains(self, price: float) -> bool:
        return self.low <= price <= self.high


def cluster_pivots(pivots: list[Pivot], atr_value: float, tolerance_atr: float = 0.5) -> list[LevelZone]:
    """Group pivots whose prices sit within `tolerance_atr` x ATR into zones."""
    if not pivots or atr_value <= 0:
        return []
    tolerance = tolerance_atr * atr_value
    ordered = sorted(pivots, key=lambda pivot: pivot.price)

    zones: list[LevelZone] = []
    current: list[Pivot] = [ordered[0]]
    for pivot in ordered[1:]:
        if pivot.price - current[0].price <= tolerance:
            current.append(pivot)
        else:
            zones.append(_zone(current))
            current = [pivot]
    zones.append(_zone(current))
    return zones


def _zone(pivots: list[Pivot]) -> LevelZone:
    prices = [pivot.price for pivot in pivots]
    return LevelZone(
        low=min(prices),
        high=max(prices),
        touches=len(pivots),
        last_touch_index=max(pivot.index for pivot in pivots),
        pivot_indices=sorted(pivot.index for pivot in pivots),
    )


def score_zones(
    zones: list[LevelZone],
    series: Series,
    *,
    recency_half_life: float = 60.0,
    volume_sma: FloatArray | None = None,
) -> list[LevelZone]:
    """Strength in [0, 1] from touch count, recency and traded volume at the zone."""
    last_index = len(series) - 1
    for zone in zones:
        touch_component = min(1.0, math.log2(1 + zone.touches) / 2.0)
        age = max(0, last_index - zone.last_touch_index)
        recency_component = 0.5 ** (age / recency_half_life)
        volume_component = _volume_component(zone, series, volume_sma)
        zone.volume_weight = volume_component
        zone.strength = round(
            min(1.0, 0.45 * touch_component + 0.35 * recency_component + 0.20 * volume_component), 3
        )
    return zones


def _volume_component(zone: LevelZone, series: Series, volume_sma: FloatArray | None) -> float:
    """Relative volume on the bars that formed the zone (0.5 when volume is unknown)."""
    if series.volume is None or volume_sma is None:
        return 0.5
    ratios: list[float] = []
    for index in zone.pivot_indices:
        average = float(volume_sma[index]) if index < volume_sma.size else float("nan")
        if math.isfinite(average) and average > 0:
            ratios.append(float(series.volume[index]) / average)
    if not ratios:
        return 0.5
    return float(min(1.0, np.mean(ratios) / 2.0))


def merge_overlapping(zones: list[LevelZone]) -> list[LevelZone]:
    """Merge zones whose bands overlap after clustering (can happen across degrees)."""
    if not zones:
        return []
    ordered = sorted(zones, key=lambda zone: zone.low)
    merged = [ordered[0]]
    for zone in ordered[1:]:
        previous = merged[-1]
        if zone.low <= previous.high:
            merged[-1] = LevelZone(
                low=previous.low,
                high=max(previous.high, zone.high),
                touches=previous.touches + zone.touches,
                last_touch_index=max(previous.last_touch_index, zone.last_touch_index),
                strength=max(previous.strength, zone.strength),
                volume_weight=max(previous.volume_weight, zone.volume_weight),
                pivot_indices=sorted(set(previous.pivot_indices) | set(zone.pivot_indices)),
            )
        else:
            merged.append(zone)
    return merged


@dataclass(frozen=True, slots=True)
class LevelSet:
    """Zones split by their position relative to the current price."""

    supports: list[LevelZone]
    resistances: list[LevelZone]

    @property
    def nearest_support(self) -> LevelZone | None:
        return self.supports[0] if self.supports else None

    @property
    def nearest_resistance(self) -> LevelZone | None:
        return self.resistances[0] if self.resistances else None


def build(
    series: Series,
    pivots: list[Pivot],
    atr_value: float,
    *,
    tolerance_atr: float = 0.5,
    max_per_side: int = 4,
    recency_half_life: float = 60.0,
    min_touches: int = 1,
    volume_sma: FloatArray | None = None,
) -> LevelSet:
    """Cluster, score and split pivots into support and resistance zones around the last close."""
    zones = merge_overlapping(cluster_pivots(pivots, atr_value, tolerance_atr))
    zones = [
        zone
        for zone in score_zones(zones, series, recency_half_life=recency_half_life, volume_sma=volume_sma)
        if zone.touches >= min_touches
    ]
    price = series.last_close
    supports = sorted([zone for zone in zones if zone.high < price], key=lambda zone: price - zone.high)
    resistances = sorted([zone for zone in zones if zone.low > price], key=lambda zone: zone.low - price)
    return LevelSet(supports=supports[:max_per_side], resistances=resistances[:max_per_side])


def zone_date(series: Series, zone: LevelZone) -> datetime | None:
    index = zone.last_touch_index
    return series.dates[index] if 0 <= index < len(series.dates) else None


def distance_in_atr(price: float, zone: LevelZone, atr_value: float) -> float | None:
    """Signed distance from `price` to the nearest edge of the zone, in ATR units."""
    if atr_value <= 0:
        return None
    if zone.contains(price):
        return 0.0
    edge = zone.low if zone.low > price else zone.high
    return abs(edge - price) / atr_value
