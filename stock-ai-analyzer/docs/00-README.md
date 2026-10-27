# Stock AI Analyzer — Phase 1 Design Package

**Status:** Draft for approval · **Date:** 2026-09-12 · **Phase:** 1 of 6 (design only, no code)

| # | Document | What it answers |
|---|----------|-----------------|
| 00 | [Getting Started](getting-started.md) | How to run it, where the credentials live, what each folder does |
| 01 | [Product Requirements](01-PRD.md) | What we build, for whom, what "good" means, what we refuse to do |
| 02 | [System Architecture](02-architecture.md) | Components, diagrams, runtime flow, folder structure, key decisions (ADRs) |
| 03 | [Database Schema](03-database-schema.md) | ERD, every table and column, indexes, constraints, factories and seeders |
| 04 | [AI Agent Workflow](04-ai-agent-workflow.md) | Pipeline DAG, each agent's contract, scoring, risk, decision policy, guardrails, learning loop |
| 05 | [API Specification](05-api-spec.md) | Public REST API, internal engine API, payloads, errors |
| 06 | [**Implementation Plan**](planning/README.md) | One document per phase ([1](planning/phase-1-design.md) · [2](planning/phase-2-foundation.md) · [3](planning/phase-3-upload.md) · [4](planning/phase-4-ai-pipeline.md) · [5](planning/phase-5-dashboard.md) · [6](planning/phase-6-testing-learning.md)): task checklists with IDs, files to create, exit gates, tests, risks |

Suggested reading order: 01 → 04 → 02 → 03 → 05 → planning. If you're short on time, read 01 and 04: they contain the decisions that shape everything else.

---

## The six decisions that shape the whole system

1. **Numbers come from computation, never from a language model.**
   Vision models are good at *describing* a chart and bad at *reading exact prices* from it. They hallucinate levels confidently. So every price, percentage and indicator value in a report is produced by deterministic code (OCR, axis calibration, indicator math, pattern geometry) and stored as an **evidence record**. The LLM only narrates evidence; a guardrail rejects any report containing a number not found in the evidence.

2. **Three data tiers, and the report always says which one it used.**
   - **Tier A — real OHLCV** (CSV import or a data provider): full quantitative analysis.
   - **Tier B — digitized from the screenshot** (price axis OCR + candle extraction): quantitative, approximate, confidence capped.
   - **Tier C — vision only**: qualitative read only; no entry/stop/target numbers, decision limited to WAIT/HOLD.
   You can't calculate EMA 200 from a screenshot showing 120 candles. Pretending otherwise is the "fake AI response" the brief forbids.

3. **The decision (BUY / WAIT / HOLD / SELL / AVOID) is made by a rule policy; the LLM explains it.**
   Rules are testable, versioned and consistent. The LLM cannot upgrade a WAIT to a BUY because the prose sounded convincing. A setup with a risk:reward below the style minimum can never be a BUY.

4. **"Confidence" is honest about what it is.**
   Until enough outcomes exist, the report shows a **setup score (0–100, uncalibrated)** from a documented factor model. Once ≥ 30 resolved predictions exist for a score bucket, it also shows the **empirical hit rate** ("similar setups reached T1 before stop 58% of the time, n = 41"). An LLM saying "70%" is not a probability.

5. **"Learning from mistakes" = measured outcomes feeding calibration, weights and prompts — not fine-tuning.**
   Every analysis emits machine-checkable predictions. A daily job scores them against real price bars. The stats drive (a) calibrated probabilities, (b) historical context injected into future reports, (c) versioned, human-approved re-weighting of scoring factors.

6. **Laravel is the system of record; the Python engine is a stateless function.**
   Image + optional OHLCV + context in → structured analysis out, streamed as NDJSON progress events. The engine has no database and no Redis; it can move to a GPU box later without redesign.

---

## Where this deviates from the brief, and why

| Brief | This design | Reason |
|-------|-------------|--------|
| 9 tables | +`exchanges`, `price_bars`, `analysis_levels`, `analysis_feedback`, `calibration_snapshots` | Outcome evaluation needs price bars; NEPSE rules (no shorting, Sun–Thu sessions, fees) need an exchange record; chart overlays need stored levels; learning needs feedback and calibration records |
| `/api/charts/upload` etc. | Same routes under `/api/v1/…` | Versioning costs nothing now and is painful to add after a commercial launch |
| BUY / HOLD / WAIT / SELL | adds **AVOID** | NEPSE doesn't allow short selling. "SELL" should only mean "exit a position you hold". For someone with no position and a bearish chart, the correct answer is "stay out" |
| `database/` top-level folder | Schema in `backend/database/migrations`; Postgres config in `infra/postgres` | Laravel owns the schema; Postgres is a container, not a source folder |
| Bull/Bear agent | Deterministic factor scoring + LLM phrasing | Scores must be reproducible to be calibrated |
| — | Added **Decision Policy** and **Guardrail** stages | Enforce the financial rules in code, not in a prompt |
| Laravel on Windows | Laravel runs in Docker Desktop / WSL2 | Horizon requires the `pcntl`/`posix` PHP extensions, which don't exist on native Windows PHP |
| "AI agents" | Fixed pipeline, no LangChain/LangGraph | The workflow is a known DAG; an agent framework adds dependencies and non-determinism without adding capability. Revisit if we add tool-using agents later |

---

## Decisions I need from you before Phase 2

1. **Hardware.** What GPU and VRAM does this machine have? (Run `nvidia-smi`.) This decides the model profile. See [04 §8](04-ai-agent-workflow.md#8-model-strategy-and-hardware-profiles).
2. **Market scope for MVP.** NEPSE only, or NEPSE plus global charts (US/India on TradingView)? NEPSE-first changes defaults (long-only, Sun–Thu calendar, NPR fees).
3. **Price data.** Is it OK to start with **CSV import** (exported from TradingView, ShareSansar, NEPSE Alpha, etc.) as the guaranteed Tier A source, and add an unofficial NEPSE fetcher later, subject to the site's terms?
4. **Users.** Single user on this PC first (simple login), or multi-user from day one? Multi-user is designed for either way, but single-user skips invites, roles and quotas.
5. **Runtime.** Is Docker Desktop with WSL2 acceptable? (It's required for Horizon.) Ollama stays native on Windows for direct GPU access.
6. **Decision vocabulary.** Approve BUY / WAIT / HOLD / SELL / **AVOID** as defined in [01 §8.3](01-PRD.md#83-decision-vocabulary)?

Record the answers in the [Phase 1 decision log](planning/phase-1-design.md#decision-log). Once the package is approved, [Phase 2 — Foundation](planning/phase-2-foundation.md) can start.
