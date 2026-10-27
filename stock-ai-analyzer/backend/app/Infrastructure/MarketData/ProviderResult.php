<?php

declare(strict_types=1);

namespace App\Infrastructure\MarketData;

use App\Domain\Market\ValueObjects\BarData;
use App\Domain\Market\ValueObjects\SkippedRow;

/**
 * What a market-data provider returned: parsed bars, rows it could not use, and source details
 * (for a CSV: delimiter, detected date format, rows read).
 */
final readonly class ProviderResult
{
    /**
     * @param  list<BarData>  $bars
     * @param  list<SkippedRow>  $skipped
     * @param  array<string, string|int|bool>  $meta
     */
    public function __construct(
        public array $bars,
        public array $skipped = [],
        public array $meta = [],
    ) {}
}
