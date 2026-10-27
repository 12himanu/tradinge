# NEPSE market data from merolagani

Daily OHLCV bars and company fundamentals, imported automatically at **17:00 Asia/Kathmandu**, two
hours after the 15:00 NEPSE close. The first run for a symbol takes its whole available history;
every run after that takes a short recent window.

This is the automatic counterpart to the CSV import in [csv-format.md](csv-format.md). Both end up
in `price_bars` through the same `ImportPriceBars` action, with the same validation and the same
idempotent upsert on `(stock_id, timeframe, bar_time)`.

## What it reads

`CompanyDetail.aspx?symbol=X` renders its price-history table only after an ASP.NET `__VIEWSTATE`
postback and then paginates it. The same site exposes the data as JSON to its own chart and popups,
so nothing here parses that table. Three unauthenticated endpoints, no session, cookie or token:

| Endpoint | Used for |
|----------|----------|
| `handlers/technicalcharthandler.ashx?type=get_advanced_chart` | Daily OHLCV, whole history in one request |
| `handlers/webrequesthandler.ashx?type=get_company_summary` | Last price, 52-week range, EPS, P/E, market cap, dividends |
| `handlers/technicalcharthandler.ashx?type=get_symbols_by_search` | The list of every listed security (582 today) |

The rendered page is fetched for one thing only — the fields no handler carries (sector, shares
outstanding, book value, PBV, moving averages) — and only for a stock that still has no sector, so
it costs one page per stock ever rather than one per stock per day.

**Daily bars only.** The handler answers other resolutions, but with raw trade prints that
TradingView aggregates in the browser, not with server-side intraday or weekly bars. Asking it for
`1h` would mean inventing bars, so `MerolaganiProvider` refuses any timeframe but `1D`.

## Running it

```powershell
docker compose exec backend php artisan market:sync-merolagani            # every active NEPSE stock
docker compose exec backend php artisan market:sync-merolagani PCBL NABIL # named symbols
docker compose exec backend php artisan market:sync-merolagani --backfill # re-ask for full history
docker compose exec backend php artisan market:sync-merolagani --queue    # what the scheduler does
```

Without `--queue` the work happens in the foreground and prints a table, which is what you want when
watching a first backfill. With `--queue` it dispatches the same batch the scheduler does, onto the
`market-data` queue.

The shipped `nepse_stocks.csv` is a 22-row starter list, so most symbols — PCBL included — do not
exist until you import the full list:

```powershell
docker compose exec backend php artisan market:import-merolagani-symbols --dry-run
docker compose exec backend php artisan market:import-merolagani-symbols
```

That creates the missing stocks and nothing else: an existing row keeps its name, sector and
instrument type, and no row is ever deactivated or deleted. Instrument type is guessed from the
company name (bond/debenture, mutual fund/scheme/yojana/kosh, otherwise equity). Sectors are filled
in by the next sync.

## How a bar is dated

The vendor's timestamps carry the right calendar date in **UTC** and an unreliable time of day: bars
up to February 2018 are stamped 05:45 UTC (11:30 in Kathmandu, mid-session), later ones around 20:45
UTC — which is 02:30 the *next* morning in Kathmandu. Reading them in exchange-local time moves every
recent bar forward a day, so Sunday sessions land on Monday and Thursday sessions on Friday.

The UTC date is the business date. It matches the `tradeDate` merolagani prints for the latest bar,
and it is what `price_bars` wants anyway: daily bars are stored at 00:00 UTC of their trading date.

## Adjusted prices

`MEROLAGANI_ADJUSTED=true` (the default) asks for the bonus- and rights-adjusted series. NEPSE
companies issue bonus shares often, and in an unadjusted history each one is a large overnight gap
that no indicator or pattern detector can tell apart from a real move. The adjusted series starts
later than the raw one (PCBL: July 2011 rather than January 2010) because adjustment factors only
exist from a certain point.

Set it to `false` only to compare against an unadjusted chart. Re-run with `--backfill` afterwards;
the two series are on different price scales and mixing them in one symbol is meaningless.

## Rows that do not become bars

Nothing is dropped silently. Every skipped bar is reported with its reason, by the command and in
`merolagani.sync.symbol` log lines.

- **Duplicate dates.** Merolagani lists a few dates twice (PCBL: 2010-04-15, 2024-10-09, 2025-03-02,
  2025-05-18). The first is kept.
