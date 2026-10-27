"""Analysis contracts shared with Laravel (docs/04-ai-agent-workflow.md §4).

Every model here is exported to `contracts/*.schema.json`; changing a field is a
contract change and must be reflected in `docs/03-database-schema.md` or `docs/05-api-spec.md`.
"""

from __future__ import annotations

from datetime import datetime
from typing import Any, Literal

from pydantic import Field, model_validator

from ai_engine.schemas.base import Strict
from ai_engine.schemas.report import AnalystNarrative

SCHEMA_VERSION = "1.0"

Timeframe = Literal["15m", "1h", "4h", "1D", "1W"]
TradingStyle = Literal["intraday", "swing", "positional", "investing"]
DataTier = Literal["A", "B", "C"]
"""A = OHLCV supplied, B = digitized from the screenshot, C = vision only."""

Direction = Literal["bullish", "bearish", "neutral"]
TradeDirection = Literal["long", "short", "flat"]
PositionStatus = Literal["none", "holding"]
DecisionAction = Literal["buy", "wait", "hold", "sell", "avoid"]
TriggerType = Literal["immediate", "close_above", "close_below", "touch"]
IndicatorStatus = Literal["computed", "vision_read", "unavailable"]
PatternCategory = Literal["classic", "candlestick", "smc"]
PatternStatus = Literal["forming", "confirmed", "failed", "mitigated", "unmitigated", "invalidated"]
DetectionMethod = Literal["algorithmic", "vision", "both"]
LevelType = Literal["support", "resistance", "liquidity", "equilibrium", "premium", "discount"]
EvidenceKind = Literal[
    "indicator", "level", "pattern", "smc", "volume", "structure", "risk", "factor", "context", "historical"
]
NarrativeSource = Literal["llm", "template"]


# --------------------------------------------------------------------------- inputs


class Bar(Strict):
    """One OHLCV bar. Timestamps are UTC instants (daily bars sit at 00:00 UTC)."""

    date: datetime
    open: float = Field(gt=0)
    high: float = Field(gt=0)
    low: float = Field(gt=0)
    close: float = Field(gt=0)
    volume: float | None = Field(default=None, ge=0)

    @model_validator(mode="after")
    def _consistent(self) -> Bar:
        if self.low > self.high:
            raise ValueError("low must not exceed high")
        if self.low > min(self.open, self.close) or self.high < max(self.open, self.close):
            raise ValueError("open and close must lie within the low-high range")
        return self


class PriceSeries(Strict):
    """Bars the analysis actually ran on, with the provenance that decides the tier."""

    symbol: str
    timeframe: Timeframe
    tier: DataTier
    source: Literal["ohlcv_import", "digitized"]
    bars: list[Bar]
    quality: float = Field(default=1.0, ge=0, le=1)

    @property
    def as_of(self) -> datetime | None:
        return self.bars[-1].date if self.bars else None


class Position(Strict):
    status: PositionStatus = "none"
    entry_price: float | None = Field(default=None, gt=0)
    quantity: float | None = Field(default=None, gt=0)
    stop_price: float | None = Field(default=None, gt=0)

    @model_validator(mode="after")
    def _holding_needs_entry(self) -> Position:
        if self.status == "holding" and self.entry_price is None:
            raise ValueError("a holding position needs an entry price")
        return self


class AccountSettings(Strict):
    """Sizing inputs, in the exchange's currency and share units."""

    equity: float = Field(gt=0)
    risk_pct: float = Field(default=1.0, gt=0, le=100)
    max_position_pct: float = Field(default=25.0, gt=0, le=100)
    lot_size: int = Field(default=10, ge=1)
    currency: str = "NPR"


class FeeSchedule(Strict):
    """Round-trip cost as a percentage of traded value (broker commission, SEBON, DP fee)."""

    buy_pct: float = Field(default=0.0, ge=0, le=10)
    sell_pct: float = Field(default=0.0, ge=0, le=10)

    @property
    def round_trip_fraction(self) -> float:
        return (self.buy_pct + self.sell_pct) / 100.0


