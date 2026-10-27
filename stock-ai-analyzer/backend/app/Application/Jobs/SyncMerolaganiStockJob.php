<?php

declare(strict_types=1);

namespace App\Application\Jobs;

use App\Application\Services\SyncMerolaganiStock;
use App\Domain\Market\Models\Stock;
use App\Infrastructure\MarketData\Merolagani\MerolaganiUnavailable;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * One symbol's daily bars and fundamentals snapshot.
 *
 * One job per symbol rather than one job for the whole market, so a single unreachable symbol
 * cannot abandon the rest, each job stays far below the queue's retry-after window even when it is
 * backfilling sixteen years of history, and Horizon shows how far the run has got.
 */
final class SyncMerolaganiStockJob implements ShouldQueue
{
    use Batchable, Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    /**
     * Seconds before each retry: merolagani is usually back within a minute.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function __construct(
        public readonly int $stockId,
        public readonly bool $backfill = false,
    ) {
        $this->onQueue('market-data');
    }

    public function handle(SyncMerolaganiStock $sync): void
    {
        $stock = Stock::query()->find($this->stockId);

        if ($stock === null) {
            Log::warning('merolagani.sync.stock_missing', ['stock_id' => $this->stockId]);

            return;
        }

        try {
            $result = $sync($stock, $this->backfill);
        } catch (MerolaganiUnavailable $e) {
            // Let the queue retry it. Logged here because the message names the endpoint and reason.
            Log::warning('merolagani.sync.unavailable', [
                'symbol' => $stock->symbol,
                'attempt' => $this->attempts(),
                'reason' => $e->getMessage(),
            ]);

            throw $e;
        }

        Log::info('merolagani.sync.symbol', $result->toArray());
    }
}
