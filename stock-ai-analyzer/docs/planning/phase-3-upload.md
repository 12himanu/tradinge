# Phase 3 — Chart Upload and Market Data

| | |
|---|---|
| **Goal** | Screenshots arrive safely, are stored privately and linked to a stock; real OHLCV can be imported to unlock Tier A analysis |
| **Prerequisites** | Phase 2 exit gate passed |
| **Effort** | ~4 days (can run in parallel with Phase 4A) |
| **Next** | Phase 4D consumes uploads and price bars |
| **Status** | All tasks built and tested (2026-09-13), started before the Phase 2 Ollama gate closed since it doesn't depend on Ollama. The exit gate still needs real CSV exports and a browser check |

## Outcome

On the Analyze page, you can paste a screenshot (Ctrl+V), drop it or pick a file, fill in the symbol (autocomplete), timeframe, style, position and thesis, and upload. The image is validated, re-encoded, deduplicated and stored privately. Separately, a CSV export of price history can be imported per symbol.

## Tasks

### 3.1 Storage and validation (backend)
- [x] **P3-01** Private filesystem disk `charts` → `/storage/charts`; path strategy `charts/{user_id}/{yyyy}/{mm}/{ulid}.png` — *stored paths are relative to the disk (`{user_id}/{yyyy}/{mm}/{ulid}.png`); the engine reads them as `charts/{path}`*
- [x] **P3-02** `Infrastructure/Storage/ChartStorage`: MIME sniffing (finfo), dimension checks, re-encode to PNG with Intervention Image (strips EXIF, neutralizes polyglots), SHA-256 of the **re-encoded** bytes, atomic write — *dimensions are read from the header before decoding, which rejects decompression bombs*
- [x] **P3-03** `Presentation/Http/Requests/UploadChartRequest`: rules from [05 §3.1](../05-api-spec.md#31-post-chartsupload), including conditional position fields
- [x] **P3-04** `Domain/Market/Services/StockResolver`: (exchange, symbol) → `Stock` or null; case and whitespace normalization

### 3.2 Upload use case and API
- [x] **P3-05** `Application/Actions/UploadChart`: validate → store → dedupe per user (return existing with `deduplicated = true`) → create `ChartUpload` → optionally dispatch `RequestAnalysis` (wired up in 4D) — *`auto_analyze` is validated; the dispatch lands in 4D*
- [x] **P3-06** `ChartUploadController`: `upload`, `show`, `image` (streamed, authorized, `Cache-Control: private`), `destroy` (soft delete); `ChartUploadResource`; `ChartUploadPolicy` — *the policy answers 404 for other users' charts*
- [x] **P3-07** `StockController@index`: `GET /stocks?search=` using the `pg_trgm` similarity index; limit 20 — *plus `GET /stocks/{stock}` with the stored price range per timeframe, for P3-16*

### 3.3 Market data
- [x] **P3-08** CSV spec in `docs/csv-format.md`. Accepts `date,open,high,low,close,volume[,turnover]` and TradingView's export header (`time,open,high,low,close,Volume`). Detects the delimiter and date format
- [x] **P3-09** `Infrastructure/MarketData/CsvPriceImporter`: streaming parse, per-row validation (OHLC consistency, positive prices, no duplicate dates, trading-day check against the exchange calendar), upsert in chunks of 1000 — *the parser checks rows; duplicates, the trading calendar and the upsert live in `ImportPriceBars`, so future network providers get the same checks*
- [x] **P3-10** `Application/Actions/ImportPriceBars` and `POST /stocks/{stock}/prices/import` → summary `{inserted, updated, skipped: [{row, reason}]}`
- [x] **P3-11** `GET /stocks/{stock}/prices?timeframe=&from=&to=`
- [x] **P3-12** `Infrastructure/MarketData/Contracts/MarketDataProvider` interface, with `CsvProvider` as the first implementation (network providers come later, per decision D3)

### 3.4 Frontend
- [x] **P3-13** `features/upload/`: dropzone plus global clipboard-paste listener, image preview, client-side size/type checks — *also checks pixel dimensions before uploading*
- [x] **P3-14** Upload form: symbol autocomplete (debounced `/stocks`), exchange, timeframe, style, position (conditional fields), thesis, "analyze immediately" toggle; Zod schema mirroring the server rules — *the toggle is shown disabled until 4D*
- [x] **P3-15** Upload progress bar, server validation errors mapped to fields, dedupe notice ("You uploaded this chart on 3 Sep — open that analysis?") — *the "open that analysis" link arrives with the report page in Phase 5*
- [x] **P3-16** Price data import screen: pick a stock, upload a CSV, show the import summary and the stored date range per timeframe

## Deliverables

Upload API and UI · private image storage · symbol search · CSV import with a validation report.

## Exit gate

- [ ] Paste, drop and picker all upload successfully; the stored file is PNG with no EXIF — *API verified by feature tests and end to end through the Next proxy; the paste handler is unit-tested; still to confirm by hand in a browser*
- [x] Re-uploading an identical image returns the existing upload (`deduplicated = true`)
- [x] Invalid type, oversized and undersized images are rejected with clear messages
- [x] Another user's chart id returns 404; images aren't reachable without auth
- [ ] A real exported CSV (TradingView or a NEPSE site) for at least 3 symbols imports with the correct row counts — *needs your exports; the importer is covered by tests on both header formats*
- [x] Feature tests green

## Tests

Pest feature tests: upload happy path, each validation rule, dedupe, authorization, image streaming, soft delete. Importer unit tests: both header formats, bad OHLC rows, duplicates, non-trading days, re-import (idempotent upsert). Frontend: form validation and paste handler tests.

## Risks

| Risk | Mitigation |
|------|------------|
| CSV formats vary by source | Header auto-mapping plus a documented format; the skipped-row report shows problems instead of silently dropping data |
| Symbol list outdated | The seeder reads a CSV you can refresh; unknown symbols are allowed (stock_id stays null → Tier B/C) |

## Out of scope

Running analyses (4D), network market-data providers (backlog, per D3).
