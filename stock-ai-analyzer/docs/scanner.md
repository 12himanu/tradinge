# The scanner

Every NEPSE stock read by the engine after the close, ranked, with the structural detail one click
away. It answers "where in the market is something worth looking at today", and then "why".

Open **Scanner** in the app, or `GET /api/v1/market-scan`.

## What it reads

The same engine that analyses a chart screenshot, run against stored price bars instead. With 60 or
more daily bars it reaches **Tier A** — the full deterministic pipeline: indicators, swing structure,
SMC zones, patterns, levels, scoring, risk and the decision policy. The screenshot was only ever how
bars got in. Now that they arrive nightly from merolagani ([market-data.md](market-data.md)), the
same analysis can run across the whole market in about a minute.

A full read takes ~90 ms per stock, so scanning 400 securities costs less than a cup of tea.

## Traps, opportunities, context

The engine reports facts: a sweep happened, a gap is unfilled, price sits in the top third of its
range. It does not say whether a fact is good news, because that depends which way you are leaning.
`SignalClassifier` reads each finding against the prevailing bias and labels it:

| Label | What it means | Examples |
|-------|---------------|----------|
| **Trap** | A reason the obvious read is the wrong one | Liquidity sweep (the break was fake), equal highs/lows (stops resting in plain sight), a change of character against the bias, an unfilled gap pulling price the other way, and buying in the premium third of a range |
| **Opportunity** | A place the move could be joined, in the direction of the bias | Unfilled gap, untested order block, break of structure confirming the bias, price in the discount third |
| **Context** | Real, worth seeing, neither | A filled gap, an invalidated block, a change of character in line with the bias |

Two shaping rules:

- **Traps sort first.** The thing that can cost money does not belong below the fold.
- **Structure events collapse to the newest per direction.** A zone is a place — two unfilled gaps
  at different prices are two things to watch. A break of structure is a moment, and only the newest
  says where the market stands; listing all of them makes a calm chart look like it is screaming.

Every signal carries the engine's own confidence and the prices that bound the zone, so the label is
a heading over evidence rather than a claim of its own.

## Probability, honestly

**A setup score is a rank, not a probability.** It weighs nine factors against each other; it says
nothing about how often a setup like this works. The API repeats this in `meta.score_note` and
`calibrated_probability` is always `null` on a scan.

The only probabilities on the page are **base rates**: every earlier time a signal fired on that
symbol, resolved against the bars that actually followed.

> PCBL · 3,514 bars of history · 20-bar horizon
>
> | signal | n | went its way | against | neither |
> |--------|---|--------------|---------|---------|
> | Bearish engulfing | 128 | 50.0% | 49.2% | 0.8% |
> | Bullish engulfing | 101 | 47.5% | 52.5% | 0.0% |
> | Hammer | 235 | 47.2% | 52.3% | 0.4% |
> | Shooting star | 197 | 43.6% | 55.8% | 0.5% |

Read that and you learn something a confident-looking percentage would have hidden: **on PCBL these
candlestick patterns have no edge.** A bullish engulfing is followed by a one-ATR fall slightly more
often than a one-ATR rise. That is the point of measuring instead of asserting.

### The method

From the close of the signal bar, does price travel one ATR in the signal's favour before it travels
one ATR against, within the style's horizon (swing: 20 bars)? Barriers are ATR-sized rather than a
fixed percentage so the question means the same thing on a 40-rupee stock and a 4,000-rupee one, and
so the distance matches how the engine already places stops.

Three rules keep it honest, all pinned by tests in `ai-engine/tests/test_base_rates.py`:

- **No look-ahead.** The ATR sizing the barriers is the one known at the signal bar, and only bars
  *after* it are walked. The signal bar's own range never decides its outcome.
- **No truncation bias.** An occurrence without a full horizon of bars after it is excluded, not
  counted as unresolved, so a signal that fired last week cannot pad the sample.
- **Ties lose.** A bar touching both barriers counts against the signal, matching the
  `ambiguous_bar` convention in outcome evaluation. Intrabar order is unknowable from daily data.

Anything under five completed occurrences is not reported at all, and the UI marks a sample under
30 as "thin — treat as noise".

