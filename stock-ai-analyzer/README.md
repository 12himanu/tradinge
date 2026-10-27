# Stock AI Analyzer

Local, evidence-based analysis of stock chart screenshots, NEPSE first. Upload a chart, add the symbol and your context, and get a structured analysis in which every number traces back to evidence. Language models never produce the numbers. Everything runs on your machine: no paid APIs.

- **New here?** [docs/getting-started.md](docs/getting-started.md) — what this is, how to run it, where the credentials are, what each folder does
- Design: [docs/00-README.md](docs/00-README.md) (PRD, architecture, schema, AI workflow, API)
- Plan and status: [docs/planning/](docs/planning/README.md)
- Market data: [docs/market-data.md](docs/market-data.md) — NEPSE bars import themselves daily at 17:00 Kathmandu
- Analyst LLM: [docs/analyst.md](docs/analyst.md) — how an LLM is allowed near a product where every number must trace to evidence
- Scanner: [docs/scanner.md](docs/scanner.md) — the whole market read after the close: traps, openings, and what each signal was worth before
- Forecast: [docs/forecast.md](docs/forecast.md) — next day and next week, measured rather than predicted, split by kind of analysis
- How it runs: [docs/how-it-works.md](docs/how-it-works.md) — starting it, what each process does, the scraper and the nightly cycle, end to end
- Day-to-day operations: [docs/runbook.md](docs/runbook.md)

| App | Stack | Port |
|-----|-------|------|
| `frontend/` | Next.js 16, React 19, TypeScript, Tailwind 4, shadcn/ui, TanStack Query | 3000 |
| `backend/` | Laravel 13 on PHP 8.4, Sanctum, Horizon, PostgreSQL 17, Redis 7 | 8000 |
| `ai-engine/` | Python 3.12, FastAPI, Ollama (native, on the GPU) | 8100 |

## Prerequisites

- Windows 11 with **Docker Desktop** on the WSL2 backend
- **Ollama for Windows**, running natively so it can use the GPU
- **Python 3.12** (`py` launcher), used for the helper scripts
- Optional, for tooling on the host: PHP 8.4+ with Composer, Node 24, and `uv`

## Quickstart

```powershell
py infra/scripts/init_env.py                          # writes .env and backend/.env with generated secrets
docker compose up -d --build                          # Postgres, Redis, backend, Horizon, scheduler, engine, frontend
docker compose exec backend php artisan migrate --seed
```

Open http://localhost:3000 and sign in with `OWNER_EMAIL` and `OWNER_PASSWORD` from `.env`.

Then set up the models:

```powershell
ollama pull qwen2.5vl:3b
ollama pull qwen3:4b
py infra/scripts/benchmark_ollama.py --pin              # after uploading 3 chart screenshots in the app
```

`GET http://localhost:8000/api/v1/health` reports the database, Redis, the engine and Ollama. The header of the app shows the same status.

## Checks

```powershell
powershell -ExecutionPolicy Bypass -File infra/scripts/check.ps1
```

This runs Pint, PHPStan (level 8), Pest, ruff, mypy (strict), pytest, ESLint, tsc and Vitest inside the containers.

## Layout

```
docs/          design package, phase plans, runbook, market data, CSV format
frontend/      Next.js app
backend/       Laravel app (owns the database schema)
ai-engine/     FastAPI analysis engine (stateless)
contracts/     generated OpenAPI document (npm run gen:api)
infra/         Dockerfiles, Postgres init, helper scripts
storage/       uploaded charts (private) and reports; bind-mounted into the containers
```
