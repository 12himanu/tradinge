<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controllers\Api\V1;

use App\Application\Jobs\ScanMarketJob;
use App\Application\Jobs\SyncMerolaganiPricesJob;
use App\Application\Services\MarketDataStatus;
use App\Presentation\Http\ApiError;
use Illuminate\Http\JsonResponse;

/**
 * Running the scraper on demand, and reporting what it is doing.
 *
 * The nightly schedule is the normal path; this is for the first import, a catch-up after the
 * machine was off, or simple impatience.
 */
final class MarketDataController
{
    /**
     * Current state of the scraper, the scan, and how much data exists.
     */
    public function status(MarketDataStatus $status): JsonResponse
    {
        return new JsonResponse(['data' => $status->snapshot()]);
    }

    /**
     * Queue a full price sync. The market scan chains itself on when the batch finishes.
     *
     * Refused while one is already running: two concurrent runs would double the load on somebody
     * else's server to produce exactly the same rows.
     */
    public function sync(MarketDataStatus $status): JsonResponse
    {
        if ($status->syncRunning()) {
            return ApiError::response(
                'SYNC_IN_PROGRESS',
                'A market-data sync is already running.',
                409,
                ['sync' => $status->snapshot()['sync']],
            );
        }

        SyncMerolaganiPricesJob::dispatch(
            spacingSeconds: (int) config('stock_ai.market_data.merolagani.spacing_seconds'),
        );

        return new JsonResponse(['data' => $status->snapshot()], 202);
    }

    /**
     * Re-read every stock with the engine, without fetching bars first.
     *
     * Useful after changing scoring weights, when the bars are already current.
     */
    public function scan(MarketDataStatus $status): JsonResponse
    {
        ScanMarketJob::dispatch();

        return new JsonResponse(['data' => $status->snapshot()], 202);
    }
}
