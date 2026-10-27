<?php

declare(strict_types=1);

namespace App\Application\Jobs;

use App\Application\Services\ScanStock;
use App\Domain\Analysis\Exceptions\EngineBusy;
use App\Domain\Analysis\Exceptions\EngineStreamTimedOut;
use App\Domain\Analysis\Exceptions\EngineUnavailable;
use App\Domain\Market\Models\Stock;
use App\Domain\Shared\Enums\TradingStyle;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * One stock's scan. Kept per-stock so a symbol the engine chokes on cannot take the market with it.
 */
final class ScanStockJob implements ShouldQueue
{
    use Batchable, Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function __construct(
        public readonly int $stockId,
        public readonly string $style = 'swing',
    ) {
        $this->onQueue('market-data');
    }

    public function handle(ScanStock $scan): void
    {
        $stock = Stock::query()->find($this->stockId);

        if ($stock === null) {
            Log::warning('scan.stock_missing', ['stock_id' => $this->stockId]);

            return;
        }

        try {
            $result = $scan($stock, TradingStyle::from($this->style));
        } catch (EngineUnavailable|EngineBusy|EngineStreamTimedOut $e) {
            // The engine, not the stock. Let the queue retry it.
            Log::warning('scan.engine_unavailable', [
                'symbol' => $stock->symbol,
                'attempt' => $this->attempts(),
                'reason' => $e->getMessage(),
            ]);

            throw $e;
        }

        Log::info('scan.stock', [
            'symbol' => $stock->symbol,
            'scanned_on' => $result->scanned_on->toDateString(),
            'bias' => $result->bias?->value,
            'score' => $result->setup_score,
            'decision' => $result->decision?->value,
            'traps' => $result->trap_count,
            'opportunities' => $result->opportunity_count,
            'error' => $result->error_code,
        ]);
    }
}
