<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * NEPSE moved from a Sunday–Thursday week to Monday–Friday: in merolagani's own price history
 * Sunday sessions stop and Friday sessions start in April 2026, on every symbol checked.
 *
 * This column drives `TradingCalendar`, which decides when a prediction expires
 * (`addTradingDays`) and which imported bars count as real sessions (`isTradingDay`). Left at
 * Sunday–Thursday it both mis-dates every horizon and rejects every Friday bar on import.
 *
 * Historical bars from before the change fall on Sundays, so they are no longer on a trading day
 * by this calendar. That is why authoritative providers bypass the weekday filter — see
 * App\Infrastructure\MarketData\Contracts\AuthoritativeCalendar.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('exchanges')
            ->where('code', 'NEPSE')
            ->update(['trading_days' => json_encode([1, 2, 3, 4, 5]), 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('exchanges')
            ->where('code', 'NEPSE')
            ->update(['trading_days' => json_encode([7, 1, 2, 3, 4]), 'updated_at' => now()]);
    }
};
