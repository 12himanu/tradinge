# Phase 6 — Outcomes, Learning and Hardening

| | |
|---|---|
| **Goal** | Close the loop: every prediction is scored against reality, the stats are visible, the scores become calibrated, and the system is hardened for daily use |
| **Prerequisites** | Phase 4D (predictions exist, done 2026-09-14) and Phase 5 (UI, core done 2026-09-14) |
| **Effort** | ~6 days initially, then ongoing |
| **Milestone** | **M6 · Keeps score** |

## Outcome

After each NEPSE session, open predictions are evaluated automatically. Each report shows **AI prediction vs actual result**. A Performance page shows hit rates, R, calibration and Brier score by pattern, style and score bucket. Once enough outcomes exist, new reports show calibrated probabilities and historical context for similar setups. The app is backed up, secured and covered by E2E tests.

> **Status 2026-09-17:** 6.1 (bar the two manual-data tasks), 6.2 and the calibration half of 6.3
> are built and green. Open: P6-04, P6-05, P6-12 to P6-14, and all of 6.4.
>
> Three design decisions worth recording.
>
> **The evaluator re-reads rather than remembers.** Each run re-evaluates an open prediction from
> `as_of` rather than from a saved cursor, and only writes an `outcomes` row when the prediction
> actually resolves. The schema requires `result` to be non-null — an outcome means *finished* —
> and re-reading one horizon of bars is cheap, so a corrected or late-imported bar still changes
> the verdict instead of being locked out.
>
> **Calibration is computed live; snapshots are history, not cache.** `GET /stats/calibration`
> always recomputes from outcomes, for the same reason. `EvaluatePredictionsJob` writes a
> `calibration_snapshots` row whenever an outcome lands, so "what was the app claiming in October"
> stays answerable — which is the only way to tell whether calibration is improving. The table
> gained a `user_id` (migration `2026_09_17_090000`): the curve is learned from one trader's
> symbols and timeframes, and pooling two users would calibrate each against the other's market.
>
> **Isotonic qualifies on pooled support, not bucket n.** Below 200 resolved setups the curve is
> raw 10-point bands; above it, PAVA merges only the bands that contradict the ordering. A band of
> 12 that merged into a block of 46 is backed by 46 outcomes, so that is the number the n ≥ 30 rule
> is applied to — and the UI shows both figures rather than quietly promoting the thin band.

## Tasks