class HistoricalContextItem(Strict):
    """A statistic about the user's own past outcomes, supplied by Laravel (docs/04 §10).

    It reaches the report as evidence, so the analyst can cite it and the grounding guardrail can
    check it, exactly like a computed number. Phase 6 fills these in; until then the list is empty.
    """

    key: str
    text: str
    value: float | None = None
    unit: Literal["price", "percent", "ratio", "count", "score", "bars", "none"] = "none"
    sample_size: int = Field(default=0, ge=0)


class AnalysisRequest(Strict):
    """What Laravel sends for one analysis (docs/05-api-spec.md)."""

    schema_version: str = SCHEMA_VERSION
    analysis_id: str
    correlation_id: str | None = None
    exchange: str = "NEPSE"
    symbol: str | None = None
    declared_symbol: str | None = None
    company_name: str | None = None
    """The resolved stock's company name, for the chart-identity check.

    NEPSE charting sites print the company name, not the ticker, so the ticker alone cannot tell
    whether a screenshot belongs to the symbol being analysed.
    """
    timeframe: Timeframe = "1D"
    trading_style: TradingStyle = "swing"
    position: Position = Field(default_factory=Position)
    account: AccountSettings
    fees: FeeSchedule = Field(default_factory=FeeSchedule)
    bars: list[Bar] = Field(default_factory=list)
    as_of: datetime | None = None
    image_path: str | None = None
    user_thesis: str | None = None
    symbol_universe: list[str] = Field(default_factory=list)
    skip_symbol_check: bool = False
    """The user has confirmed this screenshot really is `symbol`, so do not refuse it again.

    The chart-identity check reads the title with a small vision model, and a model that misreads
    one cannot be argued with by re-running: the same image reads the same way every time. Without
    a way through, a wrong refusal would be permanent, which is a worse failure than the one the
    check exists to prevent.
    """
    force_tier: DataTier | None = None
    historical_context: list[HistoricalContextItem] = Field(default_factory=list)


# --------------------------------------------------------------------------- evidence


class EvidenceItem(Strict):
    """A fact produced by a deterministic stage. The analyst may only cite these."""

    id: str
    kind: EvidenceKind
    key: str
    value: float | None = None
    value_high: float | None = None
    unit: Literal["price", "percent", "ratio", "count", "score", "bars", "none"] = "none"
    source: str
    tier: DataTier
    as_of: datetime | None = None
    text: str
    weight: float = Field(default=0.0, ge=0)
    """Relative importance, used to pick the items sent to the analyst in Phase 4C."""


# --------------------------------------------------------------------------- analysis outputs


class IndicatorResult(Strict):
    key: str
    name: str
    params: dict[str, float] = Field(default_factory=dict)
    value: float | None = None
    components: dict[str, float] | None = None
    signal: Direction | None = None
    status: IndicatorStatus
    unavailable_reason: str | None = None
    evidence_id: str | None = None


class Level(Strict):
    type: LevelType
    price_low: float = Field(gt=0)
    price_high: float = Field(gt=0)
    strength: float = Field(ge=0, le=1)
    touches: int = Field(default=0, ge=0)
    source: Literal["pivots", "volume_profile", "smc", "ocr_label"] = "pivots"
    last_touch: datetime | None = None
    evidence_id: str | None = None

    @model_validator(mode="after")
    def _ordered(self) -> Level:
        if self.price_low > self.price_high:
            raise ValueError("price_low must not exceed price_high")
        return self

    @property
    def mid(self) -> float:
        return (self.price_low + self.price_high) / 2


class PatternResult(Strict):
    category: PatternCategory
    type: str
    direction: Direction
    status: PatternStatus
    confidence: float = Field(ge=0, le=1)
    detection_method: DetectionMethod = "algorithmic"
    start_time: datetime | None = None
    end_time: datetime | None = None
    price_low: float | None = None
    price_high: float | None = None
    key_points: dict[str, Any] = Field(default_factory=dict)
    confirmation_rule: str | None = None
    measured_target: float | None = None
    invalidation_price: float | None = None
    evidence_id: str | None = None


