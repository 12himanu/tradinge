/**
 * Response shapes of the API (docs/05-api-spec.md). Kept by hand for now; `npm run gen:api` writes the
 * Scramble-generated OpenAPI types to schema.d.ts for cross-checking.
 */

export type Envelope<T> = { data: T };

export const TIMEFRAMES = ["15m", "1h", "4h", "1D", "1W"] as const;
export type Timeframe = (typeof TIMEFRAMES)[number];

export const TRADING_STYLES = ["swing", "positional", "investing", "intraday"] as const;
export type TradingStyle = (typeof TRADING_STYLES)[number];

export type User = {
  id: number;
  name: string;
  email: string;
  timezone: string;
  settings: Record<string, unknown> | null;
  default_exchange?: { code: string; name: string; currency: string; timezone: string } | null;
};

export type Stock = {
  id: number;
  symbol: string;
  name: string;
  sector: string | null;
  instrument_type: string;
  exchange?: string;
  price_coverage?: PriceCoverage[];
};

export type PriceCoverage = { timeframe: Timeframe; from: string; to: string; bars: number };

/**
 * One stored bar, exactly as PriceBarResource sends it.
 *
 * `time`, not `bar_time`: the resource renames it on the way out, and a type that guesses at the
 * column name instead of reading the resource is how the scanner drawer once crashed.
 */
export type PriceBar = {
  time: string;
  open: number;
  high: number;
  low: number;
  close: number;
  volume: number | null;
  /** Null for merolagani bars — its chart handler carries no traded amount. */
  turnover: number | null;
};

export type ChartUpload = {
  id: string;
  status: "uploaded" | "analyzing" | "analyzed" | "failed" | "rejected";
  declared: {
    symbol: string | null;
    exchange: string | null;
    timeframe: Timeframe | null;
    trading_style: TradingStyle | null;
  };
  stock: { id: number; symbol: string; name: string } | null;
  image: { url: string; width: number; height: number; size_bytes: number };
  original_filename: string | null;
  deduplicated: boolean;
  analysis: { id: string; status: string } | null;
  created_at: string;
};

export type ImportSummary = {
  inserted: number;
  updated: number;
  skipped_total: number;
  skipped: { row: number | null; reason: string }[];
  source: {
    delimiter?: string;
    date_format?: string;
    date_format_assumed?: boolean;
    rows_read?: number;
  };
  coverage: PriceCoverage[];
};

export type CheckStatus = "ok" | "degraded" | "down";

export type Health = {
  status: CheckStatus;
  checks: {
    database: { status: CheckStatus; latency_ms?: number; error?: string | null };
    redis: { status: CheckStatus; latency_ms?: number; error?: string | null };
    engine: {
      status: CheckStatus;
      version?: string | null;
      model_profile?: string | null;
      ollama?: { reachable: boolean; version: string | null; error: string | null } | null;
      error?: string | null;
    };
  };
};

// --------------------------------------------------------------------------- analysis (Phase 4D/5)

export const ANALYSIS_STATUSES = [
  "queued",
  "running",
  "completed",
  "partial",
  "failed",
  "cancelled",
] as const;
export type AnalysisStatus = (typeof ANALYSIS_STATUSES)[number];

export type DecisionAction = "buy" | "wait" | "hold" | "sell" | "avoid";
export type Bias = "bullish" | "bearish" | "neutral";
export type Direction = Bias;
/** `ohlcv` = Tier A, `digitized` = Tier B, `vision_only` = Tier C (docs/04 §3). */
export type DataTier = "ohlcv" | "digitized" | "vision_only";

export type EvidenceItem = {
  id: string;
  kind: string;
  key: string;
  value: number | null;
  value_high: number | null;
  unit: string;
  source: string;
  tier: "A" | "B" | "C";
  as_of: string | null;
  text: string;
  weight: number;
};

export type FactorScore = {
  key: string;
  label: string;
  value: number;
  weight: number;
  effective_weight: number;
  contribution: number;
  available: boolean;
  reason: string;
  evidence_ids: string[];
};

