"""The decision policy (docs/04-ai-agent-workflow.md §6).

Nine rules, evaluated top down; the first match wins. The LLM never produces these fields — it
only explains them — so this table is the single place where the app says buy, wait, hold, sell
or avoid.
"""

from __future__ import annotations

from dataclasses import dataclass

from ai_engine.pipeline.config import ScoringConfig
from ai_engine.schemas.analysis import DataTier, Decision, TradePlan


@dataclass(frozen=True, slots=True)
class PolicyInputs:
    """The facts the rules are allowed to look at."""

    tier: DataTier
    symbol_resolved: bool
    holding: bool
    setup_score: int | None
    plans: list[TradePlan]
    structure_event: str | None = None
    position_stop: float | None = None
    scenario_invalidation: float | None = None
    last_close: float | None = None
    trailing_stop: float | None = None

    @property
    def viable_plans(self) -> list[TradePlan]:
        return [plan for plan in self.plans if plan.viable]


def decide(inputs: PolicyInputs, config: ScoringConfig) -> Decision:
    """Apply the rule table. Every branch is covered by a test in `tests/test_decision_policy.py`."""
    thresholds = config.decision

    # 1 — nothing numeric can be trusted
    if not inputs.symbol_resolved or inputs.tier == "C":
        reason = (
            "the symbol could not be resolved"
            if not inputs.symbol_resolved
            else "only the screenshot could be read (Tier C)"
        )
        return Decision(
            action="hold" if inputs.holding else "wait",
            rule_id=1,
            conditions=["Confirm the symbol and timeframe, or upload price data, then re-run"],
            limited=True,
            rationale=f"No numeric plan is possible because {reason}.",
        )

    if inputs.holding:
        # 2 — the position's own exit has already triggered
        breached = _breached(inputs)
        if breached is not None:
            level, what = breached
            return Decision(
                action="sell",
                rule_id=2,
                conditions=[f"Close is below the {what} at {level:.2f}"],
                invalidation=level,
                rationale=f"The {what} at {level:.2f} has been breached on a closing basis.",
            )

        # 3 — structure turned against the position and the score confirms it
        if (
            inputs.structure_event == "choch_bearish"
            and inputs.setup_score is not None
            and inputs.setup_score <= thresholds.sell_score_at_or_below
        ):
            return Decision(
                action="sell",
                rule_id=3,
                conditions=[
                    "Bearish change of character with a setup score at or below "
                    f"{thresholds.sell_score_at_or_below}"
                ],
                rationale=(
                    "Structure changed character to the downside and the weight of evidence no longer "
                    "supports the position."
                ),
            )

        # 4 — otherwise keep holding, on a trailing stop
        conditions = ["Exit on a close below the trailing stop"]
        if inputs.trailing_stop is not None:
            conditions = [f"Exit on a close below {inputs.trailing_stop:.2f} (trailing stop)"]
        return Decision(
            action="hold",
            rule_id=4,
            conditions=conditions,
            invalidation=inputs.trailing_stop,
            rationale="The reasons for holding are intact; risk is managed by the trailing stop.",
        )

    score = inputs.setup_score

    # 5 — weight of evidence is against the trade
    if score is not None and score <= thresholds.avoid_at_or_below:
        return Decision(
            action="avoid",
            rule_id=5,
            conditions=[f"Reconsider only above a setup score of {thresholds.avoid_at_or_below}"],
            rationale=f"Setup score {score} is at or below {thresholds.avoid_at_or_below}.",
        )

    if score is not None and score >= thresholds.buy_at_or_above:
        viable = inputs.viable_plans
        # 6 — the setup is good and the trigger has already fired
        triggered = next((plan for plan in viable if plan.trigger_met), None)
        if triggered is not None:
            return Decision(
                action="buy",
                rule_id=6,
                conditions=[f"Risk is defined by the stop at {triggered.stop_price:.2f}"],
                invalidation=triggered.stop_price,
                rationale=(
                    f"Setup score {score} with a viable plan whose trigger is already met "
                    f"(net R:R {triggered.rr_net:.2f})."
                ),
            )
        # 7 — good setup, waiting for the trigger
        if viable:
            plan = viable[0]
            trigger = _trigger_text(plan)
            return Decision(
                action="wait",
                rule_id=7,
                conditions=[trigger],
                invalidation=plan.stop_price,
                rationale=f"Setup score {score} with a viable plan that has not triggered yet.",
            )
        # 8 — good chart, poor price
        conditions = _viability_conditions(inputs.plans)
        return Decision(
            action="wait",
            rule_id=8,
            conditions=conditions,
            rationale="Good chart, poor price: no plan currently clears the risk gates.",
        )

    # 9 — no edge either way
    return Decision(
        action="wait",
        rule_id=9,
        conditions=[
            f"A setup score of {thresholds.buy_at_or_above} or more with a viable plan would change this"
        ],
        rationale=(
            f"Setup score {score} sits between {thresholds.avoid_at_or_below} and "
            f"{thresholds.buy_at_or_above}: no edge in either direction."
            if score is not None
            else "The setup score could not be computed."
        ),
    )


def _breached(inputs: PolicyInputs) -> tuple[float, str] | None:
    if inputs.last_close is None:
        return None
    if inputs.position_stop is not None and inputs.last_close < inputs.position_stop:
        return inputs.position_stop, "position stop"
    if inputs.scenario_invalidation is not None and inputs.last_close < inputs.scenario_invalidation:
        return inputs.scenario_invalidation, "scenario invalidation"
    return None


def _trigger_text(plan: TradePlan) -> str:
    if plan.trigger_price is None:
        return "Wait for the entry conditions in the plan"
    if plan.trigger_type == "close_above":
        return f"Daily close above {plan.trigger_price:.2f}"
    if plan.trigger_type == "close_below":
        return f"Daily close below {plan.trigger_price:.2f}"
    return f"Price trading into {plan.trigger_price:.2f}"


def _viability_conditions(plans: list[TradePlan]) -> list[str]:
    conditions: list[str] = []
    for plan in plans:
        if plan.viable_at_price is not None:
            conditions.append(
                f"A pullback to {plan.viable_at_price:.2f} would make the {plan.entry_type} plan viable"
            )
        else:
            conditions.extend(plan.reasons)
    return conditions or ["No plan clears the minimum risk-reward at any entry near current price"]
