"""Trade planning: entries, stops, targets, sizing and viability (docs/04 §5.7).

The gates are the point of this module. A plan that fails the minimum net R:R or the maximum
stop distance is still shown, marked not viable, with the price at which it would become viable.
"""

from __future__ import annotations

import math
from dataclasses import dataclass

from ai_engine.analysis.levels import LevelSet, LevelZone
from ai_engine.analysis.series import Series
from ai_engine.pipeline.config import RiskConfig, StyleProfile
from ai_engine.schemas.analysis import (
    AccountSettings,
    FeeSchedule,
    PatternResult,
    Position,
    TradePlan,
    TriggerType,
)


@dataclass(frozen=True, slots=True)
class RiskInputs:
    """Everything needed to build a plan, gathered by the risk agent."""

    series: Series
    levels: LevelSet
    atr: float
    style: StyleProfile
    risk_config: RiskConfig
    account: AccountSettings
    fees: FeeSchedule
    position: Position
    smc_zones: list[PatternResult]
    """Unmitigated bullish order blocks and FVGs, usable as pullback entries."""

    last_structural_low: float | None = None
    pattern_target: float | None = None


def build_plans(inputs: RiskInputs) -> list[TradePlan]:
    """Every long plan worth showing, best (viable, highest net R:R) first."""
    if inputs.atr <= 0 or len(inputs.series) < 2:
        return []
    plans = [
        plan
        for plan in (_breakout_plan(inputs), _pullback_plan(inputs), _market_plan(inputs))
        if plan is not None
    ]
    return sorted(plans, key=lambda plan: (not plan.viable, -plan.rr_net))


# --------------------------------------------------------------------------- entry candidates


def _breakout_plan(inputs: RiskInputs) -> TradePlan | None:
    resistance = inputs.levels.nearest_resistance
    if resistance is None:
        return None
    trigger = resistance.high
    entry = trigger + inputs.risk_config.breakout_entry_atr_offset * inputs.atr
    stop = _structural_stop(inputs, entry, zone_low=_support_low(inputs, entry))
    if stop is None:
        return None
    return _assemble(
        inputs,
        entry_type="breakout",
        entry=entry,
        stop=stop,
        trigger_type="close_above",
        trigger_price=trigger,
        stop_basis="below the nearest support zone, minus the style buffer",
        skip_levels=[resistance],
    )


def _pullback_plan(inputs: RiskInputs) -> TradePlan | None:
    """Buy into the nearest demand zone below price: support, order block or fair value gap."""
    price = inputs.series.last_close
    reach = inputs.risk_config.pullback_max_distance_atr * inputs.atr
    candidates: list[tuple[float, float, str]] = []

    support = inputs.levels.nearest_support
    if support is not None and price - support.high <= reach:
        candidates.append((support.high, support.low, "support zone"))
    for zone in inputs.smc_zones:
        if zone.price_high is None or zone.price_low is None or zone.direction != "bullish":
            continue
        if zone.price_high < price and price - zone.price_high <= reach:
            candidates.append((zone.price_high, zone.price_low, zone.type.replace("_", " ")))
    if not candidates:
        return None

    entry, zone_low, source = max(candidates, key=lambda item: item[0])
    stop = _structural_stop(inputs, entry, zone_low=zone_low)
    if stop is None:
        return None
    return _assemble(
        inputs,
        entry_type="pullback",
        entry=entry,
        stop=stop,
        trigger_type="touch",
        trigger_price=entry,
        stop_basis=f"below the {source}, minus the style buffer",
    )


def _market_plan(inputs: RiskInputs) -> TradePlan | None:
    """At market, only right after a trigger fired and while price is still close to it."""
    series = inputs.series
    price = series.last_close
    resistance_break = _recent_breakout(inputs)
    if resistance_break is None:
        return None
    level, bars_ago = resistance_break
    if bars_ago > inputs.risk_config.market_entry_max_age_bars:
        return None
    if price - level > inputs.risk_config.market_entry_max_distance_atr * inputs.atr:
        return None
    stop = _structural_stop(inputs, price, zone_low=_support_low(inputs, price))
    if stop is None:
        return None
    return _assemble(
        inputs,
        entry_type="market",
        entry=price,
        stop=stop,
        trigger_type="immediate",
        trigger_price=None,
        stop_basis="below the breakout level and the last higher low, minus the style buffer",
    )