- **Impossible candles.** About 0.3% of bars have a low above the open or close, or a high below
  them. This is vendor data quality, not the adjustment: the rate is the same in the raw series, and
  the NEPSE index is worse at 1.4%. `price_bars` has a CHECK constraint for exactly this shape, so
  such a bar could not be stored even if we wanted it.
- **Duplicate symbols.** `HURJA` is listed twice, once with an empty name. The listing is
  deduplicated, preferring the entry that has a name.

## Sharesansar: what merolagani cannot give

Merolagani is the primary source. Sharesansar fills two holes in it.

**Instruments merolagani does not chart.** `get_advanced_chart` answers `no_data` for about a
hundred NEPSE securities — debentures, government savings bonds and promoter shares. Sharesansar
carries roughly a third of them, several with hundreds of sessions.

**Turnover.** The merolagani chart handler has volume but no traded amount. Sharesansar publishes
both, so `price_bars.turnover` can be filled where it was null.

```powershell
docker compose exec backend php artisan market:sync-sharesansar --missing --backfill  # the usual run
docker compose exec backend php artisan market:sync-sharesansar GSY SDBD87 --backfill # named symbols
```

### How it is read

Sharesansar is a Laravel site and its price history is a DataTables server-side POST behind CSRF,
so the client takes a `_token` meta tag and the session cookie it is bound to from one ordinary
page load and reuses them for the whole run; without both the endpoint answers 419. The history
endpoint keys on a numeric company id rather than the symbol — passing `NABIL` returns zero rows
rather than an error — so the id is looked up once per stock and cached in
`stocks.metadata.sharesansar.company_id`.

Pages are 50 rows, capped server-side: a larger `length` comes back HTTP 202 with an empty payload
rather than an error, so asking for a whole history at once silently yields nothing. A full
backfill of a long-listed stock is therefore dozens of requests, which is the main reason
sharesansar is not the primary source.

Dates need no decoding: the endpoint publishes an explicit `published_date`. Across 300 overlapping
NABIL sessions the two sources agreed on the date exactly, on OHLC in 298 cases and on volume in
all 300. The two disagreements were closes about 0.1% apart — one tick-aligned and one not, which
reads as last-trade against official average.

### Why the two sources are not spliced

**Sharesansar is unadjusted.** Merolagani is imported bonus- and rights-adjusted. Splicing one onto
the other inside a symbol would put two price scales in one series, and every indicator drawn across
the join would be reading a gap that never happened. So a symbol already holding adjusted merolagani
bars is refused unless `--force` is passed, and the refusal is reported rather than silently skipped.

Turnover is the deliberate exception: it is not a price, nothing rescales it, and a source that has
it may fill it in beside bars that do not.

### A provider never erases what it did not measure

`ImportPriceBars` upserts `volume` and `turnover` with `COALESCE(excluded.col, price_bars.col)`
rather than assigning them outright. Without that, the nightly merolagani sync would null the
turnover a CSV or sharesansar had already supplied — a routine job quietly deleting good data.

OHLC is always assigned outright: those four are the provider's core claim, and a correction has to
be able to lower a high.

## The trading calendar

**NEPSE trades Monday to Friday.** It moved off the Sunday–Thursday week in April 2026: in the
history of every symbol checked, Sunday sessions stop and Friday sessions start that month.

`exchanges.trading_days` is `[1,2,3,4,5]`. The value is not cosmetic — `TradingCalendar` uses it to
decide when a prediction expires (`addTradingDays`) and whether an imported bar falls on a session.

A single weekday list cannot describe a history that spans that change: bars before April 2026 fall
on Sundays and would be rejected, bars after it fall on Fridays and would be rejected under the old
list. So a provider that *is* the record of which days the exchange traded implements
`AuthoritativeCalendar`, and `ImportPriceBars` skips the weekday filter for it. A bar exists because
a session happened. Every other check — duplicates, OHLC consistency, volume — still applies, and a
CSV import is unaffected: it is still validated against the calendar.

## Scheduling

| When (Asia/Kathmandu) | What |
|-----------------------|------|
| 17:00 daily | `sync-merolagani` — queue one job per active NEPSE stock |
| after the batch finishes | `EvaluatePredictionsJob` — score open predictions against the new bars |
| after the batch finishes | `ScanMarketJob` — read every stock with the engine ([scanner.md](scanner.md)) |
| 16:00 Mon–Fri | `EvaluatePredictionsJob` again, as a safety net for a day whose sync never ran |

Evaluation is chained to the batch rather than run at a fixed later time, so there is no clock offset
to guess at: the day's bars are scored the evening they land. It is idempotent, so running it twice
costs nothing.

