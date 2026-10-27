"""Adversarial tests for the analyst guardrails (P4C-09).

Each of these is a draft a real model could plausibly return, and each must be caught. They are
written as attacks rather than as unit tests of helpers, because what matters is not that
`ungrounded_numbers` works — it is that an invented target never reaches a reader.
"""

from __future__ import annotations

import pytest

from ai_engine.agents import analyst_agent
from ai_engine.agents.analyst_agent import AnalystRequest
from ai_engine.guardrails import completeness, grounding, language
from ai_engine.schemas.analysis import EvidenceItem
from ai_engine.schemas.report import (
    AnalystNarrative,
    NarrativeSection,
    Opinion,
    ScenarioNarrative,
    ThesisReview,
)

GOOD_TEXT = "Price is holding above the rising 50-period average and the structure is intact [E1]."


def evidence(count: int = 3) -> list[EvidenceItem]:
    return [
        EvidenceItem(
            id=f"E{index}",
            kind="level",
            key=f"k{index}",
            value=value,
            unit="price",
            source="engine",
            tier="A",
            text=f"level {index}",
        )
        for index, value in enumerate([620.0, 550.5, 500.0][:count], start=1)
    ]


def section(text: str = GOOD_TEXT, ids: list[str] | None = None) -> NarrativeSection:
    return NarrativeSection(text=text, evidence=ids if ids is not None else ["E1"])


def narrative(**overrides: object) -> AnalystNarrative:
    base: dict[str, object] = {
        "headline": "A constructive setup that is not yet at a price worth paying.",
        "decision_explanation": "The plan does not clear the risk gates at this price [E1].",
        "market_structure": section(),
        "technical_view": section(),
        "pattern": section(),
        "smart_money": section(),
        "factors_bullish": ["Structure is intact [E1]."],
        "factors_bearish": ["Price sits near the top of the range [E1]."],
        "scenarios": [
            ScenarioNarrative(
                name="bullish",
                narrative=(
                    "If price closes above the level, then the move extends; "
                    "invalid if it closes back below [E1]."
                ),
                evidence=["E1"],
            ),
            ScenarioNarrative(
                name="bearish",
                narrative="If price loses the level, then it retraces; invalid if it reclaims it [E2].",
                evidence=["E2"],
            ),
        ],
        "opinion": Opinion(
            reason="The read is constructive but the entry is poor [E1].",
            risk="A failed retest would leave the position offside quickly [E2].",
            alternative_scenario="A drift back into the range would void the setup [E2].",
            what_would_change_view=["A close back below the level."],
        ),
        # Required, and empty means "the user gave no thesis".
        "user_thesis_review": ThesisReview(),
    }
    base.update(overrides)
    return AnalystNarrative.model_validate(base)


def request(decision: str = "wait", thesis_provided: bool = False) -> AnalystRequest:
    items = evidence()
    return AnalystRequest(
        symbol="PCBL",
        timeframe="1D",
        style="swing",
        tier="A",
        bias="bullish",
        setup_score=83,
        decision=decision,
        decision_rationale="Good chart, poor price.",
        scenario_block="",
        plan_block="",
        thesis_block="",
        evidence=items,
        allowed_values={620.0, 550.5, 500.0},
        expected_scenarios={"bullish", "bearish"},
        thesis_provided=thesis_provided,
    )


def rules(violations: list[grounding.Violation]) -> set[str]:
    return {violation.rule for violation in violations}


# --------------------------------------------------------------------------- a clean draft


def test_a_well_behaved_draft_passes_every_guardrail() -> None:
    assert analyst_agent.inspect(narrative(), request()) == []


# --------------------------------------------------------------------------- invented numbers


def test_catches_an_invented_target() -> None:
    draft = narrative(headline="Targets 612.00 on a break of the range.")

    violations = analyst_agent.inspect(draft, request())

    assert "grounding" in rules(violations)
    assert any("612.00" in violation.detail for violation in violations)


