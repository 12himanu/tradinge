"""The analyst agent: prose over verified numbers (docs/04-ai-agent-workflow.md §7).

The deterministic pipeline has already decided everything that matters — bias, score, levels,
scenarios, plans, the decision. This stage only explains it. That ordering is the whole design: an
LLM that cannot reach a number cannot get one wrong.

## The loop

    draft ─► guardrails ─┬─ clean ──────────────► narrative_source = "llm"
                         └─ violations ─► repair ─┬─ clean ─► "llm"
                                                  └─ still bad ─► template

One repair, then the template. A second repair costs another GPU minute to fix a model that has
already shown it cannot follow the rules for this input, and the template is not a failure state:
it is the Phase 4A report, built from the same verified facts.

Nothing here can fail an analysis. Ollama down, a timeout, malformed JSON, a draft that will not
come clean — every path returns `None` and the caller renders the template.
"""

from __future__ import annotations

from dataclasses import dataclass
from functools import lru_cache
from pathlib import Path

import structlog
from pydantic import ValidationError

from ai_engine.guardrails import completeness, grounding, language
from ai_engine.guardrails.grounding import Violation
from ai_engine.models.ollama import ChatMessage, OllamaClient, OllamaError
from ai_engine.models.sync import run_blocking
from ai_engine.schemas.analysis import EvidenceItem, NarrativeSource
from ai_engine.schemas.report import AnalystNarrative

log = structlog.get_logger(__name__)

PROMPT_DIR = Path(__file__).resolve().parent.parent / "prompts"
PROMPT_VERSION = "analyst.v1"

MAX_EVIDENCE_ITEMS = 60
"""docs/04 §7: the analyst sees the highest-weight items, not the whole store."""

TEMPERATURE = 0.2
"""Low, because this is exposition of fixed facts, not invention."""

DEFAULT_NUM_CTX = 4096
"""Enough for the prompt and the report. Overridden per hardware tier in config/models.yaml."""

KEEP_ALIVE = "10m"
"""Keep the model resident between analyses; a cold load costs more than the generation."""


@dataclass(frozen=True, slots=True)
class AnalystConnection:
    """How to reach the model. Settings, not a live client — see `run` for why."""

    base_url: str
    model: str
    num_ctx: int = DEFAULT_NUM_CTX
    timeout_seconds: float = 600.0
    """Generous: a 4 GB GPU writing a full report is minutes, and the stream heartbeats meanwhile."""


@dataclass(frozen=True, slots=True)
class AnalystRequest:
    """Everything the prompt needs, already reduced to primitives."""

    symbol: str | None
    timeframe: str
    style: str
    tier: str
    bias: str | None
    setup_score: int | None
    decision: str
    decision_rationale: str
    scenario_block: str
    plan_block: str
    thesis_block: str
    evidence: list[EvidenceItem]
    allowed_values: set[float]
    expected_scenarios: set[str]
    thesis_provided: bool


@dataclass(frozen=True, slots=True)
class AnalystResult:
    """What the stage produced, and what it cost."""

    narrative: AnalystNarrative | None
    source: NarrativeSource
    violations: list[Violation]
    repaired: bool
    model: str | None = None

    @property
    def used_llm(self) -> bool:
        return self.narrative is not None


def run(
    connection: AnalystConnection,
    request: AnalystRequest,
) -> AnalystResult:
    """Draft, check, repair once, and fall back to the template rather than publish a bad report.

    Synchronous by necessity — the pipeline is — so the whole exchange runs on one event loop of
    its own, opened and closed here. The HTTP client is created inside that loop rather than shared
    with the application's: an `httpx` pool belongs to the loop that made it, and reusing one across
    `asyncio.run` calls closes sockets out from under it.
    """
    return run_blocking(_run_async(connection, request))


