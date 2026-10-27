"""The analysis DAG (docs/04-ai-agent-workflow.md §2), Tier A path.

Phase 4A runs the deterministic half: series -> indicators -> swings and levels -> technical,
pattern and SMC agents -> scoring -> risk -> decision -> template report. Vision (4B) and the
analyst LLM (4C) plug in at the marked seams without changing this order.
"""

from __future__ import annotations

from ai_engine.agents import bull_bear_agent, pattern_agent, risk_agent, smc_agent, technical_agent
from ai_engine.agents.analyst_agent import AnalystConnection
from ai_engine.agents.chart_identity_agent import VisionConnection
from ai_engine.analysis import base_rates as base_rate_analysis
from ai_engine.analysis import indicators as ind
from ai_engine.analysis import levels as level_analysis
from ai_engine.analysis import outlook as outlook_analysis
from ai_engine.analysis import swings as swing_analysis
from ai_engine.analysis.decision_policy import PolicyInputs, decide
from ai_engine.analysis.series import Series
from ai_engine.errors import EngineError
from ai_engine.pipeline.analyst import narrate
from ai_engine.pipeline.config import EngineConfig
from ai_engine.pipeline.context import AnalysisContext, ProgressCallback
from ai_engine.pipeline.evidence import EvidenceStore
from ai_engine.pipeline.identity import check as check_identity
from ai_engine.reports.generator import render_markdown
from ai_engine.schemas.analysis import (
    AnalysisRequest,
    AnalysisResult,
    DataTier,
    Level,
    PriceSeries,
)

MIN_BARS_TIER_A = 60
"""Below this the OHLCV is not enough to carry an analysis (docs/04 §3)."""


def analyse(
    request: AnalysisRequest,
    config: EngineConfig,
    *,
    progress: ProgressCallback | None = None,
    analyst: AnalystConnection | None = None,
    vision: VisionConnection | None = None,
) -> AnalysisResult:
    """Run the deterministic pipeline and return the complete structured result."""
    series = _build_series(request)
    tier: DataTier = request.force_tier or ("A" if len(series.bars) >= MIN_BARS_TIER_A else "C")
    quality = _data_quality(request, tier, len(series.bars))

    context = AnalysisContext(
        request=request,
        config=config,
        evidence=EvidenceStore(tier=tier, as_of=series.as_of),
        tier=tier,
        data_quality=quality,
        progress=progress,
    )

    # Before anything is computed: the cheapest run is the one that never happens, and an analysis
    # of the wrong instrument is worth less than no analysis at all.
    check_identity(context, vision)

    if tier == "C":
        return _tier_c_result(context, series)

    context.series = Series.from_price_series(series)
    # Kept before the style window is applied: base rates are measured on everything the import
    # holds, which for a NEPSE symbol is years more than any style looks back over.
    full_history = context.series
    _trim_to_style(context)
    _register_context_evidence(context)

    with context.stage("indicators", 0.2):
        context.indicators = ind.compute_all(context.series)
        if context.atr <= 0:
            raise EngineError(
                "INSUFFICIENT_DATA",
                "ATR could not be computed; at least 20 bars with real ranges are required.",
                status_code=422,
            )

    with context.stage("structure", 0.35):
        swing_config = config.patterns.swings
        context.swings = swing_analysis.detect(
            context.series.high,
            context.series.low,
            internal_n=swing_config.internal_fractal,
            external_n=swing_config.external_fractal,
            atr_value=context.atr,
            zigzag_threshold=swing_config.zigzag_atr_threshold,
        )
        context.levels = _levels(context)
        _register_levels(context)

    with context.stage("technical", 0.5):
        technical = technical_agent.run(context)

    with context.stage("smc", 0.6):
        smc_view = smc_agent.run(context)
        context.smc = smc_view.result

    with context.stage("patterns", 0.7):
        pattern_view = pattern_agent.run(context)
        context.patterns = pattern_view.patterns

    with context.stage("base_rates", 0.75):
        base_rates = base_rate_analysis.compute(
            full_history,
            config.patterns.candlesticks,
            horizon_bars=context.style.horizon_bars,
        )
        # Measured on the same full history, and for the same reason: the style window is too
        # small a sample to state a distribution over.
        outlook = outlook_analysis.compute(
            full_history,
            candlesticks=config.patterns.candlesticks,
            patterns=context.patterns,
        )

    with context.stage("scoring", 0.8):
        score_view = bull_bear_agent.run(
            context,
            technical_factors=technical.factors,
            smc_factors=smc_view.factors,
            pattern_factor=pattern_view.factor,
            adx_value=technical.adx_value,
        )

    with context.stage("risk", 0.9):
        risk_view = risk_agent.run(
            context, primary_pattern=pattern_view.primary, bias=score_view.breakdown.bias
        )
        decision = decide(
            PolicyInputs(
                tier=tier,
                symbol_resolved=bool(series.symbol),
                holding=request.position.status == "holding",
                setup_score=score_view.breakdown.setup_score,
                plans=risk_view.plans,
                structure_event=smc_view.result.last_event,
                position_stop=request.position.stop_price,
                scenario_invalidation=next(
                    (scenario.invalidation for scenario in risk_view.scenarios if scenario.name == "bullish"),
                    None,
                ),
                last_close=context.series.last_close,
                trailing_stop=risk_view.trailing_stop,
            ),
            config.scoring,
        )

    result = AnalysisResult(
        analysis_id=request.analysis_id,
        engine_version=config.engine_version,
        symbol=series.symbol,
        timeframe=series.timeframe,
        trading_style=request.trading_style,
        tier=tier,
        data_quality=quality,
        as_of=context.series.as_of,
        last_price=round(context.series.last_close, 4),
        bar_count=len(context.series),
        bias=score_view.breakdown.bias,
        setup_score=score_view.breakdown.setup_score,
        decision=decision,
        score=score_view.breakdown,
        indicators=technical.indicators,
        patterns=context.patterns,
        levels=_level_models(context),
        smc=smc_view.result,
        base_rates=base_rates,
        outlook=outlook,
        scenarios=risk_view.scenarios,
        trade_plans=risk_view.plans,
        predictions=risk_agent.predictions(
            context,
            plans=risk_view.plans,
            setup_score=score_view.breakdown.setup_score,
            bias=score_view.breakdown.bias,
        ),
        evidence=context.evidence.items,
        warnings=context.warnings,
        timings=context.timings,
        narrative_source="template",
    )
    # The analyst explains what the stages above decided. Without a backend, or with a draft that
    # will not pass the guardrails, this is a no-op and the template speaks instead.
    with context.stage("analyst", 0.95):
        written = narrate(context, result, analyst)
        result.narrative = written.narrative
        result.narrative_source = written.source
        if written.narrative is None and analyst is not None:
            context.warnings.append(
                f"The analyst draft was rejected ({len(written.violations)} guardrail violations); "
                "the template report is shown instead."
                if written.violations
                else "The analyst could not be reached; the template report is shown instead."
            )

    with context.stage("report", 1.0):
        result.report_markdown = render_markdown(
            result,
            technical_summary=technical.summary,
            pattern_summary=pattern_view.summary,
            smc_summary=smc_view.summary,
            bullish_lines=score_view.bullish_lines,
            bearish_lines=score_view.bearish_lines,
            unrealized_r=risk_view.unrealized_r,
        )
        result.timings = context.timings
    return result


