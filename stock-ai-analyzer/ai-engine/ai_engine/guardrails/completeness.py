"""The draft has to actually be a report (docs/04 §7).

A structured-output schema guarantees the shape, not the substance: a model under pressure returns
`"text": "N/A"` for a section it found hard, cites `[E99]` that never existed, or writes only the
bullish scenario. Each of those passes JSON validation and fails the reader.

Citations get the strictest treatment. An id that does not resolve is worse than no citation at
all, because the whole point of printing `[E7]` is that a reader can follow it.
"""

from __future__ import annotations

import re

from ai_engine.guardrails.grounding import Violation
from ai_engine.schemas.report import SECTION_KEYS, AnalystNarrative

MIN_SECTION_CHARS = 40
"""Below this a section is a placeholder, not an explanation."""

_PLACEHOLDERS = ("n/a", "na", "none", "todo", "tbd", "not available", "unknown", "-", "—")

_INLINE_CITATION = re.compile(r"\[(E\d+)\]")


def check(
    narrative: AnalystNarrative,
    *,
    evidence_ids: set[str],
    expected_scenarios: set[str],
    thesis_provided: bool,
) -> list[Violation]:
    """Missing sections, empty opinion fields, unreal citations and absent scenarios."""
    violations: list[Violation] = []

    for key, section in narrative.sections().items():
        text = section.text.strip()
        if text.lower() in _PLACEHOLDERS or len(text) < MIN_SECTION_CHARS:
            violations.append(
                Violation(
                    rule="completeness",
                    where=f"sections.{key}",
                    detail=(
                        f"Write at least {MIN_SECTION_CHARS} characters "
                        "explaining this section from the evidence."
                    ),
                )
            )

    for field, value in (
        ("opinion.reason", narrative.opinion.reason),
        ("opinion.risk", narrative.opinion.risk),
        ("opinion.alternative_scenario", narrative.opinion.alternative_scenario),
    ):
        if value.strip().lower() in _PLACEHOLDERS or not value.strip():
            violations.append(
                Violation(rule="completeness", where=field, detail="This must be written, not left blank.")
            )

    if not narrative.opinion.what_would_change_view:
        violations.append(
            Violation(
                rule="completeness",
                where="opinion.what_would_change_view",
                detail="Give at least one observable event that would change the read.",
            )
        )

    written = {scenario.name.strip().lower() for scenario in narrative.scenarios}
    for missing in sorted(expected_scenarios - written):
        violations.append(
            Violation(
                rule="completeness",
                where="scenarios",
                detail=f'The "{missing}" scenario is missing. Both directions must be presented.',
            )
        )

    violations.extend(_citations(narrative, evidence_ids))

    review = narrative.user_thesis_review
    if thesis_provided and not (review.supporting or review.contradicting):
        violations.append(
            Violation(
                rule="completeness",
                where="user_thesis_review",
                detail="The user gave a thesis. Assess it: evidence for, evidence against.",
            )
        )

    return violations


def _citations(narrative: AnalystNarrative, evidence_ids: set[str]) -> list[Violation]:
    violations: list[Violation] = []
    known = ", ".join(sorted(evidence_ids, key=lambda item: int(item[1:]))[:8])

    for cited in sorted(narrative.cited_ids() - evidence_ids):
        violations.append(
            Violation(
                rule="completeness",
                where="evidence",
                detail=f'Evidence id "{cited}" does not exist. Cite only ids from the list, such as {known}.',
            )
        )

    for where, text in narrative.prose():
        for cited in _INLINE_CITATION.findall(text):
            if cited not in evidence_ids:
                violations.append(
                    Violation(
                        rule="completeness",
                        where=where,
                        detail=f'Cited "[{cited}]" does not exist. Cite only ids from the evidence list.',
                    )
                )

    for key in SECTION_KEYS:
        section = narrative.sections()[key]
        if not section.evidence and not _INLINE_CITATION.search(section.text):
            violations.append(
                Violation(
                    rule="completeness",
                    where=f"sections.{key}",
                    detail="Cite the evidence this section rests on.",
                )
            )

    return violations
