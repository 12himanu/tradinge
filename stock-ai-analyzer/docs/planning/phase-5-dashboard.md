# Phase 5 — Dashboard and Journal UI

| | |
|---|---|
| **Goal** | A professional trader's workspace: read a report in seconds, verify it visually, act on it, journal it, and find it again |
| **Prerequisites** | Phase 4D exit gate passed (done 2026-09-14) |
| **Effort** | ~7 days |
| **Milestone** | **M5 · Usable daily** |

## Outcome

The report page shows the decision, scenarios and trade plan at a glance, with the engine's levels **drawn over your own screenshot**. Every claim links to its evidence. History, journal, predictions and settings are fully usable, at 400 px width as well as on desktop.

## Tasks

### 5.1 Foundations
- [x] **P5-01** Design tokens (color, type, spacing); dark theme by default plus light; bull/bear colors always paired with ▲/▼, ✓/✗ and text; NPR and exchange-timezone formatters in `lib/format.ts`
- [x] **P5-02** App shell: sidebar nav (Dashboard, Analyze, History, Journal, Performance, Settings), header with engine health, empty, loading and error state components

### 5.2 Analysis flow
- [x] **P5-03** Analyze page progress: stage checklist driven by `/analysis/{id}/status` polling (2 s, stops at terminal), elapsed time, cancel button, failure message with retry
- [x] **P5-04** Report header: stock, exchange, timeframe, style, as-of, **data tier badge** with an explanation tooltip, warnings banner (symbol conflict → one-click "re-run as X")
- [x] **P5-05** `DecisionPanel`: action, bias, setup score with an "uncalibrated" label or a calibrated hit rate with n and interval, trigger conditions, invalidation; `decision_limited` state for Tier C
- [x] **P5-06** `TradePlan` card: entry type and price, stop, targets, risk %, reward %, R:R gross and net, quantity, viability state ("not viable; viable at 548")
- [x] **P5-07** `ScenarioCard` ×2 and `FactorList` (bull ✓ / bear ✗ with weights and evidence links)
- [x] **P5-08** Tabs: Technical (indicator table with status and unavailable reasons), Patterns, Smart Money (with the framework note), Levels, Risk, Evidence (E-ids, values, sources, tiers)
- [x] **P5-09** Evidence citations: `[E14]` in prose is clickable; hovering highlights the level on the chart; clicking scrolls to the evidence row
- [x] **P5-10** Report actions: copy as Markdown, re-run (other style / force), delete, feedback widget (👍/👎 plus issue type)

### 5.3 Chart visualization
- [ ] **P5-11** `ScreenshotOverlay` *(blocked on 4B: there is no pixel calibration to draw against yet, so the report shows the plain screenshot)* : SVG layer over the image using the stored calibration (price → pixel y). Layer toggles for S/R, OB/FVG zones, liquidity, entry/stop/targets; label collisions avoided. Hidden for Tier C (nothing calibrated to draw)
- [ ] **P5-12** `PriceChart` *(not started; adds the lightweight-charts dependency)*  (lightweight-charts) for Tier A: candles, volume, EMAs, the same overlays as price lines and zones; license attribution
- [ ] **P5-13** Toggle *(follows P5-11/P5-12)*  between Screenshot and Interactive views; the user's choice is remembered

### 5.4 History, predictions, journal
- [x] **P5-14** History page: filter bar (symbol, decision, bias, tier, date), sortable table with thumbnails, pagination, "compare two analyses" side by side
- [x] **P5-15** Predictions page: pending and active predictions with distance-to-trigger and distance-to-stop, expiry countdown, manual resolve form (UI placeholder until Phase 6)
- [x] **P5-16** Journal list: open and closed tabs, P&L and R columns, tags filter
- [x] **P5-17** New trade form: **prefill from an analysis trade plan**, validation mirroring the API, attach an entry screenshot
- [x] **P5-18** Close trade form and trade detail: **plan vs execution** (entry slippage, stop honored, exit vs T1), lessons

### 5.5 Dashboard and settings
- [x] **P5-19** Dashboard: recent analyses; WAIT setups with trigger levels; active predictions; open trades with unrealized R (Tier A symbols); quick-upload drop zone
- [x] **P5-20** Settings: account size, currency, risk per trade %, max position %, default style, min R:R override, fee schedule view, model profile (read-only with engine health)

### 5.6 Quality
- [ ] **P5-21** Accessibility pass *(not run: no axe tooling installed yet)*  (axe): keyboard navigation, focus states, contrast, labels, chart alt text summarizing the levels
- [x] **P5-22** Responsive pass at 400 px — dashboard, analysis, history, journal and settings all measure `scrollWidth == clientWidth`, so nothing scrolls sideways
- [x] **P5-23** Component tests (Vitest + Testing Library) for DecisionPanel, TradePlan and FactorList; the overlay test follows P5-11
- [ ] **P5-24** Playwright smoke test *(not started: Playwright is not in the project yet; the loop was verified by hand through the browser)* : login → upload → wait → report visible → create a trade from the plan → close it

## Deliverables

All MVP screens from [PRD §9](../01-PRD.md#9-ux), wired to the real API.

> **Status 2026-09-14:** the report, history, journal, settings and dashboard screens are built on
> the real 4D API and verified in a browser. Chart overlays (P5-11 – P5-13) wait for the pixel
> calibration Phase 4B produces; the accessibility audit and Playwright suite are not run yet.
>
> Phase 5 also needed backend endpoints that no phase had assigned: `PATCH /me/settings`,
> `GET /predictions`, and the whole `/trades` journal API. They were built here, with tests.

## Exit gate

- [x] You can complete the daily loop without touching the API: upload → analyse → read → journal → review history
- [ ] Overlays line up with the screenshot's own price axis within ±1% on golden-set charts *(blocked on 4B)*
- [x] Every number on the report page traces to an evidence row in ≤ 2 clicks (citations open the Evidence tab and highlight the row)
- [ ] axe: no serious or critical violations on the core pages
- [x] Works at 400 px width, with no horizontal page scroll
- [ ] Playwright smoke test green

## Risks

| Risk | Mitigation |
|------|------------|
| Overlay drift on scaled images | Store calibration in original-pixel space and scale by the rendered/natural ratio; resize-observer tests |
| Information overload | Decision, plan and scenarios above the fold; depth lives in tabs |

## Out of scope

Performance analytics page and the AI-vs-actual panel (Phase 6), websockets, PDF export.
