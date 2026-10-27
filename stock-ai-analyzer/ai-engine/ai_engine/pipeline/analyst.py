"""Wiring the analyst stage into the pipeline (P4C-06, P4C-07).

Kept out of the orchestrator so the deterministic path stays free of any LLM import, and so
`analyse()` runs identically with no model available — which is what every existing test does.

The backend is optional by design. No backend, Ollama down, a draft that will not come clean: all
three end at the template, which is the Phase 4A report built from the same verified facts. An
analysis never fails because the prose did.
"""

from __future__ import annotations

import re

from ai_engine.agents.analyst_agent import (
    MAX_EVIDENCE_ITEMS,
    AnalystConnection,
    AnalystRequest,
    AnalystResult,
)
from ai_engine.agents.analyst_agent import run as run_analyst
from ai_engine.pipeline.context import AnalysisContext
from ai_engine.schemas.analysis import AnalysisResult, Decision, Scenario, TradePlan


def narrate(
    context: AnalysisContext,
    result: AnalysisResult,
    backend: AnalystConnection | None,
) -> AnalystResult:
    """Run the analyst, or report cleanly that it was not run."""
    if backend is None:
        return AnalystResult(narrative=None, source="template", violations=[], repaired=False)

    return run_analyst(backend, build_request(context, result))


_NUMBER_IN_BLOCK = re.compile(r"-?\d+(?:\.\d+)?")


def build_request(context: AnalysisContext, result: AnalysisResult) -> AnalystRequest:
    """Everything the prompt needs, reduced to primitives the agent can format."""
    thesis = (context.request.user_thesis or "").strip()
    scenario_block = _scenarios(result.scenarios)
    plan_block = _plans(result.trade_plans, result.decision)

    return AnalystRequest(
        symbol=result.symbol,
        timeframe=result.timeframe,
        style=result.trading_style,
        tier=result.tier,
        bias=result.bias,
        setup_score=result.setup_score,
        decision=result.decision.action,
        decision_rationale=result.decision.rationale or "",
        scenario_block=scenario_block,
        plan_block=plan_block,
        thesis_block=_thesis(thesis),
        evidence=context.evidence.top(MAX_EVIDENCE_ITEMS),
        # Every number the prompt shows is a number the analyst may quote. The scenario targets
        # and plan prices are as engine-computed as the evidence store; forbidding them would mean
        # printing a figure and then rejecting the report for repeating it.
        allowed_values=(
            context.evidence.allowed_values()
            | _numbers_in(scenario_block, plan_block)
            # The evidence *text* carries numbers its `value` field does not — a pattern's
            # confidence, an indicator reading quoted in passing. The analyst is shown those lines
            # verbatim, so quoting them back has to be allowed.
            | _numbers_in(*(item.text for item in context.evidence.top(MAX_EVIDENCE_ITEMS)))
        ),
        expected_scenarios={str(scenario.name) for scenario in result.scenarios} & {"bullish", "bearish"},
        thesis_provided=thesis != "",
    )


def _numbers_in(*blocks: str) -> set[float]:
    """Every number written into the injected prompt blocks."""
    values: set[float] = set()
    for block in blocks:
        for match in _NUMBER_IN_BLOCK.finditer(block):
            try:
                values.add(float(match.group(0)))
            except ValueError:  # pragma: no cover - the pattern only matches parseable numbers
                continue
    return values


def _scenarios(scenarios: list[Scenario]) -> str:
    """The scenarios as fixed facts. The analyst narrates them; it does not set them."""
    if not scenarios:
        return "## Scenarios\n\nNone were constructed."

    lines = ["## Scenarios (fixed — narrate these, do not invent others)"]
    for scenario in scenarios:
        targets = ", ".join(f"{target:g}" for target in scenario.targets) or "none"
        primary = " (primary)" if scenario.primary else ""
        lines.append(
            f"- **{scenario.name}**{primary}: trigger {scenario.trigger or 'n/a'}; "
            f"targets {targets}; invalid if {scenario.invalidation or 'n/a'}"
        )
    return "\n".join(lines)


def _plans(plans: list[TradePlan], decision: Decision) -> str:
    """The plans, including why an unviable one is unviable — that is the reader's question."""
    if not plans:
        return "## Trade plans\n\nNone are viable at this price."

    lines = ["## Trade plans (fixed — every number here is computed)"]
    for plan in plans:
        state = "viable" if plan.viable else "not viable"
        targets = ", ".join(f"{target:g}" for target in plan.targets) or "none"
        lines.append(
            f"- **{plan.entry_type}** ({state}): entry {plan.entry_price:g}, stop {plan.stop_price:g}, "
            f"targets {targets}, R:R {plan.rr_gross:g} gross and {plan.rr_net:g} net, "
            f"size {plan.quantity} ({plan.position_pct:g}% of the account)"
        )
        # The reasons a plan is unviable are the reader's actual question, so they go in.
        for reason in plan.reasons:
            lines.append(f"  - {reason}")
        if plan.viable_at_price is not None:
            lines.append(f"  - would be viable at {plan.viable_at_price:g}")

    if decision.conditions:
        lines.append("")
        lines.append("Conditions that would change the decision:")
        lines.extend(f"- {condition}" for condition in decision.conditions)

    return "\n".join(lines)


def _thesis(thesis: str) -> str:
    if not thesis:
        return ""
    return (
        "## The user's own thesis\n\n"
        "Assess this last, in `user_thesis_review`: the evidence for it, and the evidence against.\n\n"
        f"> {thesis}"
    )