class SwingPoint(Strict):
    index: int
    date: datetime
    price: float
    kind: Literal["high", "low"]
    degree: Literal["internal", "external"]
    label: Literal["HH", "HL", "LH", "LL"] | None = None


class DealingRange(Strict):
    low: float
    high: float
    equilibrium: float
    position: float = Field(ge=0, le=1)
    """0 = at the range low (deep discount), 1 = at the range high (deep premium)."""

    zone: Literal["discount", "equilibrium", "premium"]


SMC_FRAMING_NOTE = (
    "Smart Money Concepts are a rule-based framework for reading price structure. "
    "The zones below are derived from price action alone; they do not reveal actual "
    "institutional orders or intentions."
)


class SmcResult(Strict):
    structure: Direction
    last_event: Literal["bos_bullish", "bos_bearish", "choch_bullish", "choch_bearish"] | None = None
    swings: list[SwingPoint] = Field(default_factory=list)
    events: list[PatternResult] = Field(default_factory=list)
    """BOS/CHoCH, order blocks, FVGs, liquidity pools and sweeps, as storable patterns."""

    dealing_range: DealingRange | None = None
    framing_note: str = SMC_FRAMING_NOTE


class FactorScore(Strict):
    key: str
    label: str
    value: float = Field(ge=-1, le=1)
    weight: float = Field(ge=0)
    effective_weight: float = Field(ge=0)
    contribution: float
    available: bool = True
    reason: str
    evidence_ids: list[str] = Field(default_factory=list)


class ScoreBreakdown(Strict):
    setup_score: int = Field(ge=0, le=100)
    raw_score: float = Field(ge=-1, le=1)
    effective_score: float = Field(ge=-1, le=1)
    bias: Direction
    data_quality: float = Field(ge=0, le=1)
    evidence_agreement: float = Field(ge=0, le=1)
    factors: list[FactorScore] = Field(default_factory=list)
    dropped_factors: list[str] = Field(default_factory=list)
    adx_regime: Literal["trending", "ranging", "neutral"] = "neutral"


class TradePlan(Strict):
    direction: TradeDirection
    entry_type: Literal["breakout", "pullback", "market"]
    entry_price: float = Field(gt=0)
    trigger_type: TriggerType
    trigger_price: float | None = None
    stop_price: float = Field(gt=0)
    targets: list[float] = Field(default_factory=list)
    risk_pct: float
    reward_pct: float
    rr_gross: float
    rr_net: float
    quantity: int = Field(ge=0)
    position_value: float = Field(ge=0)
    position_pct: float = Field(ge=0)
    viable: bool
    trigger_met: bool = False
    reasons: list[str] = Field(default_factory=list)
    viable_at_price: float | None = None
    stop_basis: str = ""
    evidence_id: str | None = None


class Scenario(Strict):
    name: Literal["bullish", "bearish"]
    primary: bool
    trigger: str
    trigger_type: TriggerType
    trigger_price: float | None = None
    targets: list[float] = Field(default_factory=list)
    invalidation: float | None = None
    narrative: str | None = None


class Decision(Strict):
    action: DecisionAction
    rule_id: int
    conditions: list[str] = Field(default_factory=list)
    invalidation: float | None = None
    limited: bool = False
    rationale: str = ""


class PredictionSpec(Strict):
    """A falsifiable statement stored by Laravel and scored by the Phase 6 evaluator."""

    kind: Literal["directional_bias", "trade_setup"]
    scenario: Literal["primary", "bullish", "bearish"]
    direction: TradeDirection
    timeframe: Timeframe
    trigger_type: TriggerType
    trigger_price: float | None = None
    entry_price: float | None = None
    stop_price: float | None = None
    target_price_1: float | None = None
    target_price_2: float | None = None
    invalidation_price: float | None = None
    reference_price: float = Field(gt=0)
    as_of: datetime
    horizon_bars: int = Field(gt=0)
    setup_score: int | None = Field(default=None, ge=0, le=100)
    risk_reward: float | None = None


class StageTiming(Strict):
    stage: str
    duration_ms: int = Field(ge=0)


