<?php

declare(strict_types=1);

namespace App\Application\Jobs;

use App\Domain\Market\Models\Exchange;
use App\Domain\Market\Models\Stock;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The daily merolagani run: one SyncMerolaganiStockJob per NEPSE stock, as a batch.
 *
 * Requests are spread out rather than fired together. Merolagani is somebody else's server and this
 * is a courtesy call on it once a day; `spacingSeconds` is how far apart the symbols are placed.
 *
 * The batch exists for what happens after it. Open predictions are scored against the bars that
 * have printed since, so the moment the day's bars land is the moment to re-score them — no clock
 * offset to guess at, and no waiting until tomorrow. `allowFailures` keeps one unreachable symbol
 * from cancelling that.
 *
 * Scheduled for 17:00 Asia/Kathmandu, two hours after the 15:00 close — see routes/console.php.
 */
final class SyncMerolaganiPricesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    /**
     * @param  list<string>|null  $symbols  only these symbols; null means every active NEPSE stock
     */
    public function __construct(
        public readonly ?array $symbols = null,
        public readonly bool $backfill = false,
        public readonly int $spacingSeconds = 2,
    ) {
        $this->onQueue('market-data');
    }

    public function handle(): void
    {
        $exchange = Exchange::query()->where('code', 'NEPSE')->first();

        if ($exchange === null) {
            Log::warning('merolagani.sync.no_exchange', ['code' => 'NEPSE']);

            return;
        }

        $stocks = Stock::query()
            ->where('exchange_id', $exchange->id)
            ->when($this->symbols === null, fn ($query) => $query->where('is_active', true))
            ->when(
                $this->symbols !== null,
                fn ($query) => $query->whereIn('symbol', array_map(mb_strtoupper(...), $this->symbols ?? [])),
            )
            ->orderBy('symbol')
            ->get(['id', 'symbol']);

        if ($stocks->isEmpty()) {
            Log::warning('merolagani.sync.no_stocks', ['symbols' => $this->symbols]);

            return;
        }

        $spacing = max(0, $this->spacingSeconds);
        $jobs = [];

        foreach ($stocks->values() as $position => $stock) {
            $jobs[] = (new SyncMerolaganiStockJob($stock->id, $this->backfill))
                ->delay(now()->addSeconds($position * $spacing));
        }

        $count = count($jobs);

        Bus::batch($jobs)
            ->name('merolagani-sync')
            ->allowFailures()
            ->then(static function (Batch $batch) use ($count): void {
                Log::info('merolagani.sync.finished', [
                    'stocks' => $count,
                    'failed' => $batch->failedJobs,
                ]);

                // New bars mean open predictions can be scored further. Cheap and idempotent: the
                // evaluator re-reads from each prediction's `as_of` and writes an outcome only when
                // one actually resolves.
                EvaluatePredictionsJob::dispatch();

                // And the market scanner re-reads every stock against the bars that just landed.
                // Chained rather than scheduled at a later hour so it can never run on stale data.
                ScanMarketJob::dispatch();
            })
            ->catch(static function (Batch $batch, Throwable $e): void {
                Log::error('merolagani.sync.batch_failed', [
                    'batch' => $batch->id,
                    'reason' => $e->getMessage(),
                ]);
            })
            ->onQueue('market-data')
            ->dispatch();

        Log::info('merolagani.sync.queued', [
            'stocks' => $count,
            'mode' => $this->backfill ? 'backfill' : 'incremental',
            'spacing_seconds' => $spacing,
        ]);
    }
}