async def _run_async(connection: AnalystConnection, request: AnalystRequest) -> AnalystResult:
    schema = AnalystNarrative.model_json_schema()
    messages: list[ChatMessage] = [
        {"role": "system", "content": _system_prompt(request)},
        {"role": "user", "content": "Write the report."},
    ]
    model = connection.model

    client = OllamaClient(connection.base_url, timeout_seconds=connection.timeout_seconds)
    try:
        try:
            narrative, violations = await _attempt(client, connection, messages, schema, request)
        except OllamaError as error:
            log.warning("analyst.unavailable", error=str(error), model=model)
            return AnalystResult(None, "template", [], repaired=False)

        if narrative is not None and not violations:
            return AnalystResult(narrative, "llm", [], repaired=False, model=model)

        # One repair, with the violations spelled out. A draft that fails twice is not worth a
        # third minute of GPU time.
        messages.extend(
            [
                {"role": "assistant", "content": _draft_json(narrative)},
                {"role": "user", "content": _repair_prompt(violations, request.decision)},
            ]
        )

        try:
            repaired, remaining = await _attempt(client, connection, messages, schema, request)
        except OllamaError as error:
            log.warning("analyst.repair_unavailable", error=str(error), model=model)
            return AnalystResult(None, "template", violations, repaired=True)

        if repaired is not None and not remaining:
            return AnalystResult(repaired, "llm", [], repaired=True, model=model)

        log.info("analyst.fell_back", violations=[str(item) for item in remaining or violations])
        return AnalystResult(None, "template", remaining or violations, repaired=True)
    finally:
        await client.aclose()


async def _attempt(
    client: OllamaClient,
    connection: AnalystConnection,
    messages: list[ChatMessage],
    schema: dict[str, object],
    request: AnalystRequest,
) -> tuple[AnalystNarrative | None, list[Violation]]:
    """One generation, parsed and checked. A draft that will not parse counts as fully violating."""
    result = await client.chat(
        model=connection.model,
        messages=messages,
        response_format=schema,
        temperature=TEMPERATURE,
        # Thinking would double latency for a task that is exposition, not reasoning.
        think=False,
        num_ctx=connection.num_ctx,
        keep_alive=KEEP_ALIVE,
    )

    try:
        narrative = AnalystNarrative.model_validate_json(result.content)
    except ValidationError as error:
        return None, [
            Violation(
                rule="schema",
                where="response",
                detail=f"The JSON did not match the schema: {error.error_count()} problems.",
            )
        ]

    return narrative, inspect(narrative, request)


def inspect(narrative: AnalystNarrative, request: AnalystRequest) -> list[Violation]:
    """Every guardrail, in one pass. Public so the adversarial tests can drive it directly."""
    prose = narrative.prose()
    evidence_ids = {item.id for item in request.evidence}

    return [
        *grounding.check(prose, request.allowed_values),
        *language.check(prose, request.decision),
        *completeness.check(
            narrative,
            evidence_ids=evidence_ids,
            expected_scenarios=request.expected_scenarios,
            thesis_provided=request.thesis_provided,
        ),
    ]


def _system_prompt(request: AnalystRequest) -> str:
    return _template("analyst.v1.md").format(
        symbol=request.symbol or "unknown",
        timeframe=request.timeframe,
        style=request.style,
        tier=request.tier,
        bias=request.bias or "neutral",
        setup_score="not scored" if request.setup_score is None else request.setup_score,
        decision=request.decision,
        decision_rationale=request.decision_rationale,
        scenario_block=request.scenario_block,
        plan_block=request.plan_block,
        thesis_block=request.thesis_block,
        evidence_block=evidence_block(request.evidence),
    )


def _repair_prompt(violations: list[Violation], decision: str) -> str:
    listed = "\n".join(f"- {violation}" for violation in violations) or "- The draft could not be read."
    return _template("repair.v1.md").format(violations=listed, decision=decision)


def evidence_block(items: list[EvidenceItem]) -> str:
    """One line per item: id, what it is, and its number if it has one.

    Compact on purpose. Sixty items of JSON would crowd out the instructions in a 16k window, and
    the model needs the text far more than it needs the field names.
    """
    lines = []
    for item in items:
        value = "" if item.value is None else f" = {item.value:g}"
        if item.value_high is not None:
            value += f" to {item.value_high:g}"
        unit = "" if item.unit == "none" else f" {item.unit}"
        lines.append(f"[{item.id}] {item.text}{value}{unit}")
    return "\n".join(lines)


def _draft_json(narrative: AnalystNarrative | None) -> str:
    if narrative is None:
        return "{}"
    return narrative.model_dump_json()


@lru_cache(maxsize=4)
def _template(name: str) -> str:
    return (PROMPT_DIR / name).read_text(encoding="utf-8")


__all__ = [
    "AnalystConnection",
    "AnalystRequest",
    "AnalystResult",
    "evidence_block",
    "inspect",
    "run",
]
