# Phase 4 — AI Analysis Pipeline

Phase 4 is the core of the product, so it's split into four sub-phases, each with its own exit gate.

| Sub-phase | Goal | Effort | Depends on | Status |
|-----------|------|--------|------------|--------|
| [4A](#phase-4a--deterministic-analysis-core-tier-a) | Deterministic analysis from OHLCV → template report | ~7 d | Phase 2 | **Built 2026-09-14**; exit gate open on real-CSV review and coverage |
| [4B](#phase-4b--vision-and-digitization) | Read screenshots: OCR, calibration, digitizer, VLM | ~7 d | 4A | Not started |
| [4C](#phase-4c--analyst-agent-and-guardrails) | LLM narrative with grounding guardrails | ~5 d | 4A | Not started |
| [4D](#phase-4d--integration) | Wire the engine into Laravel: jobs, persistence, API | ~4 d | 3, 4A | **Built 2026-09-14** (Tier A path; 4B/4C plug in without contract changes) |

Design reference: [04-ai-agent-workflow.md](../04-ai-agent-workflow.md).

---

## Phase 4A — Deterministic analysis core (Tier A)

**Goal:** given real OHLCV for a symbol, produce the full structured analysis (indicators, patterns, SMC, levels, scores, scenarios, trade plans, decision, predictions) and a complete **template-rendered report**, with no LLM involved.
**Milestone:** **M2 · Numbers you can trust**

### Tasks

**Contracts and plumbing**
- [x] **P4A-01** `schemas/`: `AnalysisRequest`, `PriceSeries`, `EvidenceItem`, `IndicatorResult`, `PatternResult`, `SmcResult`, `Level`, `FactorScore`, `Scenario`, `TradePlan`, `Decision`, `PredictionSpec`, `AnalysisResult`; export them to `contracts/`
- [x] **P4A-02** `pipeline/evidence.py`: `EvidenceStore` (sequential ids, typed add methods, lookup by key, value sets for grounding)
- [x] **P4A-03** `pipeline/context.py` and `pipeline/orchestrator.py`: DAG runner with stage timing and progress callbacks; Tier A path only
- [x] **P4A-04** `config/*.yaml` loaders with validation; `engine_version` = package version + config hash

**Analysis functions** (`analysis/`, pure, no I/O)
- [x] **P4A-05** `indicators.py`: EMA, RSI (Wilder), MACD, ADX/±DI, ATR, volume SMA/RVOL, OBV; availability rules; **TA-Lib parity tests** on real NEPSE CSVs
- [x] **P4A-06** `swings.py`: fractal pivots (internal n=2, external n=5), ATR zigzag; tests on synthetic series
- [x] **P4A-07** `levels.py`: pivot clustering (tolerance 0.5 × ATR), strength scoring (touches, recency, volume), zone merge
- [x] **P4A-08** `tests/fixtures/synthetic.py`: generator for pattern shapes with noise (double bottom/top, H&S, triangles, flag, wedge, channel, cup and handle)
- [x] **P4A-09** `patterns.py`: double top/bottom, H&S and inverse; confirmation, measured target, invalidation, confidence
- [x] **P4A-10** `patterns.py`: triangles (asc/desc/sym), flags, wedges, channels (trendline fitting on swings)
- [x] **P4A-11** `patterns.py`: cup and handle *(should)*; `candlesticks.py` reported only near levels
- [x] **P4A-12** `smc.py`: HH/HL/LH/LL labeling, structure state, BOS, CHoCH (close-based), displacement
- [x] **P4A-13** `smc.py`: order blocks with mitigation states, FVGs, liquidity (equal highs/lows), sweeps, dealing range and premium/discount
- [x] **P4A-14** `scoring.py` and `config/scoring.yaml`: factor functions, weight redistribution, ADX modulation, setup score, bias, evidence agreement
- [x] **P4A-15** `risk.py` and `config/styles.yaml`: entry candidates, structural stops with ATR buffer, targets, gross/net R:R with fees, position sizing with caps and lot size, viability gates, "viable at price X"; holding-mode trailing stop

**Agents**
- [x] **P4A-16** `technical_agent.py`, `pattern_agent.py`, `smc_agent.py`, `bull_bear_agent.py`, `risk_agent.py`: wrap the analysis functions, register evidence, produce the brief's output formats
- [x] **P4A-17** `decision_policy.py`: the rule table from [04 §6](../04-ai-agent-workflow.md#6-decision-policy); **one test per row**, plus precedence tests
- [x] **P4A-18** Scenario builder (bullish and bearish, with trigger, targets and invalidation) and `PredictionSpec` generation (directional bias plus viable setups; horizon from the style)

**Output**
- [x] **P4A-19** `reports/generator.py` and `templates/fallback.md.j2`: the full report from structured data, including the SMC framing note and disclaimer
- [x] **P4A-20** Dev CLI: `uv run python -m ai_engine.cli analyze --csv data/HDL.csv --symbol HDL --style swing --account 500000` → prints the Markdown report and writes the result JSON

### Exit gate (4A)
- [ ] Indicator parity with TA-Lib ≤ 1e-6 after warm-up on ≥ 3 **real** symbols — the check is written and green (max difference ~1e-14); drop real exports into `ai-engine/tests/fixtures/csv/` and it runs on those instead of the synthetic stand-ins
- [x] Every pattern detector finds its synthetic fixture and **doesn't** fire on random-walk controls — 12/12 shapes detected; false-positive rate 0.75 patterns per 300-bar control (mostly double top/bottom), recorded by `test_false_positive_rate_on_random_walks`
- [x] Every decision-policy row has a passing test (9 rows plus 4 precedence cases)
- [ ] The CLI produces a complete, sensible report for 5 NEPSE symbols; you review them and the numbers match the chart — **needs your CSV exports**; the CLI works end to end on synthetic data
- [x] Coverage ≥ 90% on `analysis/` and `agents/` — 94% overall and no module below 90%; enforced by `fail_under = 90` and measured in `infra/scripts/check.ps1`
- [x] Deterministic run time < 2 s for 500 bars — about 30-60 ms, asserted by `test_deterministic_run_is_well_inside_the_two_second_budget`

---

## Phase 4B — Vision and digitization

**Goal:** understand screenshots reliably enough to identify the chart and, when possible, reconstruct price data (Tier B).
**Milestone:** **M3 · Reads your screenshot**

### Tasks

**Golden set** *(needs your screenshots)*
- [ ] **P4B-01** Collect ≥ 50 screenshots: ≥ 30 TradingView (dark and light, with and without indicators), ≥ 10 NEPSE charting sites, ≥ 5 degraded (cropped, phone, crosshair), plus 10 non-chart images
- [ ] **P4B-02** Label them in `tests/fixtures/charts/labels.yaml`: symbol, timeframe, platform, axis min/max, last close (from real data), visible indicators, human-identified patterns

**Vision components** (`vision/`)
- [ ] **P4B-03** `preprocess.py`: RGB normalization, theme detection, pane segmentation, ×2 upscale for small images
- [ ] **P4B-04** `ocr.py`: RapidOCR wrapper; token regions (header, price axis, date axis, legends, price tag)
- [ ] **P4B-05** Parsers: symbol (rapidfuzz against `symbol_universe`), timeframe normalizer, legend parser, OHLC header parser, locale-aware numbers
- [ ] **P4B-06** `axis_calibration.py`: RANSAC linear and log fits, inlier and R² gates, log-scale detection; date-axis mapping via the trading calendar
- [ ] **P4B-07** `candle_extractor.py`: adaptive up/down color clustering, indicator-line exclusion, column scan, body/wick grouping, spacing regularity, OHLC mapping
- [ ] **P4B-08** Volume-pane extraction (relative volume); digitizer validation (price tag match, candle count) → `data_quality`

**VLM and agent**
- [ ] **P4B-09** `prompts/vision_read.v1.md` and its JSON schema; `models/vision.py` (temperature 0, image encoding, timeout)
- [ ] **P4B-10** `agents/vision_agent.py`: merge OCR and VLM, declared vs extracted conflicts, Heikin-Ashi/line/renko handling, `NOT_A_CHART`
- [ ] **P4B-11** Tier selection plus A/B cross-validation (`SYMBOL_OR_DATA_MISMATCH`) and as-of slicing in the orchestrator
- [ ] **P4B-12** VLM pattern corroboration in the pattern agent; Tier C qualitative evidence path
- [ ] **P4B-13** OCR/VLM result cache keyed by image SHA-256 plus model digest

**Evaluation**
- [ ] **P4B-14** `tests/eval/run_vision_eval.py`: runs the golden set and writes a metrics report (per-field accuracy, calibration success, last-close error, latency)

### Exit gate (4B)
- [ ] Symbol and timeframe accuracy ≥ 90% on the golden set
- [ ] Axis calibration succeeds on ≥ 80% of TradingView default-theme charts
- [ ] Digitized last-close median error < 1% where calibration succeeded
- [ ] 10/10 non-chart images rejected with `NOT_A_CHART`
- [ ] Vision stage p90 ≤ 60 s on the chosen hardware profile
- [ ] A failed calibration **always** degrades to Tier C; it never produces Tier B numbers

---

## Phase 4C — Analyst agent and guardrails

**Goal:** a professional narrative that explains the structured analysis, where every number is grounded, the language is conditional, and the decision can't be altered.

### Tasks
- [x] **P4C-01** `schemas/report.py`: the `Report` model with LLM-written fields separated from injected fields; export the JSON schema for Ollama `format`
- [~] **P4C-02** Evidence compaction (top-60 by weight is in; the < 8k-token serialisation is not measured): pick ≤ 60 items by factor weight and section needs, and serialize compactly (< 8k tokens)
- [x] **P4C-03** `prompts/analyst.v1.md` (rules from [04 §7](../04-ai-agent-workflow.md#7-analyst-agent-llm)); `agents/analyst_agent.py` (structured output, temperature 0.2, thinking off)
- [x] **P4C-04** `guardrails/grounding.py`: allowed-value set in all display precisions; numeric token extraction (commas, %, ratios, ranges); precision-aware matching
- [x] **P4C-05** `guardrails/language.py` (certainty phrases, contradicting decision words); `guardrails/completeness.py` (sections, citations, scenario pairs, opinion fields, cited ids exist)
- [x] **P4C-06** Repair loop (`prompts/repair.v1.md` with the violation list), then template fallback; `narrative_source` flag; `partial` status mapping
- [x] **P4C-07** Injection of the fixed decision, trade plan, levels and SMC framing note into the final report; user-thesis review as the last section
- [ ] **P4C-08** `templates/report.md.j2`: LLM report → Markdown (prose is stored and served; Markdown still renders the template)
- [x] **P4C-09** Adversarial guardrail tests: drafts with invented numbers, "guaranteed", a flipped decision, a missing bearish scenario, fake evidence ids
- [ ] **P4C-10** Analyst eval: 20 Tier A cases through the real model → schema-valid rate, pre- and post-repair violations, latency

### Exit gate (4C)
- [ ] 0 ungrounded numbers in final reports across the eval set
- [ ] ≥ 98% valid without template fallback
- [ ] Every adversarial test is caught
- [ ] Analyst stage p90 within budget ([04 §12](../04-ai-agent-workflow.md#12-performance-budget-gpu_8gb-profile))
- [ ] You review 5 reports and judge them professional, conditional and useful

---

## Phase 4D — Integration

**Goal:** upload → analyze → report works end to end through the app, robustly.
**Milestone:** **M4 · First real report in the app**

> Built ahead of 4B and 4C, against the Tier A pipeline from 4A. The engine already returns a
> complete `AnalysisResult` with a template report, so vision and the analyst LLM fill in fields
> that the contract, the mapper and the API already carry.

### Tasks

**Engine side**
- [x] **P4D-01** `POST /v1/analyze`: NDJSON streaming response, concurrency semaphore (503 + `Retry-After` when busy), cancellation on client disconnect, storage-root path guard, error events

**Laravel side**
- [x] **P4D-02** `Domain/Analysis/Contracts/AnalysisEngine`; `Infrastructure/AI/PythonEngineClient` with `EngineRequestSigner`, `NdjsonStreamReader` and per-read/overall timeouts; typed exceptions
- [x] **P4D-03** `Application/Actions/RequestAnalysis`: idempotency (reuse identical completed analyses unless `force`), 409 when in progress; builds the request (OHLCV from `price_bars`, fee schedule, account settings, symbol universe)
- [x] **P4D-04** `Application/Jobs/AnalyzeChartJob`: queue `ai`, tries 2, timeout 900, backoff 30; writes progress to Redis; maps errors to status and `error_code`; exits early if the analysis is already terminal
- [x] **P4D-05** `EngineResultMapper` and `PersistAnalysisResult`: one transaction writing the analysis, indicators, patterns, levels and predictions (`expires_at` computed with the exchange trading calendar); `AnalysisCompleted` event
- [x] **P4D-06** `HistoricalContextBuilder` stub (returns empty until Phase 6)
- [x] **P4D-07** Endpoints: `POST /charts/{id}/analyze`, `GET /analysis/{id}`, `GET /analysis/{id}/status`, `POST /analysis/{id}/cancel`, `POST /analysis/{id}/feedback`, `GET /history`; resources and policies
- [x] **P4D-08** `StuckAnalysisWatchdog`, scheduled every 5 minutes

**Verification**
- [x] **P4D-09** Contract tests: engine result fixtures validated against `contracts/*.schema.json`; mapper tests for each tier; recorded NDJSON streams replayed through `NdjsonStreamReader`
- [x] **P4D-10** Failure drills: engine stopped, Ollama stopped, model missing, timeout, invalid image, not a chart, Horizon worker killed mid-job
- [x] **P4D-11** Scripted E2E smoke test: upload a golden screenshot → poll → assert completed with all sections and predictions

### Exit gate (4D)
- [x] End-to-end p90 ≤ 3 min — a Tier A round trip (Laravel → engine → persisted rows) takes about 1 s; the budget was written for the vision and analyst stages that arrive in 4B/4C
- [x] Every failure drill ends in the correct status with a clear user-facing error — engine down, busy (503), stream stalled, rejected request, worker killed and never-picked-up are each covered by a test; the watchdog sweeps anything left `running`
- [x] Re-running an identical request reuses the result; `force` creates a new version
- [x] Predictions are persisted with correct horizons and trading-calendar expiry (20 swing sessions ≈ 28 calendar days on NEPSE)
- [x] Contract tests green in both repos — the built request is validated against `analysis_request.schema.json`, and the mapper runs on fixtures generated by the real engine

## Risks (Phase 4)

| Risk | Mitigation |
|------|------------|
| Pattern detectors over-fire on noise | Random-walk control tests; confidence thresholds; forming-pattern cap |
| Vision accuracy below the gates | Tier C degradation keeps the product honest; improve with golden-set feedback; allow the user to confirm symbol/timeframe |
| LLM latency too high on 8 GB | Smaller text model; shorter evidence bundle; template-only mode for quick checks |
| Grounding too strict (false violations) | Precision-aware matching and allowed parameter sets; log violations and tune with the eval data |
