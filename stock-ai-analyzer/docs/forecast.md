# Next day and next week

The page people actually arrive wanting: *what is this stock going to do tomorrow?*

This app will not answer that, because it cannot, and neither can anything else. What it can do is
answer the nearest honest question — **what have tomorrow and next week been worth on this stock
before, and what does each kind of analysis make of it right now** — and print the sample every
number was measured on so you can discount it yourself.

Open **Forecast** in the app.

## What it shows

### 1. Two horizons, measured

For the next trading day (1 bar) and the next week (5 bars), over the stock's whole stored history:

| Number | What it is |
|--------|------------|
| **Closed higher** | The share of N-bar moves that ended above where they started. Usually near half — which is the point: it is the baseline any signal has to beat |
| **Ordinary move** | The median *absolute* N-bar move. How big a normal day or week is on this stock, direction aside |
| **Middle 80% landed** | The 10th to 90th percentile of those moves, as a price band around the last close |

The band is a description, not a forecast: one move in five finished outside it, by construction.
None of this is conditioned on today's chart — it is the instrument with no signal at all, which is
exactly what makes it a baseline.

### 2. What today's signals were worth, over *this* horizon

The candlestick signals firing on the last bar, base-rated at 1 bar and at 5 bars rather than at the
trading style's horizon. "Within twenty bars" is not an answer to "tomorrow", so the forecast page
re-measures at the horizon it is talking about.

Each rate carries its sample size and how far it sits from even — the edge, not the raw percentage,
is the number that means anything. See [scanner.md](scanner.md) for the two-barrier method.

### 3. Each kind of analysis, separately

The engine's nine scoring factors, grouped the way a trader would name them:

| Family | Factors behind it |
|--------|-------------------|
| Trend | EMA alignment, price vs EMA 50/200 |
| Momentum | RSI regime, MACD |
| Market structure | Structure and last break, location in the dealing range |
| Volume | Volume confirmation |
| Chart patterns | The pattern factor |
| Room to move | Distance to the next level |

**The disagreements are the point.** A chart where trend, momentum, structure and volume all lean
the same way is a different proposition from one where momentum has turned and the trend has not
noticed yet — and the single blended setup score hides exactly that. Each row shows the engine's own
one-line reason for the factor; nothing is re-interpreted on the way to the screen.

A family nothing could be judged on says so, rather than being called neutral. "No volume data" and
"balanced" are different statements.

### 4. What it runs into first

The nearest level above and below the last close. Over a day or a week these matter more than the
trend does: a strong setup with a wall twenty paisa above it is not a strong setup.

## Where the numbers come from

The page reads the **nightly scan** ([scanner.md](scanner.md)), not a fresh engine run. The
distributions are measured over the whole stored history — up to 2,000 bars — and recomputing them
per page view would spend a GPU slot to reproduce the answer the scanner already filed.

So a stock with no scan yet has no forecast. Import its price data and run a scan
([market-data.md](market-data.md)); 60 daily bars is the minimum for any of this.

## What it deliberately does not do

- **No probability of a price.** Nothing here says "68% chance of 260". A base rate is what happened
  before, on a stated sample; a setup score is a rank. Neither becomes a forecast by being printed
  next to a date.
- **No target for tomorrow.** The band is percentiles of past moves. Targets come from the trade
  plan, which is built from levels and ATR, and lives on the analysis report.
- **No model.** The distribution is not fitted, smoothed or extrapolated — it is a sorted list of
  what the stock has already done, read at the 10th, 50th and 90th percentile.

The engine computes it in `ai_engine/analysis/outlook.py`; the rules it follows — no look-ahead, no
truncation bias, sample size always attached — are the same ones base rates follow, and are written
down at the top of that file.
