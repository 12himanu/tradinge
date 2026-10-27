"""Every number in the prose must already exist as evidence (docs/04 §7, rule 2).

A language model asked about a chart will produce plausible numbers. Plausible is the problem: a
target of 612 where the evidence says 620 reads exactly as well and is simply false. So rather than
trusting the model, every numeric token it typed is extracted and matched against the values the
deterministic stages registered. Anything left over is a violation, and the draft goes back for
repair or is thrown away.

## Matching is precision-aware

Evidence holds `620.0`; a writer may reasonably type `620`, `620.00`, or `620.0`. All three mean
the same number, so the check compares rounded values rather than strings, at the precisions the
report actually uses. `620.4` is *not* the same number and is rejected.

## What is not a claim about the market

Ordinals and counts appear in ordinary prose — "the first target", "three of six EMAs", "20-day
average" — and a period or an evidence id is not a price. The exclusions below are deliberately
narrow: anything that looks like a price, a percentage or a ratio is checked.
"""

from __future__ import annotations

import re
from dataclasses import dataclass

DECIMALS = (0, 1, 2, 3, 4)
"""Precisions a number may be written at. Prices print at 2, ratios at 2, probabilities at 3."""

_NUMBER = re.compile(
    r"""
    (?<![\w.])          # not mid-identifier and not the tail of 1.2.3
    -?                  # a negative return or change
    \d{1,3}(?:,\d{3})*  # thousands separators: 1,050
    (?:\.\d+)?          # or a decimal tail
    |
    (?<![\w.])-?\d+(?:\.\d+)?
    """,
    re.VERBOSE,
)

_EVIDENCE_ID = re.compile(r"\[E\d+\]|\bE\d+\b")
"""Citations are not numbers. Stripped before extraction so [E7] never reads as 7."""

_PERIOD = re.compile(
    r"""
    \b\d+\s*-?\s*
    (?:m|h|D|W|min|minutes?|hours?|days?|weeks?
       |bars?|sessions?|periods?|candles?)\b
    """,
    re.IGNORECASE | re.VERBOSE,
)
"""`20 bars`, `14-day`, `50-period`, `1D`: lengths of time, not prices."""

_INDICATOR_PERIOD = re.compile(
    r"\b(?:EMA|SMA|MA|WMA|RSI|MACD|ADX|ATR|RVOL|OBV|DI)\s*-?\s*\d+(?:\s*,\s*\d+)*\b",
    re.IGNORECASE,
)
"""`EMA 50`, `RSI 14`, `MACD 12,26,9`: an indicator's settings, not a price."""

_ORDINAL = re.compile(r"\b\d+(?:st|nd|rd|th)\b", re.IGNORECASE)

_DATE = re.compile(r"\b\d{4}-\d{2}-\d{2}\b|\b\d{1,2}/\d{1,2}/\d{2,4}\b")

_SMALL_COUNT_LIMIT = 12
"""Integers at or below this are read as counts ("3 of 6 EMAs"), not as prices."""


@dataclass(frozen=True, slots=True)
class Violation:
    """One guardrail failure, phrased so the repair prompt can act on it."""

    rule: str
    where: str
    detail: str

    def __str__(self) -> str:
        return f"{self.where}: {self.detail}"


def allowed_tokens(values: set[float]) -> set[str]:
    """Every way a permitted value may legitimately be written.

    Built once per analysis and reused for each field, because the same set answers every question
    and rebuilding it per sentence would dominate the cost of the check.
    """
    tokens: set[str] = set()
    for value in values:
        for decimals in DECIMALS:
            rounded = round(value, decimals)
            tokens.add(f"{rounded:.{decimals}f}")
            # A whole number may be written without its trailing zeros.
            if rounded == int(rounded):
                tokens.add(str(int(rounded)))
    return tokens


def check(prose: list[tuple[str, str]], values: set[float]) -> list[Violation]:
    """Ungrounded numbers across every written field."""
    tokens = allowed_tokens(values)
    violations: list[Violation] = []

    for where, text in prose:
        for raw in ungrounded_numbers(text, tokens):
            violations.append(
                Violation(
                    rule="grounding",
                    where=where,
                    detail=f'"{raw}" is not in the evidence. Use a value from the evidence, or remove it.',
                )
            )

    return violations


def ungrounded_numbers(text: str, tokens: set[str]) -> list[str]:
    """Numeric tokens in `text` that no evidence value can account for."""
    scrubbed = _scrub(text)
    found: list[str] = []

    for match in _NUMBER.finditer(scrubbed):
        raw = match.group(0)
        if _is_grounded(raw, tokens):
            continue
        found.append(raw)

    return found


def _scrub(text: str) -> str:
    """Remove the spans that contain digits but make no claim about price."""
    for pattern in (_EVIDENCE_ID, _DATE, _INDICATOR_PERIOD, _PERIOD, _ORDINAL):
        text = pattern.sub(" ", text)
    return text


def _is_grounded(raw: str, tokens: set[str]) -> bool:
    """Whether a written number is a permitted value, compared at the precision it was written at.

    Rounding the written number until *something* matched would be the wrong check: it would let
    "620.4" pass against evidence of 620.0, a different price that reads exactly as convincingly.
    So a token with one decimal is matched against the evidence rendered to one decimal, and no
    further.
    """
    cleaned = raw.replace(",", "")

    try:
        value = float(cleaned)
    except ValueError:
        # Unparseable means it is not a number we need to defend.
        return True

    # A small whole number in prose is a count, not a price: "3 of 6 EMAs are aligned".
    if value == int(value) and abs(value) <= _SMALL_COUNT_LIMIT:
        return True

    decimals = min(len(cleaned.partition(".")[2]), max(DECIMALS))

    return f"{value:.{decimals}f}" in tokens
