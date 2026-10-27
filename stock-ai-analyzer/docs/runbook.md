# Runbook

Commands run from the repository root in PowerShell unless noted.

## Start and stop

```powershell
docker compose up -d            # start everything (add --build after Dockerfile changes)
docker compose ps               # status and health
docker compose stop             # stop, keeping data
docker compose down             # remove containers, keep the database and Redis volumes
docker compose down -v          # also delete the database, Redis data and frontend caches
```

| URL | What |
|-----|------|
| http://localhost:3000 | App |
| http://localhost:3000/forecast | Next day and next week for one stock ([forecast.md](forecast.md)) |
| http://localhost:8000/api/v1/health | Health of the database, Redis, the engine and Ollama |
| http://localhost:8000/docs/api | API documentation (Scramble) |
| http://localhost:8000/horizon | Queue dashboard |
| http://localhost:8100/health | Engine health |

## Configuration changes

- `.env` (root) holds shared values: ports, database credentials, the engine secret, the owner account. `backend/.env` holds Laravel settings. `py infra/scripts/init_env.py` creates both and never overwrites existing values.
- **After editing `backend/.env`, restart the Laravel services:** `docker compose restart backend horizon scheduler`. The API server runs with `--no-reload`, so it doesn't pick up changes on its own.
- After changing `frontend/package.json`, install inside the container too, then restart it: `docker compose exec frontend npm install` and `docker compose restart frontend`. The container keeps its own Linux `node_modules`.

## Database

```powershell
docker compose exec backend php artisan migrate                 # apply new migrations
docker compose exec backend php artisan migrate:status          # what has and has not run
docker compose exec backend php artisan migrate:fresh --seed    # reset: drops every table, then seeds
docker compose exec backend php artisan db:seed                 # re-run seeders (idempotent)
docker compose exec postgres psql -U stock_ai stock_ai          # SQL shell
```

The owner account comes from `OWNER_EMAIL` and `OWNER_PASSWORD` (at least 12 characters). Changing them and re-running `db:seed` updates the account.

Tests use the separate `stock_ai_testing` database. The test suite refuses to start against any other database.

## Market data

NEPSE daily bars import themselves at 17:00 Kathmandu from merolagani — full details in
[market-data.md](market-data.md) and a step-by-step walkthrough in
[getting-started.md](getting-started.md#5-market-data-the-scraper). The short version:

```powershell
docker compose exec backend php artisan market:import-merolagani-symbols  # once: create all 582 listed securities
docker compose exec backend php artisan market:sync-merolagani PCBL       # sync one symbol now
docker compose exec backend php artisan market:sync-merolagani            # sync every active stock now
```

The first run for a symbol takes its whole history (PCBL: ~3,500 bars, about 8 seconds). Later runs
take the last ten days. Everything is idempotent, so re-running is always safe.

The scanner reads every stock with the engine once the bars land — see
[scanner.md](scanner.md). To run it by hand:

```powershell
docker compose exec backend php artisan market:scan         # every active stock
docker compose exec backend php artisan market:scan PCBL    # one symbol
```

Sharesansar fills what merolagani does not chart — debentures, government bonds, promoter shares —
and carries turnover:

```powershell
docker compose exec backend php artisan market:sync-sharesansar --missing --backfill
```

For anything neither source covers — another exchange, a corrected history, intraday bars —
import OHLCV CSV files on the **Market data** page. Accepted formats: [csv-format.md](csv-format.md).
The starter stock list comes from `backend/database/seeders/data/nepse_stocks.csv`; edit it and run
`db:seed`, or just use the symbol import above.

## Models (Ollama)

Ollama runs natively on Windows. The containers reach it at `host.docker.internal:11434`.

```powershell
ollama list                                   # installed models and their digests
ollama pull qwen2.5vl:3b                      # vision model, gpu_4gb profile
ollama pull qwen3:4b                          # text model, gpu_4gb profile
py infra/scripts/benchmark_ollama.py          # measure; writes infra/benchmark-report.md
py infra/scripts/benchmark_ollama.py --pin    # also pin the measured digests in ai-engine/config/models.yaml
```

The benchmark uses the three newest chart screenshots under `storage/charts`, so upload a few real charts first, or pass `--charts a.png b.png c.png`. The active profile is `MODEL_PROFILE` in `.env`. After changing it, run `docker compose up -d ai-engine`.

## Logs

```powershell
docker compose logs -f backend horizon        # Laravel (JSON lines, with request ids)
docker compose logs -f ai-engine              # engine (structlog JSON)
docker compose logs -f frontend
docker compose exec backend php artisan pail  # live Laravel log tail
```

## Checks and generated code

```powershell
powershell -ExecutionPolicy Bypass -File infra/scripts/check.ps1     # everything; -Only backend|engine|frontend
cd frontend; npm run gen:api                                          # OpenAPI → contracts/openapi.json → src/lib/api/schema.d.ts (needs PHP on the host)
cd ai-engine; py -m uv run python scripts/export_schemas.py           # Pydantic → ai-engine/contracts/*.schema.json
```

## Troubleshooting

| Symptom | Fix |
|---------|-----|
| Health shows "Ollama isn't running" | Start Ollama from the Start menu; check that `curl http://localhost:11434/api/tags` answers |
| Reports warn the screenshot "was not checked against the symbol" | The vision model is missing: `ollama pull qwen2.5vl:3b`. Or turn the check off with `SYMBOL_CHECK_ENABLED=false` |
| An analysis fails with `SYMBOL_MISMATCH` | The chart's title names another company. Relink it to the right symbol on the error, or confirm it if the model misread |
| Health shows Redis or the database down | `docker compose ps`; `docker compose restart redis postgres` |
| Login returns 419 | Clear the site's cookies for localhost and reload |
| Uploads fail with "failed to upload" for large files | Screenshots over 10 MB are rejected; PHP's own limit is 12 MB (`infra/docker/php/php.ini`) |
| Frontend shows module-not-found errors after a dependency change | `docker compose exec frontend npm install`, then `docker compose restart frontend` |