export type ScoreBreakdown = {
  setup_score: number;
  raw_score: number;
  effective_score: number;
  bias: Bias;
  data_quality: number;
  evidence_agreement: number;
  factors: FactorScore[];
  dropped_factors: string[];
  adx_regime: "trending" | "ranging" | "neutral";
};

export type TradePlan = {
  direction: "long" | "short" | "flat";
  entry_type: "breakout" | "pullback" | "market";
  entry_price: number;
  trigger_type: TriggerType;
  trigger_price: number | null;
  stop_price: number;
  targets: number[];
  risk_pct: number;
  reward_pct: number;
  rr_gross: number;
  rr_net: number;
  quantity: number;
  position_value: number;
  position_pct: number;
  viable: boolean;
  trigger_met: boolean;
  reasons: string[];
  viable_at_price: number | null;
  stop_basis: string;
  evidence_id: string | null;
};

export type Scenario = {
  name: "bullish" | "bearish";
  primary: boolean;
  trigger: string;
  trigger_type: TriggerType;
  trigger_price: number | null;
  targets: number[];
  invalidation: number | null;
  narrative: string | null;
};

export type TriggerType = "immediate" | "close_above" | "close_below" | "touch";

export type Decision = {
  action: DecisionAction;
  rule_id: number;
  conditions: string[];
  invalidation: number | null;
  limited: boolean;
  rationale: string;
};

export type AnalysisIndicator = {
  key: string;
  name: string;
  params: Record<string, number>;
  value: number | null;
  components: Record<string, number> | null;
  signal: Bias | null;
  status: "computed" | "vision_read" | "unavailable";
  unavailable_reason: string | null;
  evidence_id: string | null;
};

export type AnalysisPattern = {
  category: "classic" | "candlestick" | "smc";
  type: string;
  direction: Bias;
  status: "forming" | "confirmed" | "failed" | "mitigated" | "unmitigated" | "invalidated";
  confidence: number;
  detection_method: "algorithmic" | "vision" | "both";
  start_time: string | null;
  end_time: string | null;
  price_low: number | null;
  price_high: number | null;
  key_points: Record<string, unknown>;
  confirmation_rule: string | null;
  measured_target: number | null;
  invalidation_price: number | null;
  evidence_id: string | null;
};

export type AnalysisLevel = {
  type: "support" | "resistance" | "liquidity" | "equilibrium" | "premium" | "discount";
  price_low: number;
  price_high: number;
  strength: number;
  touches: number | null;
  source: "pivots" | "volume_profile" | "smc" | "ocr_label";
  pixel_y: number | null;
  evidence_id: string | null;
};

export type Prediction = {
  id: string;
  analysis_id: string;
  kind: "directional_bias" | "trade_setup";
  scenario: "primary" | "bullish" | "bearish";
  direction: "long" | "short" | "flat";
  timeframe: Timeframe;
  trigger_type: TriggerType;
  trigger_price: number | null;
  entry_price: number | null;
  stop_price: number | null;
  target_price_1: number | null;
  target_price_2: number | null;
  invalidation_price: number | null;
  reference_price: number;
  as_of: string;
  horizon_bars: number;
  expires_at: string;
  setup_score: number | null;
  probability: number | null;
  risk_reward: number | null;
  status: "pending" | "active" | "target_hit" | "stopped" | "expired" | "invalidated" | "cancelled";
  activated_at: string | null;
  resolved_at: string | null;
  outcome?: PredictionOutcome | null;
};

export type SmcResult = {
  structure: Bias;
  last_event: string | null;
  swings: { index: number; date: string; price: number; kind: string; label: string | null }[];
  events: AnalysisPattern[];
  dealing_range: {
    low: number;
    high: number;
    equilibrium: number;
    position: number;
    zone: "discount" | "equilibrium" | "premium";
  } | null;
  framing_note: string;
};

