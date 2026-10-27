# Seeder data

`nepse_stocks.csv` is a **starter list** of well-known NEPSE securities so the app works out of the box. Company names and sectors change (mergers, renames), so replace it with the official listed-company export from NEPSE and re-run:

```powershell
docker compose exec backend php artisan db:seed --class=StockSeeder
```

Columns: `symbol,name,sector,instrument_type` (`instrument_type` ∈ equity, mutual_fund, debenture, index, etf). The seeder upserts by symbol, so re-running is safe.