Runs happen every day, not only on trading days. The trading week has changed before, and a day with
no new bar costs one request that imports nothing.

Symbols are spaced `MEROLAGANI_SPACING_SECONDS` apart (default 2). Merolagani is somebody else's
server and this is one courtesy call a day on it. The `market-data` queue has its own Horizon
supervisor with a single process, so it never competes with an analysis or delays one.

## Catching up

Every run re-asks for `MEROLAGANI_LOOKBACK_DAYS` (default 10) before the last stored bar, so a late
or restated bar is picked up by the next run instead of being lost, and a few missed days need no
intervention. A stock with no bars at all is backfilled with its whole history automatically — there
is no separate first-time step.

A bar that already exists is updated in place. That includes bars imported from a CSV: for an
overlapping date the vendor's values replace the CSV values and `source` becomes `merolagani`. If you
have hand-corrected a CSV, re-import it after the sync.

## Settings

All in `config/stock_ai.php` under `market_data.merolagani`, all overridable from `backend/.env`:

| Variable | Default | Meaning |
|----------|---------|---------|
| `MEROLAGANI_URL` | `https://eng.merolagani.com` | Base URL |
| `MEROLAGANI_ADJUSTED` | `true` | Bonus/rights-adjusted prices |
| `MEROLAGANI_LOOKBACK_DAYS` | `10` | Days a routine run re-asks for |
| `MEROLAGANI_HISTORY_START` | `2000-01-01` | Earliest date a backfill asks for |
| `MEROLAGANI_SPACING_SECONDS` | `2` | Seconds between symbols in a scheduled run |
| `MEROLAGANI_TIMEOUT` | `30` | Per-request timeout |
| `MEROLAGANI_CONNECT_TIMEOUT` | `10` | Connect timeout |
| `MEROLAGANI_RETRIES` | `3` | Attempts per request |
| `MEROLAGANI_RETRY_DELAY_MS` | `1000` | Delay between attempts |
| `MEROLAGANI_USER_AGENT` | `stock-ai-analyzer/1.0 (local, personal use)` | Identifies the caller |

## Fundamentals snapshot

`get_company_summary` plus the page fields land in `stocks.metadata.merolagani`:

```json
{
  "fetched_at": "2026-09-19T07:07:19Z",
  "trade_date": "2026-09-18",
  "last_price": 259, "percent_change": 2.86,
  "fifty_two_week_high": 275.9, "fifty_two_week_low": 225,
  "one_eighty_day_average": 241.35, "one_twenty_day_average": 239.38,
  "thirty_day_average_volume": 66834, "one_year_yield_pct": 10.68,
  "eps": 19.63, "eps_fiscal_year": "082-083", "eps_quarter": "4",
  "pe_ratio": 13.19, "book_value": 175.8, "pbv": 1.47,
  "market_cap": 54272884792.07, "shares_outstanding": 209547817.73, "paidup_value": 100,
  "cash_dividend_pct": 0.421, "bonus_dividend_pct": 8, "dividend_fiscal_year": "081-082"
}
```

**These are reference figures only.** None of them reaches the engine and none prices a decision —
the engine works from `price_bars`. They are there so a symbol's page has context without a second
trip to the website. `trade_date` keeps only the date: the clock merolagani prints beside it
(`02:59:48`) is not when the stock last traded.

The snapshot is best effort. If it fails, the bars are already stored and the run still succeeds; the
next run picks the snapshot up.

## What is not imported

- **Turnover.** `price_bars.turnover` stays null. The chart handler carries volume but no traded
  amount; only the paginated HTML table has it. A CSV import still fills it.
- **Intraday and weekly bars.** Daily only, for the reason above.
- **Floorsheet, news, announcements, quarterly reports.** Available on the site, not modelled here.

## Troubleshooting

| Symptom | Cause |
|---------|-------|
| `None of those symbols are NEPSE stocks` | The stock does not exist yet — run `market:import-merolagani-symbols` |
| `Merolagani price history returned HTTP 5xx` | The site is down. The job retries twice (30 s, 120 s), then the next daily run picks it up |
| Bars stop arriving but no errors | Check `horizon:status` and that `supervisor-market-data` is running; Horizon needs a restart after a config change |
| A symbol reports 0 new, 0 updated | `no_data` for the range — normal for a delisted or newly listed security |

Logs: `merolagani.sync.queued`, `merolagani.sync.symbol`, `merolagani.sync.finished`,
`merolagani.sync.unavailable`, `merolagani.snapshot.unavailable`.
