"""Risk agent: trade plans, scenarios and the predictions they imply (docs/04 §5.7, §10)."""

from __future__ import annotations

from dataclasses import dataclass

from ai_engine.analysis import risk
from ai_engine.analysis.levels import LevelZone
from ai_engine.pipeline.context import AnalysisContext
from ai_engine.schemas.analysis import (
    Direction,
    PatternResult,
    PredictionSpec,
    Scenario,
    SmcResult,
    TradePlan,
)


@dataclass(frozen=True, slots=True)
class RiskView:
    plans: list[TradePlan]
    scenarios: list[Scenario]
    trailing_stop: float | None
    unrealized_r: float | None


def run(
    context: AnalysisContext,
    *,
    primary_pattern: PatternResult | None,
    bias: Direction,
) -> RiskView:
    series, levels = context.series, context.levels
    if series is None or levels is None:
        raise ValueError("risk agent needs a price series and levels")

    inputs = risk.RiskInputs(
        series=series,
        levels=levels,
        atr=context.atr,
        style=context.style,
        risk_config=context.config.styles.risk,
        account=context.request.account,
        fees=context.request.fees,
        position=context.request.position,
        smc_zones=_usable_zones(context.smc),
        last_structural_low=_last_structural_low(context),
        pattern_target=None if primary_pattern is None else primary_pattern.measured_target,
    )

    plans = risk.build_plans(inputs)
    for index, plan in enumerate(plans):
        plan.evidence_id = _register_plan(context, plan, index)

    return RiskView(
        plans=plans,
        scenarios=_scenarios(context, plans, primary_pattern, bias),
        trailing_stop=risk.trailing_stop(inputs),
        unrealized_r=risk.unrealized_r(inputs),
    )


def _usable_zones(result: SmcResult | None) -> list[PatternResult]:
    if result is None:
        return []
    return [event for event in result.events if event.status in {"unmitigated", "confirmed"}]


def _last_structural_low(context: AnalysisContext) -> float | None:
    """The most recent higher low: what a long position is structurally betting on."""
    if context.smc is None:
        return None
    lows = [point for point in context.smc.swings if point.kind == "low"]
    return lows[-1].price if lows else None


def _register_plan(context: AnalysisContext, plan: TradePlan, index: int) -> str:
    targets = ", ".join(f"{target:.2f}" for target in plan.targets)
    verdict = "viable" if plan.viable else "not viable: " + "; ".join(plan.reasons)
    item = context.evidence.add(
        "risk",
        f"plan_{plan.entry_type}_{index}",
        (
            f"{plan.entry_type.capitalize()} plan: entry {plan.entry_price:.2f}, stop "
            f"{plan.stop_price:.2f} ({plan.stop_basis}), targets {targets}; risk {plan.risk_pct:.2f}%, "
            f"reward {plan.reward_pct:.2f}%, R:R {plan.rr_gross:.2f} gross and {plan.rr_net:.2f} net; "
            f"{plan.quantity} shares ({plan.position_pct:.1f}% of the account) — {verdict}"
        ),
        value=plan.entry_price,
        unit="price",
        source="risk",
        weight=1.0 if plan.viable else 0.5,
    )
    for value, key, text in (
        (plan.stop_price, f"plan_{index}_stop", "Stop"),
        (plan.rr_net, f"plan_{index}_rr_net", "Net risk-reward"),
        (float(plan.quantity), f"plan_{index}_quantity", "Position size in shares"),
    ):
        context.evidence.add(
            "risk",
            key,
            f"{text} for the {plan.entry_type} plan: {value:g}",
            value=value,
            unit="price" if key.endswith("stop") else "ratio" if key.endswith("rr_net") else "count",
            source="risk",
            weight=0.6,
        )
    for target_index, target in enumerate(plan.targets, start=1):
        context.evidence.add(
            "risk",
            f"plan_{index}_target_{target_index}",
            f"Target {target_index} for the {plan.entry_type} plan: {target:.2f}",
            value=target,
            unit="price",
            source="risk",
            weight=0.7,
        )
    return item.id


# --------------------------------------------------------------------------- scenarios


