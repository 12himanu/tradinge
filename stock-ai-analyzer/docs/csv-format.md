# CSV price data format

Imported through **Market data** in the app, or `POST /api/v1/stocks/{stock}/prices/import` (multipart: `file`, `timeframe`). CSV is the guaranteed Tier A data source (decision D3). One file holds one symbol and one timeframe.

## Columns

A header row is required. Header names are matched case-insensitively, ignoring spaces and punctuation (`Close Price`, `close_price` and `CLOSE-PRICE` are the same). Extra columns are ignored.

| Column | Required | Accepted headers (first match wins) |
|--------|----------|--------------------------------------|
| date | yes | `date`, `time`, `datetime`, `timestamp`, `trading_date`, `business_date`, `published_date` |
| open | yes | `open`, `open_price`, `opening_price` |
| high | yes | `high`, `high_price`, `max_price` |
| low | yes | `low`, `low_price`, `min_price` |
| close | yes | `close`, `close_price`, `closing_price`, `ltp`, `last_traded_price` |
| volume | no | `volume`, `vol`, `traded_quantity`, `total_traded_quantity`, `traded_shares`, `qty` |
| turnover | no | `turnover`, `amount`, `traded_amount`, `total_traded_amount`, `total_traded_value` |

This covers the plain format `date,open,high,low,close,volume[,turnover]`, TradingView's export (`time,open,high,low,close,Volume`) and the usual NEPSE-site exports.

## Delimiter and numbers

- Delimiter: comma, semicolon, tab or pipe, detected from the header row.
- Values may be quoted. Thousands separators are removed: `"1,050.00"` → `1050.00`.
- Blank lines are ignored. A UTF-8 byte order mark and CRLF line endings are fine.

## Dates

The format is detected from the first 500 rows:

| Format | Example | Notes |
|--------|---------|-------|
| ISO | `2026-09-06`, `2026-09-06 11:00`, `2026-09-06T05:15:00Z` | Preferred |
| Year first | `2026/09/06` | |
| Day first | `06/09/2026`, `06-09-2026`, `06.09.2026` | |
| Month first | `09/06/2026` | Chosen only when some day is above 12 |
| Unix seconds / milliseconds | `1788652800` | TradingView "UNIX timestamp" setting |

Slash dates are ambiguous when every day in the file is 12 or less. The importer then assumes **day first** and reports `date_format_assumed: true`. Check the stored range after import, or export with ISO dates. A file that mixes day-first and month-first dates is rejected.

Dates without a UTC offset are read in the exchange's timezone (NEPSE: Asia/Kathmandu).

**Daily and weekly bars** are stored at `00:00 UTC` of their calendar date on the exchange, whatever time or offset the file used. **Intraday bars** (`15m`, `1h`, `4h`) keep their exact instant, stored in UTC.

## Row checks

A row that fails a check is skipped and listed in the import summary with its line number (the header is line 1). Nothing is dropped silently.

- The date parses.
- Open, high, low and close are present, numeric and positive.
- `low ≤ min(open, close)`, `high ≥ max(open, close)` and `low ≤ high`.
- Volume and turnover, when present, are non-negative numbers.
- No other row in the file has the same date.
- For daily and intraday bars, the date is a trading day on the exchange: NEPSE trades Monday to Friday (it moved off the Sunday–Thursday week in April 2026), minus the holidays in `exchanges.holidays`. This check applies to CSV files only — a provider that is itself the record of which days the exchange traded bypasses it, see [market-data.md](market-data.md#the-trading-calendar).

## Limits

- 10 MB per file and 100,000 rows. Split larger histories into several files.
- Re-importing is safe. Existing bars with the same date are updated in place (`updated` in the summary), so a corrected export fixes earlier rows.

## Import summary

```json
{
  "data": {
    "inserted": 248,
    "updated": 2,
    "skipped_total": 3,
    "skipped": [{"row": 5, "reason": "Fri 11 Sep 2026 is not a NEPSE trading day."}],
    "source": {"delimiter": "comma", "date_format": "iso", "date_format_assumed": false, "rows_read": 253},
    "coverage": [{"timeframe": "1D", "from": "2025-09-01T00:00:00Z", "to": "2026-09-10T00:00:00Z", "bars": 250}]
  }
}
```

At most 200 skipped rows are listed; `skipped_total` has the full count.
