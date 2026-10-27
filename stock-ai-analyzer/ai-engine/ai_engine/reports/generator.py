"""Template report rendering (P4A-19).

This is the fallback narrative *and* the Phase 4A deliverable: the same verified facts the
analyst LLM would narrate, written straight from the structured result. Nothing here invents a
number — every value comes from `AnalysisResult`.
"""

from __future__ import annotations

from functools import lru_cache
from pathlib import Path

from jinja2 import Environment, FileSystemLoader, StrictUndefined

from ai_engine.schemas.analysis import SMC_FRAMING_NOTE, AnalysisResult, PatternResult

TEMPLATE_DIR = Path(__file__).resolve().parent / "templates"
EN_DASH = chr(0x2013)  # zone ranges print as a real en dash: 500.00 - 505.00

DISCLAIMER = (
    "This is a technical analysis of price data, not investment advice. Setups fail; position "
    "sizing and the stop are what keep a wrong read survivable. Scores are uncalibrated until "
    "enough outcomes have been recorded."
)


@lru_cache(maxsize=1)
def _environment() -> Environment:
    environment = Environment(
        loader=FileSystemLoader(TEMPLATE_DIR),
        undefined=StrictUndefined,
        trim_blocks=True,
        lstrip_blocks=True,
        autoescape=False,  # noqa: S701 - Markdown output, never HTML
        keep_trailing_newline=True,
    )
    environment.filters["price"] = _price
    environment.globals["zone_text"] = _zone_text
    return environment


def _zone_text(zone: PatternResult, tier: str) -> str:
    """A zone's price as one cell: a single level, or a band when the zone has width."""
    if zone.price_low is None:
        return "—"
    if zone.price_high is None or zone.price_high == zone.price_low:
        return _price(zone.price_low, tier)
    return f"{_price(zone.price_low, tier)} {EN_DASH} {_price(zone.price_high, tier)}"


def _price(value: float | None, tier: str = "A") -> str:
    """Tier B numbers are approximations and are labelled as such."""
    if value is None:
        return "n/a"
    prefix = "≈ " if tier == "B" else ""
    return f"{prefix}{value:,.2f}"


def render_markdown(
    result: AnalysisResult,
    *,
    technical_summary: str = "",
    pattern_summary: str = "",
    smc_summary: str = "",
    bullish_lines: list[str] | None = None,
    bearish_lines: list[str] | None = None,
    unrealized_r: float | None = None,
) -> str:
    """Render the full Markdown report for one analysis."""
    template = _environment().get_template("fallback.md.j2")
    return template.render(
        result=result,
        technical_summary=technical_summary,
        pattern_summary=pattern_summary,
        smc_summary=smc_summary,
        bullish_lines=bullish_lines or [],
        bearish_lines=bearish_lines or [],
        unrealized_r=unrealized_r,
        smc_note=SMC_FRAMING_NOTE,
        disclaimer=DISCLAIMER,
        classic_patterns=[pattern for pattern in result.patterns if pattern.category == "classic"],
        candlesticks=[pattern for pattern in result.patterns if pattern.category == "candlestick"],
        supports=[level for level in result.levels if level.type == "support"],
        resistances=[level for level in result.levels if level.type == "resistance"],
    )
