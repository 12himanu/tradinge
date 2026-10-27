# Phase 2 — Foundation

| | |
|---|---|
| **Goal** | A running walking skeleton: all services up, schema migrated, auth working, the engine talking to Ollama, and the model profile chosen from real measurements |
| **Prerequisites** | Phase 1 approved (D1–D7). Docker Desktop with WSL2 installed. Ollama installed natively |
| **Effort** | ~5 days |
| **Milestone** | **M1 · Walking skeleton** |
| **Status** | Built and green (2026-09-13). Ollama 0.34.0 is now installed and reachable on the host with `qwen3:4b` pulled (2026-09-14); still open: the vision model and the benchmark (P2-09 – P2-11) |

## Outcome

`docker compose up -d` starts Postgres, Redis, Laravel (API, Horizon, scheduler), the AI engine and the frontend. You can log in from the browser. `GET /api/v1/health` shows the DB, Redis, the engine and Ollama all green. Lint, type-check and test commands pass in all three apps.

## Tasks

### 2.1 Repository and tooling
- [x] **P2-01** `git init`; root `.gitignore` (per-app ignores too), `.editorconfig`, `.gitattributes` (LF line endings; this matters on Windows with WSL mounts) — *no commits yet*
- [x] **P2-02** Root `.env.example`: ports, `DB_*`, `REDIS_*`, `ENGINE_URL`, `ENGINE_HMAC_SECRET`, `OLLAMA_HOST`, `OWNER_EMAIL`, `OWNER_PASSWORD` — *plus `infra/scripts/init_env.py`, which generates the secrets*
- [x] **P2-03** Root `README.md`: prerequisites and a quickstart (`docker compose up`, migrate, seed, open :3000)

### 2.2 Containers
- [x] **P2-04** `infra/docker/php/Dockerfile`: PHP 8.4 with `pdo_pgsql`, `redis`, `pcntl`, `posix`, `intl`, `bcmath`, `gd` or `imagick`, `opcache`; Composer
- [ ] **P2-05** `infra/docker/python/Dockerfile`: Python 3.12-slim, `uv`, OS libraries for OpenCV headless and ONNX Runtime — *Python and uv done; OS libraries still to add when Phase 4B brings in OpenCV and RapidOCR*
- [x] **P2-06** `infra/docker/node/Dockerfile`: Node LTS
- [x] **P2-07** `docker-compose.yml`: `postgres:17`, `redis:7`, `backend`, `horizon`, `scheduler`, `ai-engine`, `frontend`; healthchecks; named volume for Postgres; `./storage` bind mount (rw for backend, **ro** for ai-engine); `extra_hosts: host.docker.internal:host-gateway` — *the API server runs `artisan serve --no-reload`, so it keeps the compose environment*
- [x] **P2-08** `infra/postgres/init.sql`: `CREATE EXTENSION pg_trgm;` — *as `init.sh`, which also creates the `stock_ai_testing` database*