def _recent_breakout(inputs: RiskInputs) -> tuple[float, int] | None:
    """The most recent close above a level that was resistance a few bars ago."""
    series = inputs.series
    window = min(inputs.risk_config.market_entry_max_age_bars + 1, len(series) - 1)
    for bars_ago in range(window):
        index = len(series) - 1 - bars_ago
        previous_high = float(max(series.high[max(0, index - 20) : index]))
        if series.close[index] > previous_high:
            return previous_high, bars_ago
    return None


def _support_low(inputs: RiskInputs, entry: float) -> float | None:
    below = [zone for zone in inputs.levels.supports if zone.high < entry]
    return below[0].low if below else None


def _structural_stop(inputs: RiskInputs, entry: float, *, zone_low: float | None) -> float | None:
    """Below the structural invalidation, minus the style's ATR buffer."""
    anchors = [value for value in (zone_low, inputs.last_structural_low) if value is not None]
    if not anchors:
        anchors = [float(min(inputs.series.low[-10:]))]
    stop = max(anchors) - inputs.style.stop_buffer_atr * inputs.atr
    return stop if 0 < stop < entry else None


# --------------------------------------------------------------------------- assembly and gates


def _assemble(
    inputs: RiskInputs,
    *,
    entry_type: str,
    entry: float,
    stop: float,
    trigger_type: TriggerType,
    trigger_price: float | None,
    stop_basis: str,
    skip_levels: list[LevelZone] | None = None,
) -> TradePlan | None:
    targets = _targets(inputs, entry, skip=skip_levels or [])
    if not targets:
        return None

    risk_per_share = entry - stop
    if risk_per_share <= 0:
        return None
    first_target = targets[0]
    cost = inputs.fees.round_trip_fraction

    risk_pct = risk_per_share / entry * 100
    reward_pct = (first_target - entry) / entry * 100
    rr_gross = (first_target - entry) / risk_per_share
    rr_net = (first_target - entry - cost * entry) / (risk_per_share + cost * entry)

    quantity, position_value, position_pct = _size(inputs, entry, risk_per_share, cost)
    reasons: list[str] = []
    stop_distance_atr = risk_per_share / inputs.atr
    if stop_distance_atr > inputs.style.max_stop_atr:
        reasons.append(
            f"Stop is {stop_distance_atr:.1f} x ATR, wider than the {inputs.style.max_stop_atr:.1f} x ATR "
            f"limit for this style"
        )
    if risk_pct > inputs.style.max_stop_pct:
        reasons.append(f"Stop is {risk_pct:.1f}% away, wider than the {inputs.style.max_stop_pct:.1f}% limit")
    if rr_net < inputs.style.min_rr_net:
        reasons.append(
            f"Net R:R {rr_net:.2f} is below the {inputs.style.min_rr_net:.1f} minimum for this style"
        )
    if quantity <= 0:
        reasons.append("Position size rounds to zero lots at this risk budget")

    viable = not reasons
    return TradePlan(
        direction="long",
        entry_type=entry_type,
        entry_price=round(entry, 4),
        trigger_type=trigger_type,
        trigger_price=None if trigger_price is None else round(trigger_price, 4),
        stop_price=round(stop, 4),
        targets=[round(target, 4) for target in targets],
        risk_pct=round(risk_pct, 2),
        reward_pct=round(reward_pct, 2),
        rr_gross=round(rr_gross, 2),
        rr_net=round(rr_net, 2),
        quantity=quantity,
        position_value=round(position_value, 2),
        position_pct=round(position_pct, 2),
        viable=viable,
        trigger_met=_trigger_met(inputs, trigger_type, trigger_price),
        reasons=reasons,
        viable_at_price=None if viable else _viable_entry(inputs, stop, first_target),
        stop_basis=stop_basis,
    )


