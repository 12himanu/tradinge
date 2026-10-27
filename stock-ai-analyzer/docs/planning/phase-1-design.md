# Phase 1 — Design

| | |
|---|---|
| **Goal** | Agree on what we build and how, before writing code |
| **Status** | Deliverables complete · **awaiting approval** |
| **Effort** | Done |
| **Next** | [Phase 2 — Foundation](phase-2-foundation.md) |

## Deliverables

- [x] P1-01 Product Requirements Document → [01-PRD.md](../01-PRD.md)
- [x] P1-02 System architecture, diagrams, ADRs, folder structure → [02-architecture.md](../02-architecture.md)
- [x] P1-03 Database schema, ERD, constraints, factories and seeders plan → [03-database-schema.md](../03-database-schema.md)
- [x] P1-04 AI agent workflow: pipeline, agent contracts, scoring, risk, policy, guardrails, learning loop → [04-ai-agent-workflow.md](../04-ai-agent-workflow.md)
- [x] P1-05 API specification: public and internal → [05-api-spec.md](../05-api-spec.md)
- [x] P1-06 Phased implementation plan → this folder

## Approval checklist (your decisions)

- [ ] **D1 Hardware:** GPU model and VRAM (`nvidia-smi`) → selects the model profile
- [ ] **D2 Market scope:** NEPSE only, or NEPSE plus global charts
- [ ] **D3 Price data:** CSV import as the guaranteed Tier A source; unofficial NEPSE fetcher later (yes/no)
- [ ] **D4 Users:** single local user first, or multi-user from day one
- [ ] **D5 Runtime:** Docker Desktop + WSL2 for Laravel, Horizon and the engine; Ollama native
- [ ] **D6 Decision vocabulary:** BUY / WAIT / HOLD / SELL / AVOID
- [ ] **D7 Approve the package** (or list changes)

## Exit gate

All decisions D1–D7 recorded here, with any design changes applied to the docs. Then Phase 2 starts.

## Decision log

| # | Decision | Date | Notes |
|---|----------|------|-------|
| D1 | NVIDIA RTX 3050 Laptop, **4 GB VRAM**, 16 GB RAM, i5-12500H → new `gpu_4gb` profile (3B–4B models); final pick from the Phase 2 benchmark | 2026-09-12 | Measured with `nvidia-smi`. Below the 8 GB profile, which reinforces OCR-first vision and Tier A (CSV) data |
| D2 | NEPSE-first defaults; schema and engine stay multi-exchange | 2026-09-12 | Default taken on approval; can change |
| D3 | CSV import is the guaranteed Tier A source; network providers later | 2026-09-12 | Default taken on approval |
| D4 | Single local owner account first; multi-user-ready schema | 2026-09-12 | Default taken on approval |
| D5 | Docker Desktop + WSL2 for backend and engine; Ollama native | 2026-09-12 | Docker Desktop 29.5 and WSL2 (Ubuntu) already installed |
| D6 | BUY / WAIT / HOLD / SELL / AVOID | 2026-09-12 | Approved with the package |
| D7 | Package approved; Phase 2 started | 2026-09-12 | |