def test_catches_an_invented_number_anywhere_it_hides() -> None:
    draft = narrative(
        opinion=Opinion(
            reason="Worth buying near 431.25 [E1].",
            risk="A drop below 300 would hurt [E2].",
            alternative_scenario="Sideways into the range [E2].",
            what_would_change_view=["A close under 289.90."],
        )
    )

    violations = analyst_agent.inspect(draft, request())
    flagged = {violation.where for violation in violations if violation.rule == "grounding"}

    assert "opinion.reason" in flagged
    assert "opinion.risk" in flagged
    assert "opinion.what_would_change_view[0]" in flagged


def test_accepts_an_evidence_number_written_at_any_sensible_precision() -> None:
    for written in ("620", "620.0", "620.00", "550.50", "550.5"):
        draft = narrative(headline=f"The level at {written} is the one that matters [E1].")

        assert analyst_agent.inspect(draft, request()) == [], written


def test_accepts_a_thousands_separator() -> None:
    values = {1050.0}
    assert grounding.ungrounded_numbers("Resistance at 1,050.00", grounding.allowed_tokens(values)) == []


def test_does_not_mistake_counts_periods_or_citations_for_prices() -> None:
    tokens = grounding.allowed_tokens({620.0})
    text = "3 of 6 EMAs align [E7]; the 14-day RSI and the 200-period average agree on 2026-09-18."

    assert grounding.ungrounded_numbers(text, tokens) == []


def test_a_near_miss_is_still_a_miss() -> None:
    # 620.4 reads exactly like 620.0 and is a different price.
    tokens = grounding.allowed_tokens({620.0})

    assert grounding.ungrounded_numbers("Target 620.4", tokens) == ["620.4"]


# --------------------------------------------------------------------------- language


@pytest.mark.parametrize(
    "phrase",
    [
        "This will rally to the level",
        "A guaranteed setup",
        "This is risk-free",
        "Price will definitely break out",
    ],
)
def test_catches_certainty(phrase: str) -> None:
    violations = analyst_agent.inspect(narrative(headline=phrase), request())

    assert "language" in rules(violations)


def test_allows_conditional_language() -> None:
    draft = narrative(headline="If price reclaims the level, then the setup improves; invalid below it.")

    assert analyst_agent.inspect(draft, request()) == []


def test_does_not_fire_on_a_word_that_merely_contains_a_banned_phrase() -> None:
    assert language.check([("headline", "The reader was reassured by the retest.")], "wait") == []


@pytest.mark.parametrize(
    "phrase",
    [
        "Institutions are accumulating here",
        "Smart money is buying this level",
        "Market makers are defending the low",
    ],
)
def test_catches_claims_about_institutional_intent(phrase: str) -> None:
    violations = analyst_agent.inspect(narrative(headline=phrase), request())

    assert "language" in rules(violations)


def test_catches_a_draft_that_overrules_the_decision() -> None:
    draft = narrative(decision_explanation="The gates say wait, but buy now while it is cheap [E1].")

    violations = analyst_agent.inspect(draft, request(decision="wait"))

    assert any("contradicts the fixed decision" in violation.detail for violation in violations)


def test_the_same_words_are_fine_under_a_decision_they_do_not_contradict() -> None:
    draft = narrative(decision_explanation="The setup clears the gates, so buy now is the read [E1].")

    assert analyst_agent.inspect(draft, request(decision="buy")) == []


# --------------------------------------------------------------------------- completeness


def test_catches_a_fabricated_evidence_id() -> None:
    draft = narrative(market_structure=section(ids=["E99"]))

    violations = analyst_agent.inspect(draft, request())

    assert any("E99" in violation.detail for violation in violations)


def test_catches_a_fabricated_inline_citation() -> None:
    draft = narrative(headline="The structure is intact [E42].")

    violations = analyst_agent.inspect(draft, request())

    assert any("E42" in violation.detail for violation in violations)


def test_a_citation_that_quotes_the_evidence_line_is_accepted() -> None:
    # Models copy the prompt's own format back: "[E1] level 1" becomes "E1: level 1". The id is
    # right and unambiguous, so it is taken rather than failing the whole report over punctuation.
    draft = narrative(market_structure=section(ids=["E1: level 1 at 620.00"]))

    assert analyst_agent.inspect(draft, request()) == []


def test_a_citation_with_no_id_in_it_is_still_caught() -> None:
    draft = narrative(market_structure=section(ids=["the rising average"]))

    violations = analyst_agent.inspect(draft, request())

    assert any("does not exist" in violation.detail for violation in violations)