def _targets(inputs: RiskInputs, entry: float, *, skip: list[LevelZone]) -> list[float]:
    """T1 is the next level at least 1 x ATR above entry; T2 the one after, or the measured move."""
    minimum = entry + inputs.risk_config.target_min_distance_atr * inputs.atr
    skipped = {(zone.low, zone.high) for zone in skip}
    levels = [
        zone.low
        for zone in inputs.levels.resistances
        if (zone.low, zone.high) not in skipped and zone.low >= minimum
    ]
    liquidity = [
        zone.price_high
        for zone in inputs.smc_zones
        if zone.type in {"equal_highs", "liquidity_sweep"}
        and zone.price_high is not None
        and zone.price_high >= minimum
    ]
    candidates = sorted(set(levels + liquidity))
    if inputs.pattern_target is not None and inputs.pattern_target >= minimum:
        candidates = sorted({*candidates, inputs.pattern_target})
    if not candidates:
        # No level overhead: project one measured 2 x ATR step so the plan is still checkable.
        candidates = [entry + 2 * inputs.atr]
    return candidates[:2]


def _size(inputs: RiskInputs, entry: float, risk_per_share: float, cost: float) -> tuple[int, float, float]:
    """Quantity from the risk budget, capped by the position limit and rounded to whole lots."""
    account = inputs.account
    budget = account.equity * account.risk_pct / 100
    per_share_cost = risk_per_share + cost * entry
    if per_share_cost <= 0:
        return 0, 0.0, 0.0
    quantity = math.floor(budget / per_share_cost)
    cap = math.floor(account.equity * account.max_position_pct / 100 / entry)
    quantity = min(quantity, cap)
    quantity = quantity - quantity % account.lot_size if account.lot_size > 1 else quantity
    quantity = max(quantity, 0)
    value = quantity * entry
    return quantity, value, (value / account.equity * 100 if account.equity else 0.0)


def _trigger_met(inputs: RiskInputs, trigger_type: TriggerType, trigger_price: float | None) -> bool:
    if trigger_type == "immediate":
        return True
    if trigger_price is None:
        return False
    price = inputs.series.last_close
    if trigger_type == "close_above":
        return price > trigger_price
    if trigger_type == "close_below":
        return price < trigger_price
    return bool(inputs.series.low[-1] <= trigger_price <= inputs.series.high[-1])


def _viable_entry(inputs: RiskInputs, stop: float, target: float) -> float | None:
    """The entry price at which this plan would clear the style's minimum net R:R.

    Solves rr_net(E) = min_rr for E with the stop and target fixed, which is the honest answer to
    "good chart, poor price": it names the price that would change the answer.
    """
    minimum = inputs.style.min_rr_net
    cost = inputs.fees.round_trip_fraction
    denominator = 1 + minimum * (1 + cost) + cost
    if denominator <= 0:
        return None
    entry = (target + minimum * stop) / denominator
    if not stop < entry < target:
        return None
    if (entry - stop) / inputs.atr > inputs.style.max_stop_atr:
        return None
    return round(entry, 4)


# --------------------------------------------------------------------------- holding mode


def trailing_stop(inputs: RiskInputs) -> float | None:
    """Holding mode: the higher of the original stop and the last higher low, minus the buffer."""
    position = inputs.position
    if position.status != "holding":
        return None
    candidates = [value for value in (position.stop_price,) if value is not None]
    if inputs.last_structural_low is not None:
        candidates.append(inputs.last_structural_low - inputs.style.stop_buffer_atr * inputs.atr)
    return round(max(candidates), 4) if candidates else None


def unrealized_r(inputs: RiskInputs) -> float | None:
    """Open profit in R, using the position's own stop as the unit of risk."""
    position = inputs.position
    if position.status != "holding" or position.entry_price is None or position.stop_price is None:
        return None
    risk = position.entry_price - position.stop_price
    if risk <= 0:
        return None
    return round((inputs.series.last_close - position.entry_price) / risk, 2)