def _scenarios(
    context: AnalysisContext,
    plans: list[TradePlan],
    primary_pattern: PatternResult | None,
    bias: Direction,
) -> list[Scenario]:
    """One bullish and one bearish scenario, each with a trigger, targets and an invalidation."""
    series, levels = context.series, context.levels
    if series is None or levels is None:
        return []
    price = series.last_close
    resistance = levels.nearest_resistance
    support = levels.nearest_support

    bullish_trigger = resistance.high if resistance else price + context.atr
    bullish_targets = [zone.low for zone in levels.resistances[1:3]] or [
        round(bullish_trigger + 2 * context.atr, 4)
    ]
    if (
        primary_pattern is not None
        and primary_pattern.direction == "bullish"
        and primary_pattern.measured_target
    ):
        bullish_targets = sorted({*bullish_targets, primary_pattern.measured_target})[:2]
    bullish_invalidation = support.low if support else round(price - 2 * context.atr, 4)

    bearish_trigger = support.low if support else price - context.atr
    bearish_targets = [zone.high for zone in levels.supports[1:3]] or [
        round(bearish_trigger - 2 * context.atr, 4)
    ]
    bearish_invalidation = resistance.high if resistance else round(price + 2 * context.atr, 4)

    viable = next((plan for plan in plans if plan.viable), None)
    if viable is not None:
        bullish_trigger = viable.trigger_price or viable.entry_price
        bullish_invalidation = viable.stop_price
        bullish_targets = viable.targets or bullish_targets

    scenarios = [
        Scenario(
            name="bullish",
            primary=bias != "bearish",
            trigger=f"Daily close above {bullish_trigger:.2f}",
            trigger_type="close_above",
            trigger_price=round(bullish_trigger, 4),
            targets=[round(target, 4) for target in bullish_targets],
            invalidation=round(bullish_invalidation, 4),
        ),
        Scenario(
            name="bearish",
            primary=bias == "bearish",
            trigger=f"Daily close below {bearish_trigger:.2f}",
            trigger_type="close_below",
            trigger_price=round(bearish_trigger, 4),
            targets=[round(target, 4) for target in bearish_targets],
            invalidation=round(bearish_invalidation, 4),
        ),
    ]
    for scenario in scenarios:
        context.evidence.add(
            "context",
            f"scenario_{scenario.name}",
            (
                f"{scenario.name.capitalize()} scenario: {scenario.trigger.lower()}, targets "
                + ", ".join(f"{target:.2f}" for target in scenario.targets)
                + f", invalid at {scenario.invalidation:.2f}"
            ),
            value=scenario.trigger_price,
            unit="price",
            source="risk",
            weight=0.8,
        )
        # The invalidation and each target are separate facts: the analyst may cite any of them,
        # and the Phase 4C grounding check only allows numbers that are registered here.
        context.evidence.add(
            "context",
            f"scenario_{scenario.name}_invalidation",
            f"The {scenario.name} scenario is invalid if price closes beyond {scenario.invalidation:.2f}",
            value=scenario.invalidation,
            unit="price",
            source="risk",
            weight=0.7,
        )
        for index, target in enumerate(scenario.targets, start=1):
            key = f"scenario_{scenario.name}_target_{index}"
            if context.evidence.get(key) is None:
                context.evidence.add(
                    "context",
                    key,
                    f"Target {index} in the {scenario.name} scenario: {target:.2f}",
                    value=target,
                    unit="price",
                    source="risk",
                    weight=0.6,
                )
    return scenarios


def predictions(
    context: AnalysisContext,
    *,
    plans: list[TradePlan],
    setup_score: int | None,
    bias: Direction,
) -> list[PredictionSpec]:
    """One directional-bias prediction plus one trade setup per viable plan (docs/04 §10)."""
    series = context.series
    if series is None or series.as_of is None or context.tier == "C":
        return []

    horizon = context.style.horizon_bars
    reference = series.last_close
    specs = [
        PredictionSpec(
            kind="directional_bias",
            scenario="primary",
            direction="long" if bias == "bullish" else "short" if bias == "bearish" else "flat",
            timeframe=series.timeframe,
            trigger_type="immediate",
            reference_price=round(reference, 4),
            as_of=series.as_of,
            horizon_bars=horizon,
            setup_score=setup_score,
        )
    ]
    for plan in plans:
        if not plan.viable:
            continue
        specs.append(
            PredictionSpec(
                kind="trade_setup",
                scenario="bullish" if plan.direction == "long" else "bearish",
                direction=plan.direction,
                timeframe=series.timeframe,
                trigger_type=plan.trigger_type,
                trigger_price=plan.trigger_price,
                entry_price=plan.entry_price,
                stop_price=plan.stop_price,
                target_price_1=plan.targets[0] if plan.targets else None,
                target_price_2=plan.targets[1] if len(plan.targets) > 1 else None,
                invalidation_price=plan.stop_price,
                reference_price=round(reference, 4),
                as_of=series.as_of,
                horizon_bars=horizon,
                setup_score=setup_score,
                risk_reward=plan.rr_net,
            )
        )
    return specs


def zone_bounds(zone: LevelZone) -> tuple[float, float]:
    return zone.low, zone.high