def test_catches_a_missing_bearish_scenario() -> None:
    # The schema demands two scenarios, so this fails as two of the same direction: the model
    # writes the bull case twice and never argues against itself.
    draft = narrative(
        scenarios=[
            ScenarioNarrative(
                name="bullish", narrative="If it breaks, then it runs; invalid below [E1].", evidence=["E1"]
            ),
            ScenarioNarrative(
                name="bullish",
                narrative="If it holds, then it grinds up; invalid below [E2].",
                evidence=["E2"],
            ),
        ]
    )

    violations = analyst_agent.inspect(draft, request())

    assert any('"bearish" scenario is missing' in violation.detail for violation in violations)


def test_catches_a_placeholder_section() -> None:
    draft = narrative(technical_view=NarrativeSection(text="N/A", evidence=["E1"]))

    violations = analyst_agent.inspect(draft, request())

    assert any(violation.where == "sections.technical_view" for violation in violations)


def test_catches_a_section_with_no_citation_at_all() -> None:
    draft = narrative(
        pattern=NarrativeSection(
            text="A long enough sentence that says something but cites nothing.", evidence=[]
        )
    )

    violations = analyst_agent.inspect(draft, request())

    assert any(violation.where == "sections.pattern" for violation in violations)


def test_catches_an_empty_opinion() -> None:
    draft = narrative(
        opinion=Opinion(
            reason="none",
            risk="A failed retest would hurt [E2].",
            alternative_scenario="Sideways [E2].",
            what_would_change_view=[],
        )
    )

    violations = analyst_agent.inspect(draft, request())
    flagged = {violation.where for violation in violations}

    assert "opinion.reason" in flagged
    assert "opinion.what_would_change_view" in flagged


def test_requires_a_thesis_review_when_the_user_gave_one() -> None:
    violations = analyst_agent.inspect(narrative(), request(thesis_provided=True))

    assert any(violation.where == "user_thesis_review" for violation in violations)


def test_accepts_a_thesis_review_when_one_was_asked_for() -> None:
    draft = narrative(
        user_thesis_review=ThesisReview(
            supporting=["Structure agrees with the thesis [E1]."],
            contradicting=["Price is at the top of the range [E2]."],
        )
    )

    assert analyst_agent.inspect(draft, request(thesis_provided=True)) == []


def test_a_thesis_review_is_checked_for_invented_numbers_too() -> None:
    draft = narrative(
        user_thesis_review=ThesisReview(
            supporting=["The thesis needs 999.99 to hold [E1]."], contradicting=[]
        )
    )

    violations = analyst_agent.inspect(draft, request(thesis_provided=True))

    assert any("999.99" in violation.detail for violation in violations)


# --------------------------------------------------------------------------- reporting


def test_a_violation_names_where_it_happened() -> None:
    draft = narrative(technical_view=section(text="A target of 777.77 is in reach [E1]."))

    violations = analyst_agent.inspect(draft, request())
    grounded = [violation for violation in violations if violation.rule == "grounding"]

    assert grounded[0].where == "sections.technical_view"
    assert str(grounded[0]).startswith("sections.technical_view: ")


def test_every_rule_can_fire_at_once() -> None:
    draft = narrative(
        headline="Guaranteed move to 612.00 [E77].",
        scenarios=[
            ScenarioNarrative(
                name="bullish", narrative="If it breaks, then it runs; invalid below [E1].", evidence=["E1"]
            ),
            ScenarioNarrative(
                name="bullish",
                narrative="If it holds, then it grinds up; invalid below [E2].",
                evidence=["E2"],
            ),
        ],
    )

    assert rules(analyst_agent.inspect(draft, request())) == {"grounding", "language", "completeness"}


def test_completeness_names_real_ids_to_help_the_repair() -> None:
    draft = narrative(market_structure=section(ids=["E99"]))

    violations = completeness.check(
        draft,
        evidence_ids={"E1", "E2", "E3"},
        expected_scenarios={"bullish", "bearish"},
        thesis_provided=False,
    )

    assert any("E1" in violation.detail for violation in violations)