# --------------------------------------------------------------------------- helpers


def _build_series(request: AnalysisRequest) -> PriceSeries:
    bars = sorted(request.bars, key=lambda bar: bar.date)
    if request.as_of is not None:
        bars = [bar for bar in bars if bar.date <= request.as_of]
    return PriceSeries(
        symbol=request.symbol or request.declared_symbol or "",
        timeframe=request.timeframe,
        tier="A" if len(bars) >= MIN_BARS_TIER_A else "C",
        source="ohlcv_import",
        bars=bars,
        quality=1.0,
    )


def _data_quality(request: AnalysisRequest, tier: DataTier, bar_count: int) -> float:
    """Tier A quality reflects bar coverage: 200 bars is full marks, fewer is proportionally less."""
    cap = 1.0 if tier == "A" else 0.85 if tier == "B" else 0.0
    if tier != "A":
        return cap
    coverage = min(1.0, bar_count / 200)
    return round(min(cap, 0.7 + 0.3 * coverage), 3)


def _trim_to_style(context: AnalysisContext) -> None:
    """Keep the style's lookback window so a long history does not dominate the geometry."""
    series = context.series
    if series is None:
        return
    lookback = context.style.swing_lookback_bars
    if len(series) > lookback:
        context.series = series.slice(len(series) - lookback)


