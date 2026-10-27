<?php

declare(strict_types=1);

use App\Application\Jobs\EvaluatePredictionsJob;
use App\Application\Jobs\StuckAnalysisWatchdog;
use App\Application\Jobs\SyncMerolaganiPricesJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// An analysis whose worker died would otherwise poll forever in the UI (P4D-08).
Schedule::job(new StuckAnalysisWatchdog)
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->name('analysis-watchdog');

// After the NEPSE close, score every open prediction against the day's bars.
// Nothing is evaluated on data the analysis could have seen: the evaluator only reads bars after
// `as_of`, so a late run is harmless and a missed day is picked up by the next one (P6-03).
Schedule::job(new EvaluatePredictionsJob)
    // NEPSE trades Monday to Friday since April 2026 (1 = Monday … 5 = Friday).
    ->days([1, 2, 3, 4, 5])
    // A safety net only: the merolagani sync below re-scores predictions as soon as the day's bars
    // land, which is after this runs. This catches a day whose sync never happened.
    ->dailyAt('16:00')
    ->timezone('Asia/Kathmandu')
    ->withoutOverlapping()
    ->name('evaluate-predictions');

// NEPSE closes at 15:00 Asia/Kathmandu; merolagani publishes the day's bar in the hours after that.
// 17:00 leaves it time to appear, and every run also re-asks for the previous `lookback_days`, so a
// late or restated bar is picked up by the next run rather than being lost. A stock with no bars yet
// is backfilled with its whole history on its first run, so no separate first-time step is needed.
// Runs daily: the trading week is Monday-Friday now but has changed before, and a day with no new
// bar costs one request that imports nothing.
Schedule::job(new SyncMerolaganiPricesJob(
    spacingSeconds: (int) config('stock_ai.market_data.merolagani.spacing_seconds'),
))
    ->dailyAt('17:00')
    ->timezone('Asia/Kathmandu')
    ->withoutOverlapping()
    ->name('sync-merolagani');
