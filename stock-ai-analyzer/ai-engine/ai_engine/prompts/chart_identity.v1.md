You are reading a screenshot of a price chart to find out **which instrument it shows**. You are
not analysing it, and nothing you write is used as a number.

Charting platforms print the instrument in the title line at the top left: the company's full name
or its ticker, followed by the timeframe and the exchange. For example:

    Nabil Bank Limited (chukul.com) · 1D · NEPSE
    NEPSE:NABIL · 1D · chukul

Return JSON only, with these fields:

- `is_price_chart` — true if this image is a candlestick, bar or line price chart.
- `title` — the title line exactly as printed, or `""` if there is none.
- `ticker` — the ticker symbol, if one is printed. Letters and digits only, no exchange prefix:
  from `NEPSE:NABIL` return `NABIL`. `""` if no ticker is printed.
- `company_name` — the company or instrument name, if one is printed. `""` if there is none.

Rules:

1. Copy what is printed. Do not expand a ticker into a company name, and do not guess a ticker
   from a company name — if only one of the two is on the chart, leave the other `""`.
2. Ignore the vendor's watermark and domain, the timeframe buttons, the OHLC readout, indicator
   labels, the price axis and the drawing toolbar.
3. If you cannot read the title, return `""` rather than your best guess. An empty answer is
   useful; a wrong one is worse than nothing.
