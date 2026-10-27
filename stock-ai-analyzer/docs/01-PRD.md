# Product Requirements Document — Stock AI Analyzer

| | |
|---|---|
| Version | 0.1 — draft for approval |
| Date | 2026-09-12 |
| Status | Phase 1 |
| Scope | MVP (local, single machine), designed to become a commercial product |

---

## 1. Summary

Stock AI Analyzer turns a chart screenshot (TradingView, NEPSE charting sites) into a structured, evidence-backed technical analysis report. The report covers market structure, indicators, chart patterns, Smart Money Concepts, bullish and bearish scenarios with triggers and invalidation levels, and a risk-sized trade plan. It runs on local open-source models via Ollama and uses no paid APIs.

Every analysis is saved as a set of **machine-checkable predictions** and later scored against what the market actually did. Over time, both the tool's track record and the user's own become measurable.

## 2. Problem

- **Inconsistent analysis.** The same chart gets read differently on different days. Checklists get skipped when a trader is excited or afraid.
- **Chatbots invent numbers.** General-purpose AI describes charts fluently but invents price levels and speaks in certainties ("this will go to 650").
- **Nobody keeps score.** Traders don't know which setups actually work *for them*. Journals are abandoned because they're tedious and disconnected from the analysis that motivated the trade.
- **NEPSE is an afterthought.** Most TA tools assume US markets: short selling, Mon–Fri sessions, USD fee structures.

## 3. Users

| Persona | Description | Primary need |
|---------|-------------|--------------|
| **P1 — Owner / active trader** (primary) | Swing and positional NEPSE trader, uses TradingView and NEPSE charting sites, technically capable | A disciplined second opinion, a risk-sized plan, and a journal that closes the loop |
| **P2 — Learning trader** | Knows the basics, wants to understand *why* | Explanations of patterns and SMC in the context of real charts |
| **P3 — Analyst desk** (commercial, later) | Small advisory or research team | Consistent reports, history, export, audit trail |

## 4. Goals and non-goals

### Goals (MVP)

| ID | Goal | Measure |
|----|------|---------|
| G1 | Complete structured report from a screenshot | ≤ 3 min end-to-end on an 8 GB VRAM GPU profile |
| G2 | No invented numbers | 100% of numbers in reports trace to evidence records (enforced by a guardrail) |
| G3 | Every recommendation is fully reasoned | Each includes why, evidence, risk, alternative scenario and invalidation (schema-enforced) |
| G4 | Close the loop | Predictions auto-evaluated when price data exists; manual resolution otherwise |
| G5 | Local and private | Works offline except optional market-data fetch; no paid APIs; no telemetry |

### Non-goals (MVP)

- Price forecasting in any form ("price will be X on date Y").
- Order execution or broker integration.
- Fundamental analysis (financials, valuation). Planned post-MVP.
- Real-time intraday streaming.
- Multi-tenant SaaS, billing, teams.
- Fine-tuning models.

## 5. Product principles