/** The listing shape: `GET /history` omits the report payload. */
export type AnalysisSummary = {
  id: string;
  status: AnalysisStatus;
  current_stage: string | null;
  chart_upload_id: string;
  stock: Stock | null;
  timeframe: Timeframe;
  trading_style: TradingStyle;
  position_status: "none" | "holding";
  data_tier: DataTier | null;
  data_quality: number | null;
  as_of: string | null;
  /** Bars available for this symbol and timeframe when the analysis was requested. */
  price_bar_count: number | null;
  last_price: number | null;
  bias: Bias | null;
  setup_score: number | null;
  calibrated_probability: number | null;
  decision: DecisionAction | null;
  decision_limited: boolean;
  narrative_source: "llm" | "template" | null;
  engine_version: string | null;
  error_code: string | null;
  error_message: string | null;
  duration_ms: number | null;
  queued_at: string | null;
  completed_at: string | null;
  created_at: string | null;
};

export type Analysis = AnalysisSummary & {
  user_thesis: string | null;
  report: {
    decision?: Decision;
    bias?: { direction: Bias | null; setup_score: number | null; note: string };
    factors?: FactorScore[];
    outlook?: HorizonOutlook[];
    scenarios?: Scenario[];
    trade_plans?: TradePlan[];
    smc?: SmcResult | null;
  } | null;
  report_markdown: string | null;
  warnings: string[];
  evidence: EvidenceItem[];
  score: ScoreBreakdown | null;
  smc: SmcResult | null;
  timings: { stage: string; duration_ms: number }[];
  indicators: AnalysisIndicator[];
  patterns: AnalysisPattern[];
  levels: AnalysisLevel[];
  predictions: Prediction[];
};

export type AnalysisProgress = {
  id: string;
  status: AnalysisStatus;
  stage: string | null;
  progress: number;
  terminal: boolean;
  error_code: string | null;
  error_message: string | null;
  duration_ms: number | null;
};

export type Paginated<T> = {
  data: T[];
  links?: { first?: string; last?: string; prev?: string | null; next?: string | null };
  meta?: { current_page: number; last_page: number; per_page: number; total: number };
};

// --------------------------------------------------------------------------- journal (Phase 5)

export const TRADE_STATUSES = ["planned", "open", "closed", "cancelled"] as const;
export type TradeStatus = (typeof TRADE_STATUSES)[number];

export const EXIT_REASONS = ["target", "stop", "manual", "time"] as const;
export type ExitReason = (typeof EXIT_REASONS)[number];

export type Trade = {
  id: string;
  stock?: { id: number | null; symbol: string | null; name: string | null };
  analysis_id: string | null;
  prediction_id: string | null;
  side: "long" | "short";
  status: TradeStatus;
  entry_date: string | null;
  entry_price: number | null;
  quantity: number | null;
  stop_loss: number | null;
  target_price: number | null;
  strategy: string | null;
  reason: string | null;
  tags: string[];
  confidence_level: number | null;
  exit_date: string | null;
  exit_price: number | null;
  exit_reason: ExitReason | null;
  fees: number | null;
  gross_pnl: number | null;
  net_pnl: number | null;
  return_pct: number | null;
  r_multiple: number | null;
  followed_plan: boolean | null;
  lessons: string | null;
  created_at: string | null;
};

export type UserSettings = {
  account_size?: number | null;
  currency?: string;
  risk_per_trade_pct?: number;
  max_position_pct?: number;
  default_style?: TradingStyle;
  min_rr_override?: number | null;
  model_profile?: string;
};

// --------------------------------------------------------------------------- stats (Phase 6.2)

export type Interval = { low: number; high: number };

/** Every rate arrives with its interval and its `n`; a rate alone would overstate a small sample. */
export type GroupMetrics = {
  group?: string;
  predictions: number;
  triggered: number;
  trigger_rate: number | null;
  resolved: number;
  wins: number;
  hit_rate: number | null;
  hit_rate_interval: Interval | null;
  expectancy_r: number | null;
  median_r: number | null;
  profit_factor: number | null;
  mfe_pct_median: number | null;
  mae_pct_median: number | null;
  ambiguous_bars: number;
  sufficient_sample: boolean;
};

export type PerformanceStats = {
  group_by: string;
  filters: Record<string, string>;
  overall: GroupMetrics & {
    brier_score: number | null;
    directional_accuracy: {
      correct: number;
      total: number;
      rate: number | null;
      interval: Interval | null;
    };
    engine_versions: string[];
  };
  groups: GroupMetrics[];
  r_histogram: { from: number; to: number; count: number }[];
};

