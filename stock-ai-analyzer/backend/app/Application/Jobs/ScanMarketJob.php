<?php

declare(strict_types=1);

namespace App\Application\Jobs;

use App\Domain\Market\Models\Exchange;
use App\Domain\Market\Models\Stock;
use App\Domain\Shared\Enums\Timeframe;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reads the whole market: one ScanStockJob per stock that has enough bars to analyse.
 *
 * Runs after the merolagani sync, on the same queue, so it can only start once the day's bars are
 * in. Stocks with no bars yet are skipped rather than filed as failures: a newly listed security
 * has nothing to read, and saying so 500 times a night is noise.
 */
final class ScanMarketJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    /**
     * @param  list<string>|null  $symbols  only these symbols; null means every active NEPSE stock
     */
    public function __construct(
        public readonly ?array $symbols = null,
        public readonly string $style = 'swing',
        public readonly int $minBars = 60,
    ) {
        $this->onQueue('market-data');
    }

    public function handle(): void
    {
        $exchange = Exchange::query()->where('code', 'NEPSE')->first();

        if ($exchange === null) {
            Log::warning('scan.no_exchange', ['code' => 'NEPSE']);

            return;
        }

        $stocks = Stock::query()
            ->where('exchange_id', $exchange->id)
            ->when($this->symbols === null, fn ($query) => $query->where('is_active', true))
            ->when(
                $this->symbols !== null,
                fn ($query) => $query->whereIn('symbol', array_map(mb_strtoupper(...), $this->symbols ?? [])),
            )
            ->whereHas('priceBars', fn ($query) => $query->where('timeframe', Timeframe::D1->value))
            ->withCount(['priceBars as daily_bars' => fn ($query) => $query->where('timeframe', Timeframe::D1->value)])
            ->having('daily_bars', '>=', $this->minBars)
            ->orderBy('symbol')
            ->get(['id', 'symbol']);

        if ($stocks->isEmpty()) {
            Log::warning('scan.no_stocks', ['symbols' => $this->symbols, 'min_bars' => $this->minBars]);

            return;
        }

        $jobs = $stocks->map(fn (Stock $stock): ScanStockJob => new ScanStockJob($stock->id, $this->style))->all();

        Bus::batch($jobs)
            ->name('market-scan')
            ->allowFailures()
            ->then(static function (Batch $batch): void {
                Log::info('scan.finished', ['stocks' => $batch->totalJobs, 'failed' => $batch->failedJobs]);
            })
            ->catch(static function (Batch $batch, Throwable $e): void {
                Log::error('scan.batch_failed', ['batch' => $batch->id, 'reason' => $e->getMessage()]);
            })
            ->onQueue('market-data')
            ->dispatch();

        Log::info('scan.queued', ['stocks' => count($jobs), 'style' => $this->style]);
    }
}
