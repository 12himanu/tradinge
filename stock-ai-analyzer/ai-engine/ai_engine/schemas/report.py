"""What the analyst LLM is allowed to write (docs/04-ai-agent-workflow.md §7).

The model returns **prose only**. Every number, level, scenario trigger, trade plan and the
decision itself are computed by the deterministic pipeline and injected afterwards, so the analyst
cannot move a stop, soften a decision or invent a target — the worst it can do is describe them
badly, and the guardrails catch that.

This is why the draft has no price fields. A schema that let the model emit `stop: 520.0` would be
a schema that let it emit `stop: 420.0`, and no amount of checking afterwards makes that safe.
Numbers reach the reader through injection or not at all; a figure the model types into prose has
to match a registered piece of evidence to survive `guardrails.grounding`.
"""

from __future__ import annotations

import re
from typing import Literal

from pydantic import Field, field_validator

from ai_engine.schemas.base import Strict

_CITATION = re.compile(r"E\d+")


def _normalise_citations(value: list[str]) -> list[str]:
    """Reduce each citation to the bare id.

    Models copy the evidence line back verbatim — `"E10: Bos (bullish, confirmed) at 535.00"`
    rather than `"E10"` — because that is how the prompt prints it. The id is unambiguous and
    correct, so it is taken; failing the whole report over the formatting would burn a GPU minute
    to teach punctuation. An entry with no id at all is left alone, and the completeness check
    rejects it.
    """
    cleaned = []
    for entry in value:
        match = _CITATION.search(entry)
        cleaned.append(match.group(0) if match else entry)
    return cleaned


SCHEMA_VERSION = "1.0"

SECTION_KEYS = ("market_structure", "technical_view", "pattern", "smart_money")
"""The four sections the analyst must write, in the order they are read."""


class NarrativeSection(Strict):
    """One written section and the evidence it leans on."""

    text: str = Field(min_length=1, description="Two to four sentences. Cite ids inline as [E7].")
    evidence: list[str] = Field(
        default_factory=list,
        description="Evidence ids only, e.g. ['E3', 'E4']. The id alone, not the evidence text.",
    )

    _clean_evidence = field_validator("evidence")(_normalise_citations)


class ScenarioNarrative(Strict):
    """The prose for one scenario. Its trigger, targets and invalidation are injected."""

    name: Literal["bullish", "bearish"]
    """Constrained in the schema rather than checked afterwards: structured output then cannot
    emit "upside" or "Bull case", which is how a small model usually gets this wrong."""
    narrative: str = Field(
        min_length=1,
        description="Conditional: 'If ... then ... ; invalid if ...'. Never state certainty.",
    )
    evidence: list[str] = Field(
        default_factory=list,
        description="Evidence ids only, e.g. ['E3', 'E4'].",
    )

    _clean_evidence = field_validator("evidence")(_normalise_citations)


class Opinion(Strict):
    """The analyst's read, which is the one place an opinion is wanted."""

    reason: str = Field(min_length=1, description="Why this decision, grounded in the evidence.")
    risk: str = Field(min_length=1, description="What would hurt most if the read is wrong.")
    alternative_scenario: str = Field(min_length=1, description="The other way this resolves.")
    what_would_change_view: list[str] = Field(
        default_factory=list,
        description="Observable events that would change the read. One line each.",
    )


class ThesisReview(Strict):
    """The user's own thesis, weighed. Absent when they offered none."""

    supporting: list[str] = Field(default_factory=list, description="Evidence for their thesis.")
    contradicting: list[str] = Field(default_factory=list, description="Evidence against it.")


class AnalystNarrative(Strict):
    """Everything the LLM writes for one analysis, and nothing else.

    Sent to Ollama as the structured-output schema, so the model is constrained to this shape
    before a guardrail ever looks at it.
    """

    schema_version: str = SCHEMA_VERSION
    headline: str = Field(min_length=1, description="One sentence. No number that is not evidence.")
    decision_explanation: str = Field(
        min_length=1,
        description="Explain the fixed decision. Do not change or soften it.",
    )
    market_structure: NarrativeSection
    technical_view: NarrativeSection
    pattern: NarrativeSection
    smart_money: NarrativeSection
    factors_bullish: list[str] = Field(default_factory=list, description="One line each.")
    factors_bearish: list[str] = Field(default_factory=list, description="One line each.")
    scenarios: list[ScenarioNarrative] = Field(
        min_length=2,
        description="Exactly two: one named 'bullish' and one named 'bearish'.",
    )
    """Required, with no default. Ollama's grammar does not enforce `minItems`, and a field with a
    default is one Pydantic fills silently when the model omits it — so an absent list became an
    empty one that never failed validation. Without a default, omitting it is a parse error the
    repair prompt can report."""
    opinion: Opinion
    user_thesis_review: ThesisReview = Field(
        description="The user's thesis weighed. Leave both lists empty if they gave none.",
    )
    """Required for the same reason as `scenarios`: an optional field is one a model under pressure
    omits. Empty lists mean "no thesis to review"; completeness knows whether one was given."""

    def sections(self) -> dict[str, NarrativeSection]:
        return {
            "market_structure": self.market_structure,
            "technical_view": self.technical_view,
            "pattern": self.pattern,
            "smart_money": self.smart_money,
        }

    def prose(self) -> list[tuple[str, str]]:
        """Every written string with a label, for the guardrails to walk.

        Labels name the field so a violation can say *where* rather than just *what*.
        """
        parts: list[tuple[str, str]] = [
            ("headline", self.headline),
            ("decision_explanation", self.decision_explanation),
        ]
        for key, section in self.sections().items():
            parts.append((f"sections.{key}", section.text))
        for index, line in enumerate(self.factors_bullish):
            parts.append((f"factors_bullish[{index}]", line))
        for index, line in enumerate(self.factors_bearish):
            parts.append((f"factors_bearish[{index}]", line))
        for scenario in self.scenarios:
            parts.append((f"scenarios.{scenario.name}", scenario.narrative))
        parts.extend(
            [
                ("opinion.reason", self.opinion.reason),
                ("opinion.risk", self.opinion.risk),
                ("opinion.alternative_scenario", self.opinion.alternative_scenario),
            ]
        )
        for index, line in enumerate(self.opinion.what_would_change_view):
            parts.append((f"opinion.what_would_change_view[{index}]", line))
        for index, line in enumerate(self.user_thesis_review.supporting):
            parts.append((f"user_thesis_review.supporting[{index}]", line))
        for index, line in enumerate(self.user_thesis_review.contradicting):
            parts.append((f"user_thesis_review.contradicting[{index}]", line))
        return parts

    def cited_ids(self) -> set[str]:
        """Evidence ids named in the structured `evidence` lists."""
        cited = set()
        for section in self.sections().values():
            cited.update(section.evidence)
        for scenario in self.scenarios:
            cited.update(scenario.evidence)
        return cited