### 6.1 Outcome evaluation
- [x] **P6-01** `Domain/Prediction/Services/PredictionEvaluator` (pure PHP), implementing [04 §10](../04-ai-agent-workflow.md#10-learning-loop): triggers, invalidation, gap fills, same-bar ambiguity (loss), expiry and mark-to-market, MFE/MAE, R multiple, directional bias and the Brier component
- [x] **P6-02** Exhaustive evaluator tests, table-driven: 21 cases covering each trigger type, gaps through the stop and through a touch trigger, the ambiguous bar, expiry both pending and active, invalidation before trigger, missing bars, shorts and the Brier component
- [x] **P6-03** `EvaluatePredictionsJob` on the `evaluation` queue, scheduled 16:00 Asia/Kathmandu Sun–Thu; idempotent by re-evaluating from `as_of` (see the note above)
- [ ] **P6-04** Data freshness: a reminder banner when Tier A symbols with open predictions have stale bars (CSV not updated), and `SyncMarketDataJob` for providers once enabled (D3)
- [ ] **P6-05** Manual resolution: `POST /predictions/{id}/outcome` and its UI form; conflict rules with auto-evaluation

### 6.2 Visibility
- [x] **P6-06** **AI vs Actual** panel on the report page: prediction status timeline; the price path after `as_of` with trigger, stop and targets marked; result, R, MFE/MAE
- [x] **P6-07** `Application/Services/PerformanceStatsService` and `GET /stats/performance?group_by=`: hit rate with a Wilson interval, trigger rate, expectancy, profit factor, MFE/MAE, n
- [x] **P6-08** Performance page: breakdown tables, R distribution histogram, hit rate by score bucket, filters (period, style, tier, engine version)
- [x] **P6-09** Journal analytics: win rate and expectancy by strategy and tag, followed-plan vs not, **behavioral flags** (early exits before T1, moved stops, oversizing vs risk settings). Deterministic stats

### 6.3 Learning
- [x] **P6-10** `CalibrationService`: per engine version, binned (n ≥ 30 per bucket) then isotonic; writes `calibration_snapshots`; `GET /stats/calibration`; calibration curve chart with Brier score
- [x] **P6-11** Calibrated probability is included in new analyses, and the "uncalibrated" label disappears when a bucket qualifies
- [ ] **P6-12** `HistoricalContextBuilder`, real implementation: similar setups (same pattern type, style, exchange, score bucket) → `historical_context` in the engine request, surfaced as evidence in the report
- [ ] **P6-13** `ai-engine/scripts/refit_weights.py`: logistic regression on factor vectors vs outcomes with walk-forward validation → a proposal report plus a `scoring.yaml` diff. **You approve before it's applied** (bumps `engine_version`)
- [ ] **P6-14** Feedback → golden set: "misread chart" feedback exports the screenshot and the analysis to `tests/fixtures/charts/inbox/` for labeling

### 6.4 Hardening
- [ ] **P6-15** Security review: upload hardening, authorization on every route, HMAC, rate limits, secrets, dependency audit (`composer audit`, `pip-audit`, `npm audit`)
- [ ] **P6-16** Backups: `infra/scripts/backup.ps1` (pg_dump plus the storage folder, rotated); a **tested restore** procedure in the runbook
- [ ] **P6-17** Operational: log rotation, Horizon failed-job alerts in the UI, disk usage check for `storage/charts`
- [ ] **P6-18** Performance: profile the pipeline on real runs; tune `keep_alive`, OCR cache hit rate, evidence bundle size; confirm the latency budgets
- [ ] **P6-19** Full Playwright suite: auth, upload variants, every analysis terminal state, journal lifecycle, manual resolution, performance page
- [ ] **P6-20** Soak test: 50 sequential analyses; check for memory leaks (engine and Ollama), queue health, stuck jobs
- [ ] **P6-21** Eval regression suite (vision, analyst) wired into `check.ps1 --eval`; the baseline is stored
- [ ] **P6-22** Docs: user guide (how to read a report, what tiers mean, how scoring works), runbook (model upgrades, troubleshooting), changelog

## Deliverables

Automatic outcome evaluation · AI-vs-actual view · performance and calibration analytics · journal insights · weight re-fit tooling · backups · complete E2E and eval suites.

## Exit gate

- [ ] For ≥ 2 weeks of real use, predictions resolve correctly: spot-check 20 outcomes by hand against the charts, with 0 errors
- [x] The evaluator test table passes, including gaps and ambiguity cases
- [ ] The Performance page reflects real outcomes; intervals and n are always shown
- [x] Calibration switches on automatically when a bucket reaches n ≥ 30 (verified with a test fixture)
- [ ] A backup restore onto a fresh stack succeeds
- [ ] The Playwright suite and the 50-run soak test pass; no stuck analyses
- [ ] No high-severity findings open from the security review

## Risks

| Risk | Mitigation |
|------|------------|
| Too few outcomes to calibrate for months | Show raw stats with intervals meanwhile; pool across engine versions only with an explicit flag |
| Stale CSV data blocks evaluation | Freshness banner; manual resolution; network providers (D3) |
| Overfitting weights to a small sample | Walk-forward validation, minimum n, human approval, versioned configs that can be rolled back |

## After Phase 6

MVP complete. Continue with the [post-MVP backlog](README.md#post-mvp-backlog-not-scheduled), starting from whatever the performance data and your daily use show matters most.
