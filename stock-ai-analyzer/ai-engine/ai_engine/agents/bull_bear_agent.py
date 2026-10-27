"""Bull/bear agent: assembles the factor table and scores it (docs/04 §5.6)."""

from __future__ import annotations

from dataclasses import dataclass

from ai_engine.analysis.scoring import FactorInput, room_to_move, score
from ai_engine.pipeline.context import AnalysisContext
from ai_engine.schemas.analysis import ScoreBreakdown


@dataclass(frozen=True, slots=True)
class ScoreView:
    breakdown: ScoreBreakdown
    bullish_lines: list[str]
    bearish_lines: list[str]


def run(
    context: AnalysisContext,
    *,
    technical_factors: dict[str, FactorInput],
    smc_factors: dict[str, FactorInput],
    pattern_factor: FactorInput,
    adx_value: float | None,
) -> ScoreView:
    """Combine every agent's factor into one setup score."""
    series, levels = context.series, context.levels
    if series is None or levels is None:
        raise ValueError("scoring needs a price series and levels")

    room = room_to_move(series.last_close, levels, context.atr)
    nearest = levels.nearest_resistance
    if nearest is not None:
        room = FactorInput(room.value, room.reason, context.evidence.ids_for("resistance_1"))

    factors: dict[str, FactorInput] = {
        **technical_factors,
        **smc_factors,
        "pattern": pattern_factor,
        "room_to_move": room,
    }
    breakdown = score(factors, context.config.scoring, adx_value=adx_value, data_quality=context.data_quality)

    for factor in breakdown.factors:
        if not factor.available:
            continue
        context.evidence.add(
            "factor",
            f"factor_{factor.key}",
            f"{factor.label}: {factor.reason} (weight {factor.effective_weight:.2f}, "
            f"contribution {factor.contribution:+.3f})",
            value=factor.contribution,
            unit="score",
            source="scoring",
            weight=abs(factor.contribution) * 10,
        )
    context.evidence.add(
        "factor",
        "setup_score",
        (
            f"Setup score {breakdown.setup_score} of 100 ({breakdown.bias}); "
            f"{breakdown.evidence_agreement:.0%} of the evidence weight agrees. Uncalibrated: no "
            f"probability is claimed until enough outcomes exist."
        ),
        value=breakdown.setup_score,
        unit="score",
        source="scoring",
        weight=1.0,
    )
    if breakdown.dropped_factors:
        context.warn(
            "Factors dropped and their weight redistributed: " + ", ".join(breakdown.dropped_factors)
        )

    return ScoreView(
        breakdown=breakdown,
        bullish_lines=_lines(breakdown, positive=True),
        bearish_lines=_lines(breakdown, positive=False),
    )


def _lines(breakdown: ScoreBreakdown, *, positive: bool) -> list[str]:
    """The two-column bull/bear listing from the brief."""
    lines: list[str] = []
    for factor in breakdown.factors:
        if not factor.available or factor.contribution == 0:
            continue
        if (factor.contribution > 0) != positive:
            continue
        mark = "✓" if positive else "✗"
        citation = f" [{factor.evidence_ids[0]}]" if factor.evidence_ids else ""
        lines.append(f"{mark} {factor.label} ({factor.contribution:+.2f}){citation}")
    return lines