export type CalibrationBucket = {
  bucket: string;
  low: number;
  high: number;
  n: number;
  wins: number;
  /** Mean setup score in the band, as a probability: what the score claimed. */
  predicted: number;
  /** Share that reached T1 before the stop: what happened. */
  observed: number;
  interval: Interval;
  /** Outcomes behind the fitted value — larger than `n` when isotonic pooled neighbours. */
  support: number;
  /** The published probability, or null while the band is still an anecdote. */
  calibrated: number | null;
  qualified: boolean;
};

export type Calibration = {
  engine_version: string | null;
  scope: string;
  method: "binned" | "isotonic";
  sample_size: number;
  active: boolean;
  min_bucket_sample: number;
  brier_score: number | null;
  brier_score_calibrated: number | null;
  calibration_error: number | null;
  buckets: CalibrationBucket[];
  engine_versions: string[];
};

export type JournalMetrics = {
  group?: string;
  trades: number;
  wins: number;
  win_rate: number | null;
  win_rate_interval: Interval | null;
  net_pnl: number | null;
  expectancy_r: number | null;
  median_r: number | null;
  profit_factor: number | null;
  sufficient_sample: boolean;
};

export type BehaviourFlag = {
  key: string;
  severity: "high" | "medium";
  text: string;
  count: number;
  of: number;
};

export type JournalAnalytics = {
  totals: JournalMetrics;
  by_strategy: JournalMetrics[];
  by_tag: JournalMetrics[];
  plan_adherence: { answered: number; followed: JournalMetrics; deviated: JournalMetrics };
  flags: BehaviourFlag[];
};

export type PredictionOutcome = {
  result: string;
  evaluation_method: string;
  triggered_at: string | null;
  exit_at: string | null;
  exit_price: number | null;
  return_pct: number | null;
  r_multiple: number | null;
  mfe_pct: number | null;
  mae_pct: number | null;
  bars_to_resolution: number | null;
  direction_correct: boolean | null;
  ambiguous_bar: boolean;
};

export type PricePath = {
  as_of: string | null;
  reference_price: number | null;
  bars: { date: string; open: number; high: number; low: number; close: number }[];
};

// --------------------------------------------------------------------------- market scanner

export const SIGNAL_KINDS = ["trap", "opportunity", "context"] as const;
export type SignalKind = (typeof SIGNAL_KINDS)[number];

export const RANGE_ZONES = ["premium", "equilibrium", "discount"] as const;
export type RangeZone = (typeof RANGE_ZONES)[number];

/** One structural finding, already labelled trap / opportunity / context by the backend. */
export type ScanSignal = {
  kind: SignalKind;
  category: string;
  type: string;
  direction: "bullish" | "bearish" | "neutral";
  status: string;
  headline: string;
  confidence: number;
  price_low: number | null;
  price_high: number | null;
  at: string | null;
};

/**
 * How a signal resolved the previous times it fired on this symbol.
 *
 * This is the only probability on the page, and it is a measurement of the past, not a forecast.
 * `sample_size` is part of the reading: a 70% rate on six occurrences is noise.
 */
export type BaseRate = {
  category: string;
  type: string;
  direction: "bullish" | "bearish" | "neutral";
  sample_size: number;
  favourable: number;
  adverse: number;
  unresolved: number;
  favourable_rate: number;
  adverse_rate: number;
  unresolved_rate: number;
  horizon_bars: number;
  barrier_atr: number;
  median_barrier_pct: number | null;
  median_bars_to_resolution: number | null;
};

/**
 * What one horizon has been worth on this stock, measured over its own history.
 *
 * Not a forecast: `up_rate` is the share of N-bar moves that closed higher, and the band is the
 * 10th-90th percentile of them, so one move in five finished outside it. Everything but `sample`
 * is null when there was too little history to say anything.
 */
export type HorizonOutlook = {
  bars: number;
  label: string;
  sample: number;
  up_rate: number | null;
  median_move_pct: number | null;
  typical_move_pct: number | null;
  low_move_pct: number | null;
  high_move_pct: number | null;
  band_low: number | null;
  band_high: number | null;
  reference_price: number | null;
  /** The signals firing now, scored over this horizon rather than the trading style's. */
  base_rates: BaseRate[];
};

