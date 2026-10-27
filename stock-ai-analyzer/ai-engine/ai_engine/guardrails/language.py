"""How the analyst is allowed to speak (docs/04 §7, rules 3, 4 and 6).

Three things are caught here, and all three are about a reader acting on words the analysis does
not support.

**Certainty.** A setup is a probability, never a promise. "Will rally", "guaranteed", "risk-free"
describe a future nobody has, and a trader who reads them sizes differently. Scenarios must stay
conditional.

**Contradicting the decision.** The decision comes from the risk gates, not the model. A draft that
says "buy here" under a `wait` has not explained the decision, it has overruled it — and the reader
follows the sentence, not the field.

**Claiming to see institutional orders.** Smart Money Concepts name price structure. An order block
is a rule about candles, not a window into somebody's book. "Institutions are accumulating" states
a fact about other people's intentions that no chart contains.
"""

from __future__ import annotations

import re

from ai_engine.guardrails.grounding import Violation

CERTAINTY_PHRASES = (
    "guaranteed",
    "guarantee",
    "risk-free",
    "risk free",
    "no risk",
    "cannot fail",
    "can't fail",
    "certain to",
    "certainly will",
    "will definitely",
    "definitely will",
    "is going to rally",
    "is going to fall",
    "will rally",
    "will crash",
    "will surge",
    "will plummet",
    "assured",
    "100% chance",
    "sure thing",
    "no doubt",
    "without a doubt",
)

INSTITUTIONAL_CLAIMS = (
    "institutions are buying",
    "institutions are selling",
    "institutions are accumulating",
    "institutions are distributing",
    "smart money is buying",
    "smart money is selling",
    "smart money is accumulating",
    "banks are buying",
    "banks are selling",
    "market makers are",
    "institutional orders",
    "the institutions will",
    "big players are",
    "whales are",
)

# Phrases that tell the reader to act against the decision the risk gates reached. Keyed by the
# decision they contradict.
_CONTRADICTIONS: dict[str, tuple[str, ...]] = {
    "wait": (
        "buy now",
        "buy here",
        "enter now",
        "enter here",
        "sell now",
        "go long now",
        "take the trade now",
    ),
    "avoid": ("buy now", "buy here", "enter now", "enter here", "go long", "take the trade", "worth buying"),
    "hold": ("sell now", "sell here", "exit now", "close the position now", "dump"),
    "buy": ("do not buy", "don't buy", "avoid this", "stay away", "sell now"),
    "sell": ("buy now", "buy here", "add to the position", "accumulate here"),
}


def check(prose: list[tuple[str, str]], decision: str) -> list[Violation]:
    """Certainty, contradiction and institutional-intent claims across every written field."""
    violations: list[Violation] = []

    for where, text in prose:
        lowered = text.lower()

        for phrase in CERTAINTY_PHRASES:
            if _contains(lowered, phrase):
                violations.append(
                    Violation(
                        rule="language",
                        where=where,
                        detail=f'"{phrase}" states certainty. Write it conditionally: "if ... then ...".',
                    )
                )

        for phrase in INSTITUTIONAL_CLAIMS:
            if _contains(lowered, phrase):
                violations.append(
                    Violation(
                        rule="language",
                        where=where,
                        detail=(
                            f'"{phrase}" claims knowledge of institutional orders. Smart Money Concepts '
                            "describe price structure only."
                        ),
                    )
                )

        for phrase in _CONTRADICTIONS.get(decision, ()):
            if _contains(lowered, phrase):
                violations.append(
                    Violation(
                        rule="language",
                        where=where,
                        detail=(
                            f'"{phrase}" contradicts the fixed decision "{decision}". '
                            "Explain the decision, do not change it."
                        ),
                    )
                )

    return violations


def _contains(haystack: str, phrase: str) -> bool:
    """Whole-phrase match, so "assured" does not fire inside "reassured"."""
    return re.search(rf"(?<!\w){re.escape(phrase)}(?!\w)", haystack) is not None
