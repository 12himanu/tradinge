<?php

declare(strict_types=1);

namespace App\Infrastructure\MarketData;

use App\Domain\Market\Enums\PriceSource;
use App\Domain\Market\Models\Stock;
use App\Domain\Shared\Enums\Timeframe;
use App\Infrastructure\MarketData\Contracts\MarketDataProvider;
use LogicException;

/**
 * Bars from one uploaded CSV file (the guaranteed Tier A source, decision D3).
 */
final class CsvProvider implements MarketDataProvider
{
    public function __construct(
        private readonly CsvPriceImporter $importer,
        private readonly string $path,
    ) {}

    public function source(): PriceSource
    {
        return PriceSource::Csv;
    }

    public function fetch(Stock $stock, Timeframe $timeframe): ProviderResult
    {
        $exchange = $stock->loadMissing('exchange')->exchange
            ?? throw new LogicException("Stock [{$stock->id}] has no exchange.");

        return $this->importer->parse($this->path, $timeframe, $exchange->timezone);
    }
}