**Scope.** Base rates currently cover candlestick patterns, measured across the whole stored
history (up to 2,000 bars sent per scan, far more than the ~250 any style looks back over). SMC
events are not base-rated: the engine's SMC detectors are capped at each level because they read
current state rather than enumerate history, so a historical sample would need those caps lifted.
The SMC findings still appear as traps and opportunities — they just have no measured hit rate yet.

**Your own record is separate.** As your real analyses emit predictions and those resolve, the
existing Phase 6 calibration builds a curve from *your* outcomes. Base rates describe the market; a
calibrated probability would describe you. A scan contributes to neither.

**What the scan also carries.** Each row keeps the measured next-day and next-week move
distributions for that stock, which is what the Forecast page reads — see
[forecast.md](forecast.md). They are measured on the same full history the base rates are, so the
scan is where they are filed rather than recomputed per page view.

## Why a scan is not an analysis

Scans live in `market_scans`, not `analyses`. An analysis is something you asked for: it belongs to
you, it emits predictions, and those predictions are the track record. Scanning 400 stocks a night
would file 400 predictions a night that nobody intended to trade, and the calibration curve would
end up describing the scanner rather than you.

So a scan files no prediction, and the engine's `predictions` block is dropped on the way in. Turning
a scan into a real analysis stays a deliberate act.

## One reading per stock

The list shows each stock's **most recent scan**, not every scan filed on the newest date. Plenty of
NEPSE securities go days without trading, so their last bar — and therefore their last reading — is
older than the market's. Keying the view to a single date quietly drops 60-odd stocks, and an
illiquid stock is exactly the kind a scanner exists to surface.

Pass `scanned_on=YYYY-MM-DD` to ask the other question: what did one particular session look like.
`meta.session_only` says which question was answered.

## Running it

```powershell
docker compose exec backend php artisan market:scan              # every active stock, foreground
docker compose exec backend php artisan market:scan PCBL NABIL   # named symbols
docker compose exec backend php artisan market:scan --queue      # what the scheduler does
docker compose exec backend php artisan market:scan --style=positional
```

Scheduled automatically: the merolagani sync batch chains `ScanMarketJob` when it finishes, so the
scan can never run on stale bars — no clock offset to guess at. One `ScanStockJob` per stock on the
`market-data` queue, so one bad symbol cannot take the market with it.

A stock the engine could not read still gets a row, with `error_code` and `error_message`, so a gap
in the scanner is explained rather than silently missing. Those rows are left out of the ranking and
counted in `meta.summary.unreadable`.

## API

`GET /api/v1/market-scan`

| Parameter | Values |
|-----------|--------|
| `bias` | `bullish`, `bearish`, `neutral` |
| `decision` | `buy`, `wait`, `hold`, `sell`, `avoid` |
| `range_zone` | `premium`, `equilibrium`, `discount` |
| `min_score` | 0–100 |
| `traps`, `opportunities`, `viable` | boolean |
| `q` | symbol or name |
| `sort` | `score` (default), `score_asc`, `traps`, `opportunities`, `symbol` |
| `scanned_on` | `YYYY-MM-DD`, to view one session |
| `per_page` | 1–200, default 50 |

The list payload is deliberately light — badges need `top_signals`, not the full reading.
`GET /api/v1/market-scan/{stock}` returns one stock with `signals`, `base_rates`, `levels`, `plans`,
`factors`, `warnings` and `report_markdown`.

## Reading the market, not just a stock

`meta.summary` counts the whole scan, which is often the more useful number. A worked example from
the first full run over 391 readable NEPSE stocks:

| Range zone | Stocks | Average setup score |
|------------|--------|---------------------|
| Discount | 144 | 37 |
| Equilibrium | 114 | 46 |
| Premium | 131 | 60 |

The highest-scoring charts are the most extended ones — which is exactly what a trend-following
score *should* do, and exactly the trap the page is built to show. A score of 83 on a stock sitting
at the top of its range is the engine saying "good chart, poor price", and its decision on that run
was `wait` with the pullback prices that would make each plan viable.

On that same run: 1 `buy`, 237 `wait`, 153 `avoid`, and 40 stocks with a plan clearing the 2.0 net
R:R gate. A scanner that finds few trades is working.