export type ScanFactor = {
  key: string;
  label: string;
  value: number | null;
  weight: number;
  effective_weight: number;
  contribution: number;
  available: boolean;
  reason: string | null;
  evidence_ids?: string[];
};

/**
 * One trade plan, exactly as the engine emits it (ai-engine TradePlan).
 *
 * Mirrors the engine's field names rather than prettier ones: a type that renames what the wire
 * actually carries is a type that lies, and TypeScript will happily check code against the lie.
 */
export type ScanPlan = {
  direction: "long" | "short";
  entry_type: "breakout" | "pullback" | "market";
  entry_price: number;
  trigger_type: string;
  trigger_price: number | null;
  stop_price: number;
  targets: number[];
  risk_pct: number;
  reward_pct: number;
  rr_gross: number;
  rr_net: number;
  quantity: number;
  position_value: number;
  position_pct: number;
  viable: boolean;
  trigger_met: boolean;
  /** Why an unviable plan is unviable — the reader's actual question. */
  reasons: string[];
  viable_at_price: number | null;
  stop_basis: string;
  evidence_id: string | null;
};

export type ScanLevel = {
  type: string;
  price_low: number;
  price_high: number;
  strength: number;
  touches: number | null;
  source: string;
  last_touch: string | null;
  evidence_id: string | null;
};

export type MarketScan = {
  id: number;
  stock: { id: number | null; symbol: string | null; name: string | null; sector: string | null };
  scanned_on: string;
  as_of: string | null;
  timeframe: Timeframe;
  trading_style: TradingStyle;
  last_price: number | null;
  bias: "bullish" | "bearish" | "neutral" | null;
  /** A ranking, never a probability. The API repeats this in `meta.score_note`. */
  setup_score: number | null;
  decision: DecisionAction | null;
  decision_rationale: string | null;
  structure: string | null;
  last_event: string | null;
  range_zone: RangeZone | null;
  range_position: number | null;
  trap_count: number;
  opportunity_count: number;
  has_viable_plan: boolean;
  bar_count: number;
  data_quality: number | null;
  engine_version: string | null;
  error_code: string | null;
  error_message: string | null;
  /** Always null here: a scan files no prediction, so nothing calibrates it. */
  calibrated_probability: null;
  top_signals?: ScanSignal[];
  signals?: ScanSignal[];
  base_rates?: BaseRate[];
  outlook?: HorizonOutlook[];
  levels?: ScanLevel[];
  plans?: ScanPlan[];
  factors?: ScanFactor[];
  warnings?: string[];
  report_markdown?: string | null;
};

export type MarketScanSummary = {
  scanned: number;
  unreadable: number;
  bullish: number;
  bearish: number;
  neutral: number;
  with_traps: number;
  with_opportunities: number;
  viable_plans: number;
};

export type MarketScanPage = Paginated<MarketScan> & {
  meta?: Paginated<MarketScan>["meta"] & {
    scanned_on: string | null;
    summary: MarketScanSummary;
    score_note: string;
  };
};

// --------------------------------------------------------------------------- market data control

/** Progress of one queued batch, as `job_batches` records it. */
export type BatchProgress = {
  running: boolean;
  total: number;
  pending: number;
  done?: number;
  failed: number;
  /** 0–1, or null when nothing has been queued yet. */
  progress: number | null;
  started_at: string | null;
  finished_at: string | null;
};

export type MarketCoverage = {
  stocks_total: number;
  stocks_with_bars: number;
  bars: number;
  latest_bar: string | null;
  scanned: number;
  last_scan: string | null;
};

export type MarketDataStatus = {
  sync: BatchProgress;
  scan: BatchProgress;
  coverage: MarketCoverage;
};

/** The scanner's readings grouped by what the decision policy said. */
export type Hotlist = {
  data: Record<DecisionAction, MarketScan[]>;
  meta?: {
    scanned_on: string | null;
    summary: MarketScanSummary;
    per_group: number;
    score_note: string;
  };
};