class BaseRate(Strict):
    """How a signal resolved the previous times it fired on this instrument.

    A measurement of the past, not a forecast, and never a substitute for a calibrated probability:
    `sample_size` is part of the reading, because a 70% rate on six occurrences is a coincidence
    with a percent sign on it. See ai_engine/analysis/base_rates.py for the method.
    """

    category: PatternCategory
    type: str
    direction: Direction
    sample_size: int = Field(ge=0, description="Occurrences with a full horizon of bars after them")
    favourable: int = Field(ge=0)
    adverse: int = Field(ge=0)
    unresolved: int = Field(ge=0, description="Neither barrier reached within the horizon")
    favourable_rate: float = Field(ge=0, le=1)
    adverse_rate: float = Field(ge=0, le=1)
    unresolved_rate: float = Field(ge=0, le=1)
    horizon_bars: int = Field(gt=0)
    barrier_atr: float = Field(gt=0, description="Barrier distance in ATR at the signal bar")
    median_barrier_pct: float | None = Field(default=None, description="That barrier as a % of price")
    median_bars_to_resolution: int | None = None


class HorizonOutlook(Strict):
    """What one horizon has been worth on this instrument, measured (ai_engine/analysis/outlook.py).

    A description of the past, never a forecast of the next move: `up_rate` is the share of N-bar
    moves that closed higher, and the band is the 10th-90th percentile of them. One move in five
    finished outside that band. Everything is null when `sample` is too small to say anything.
    """

    bars: int = Field(gt=0)
    label: str
    sample: int = Field(ge=0, description="Completed N-bar moves the numbers were measured on")
    up_rate: float | None = Field(default=None, ge=0, le=1)
    median_move_pct: float | None = None
    typical_move_pct: float | None = Field(
        default=None, ge=0, description="Median absolute move: the size of an ordinary one"
    )
    low_move_pct: float | None = None
    high_move_pct: float | None = None
    band_low: float | None = Field(default=None, gt=0)
    band_high: float | None = Field(default=None, gt=0)
    reference_price: float | None = Field(default=None, gt=0)
    base_rates: list[BaseRate] = Field(default_factory=list)
    """How the signals firing *now* resolved over this horizon, the other times they fired.

    Measured at this horizon rather than the trading style's, because "within twenty bars" is not
    an answer to "tomorrow". Only the types currently on the chart are kept: the rest describe a
    setup nobody is looking at.
    """


class AnalysisResult(Strict):
    """The engine's complete answer for one analysis."""

    schema_version: str = SCHEMA_VERSION
    analysis_id: str
    engine_version: str
    status: Literal["completed", "partial"] = "completed"
    symbol: str | None = None
    timeframe: Timeframe
    trading_style: TradingStyle
    tier: DataTier
    data_quality: float = Field(ge=0, le=1)
    as_of: datetime | None = None
    last_price: float | None = None
    bar_count: int = 0
    bias: Direction | None = None
    setup_score: int | None = Field(default=None, ge=0, le=100)
    decision: Decision
    score: ScoreBreakdown | None = None
    indicators: list[IndicatorResult] = Field(default_factory=list)
    patterns: list[PatternResult] = Field(default_factory=list)
    levels: list[Level] = Field(default_factory=list)
    smc: SmcResult | None = None
    base_rates: list[BaseRate] = Field(default_factory=list)
    outlook: list[HorizonOutlook] = Field(default_factory=list)
    """The measured next-day and next-week move distributions. Empty below Tier A."""
    scenarios: list[Scenario] = Field(default_factory=list)
    trade_plans: list[TradePlan] = Field(default_factory=list)
    predictions: list[PredictionSpec] = Field(default_factory=list)
    evidence: list[EvidenceItem] = Field(default_factory=list)
    warnings: list[str] = Field(default_factory=list)
    timings: list[StageTiming] = Field(default_factory=list)
    narrative_source: NarrativeSource | None = None
    narrative: AnalystNarrative | None = None
    """The analyst's prose, when one ran and its draft passed every guardrail."""
    report_markdown: str | None = None
