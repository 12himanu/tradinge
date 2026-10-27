<?php

declare(strict_types=1);

namespace App\Infrastructure\MarketData\Contracts;

use App\Domain\Market\Enums\PriceSource;
use App\Domain\Market\Models\Stock;
use App\Domain\Shared\Enums\Timeframe;
use App\Infrastructure\MarketData\ProviderResult;

/**
 * A source of real OHLCV bars. CsvProvider is the first implementation; network providers (NEPSE,
 * Yahoo) come later per decision D3 and plug into the same ImportPriceBars action.
 */
interface MarketDataProvider
{
    public function source(): PriceSource;

    public function fetch(Stock $stock, Timeframe $timeframe): ProviderResult;
}
