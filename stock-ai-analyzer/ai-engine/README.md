# AI Engine

Stateless analysis service for Stock AI Analyzer. It receives a chart image plus context from the Laravel backend and returns a structured, evidence-backed analysis. It has no database; Laravel is the system of record.

Design: [`docs/04-ai-agent-workflow.md`](../docs/04-ai-agent-workflow.md) · Plan: [`docs/planning/`](../docs/planning/README.md)

## Endpoints

| Route | Auth | Purpose |
|-------|------|---------|
| `GET /livez` | none | Process liveness (Docker healthcheck) |
| `GET /health` | none | Readiness: Ollama reachability, required models, storage |
| `GET /v1/models` | HMAC | Active model profile and install status |

Signed requests carry `X-Timestamp` (Unix seconds) and `X-Signature = hex(HMAC-SHA256(secret, timestamp + "." + body))`.

`POST /v1/analyze` arrives in Phase 4D; until then the deterministic pipeline is driven by the CLI below.

## Analysis pipeline (Phase 4A)

Tier A runs entirely without a model: OHLCV in, structured analysis and a template report out.

```
bars -> indicators -> swings + levels -> technical | SMC | patterns -> scoring -> risk -> decision -> report
```

| Module | What it owns |
|--------|--------------|
| `analysis/indicators.py` | EMA, RSI, MACD, ADX/DI, ATR, RVOL, OBV — TA-Lib-exact |
| `analysis/swings.py`, `levels.py` | Fractal pivots, ATR zigzag, support/resistance zones |
| `analysis/patterns.py`, `candlesticks.py` | Classic patterns with confirmation, target and invalidation |
| `analysis/smc.py` | Structure, BOS/CHoCH, order blocks, FVGs, liquidity, dealing range |
| `analysis/scoring.py` | Factor table, ADX modulation, 0-100 setup score |
| `analysis/risk.py` | Entries, structural stops, targets, net R:R, sizing, viability |
| `analysis/decision_policy.py` | The nine rules that produce BUY / WAIT / HOLD / SELL / AVOID |
| `pipeline/` | Config loading, the evidence store, the DAG orchestrator |
| `reports/` | The template report (also the Phase 4C fallback) |

Every number a report may print is registered in the `EvidenceStore` first; the Phase 4C
guardrail will reject any number that is not in it.

### CLI

```powershell
py -m uv run python -m ai_engine.cli analyze --csv data/HDL.csv --symbol HDL --style swing --account 500000
# --json result.json  writes the full AnalysisResult
# --holding-entry 540 --holding-stop 520   analyse a position you already hold
```

The CSV format is [`docs/csv-format.md`](../docs/csv-format.md). Tier A needs at least 60 bars;
below that the run degrades to Tier C with no score and no trade plan.

## Development

```powershell
py -m uv sync                      # create .venv with dev tools
py -m uv run pytest                # tests (Ollama integration tests skipped unless RUN_OLLAMA_TESTS=1)
py -m uv run pytest --cov=ai_engine.analysis --cov=ai_engine.agents   # coverage gate (>= 90%)
py -m uv run ruff check .
py -m uv run mypy
py -m uv run python scripts/export_schemas.py   # refresh contracts/*.schema.json
```

In Docker the service runs with `--reload`; the virtualenv lives at `/opt/venv`.

## Configuration

Analysis tolerances live in `config/`: `scoring.yaml` (factor weights, thresholds), `styles.yaml`
(horizons, stops, minimum R:R) and `patterns.yaml` (geometry). They are hashed into
`engine_version`, so every stored analysis names the exact configuration that produced it.

Environment variables use the `ENGINE_` prefix: `ENGINE_HMAC_SECRET` (required, ≥ 32 chars), `ENGINE_OLLAMA_HOST`, `ENGINE_MODEL_PROFILE`, `ENGINE_STORAGE_ROOT`, `ENGINE_LOG_LEVEL`, `ENGINE_LOG_JSON`. Model profiles live in `config/models.yaml`.
