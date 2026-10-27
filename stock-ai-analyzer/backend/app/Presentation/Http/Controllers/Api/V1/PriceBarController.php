<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controllers\Api\V1;

use App\Application\Actions\ImportPriceBars;
use App\Domain\Market\Models\PriceBar;
use App\Domain\Market\Models\Stock;
use App\Infrastructure\MarketData\CsvPriceImporter;
use App\Infrastructure\MarketData\CsvProvider;
use App\Infrastructure\MarketData\InvalidCsvFile;
use App\Presentation\Http\Requests\ImportPricesRequest;
use App\Presentation\Http\Requests\PriceBarsRequest;
use App\Presentation\Http\Resources\PriceBarResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

/**
 * Real market data per stock. Price bars are shared reference data, not per-user resources.
 */
final class PriceBarController
{
    /**
     * Import an OHLCV CSV (docs/csv-format.md). Returns `{inserted, updated, skipped_total, skipped: [{row, reason}],
     * source, coverage}`; bad rows are reported, not silently dropped.
     */
    public function import(ImportPricesRequest $request, Stock $stock, ImportPriceBars $import, CsvPriceImporter $importer): JsonResponse
    {
        try {
            $summary = $import($stock, $request->timeframe(), new CsvProvider($importer, $request->csvPath()));
        } catch (InvalidCsvFile $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        return new JsonResponse(['data' => $summary->toArray((int) config('stock_ai.market_data.max_skipped_listed'))]);
    }

    /**
     * Stored bars in ascending time order. Without a range, returns the most recent `limit` bars.
     */
    public function index(PriceBarsRequest $request, Stock $stock): AnonymousResourceCollection
    {
        $timeframe = $request->timeframe();
        $from = $request->from();
        $to = $request->to();

        $bars = PriceBar::query()
            ->where('stock_id', $stock->id)
            ->where('timeframe', $timeframe->value)
            ->when($from !== null, fn ($query) => $query->where('bar_time', '>=', $from))
            ->when($to !== null, fn ($query) => $query->where('bar_time', '<=', $to))
            ->orderByDesc('bar_time')
            ->limit($request->limit())
            ->get()
            ->reverse()
            ->values();

        return PriceBarResource::collection($bars)->additional(['meta' => [
            'stock' => ['id' => $stock->id, 'symbol' => $stock->symbol],
            'timeframe' => $timeframe->value,
            'count' => $bars->count(),
        ]]);
    }
}