### 2.3 Ollama and model selection
- [ ] **P2-09** Pull the candidate models for your hardware profile ([04 §8](../04-ai-agent-workflow.md#8-model-strategy-and-hardware-profiles)) — *Ollama 0.34.0 installed and `qwen3:4b` present (2026-09-14); still needs `ollama pull qwen2.5vl:3b`*
- [ ] **P2-10** `infra/scripts/benchmark_ollama.py`: VLM latency on 3 sample charts, text-model tokens/s, peak VRAM, model-swap time. Writes `benchmark-report.md` and a recommended profile — *script written (stdlib only, uses the 3 newest uploads); runnable once the vision model is pulled and you have uploaded 3 charts*
- [ ] **P2-11** Record the chosen profile and **pinned model digests** in `ai-engine/config/models.yaml` — *`benchmark_ollama.py --pin` writes them*

### 2.4 Laravel backend
- [x] **P2-12** Create the Laravel 13 app in `backend/`; confirm PHP 8.4; `php artisan install:api` (Sanctum); install Horizon, Scramble; dev: Pest, Larastan (level 8), Pint, Telescope (local only) — *Telescope not installed; JSON logs and `artisan pail` cover local debugging for now*
- [x] **P2-13** Create the layer namespaces `app/Domain`, `app/Application`, `app/Infrastructure`, `app/Presentation`; `DomainServiceProvider`; `routes/api_v1.php` under the `/api/v1` prefix
- [x] **P2-14** Config: `pgsql` connection; Redis for cache, queue and session; Horizon supervisors `ai` (1 process), `evaluation` (1), `default` (2); Redis queue `retry_after = 960`; JSON log channel with a correlation-id processor
- [x] **P2-15** Migrations for every table in [03-database-schema](../03-database-schema.md), including CHECK constraints (raw statements) and partial/trigram indexes — *fixed: the holding-position CHECK accepted NULL prices*
- [ ] **P2-16** PHP backed enums and value objects (`Timeframe`, `TradingStyle`, `Decision`, `Bias`, `DataTier`, `AnalysisStatus`, `PredictionStatus`, `OutcomeResult`, `Price`, `Percentage`) — *all enums done; `Price` and `Percentage` value objects not yet (first needed by risk math in Phase 4)*
- [x] **P2-17** Eloquent models in `Domain/*/Models`: relations, casts (enums, decimals, jsonb → arrays or DTOs), `HasUlids`, `SoftDeletes`
- [x] **P2-18** Factories (tests only) and seeders: `ExchangeSeeder` (NEPSE fee schedule marked *verify*), `StockSeeder` (from `database/seeders/data/nepse_stocks.csv`), `UserSeeder` (owner from `.env`)
- [x] **P2-19** Auth: `POST /auth/login`, `POST /auth/logout`, `GET /me`; Sanctum stateful domains; CORS; rate limiters (`upload`, `analyze`, `api`)
- [x] **P2-20** `GET /api/v1/health`: checks the DB, Redis and the engine's `/health`
- [x] **P2-21** Composer scripts `lint`, `analyse`, `test`

### 2.5 AI engine skeleton
- [x] **P2-22** `uv` project with the `ai_engine/` package layout from [02 §6.3](../02-architecture.md#63-ai-engine-python); ruff, `mypy --strict`, pytest
- [x] **P2-23** `settings.py` (pydantic-settings), structlog JSON logging with `X-Request-Id` binding
- [x] **P2-24** FastAPI app factory; `GET /health` (Ollama reachability, installed models, OCR availability, GPU info); `GET /v1/models`
- [x] **P2-25** HMAC verification dependency (timestamp window, constant-time compare) plus tests
- [x] **P2-26** `models/ollama.py`: async client with timeouts, retries on connection errors, JSON-schema `format`, `keep_alive`, `think` flag. Integration test marked `@pytest.mark.ollama`
- [x] **P2-27** `scripts/export_schemas.py`: Pydantic models → `contracts/*.schema.json` (with a placeholder `HealthResponse` to prove the pipeline)

### 2.6 Frontend skeleton
- [x] **P2-28** `create-next-app` (TypeScript, Tailwind, App Router, `src/`); ESLint, Prettier; shadcn/ui init; TanStack Query provider — *plus Vitest and Testing Library*
- [x] **P2-29** `lib/api/client.ts`: Sanctum CSRF flow, error normalization to `{code, message}` — *the browser reaches the API through Next rewrites (`/api`, `/sanctum`), so cookies are first-party*
- [x] **P2-30** Login page; authenticated `(app)` layout with nav placeholders; health indicator in the header
- [x] **P2-31** `npm run gen:api`: Scramble OpenAPI → `openapi-typescript` → `lib/api/schema.d.ts` — *exports to `contracts/openapi.json`; runs on the host (needs PHP)*

### 2.7 Developer workflow
- [x] **P2-32** `infra/scripts/check.ps1`: runs every app's lint, type-check and tests (via `docker compose exec`)
- [x] **P2-33** Short `docs/runbook.md`: start, stop, reset the DB, pull models, view Horizon, tail logs

## Deliverables

Running compose stack · migrated and seeded DB · login from the browser · engine ↔ Ollama verified · benchmark report with the chosen model profile · green check script.

## Exit gate

- [x] `docker compose up -d` brings all services to *healthy* within 2 minutes
- [x] `php artisan migrate:fresh --seed` runs cleanly; NEPSE and its stock list are present
- [ ] `GET /api/v1/health` → db, redis, engine, ollama are all `ok` — *db, redis and engine ok; Ollama not installed*
- [x] Login and logout work from the frontend; unauthenticated API calls get 401 — *verified end to end through the Next proxy (CSRF → login → /me → logout → 401)*
- [x] The engine rejects unsigned requests (401) and accepts signed ones
- [x] `check.ps1` passes: Pint, PHPStan L8, Pest, ruff, mypy strict, pytest, ESLint, tsc — *and Vitest*
- [ ] `models.yaml` contains digest-pinned models, justified by the benchmark report

## Tests

Pest: migration smoke test, enum/cast round-trips, auth feature tests, health endpoint (engine faked with `Http::fake`). Pytest: HMAC tests, settings validation, health route with a mocked Ollama. Frontend: login form component test.

The test suite runs against `stock_ai_testing` only: `phpunit.xml` overrides the compose-injected variables through `<server>` entries, and `tests/TestCase.php` refuses to boot against any other database.

## Risks

| Risk | Mitigation |
|------|------------|
| Slow file I/O on Windows bind mounts | Keep the repo inside the WSL filesystem (`\\wsl$\…`), or use named volumes for `vendor/` and `node_modules/` |
| Containers can't reach the GPU for Ollama | By design: Ollama runs natively; containers call `host.docker.internal` |
| Laravel 13 or package version incompatibilities | Pin versions at P2-12; record them in the README |

## Out of scope

Upload logic, any analysis, UI beyond login and the layout shell.