1. **Scenarios, not predictions.** Every view is conditional: "If X, then likely Y; invalid if Z."
2. **Show the work.** Every claim cites evidence (`[E12]`), and the evidence is inspectable in the UI.
3. **Computation for facts, models for perception and language.** See [04 §1](04-ai-agent-workflow.md#1-core-principle-evidence-first).
4. **Degrade honestly.** With less data, the report says less and says why. It never fills gaps with plausible-looking numbers.
5. **Refuse bad trades.** If risk:reward is below the style minimum, or the stop is too wide, the answer is not BUY, however bullish the chart looks.
6. **Blind first, then compare.** The user's own thesis is hidden from the analysis stages and addressed only at the end, to avoid anchoring.
7. **Market-aware.** NEPSE rules are first-class: long-only, Sun–Thu sessions, price bands, NPR fee schedule.

## 6. Inputs

| Field | Required | Notes |
|-------|----------|-------|
| Screenshot | Yes | PNG, JPEG or WebP; ≤ 10 MB; ≥ 600×400 px. Drag-drop, file picker or **clipboard paste** (traders screenshot to the clipboard) |
| Symbol | Recommended | Autocomplete from `stocks`. If missing, extracted by OCR. Conflicts with the extracted symbol are flagged |
| Exchange | Default NEPSE | |
| Timeframe | Recommended | Default 1D. Verified against the chart header |
| Trading style | Yes | Swing · Positional · Investing · Intraday (intraday: manual outcome evaluation only) |
| Position status | Optional | None / Holding (entry price, quantity). Determines whether HOLD/SELL or BUY/WAIT/AVOID apply |
| Your thesis | Optional | Free text. Reviewed against the evidence at the end of the report |

## 7. Functional requirements

Priority: **M** must · **S** should · **C** could (MoSCoW).

### FR-1 Upload and intake
| ID | P | Requirement |
|----|---|-------------|
| 1.1 | M | Upload by drag-drop, picker or clipboard paste, with preview |
| 1.2 | M | Validate type, size and dimensions; re-encode the image (strips metadata, neutralizes malformed files); dedupe per user by SHA-256 |
| 1.3 | M | Capture symbol, exchange, timeframe, style and position; symbol autocomplete |
| 1.4 | S | "Analyze immediately" toggle on upload |
| 1.5 | M | Import OHLCV CSV per symbol and timeframe (unlocks Tier A and auto-evaluation) |

### FR-2 Chart understanding (Vision)
| ID | P | Requirement |
|----|---|-------------|
| 2.1 | M | Extract symbol, timeframe, platform, visible indicators with their legend values, price-axis labels, last price, visible date range |
| 2.2 | M | Flag conflicts between declared and extracted symbol or timeframe. The declared value wins, the report shows the conflict prominently, and the user can correct it and re-run in one click |
| 2.3 | M | Qualitative read: trend, notable structures, user-drawn lines |
| 2.4 | S | Digitize candles (Tier B) when price-axis calibration succeeds |
| 2.5 | M | Report the data tier and a data-quality score, with the reasons |
| 2.6 | M | Reject non-chart images with a clear message |

### FR-3 Technical analysis
| ID | P | Requirement |
|----|---|-------------|
| 3.1 | M | EMA 9/21/50/200, RSI 14, MACD 12/26/9, ADX 14 (+DI/−DI), ATR 14, volume SMA 20 and relative volume, OBV |
| 3.2 | M | EMA alignment, price vs EMAs, slopes, RSI regime, MACD state, trend strength, volume confirmation |
| 3.3 | S | RSI and MACD divergence against price swings |
| 3.4 | M | An indicator that can't be computed is shown as **unavailable, with the reason** (e.g. "EMA 200 needs 200 bars; 118 available"). It is never estimated |

### FR-4 Chart patterns
| ID | P | Requirement |
|----|---|-------------|
| 4.1 | M | Double top/bottom, head and shoulders (and inverse), ascending/descending/symmetrical triangle, flag, rising/falling wedge, channel |
| 4.2 | S | Cup and handle |
| 4.3 | M | Each pattern: status (forming / confirmed / failed), confidence, confirmation rule, measured-move target, invalidation price |
| 4.4 | S | Candlestick patterns (engulfing, hammer, pin bar), reported only at key levels |

### FR-5 Smart Money Concepts
| ID | P | Requirement |
|----|---|-------------|
| 5.1 | M | Swing structure (HH/HL/LH/LL), Break of Structure, Change of Character |
| 5.2 | M | Fair Value Gaps and Order Blocks with mitigation state; liquidity pools (equal highs/lows, prior swings) and sweeps |
| 5.3 | M | Premium / discount / equilibrium of the current dealing range |
| 5.4 | M | Framed as a **trading framework**. The report states that these zones are rule-based constructs and do not reveal actual institutional orders |

### FR-6 Levels
| 6.1 | M | Support and resistance zones from swing clustering, with strength (touches, recency, volume) |

### FR-7 Bull/Bear summary
| 7.1 | M | Bullish ✓ and bearish ✗ factor lists, each with weight and evidence; overall setup score and bias |

### FR-8 Scenarios
| 8.1 | M | At least one bullish and one bearish scenario, each with trigger, target zone, invalidation and evidence. One is marked primary |

### FR-9 Risk management
| ID | P | Requirement |
|----|---|-------------|
| 9.1 | M | Entry (type: breakout / pullback / at market) with trigger condition |
| 9.2 | M | Structural stop plus an ATR buffer; reject stops wider than the style maximum |
| 9.3 | M | Targets T1/T2 from levels and measured moves |
| 9.4 | M | Risk %, reward %, R:R gross and **net of estimated costs** |
| 9.5 | M | Position size from account size and risk-per-trade %, capped by maximum position % |
| 9.6 | M | Style-specific minimum R:R; below minimum ⇒ never BUY |

### FR-10 Decision
| 10.1 | M | BUY / WAIT / HOLD / SELL / AVOID, produced by the decision policy ([04 §6](04-ai-agent-workflow.md#6-decision-policy)), with explicit conditions |

### FR-11 Analyst report
| ID | P | Requirement |
|----|---|-------------|
| 11.1 | M | Sections as in §8.1, written from the viewpoint of an equity research analyst, technical analyst and risk manager |
| 11.2 | M | Evidence citations; "what would change this view"; alternative scenario |
| 11.3 | S | Review of the user's thesis: evidence for, evidence against |
| 11.4 | M | Short, non-intrusive disclaimer |

### FR-12 History
| 12.1 | M | List, filter (symbol, decision, bias, date, tier), search, reopen |
| 12.2 | S | Re-run with a newer engine version; both versions are kept |

### FR-13 Trading journal
| ID | P | Requirement |
|----|---|-------------|
| 13.1 | M | Record stock, entry price, date, quantity, reason, strategy, expected target, stop loss, tags |
| 13.2 | M | Link to an analysis or prediction; attach an entry screenshot |
| 13.3 | M | Close a trade: exit price and date, fees, P&L, R multiple, followed-plan flag, lessons |
| 13.4 | M | **AI plan vs your execution** comparison (entry slippage, stop honored, exit vs target) |

### FR-14 Outcome tracking
| ID | P | Requirement |
|----|---|-------------|
| 14.1 | M | Predictions created automatically from every completed analysis |
| 14.2 | M | Daily evaluation after market close (Tier A symbols, 1D/1W) |
| 14.3 | M | Manual resolution when no price data exists |
| 14.4 | M | **AI prediction vs actual result** view per analysis |

### FR-15 Performance and learning
| ID | P | Requirement |
|----|---|-------------|
| 15.1 | M | Stats by pattern, style, decision, score bucket and data tier: hit rate, average R, expectancy, MFE/MAE |
| 15.2 | S | Calibration chart and Brier score |
| 15.3 | S | Historical context for similar setups injected into new reports |
| 15.4 | C | Factor weight re-fit proposals (human-approved, versioned) |
| 15.5 | S | Thumbs up/down plus issue type on each report |

### FR-16 Export and settings
| 16.1 | S | Copy report as Markdown |
| 16.2 | C | PDF export |
| 16.3 | M | Settings: account size, risk %, max position %, default style, min R:R, fee schedule, model profile |

## 8. Report specification

### 8.1 Sections (in order)

1. **Header**: stock, exchange, timeframe, style, as-of date, data tier badge.
2. **Decision**: action, one-line reason, trigger conditions, invalidation.
3. **Bias and setup score**: plus calibrated probability once available.
4. **Market structure**
5. **Technical view**: trend, momentum, volume.
6. **Pattern**
7. **Smart Money analysis**, with the framework note.
8. **Support** / **Resistance** zones.
9. **Bullish factors ✓ / Bearish factors ✗**
10. **Scenarios**: bullish and bearish, each with trigger, targets and invalidation.
11. **Trade plan**: possible entry, stop loss, targets, risk %, reward %, R:R, position size.
12. **Professional opinion**: decision, reason, risk, alternative scenario, what would change the view.
13. **Your thesis vs the evidence** (if provided).
14. **Historical context** (once available): how similar setups have resolved.
15. **Evidence appendix**: E1…En, each with value, source and tier.
16. **Disclaimer**

### 8.2 Rules every recommendation must satisfy

| Must include | Enforced by |
|--------------|-------------|
| **Why**: the reasoning chain | Report schema: non-empty `reason` |
| **Evidence**: cited records | Guardrail: ≥ 1 evidence id per section; every number grounded |
| **Risk**: what can go wrong and how much | Schema: `risk` and `trade_plan.risk_pct` |
| **Alternative scenario** | Schema: ≥ 2 scenarios, opposite directions |
| **Invalidation**: what kills the idea | Schema: `invalidation` per scenario and on the decision |

Language guardrails reject certainty claims ("will definitely", "guaranteed", "sure shot", "risk-free", "cannot fail", "100%").

### 8.3 Decision vocabulary

| Decision | Applies when | Meaning |
|----------|--------------|---------|
| **BUY** | No position | A valid long setup whose trigger is already met; R:R ≥ style minimum; data Tier A or B |
| **WAIT** | No position | A setup is forming but not triggered, or there's no edge. Always lists the exact conditions that would change it |
| **AVOID** | No position | Bearish structure or no favorable setup. On NEPSE this means "stay out"; there's no short-selling recommendation |
| **HOLD** | Holding | Structure intact. States a trailing stop and the conditions for exit |
| **SELL** | Holding | Invalidation breached or bearish structure confirmed. Means *exit the position*, not short |

### 8.4 Confidence semantics

| Shown as | Meaning | When |
|----------|---------|------|
| **Setup score 68/100 (uncalibrated)** | Weighted factor model output ([04 §5.6](04-ai-agent-workflow.md#56-bullbear-agent-deterministic)). 50 = neutral | Always, for Tier A/B |
| **Historical hit rate 58% (n = 41)** | Share of past predictions in this score bucket that reached T1 before stop | Once the bucket has ≥ 30 resolved predictions |
| **Qualitative only** | No score; vision-only read | Tier C |

### 8.5 Illustrative report excerpt

> **Illustrative only.** This excerpt shows the format, using the numbers from the brief. It isn't real analysis output.

```
HDL · NEPSE · 1D · Swing                     Data: Tier A (CSV OHLCV) · as of 2026-09-10

DECISION: WAIT FOR CONFIRMATION
  Trigger:       daily close above 580 [E14] with volume ≥ 1.5× 20-day average [E9]
  Invalidation:  daily close below 520 [E17]

Bias: Bullish · Setup score 70/100 (uncalibrated — 12 of 30 outcomes needed)

Bullish scenario (primary)
  If price closes above 580 with strong volume → possible target zone 620–650 [E21, E22]
Bearish scenario
  If price loses support at 520 → possible downside 480–500 [E18]

Trade plan (pullback variant)
  Entry 550 · Stop 520 · Risk 5.45% · Target 620 · Reward 12.73% · R:R 1:2.33 (gross)
  Position size at 1% account risk: 166 shares

Bullish factors                        Bearish factors
✓ EMA 9 > 21 > 50 [E5]                 ✗ Resistance at 580 within 1.2 ATR [E14]
✓ Higher lows since July [E3]          ✗ RSI divergence on last swing high [E11]
```

## 9. UX

### 9.1 Screens

| Screen | Purpose |
|--------|---------|
| Dashboard | Recent analyses, WAIT setups with live distance to trigger, open predictions, open trades, performance snapshot |
| Analyze | Upload (drop/paste), inputs, conflict confirmation, live stage progress |
| Analysis report | Decision panel, annotated chart, scenarios, factors, trade plan, tabs for Technical / Patterns / SMC / Levels / Risk / Evidence, AI-vs-actual panel |
| History | Filterable table; compare two analyses of the same stock |
| Journal | Trades list, entry form, close-trade form, plan-vs-execution |
| Performance | Hit rates, R distribution, calibration curve, breakdowns |
| Settings | Risk profile, fees, model profile |

### 9.2 Report page wireframe

```
┌──────────────────────────────────────────────────────────────────────────────┐
│ HDL · NEPSE · 1D · Swing        [Tier A · OHLCV]   as of 10 Sep 2026   ⋯     │
├────────────────────────────────────────┬─────────────────────────────────────┤
│                                        │ DECISION        WAIT                │
│  Screenshot with overlays drawn from   │ Bias Bullish · Score 70/100         │
│  calibrated coordinates:               │ ─────────────────────────────────── │
│   ─ ─ resistance 580                   │ Trigger   close > 580, vol ≥ 1.5×   │
│   ▓▓▓ order block 540–552              │ Invalid   close < 520               │
│   ─ ─ support 520                      │ ─────────────────────────────────── │
│   entry / stop / T1 / T2 lines         │ Entry 550  Stop 520  T1 620         │
│                                        │ Risk 5.45%  R:R 1:2.33  Qty 166     │
│  [Screenshot | Interactive chart]      │                                     │
├────────────────────────────────────────┴─────────────────────────────────────┤
│ Scenarios:  ▲ Bullish (primary)  ▼ Bearish          ✓ Bull factors ✗ Bear    │
├──────────────────────────────────────────────────────────────────────────────┤
│ Technical │ Patterns │ Smart Money │ Levels │ Risk │ Evidence │ AI vs Actual │
└──────────────────────────────────────────────────────────────────────────────┘
```

Drawing the engine's levels back onto the user's own screenshot is the most important trust feature. If a level is wrong, the user sees it immediately.

### 9.3 UX principles
- Evidence is one click away from every claim.
- Uncertainty is visible: tier badge, "approx. ±1%" on digitized levels, "uncalibrated" label.
- Color is never the only signal: ✓/✗, ▲/▼ and text accompany green and red (accessibility).
- Dark theme by default; NPR formatting and Asia/Kathmandu time for NEPSE.

## 10. Non-functional requirements

| Area | Requirement |
|------|-------------|
| Performance | Upload API p95 < 1.5 s; deterministic stages < 2 s; end-to-end ≤ 3 min (8 GB VRAM), ≤ 90 s (16 GB VRAM) |
| Reliability | No analysis stays stuck in `running` (job timeout plus watchdog); transient failures retried; if the narrative fails, a template report is rendered from evidence (`partial` status) |
| Privacy | No data leaves the machine except optional market-data fetches; no telemetry |
| Security | Sanctum auth; private storage; upload hardening; HMAC-signed engine calls; rate limits; OWASP ASVS L1 baseline |
| Reproducibility | Engine, prompt and model versions plus the full evidence bundle stored per analysis; deterministic stages reproduce exactly |
| Code quality | PHPStan level 8 (Larastan), mypy `--strict`, TypeScript `strict`; coverage ≥ 90% on domain and analysis code, ≥ 75% overall |
| Observability | Structured JSON logs with the analysis ULID as correlation id; per-stage timings; Horizon dashboard |
| Accessibility | WCAG 2.2 AA on core flows |

## 11. Success metrics

| Metric | Target (MVP) |
|--------|--------------|
| Ungrounded numbers in reports | 0 |
| Schema-valid reports without fallback | ≥ 98% |
| Symbol and timeframe extraction accuracy (golden set) | ≥ 90% |
| Price-axis calibration success on TradingView default themes | ≥ 80% |
| Digitized last close vs true close | median error < 1% |
| End-to-end latency (8 GB profile) | p90 ≤ 3 min |
| Predictions with a resolved outcome after horizon (Tier A) | ≥ 95% |
| Reports rated useful by the owner | ≥ 70% |
| Brier score for bias predictions | Tracked from day one; improves version over version |

## 12. Compliance and disclaimers

- The product is positioned as an **analytical and educational tool**, not investment advice. Every report carries a short disclaimer.
- **Before any commercial launch, get legal review.** In Nepal, SEBON regulates investment advisory activity; India (SEBI Research Analyst rules), the US and others have similar regimes. The commercial tier may need "setup assessment" language instead of BUY/SELL.
- Screenshots are the user's own, for personal analysis. The commercial product must not redistribute third-party market data.
- `lightweight-charts` (TradingView, Apache-2.0) requires attribution per its license.
- Unofficial data sources must respect their terms of service. CSV import is the safe default.

## 13. Assumptions and constraints

- Single Windows 11 machine; Docker Desktop with WSL2; Ollama native with GPU.
- Laravel 13 with PHP 8.4+, PostgreSQL 17+, Redis 7+, Next.js (current stable, App Router), Python 3.12+. Versions are confirmed at Phase 2 setup.
- Auto-evaluation in the MVP covers 1D and 1W timeframes; intraday outcomes are resolved manually.
- NEPSE parameters (session days and hours, price band, tick size, fees, capital gains tax) are **configuration**, seeded with values to verify against current SEBON/NEPSE rules.

## 14. Out of scope (future candidates)

Multi-timeframe analysis (upload daily and weekly together), price alerts when triggers hit, broker floorsheet analytics, fundamental overlay, real-time websockets (Laravel Reverb), PDF branding, mobile app, hosted GPU inference, teams and billing, Nepali (B.S.) date display.
