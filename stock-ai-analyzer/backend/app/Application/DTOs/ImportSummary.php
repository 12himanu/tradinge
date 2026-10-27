<?php

declare(strict_types=1);

namespace App\Application\DTOs;

use App\Domain\Market\ValueObjects\SkippedRow;

final readonly class ImportSummary
{
    /**
     * @param  list<SkippedRow>  $skipped
     * @param  array<string, string|int|bool>  $source
     * @param  list<array{timeframe: string, from: string, to: string, bars: int}>  $coverage
     */
    public function __construct(
        public int $inserted,
        public int $updated,
        public array $skipped,
        public array $source,
        public array $coverage,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(int $maxSkippedListed): array
    {
        return [
            'inserted' => $this->inserted,
            'updated' => $this->updated,
            'skipped_total' => count($this->skipped),
            'skipped' => array_map(
                static fn (SkippedRow $row): array => $row->toArray(),
                array_slice($this->skipped, 0, $maxSkippedListed),
            ),
            'source' => $this->source,
            'coverage' => $this->coverage,
        ];
    }
}
