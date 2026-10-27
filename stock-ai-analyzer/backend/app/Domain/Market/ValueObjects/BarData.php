<?php

declare(strict_types=1);

namespace App\Domain\Market\ValueObjects;

use Carbon\CarbonImmutable;

/**
 * One OHLCV bar from a market-data source. Prices are decimal strings so no precision is lost before
 * they reach numeric(14,4) columns. Daily and weekly bars are stamped at 00:00 UTC of their calendar date.
 */
final readonly class BarData
{
    public function __construct(
        public CarbonImmutable $time,
        public string $open,
        public string $high,
        public string $low,
        public string $close,
        public ?string $volume = null,
        public ?string $turnover = null,
        public ?int $sourceRow = null,
    ) {}
}