def _register_context_evidence(context: AnalysisContext) -> None:
    series = context.series
    if series is None:
        return
    context.evidence.add(
        "context",
        "last_close",
        f"Last close {series.last_close:.2f} on {series.as_of:%Y-%m-%d} ({len(series)} bars analysed)",
        value=round(series.last_close, 4),
        unit="price",
        source="price_series",
        weight=1.0,
    )
    for index, item in enumerate(context.request.historical_context, start=1):
        context.evidence.add(
            "historical",
            f"historical_{item.key}_{index}",
            item.text,
            value=item.value,
            unit=item.unit,
            source="outcomes",
            weight=0.7,
        )
    context.evidence.add(
        "context",
        "data_quality",
        (
            f"Data quality {context.data_quality:.2f} on tier {context.tier} from {len(series)} bars; "
            "the setup score is scaled by it"
        ),
        value=context.data_quality,
        unit="ratio",
        source="price_series",
        weight=0.4,
    )


def _levels(context: AnalysisContext):  # type: ignore[no-untyped-def]
    series, swings = context.series, context.swings
    assert series is not None
    assert swings is not None
    config = context.config.patterns.levels
    pivots = [*swings.external, *swings.internal]
    return level_analysis.build(
        series,
        pivots,
        context.atr,
        tolerance_atr=config.cluster_tolerance_atr,
        max_per_side=config.max_levels_per_side,
        recency_half_life=config.recency_half_life_bars,
        min_touches=config.min_touches,
        volume_sma=None if context.indicators is None else context.indicators.volume_sma,
    )


def _register_levels(context: AnalysisContext) -> None:
    levels = context.levels
    series = context.series
    if levels is None or series is None:
        return
    for index, zone in enumerate(levels.supports, start=1):
        context.evidence.add(
            "level",
            f"support_{index}",
            (
                f"Support zone {zone.low:.2f}-{zone.high:.2f}, {zone.touches} touches, "
                f"strength {zone.strength:.2f}"
            ),
            value=round(zone.low, 4),
            value_high=round(zone.high, 4),
            unit="price",
            source="pivots",
            weight=0.8 + zone.strength / 5,
            as_of=level_analysis.zone_date(series, zone),
        )
    for index, zone in enumerate(levels.resistances, start=1):
        context.evidence.add(
            "level",
            f"resistance_{index}",
            (
                f"Resistance zone {zone.low:.2f}-{zone.high:.2f}, {zone.touches} touches, "
                f"strength {zone.strength:.2f}"
            ),
            value=round(zone.low, 4),
            value_high=round(zone.high, 4),
            unit="price",
            source="pivots",
            weight=0.8 + zone.strength / 5,
            as_of=level_analysis.zone_date(series, zone),
        )


def _level_models(context: AnalysisContext) -> list[Level]:
    levels, series = context.levels, context.series
    if levels is None or series is None:
        return []
    models: list[Level] = []
    for kind, zones in (("support", levels.supports), ("resistance", levels.resistances)):
        for index, zone in enumerate(zones, start=1):
            models.append(
                Level(
                    type=kind,
                    price_low=round(zone.low, 4),
                    price_high=round(zone.high, 4),
                    strength=zone.strength,
                    touches=zone.touches,
                    source="pivots",
                    last_touch=level_analysis.zone_date(series, zone),
                    evidence_id=context.evidence.id_for(f"{kind}_{index}"),
                )
            )
    if context.smc is not None and context.smc.dealing_range is not None:
        dealing = context.smc.dealing_range
        models.append(
            Level(
                type="equilibrium",
                price_low=round(dealing.equilibrium, 4),
                price_high=round(dealing.equilibrium, 4),
                strength=0.5,
                touches=0,
                source="smc",
                evidence_id=context.evidence.id_for("dealing_range"),
            )
        )
    return models


def _tier_c_result(context: AnalysisContext, series: PriceSeries) -> AnalysisResult:
    """Not enough data for numbers: the decision is limited and no plan is produced."""
    context.warn(
        f"Only {len(series.bars)} bars were supplied; at least {MIN_BARS_TIER_A} are needed for a "
        "numeric analysis, so this run is limited to a qualitative read."
    )
    decision = decide(
        PolicyInputs(
            tier="C",
            symbol_resolved=bool(series.symbol),
            holding=context.request.position.status == "holding",
            setup_score=None,
            plans=[],
        ),
        context.config.scoring,
    )
    result = AnalysisResult(
        analysis_id=context.request.analysis_id,
        engine_version=context.config.engine_version,
        status="partial",
        symbol=series.symbol or None,
        timeframe=series.timeframe,
        trading_style=context.request.trading_style,
        tier="C",
        data_quality=0.0,
        as_of=series.as_of,
        last_price=round(series.bars[-1].close, 4) if series.bars else None,
        bar_count=len(series.bars),
        decision=decision,
        evidence=context.evidence.items,
        warnings=context.warnings,
        timings=context.timings,
        narrative_source="template",
    )
    result.report_markdown = render_markdown(result)
    return result
