<?php

declare(strict_types=1);

namespace App\Application\DTOs;

/**
 * What one symbol's merolagani sync did, for the console table and the run log.
 */
final readonly class MerolaganiSyncResult
{
    /**
     * @param  bool  $backfill  true when the whole available history was requested, not just recent days
     * @param  list<string>  $skipped  reasons bars were not stored, deduplicated
     */
    public function __construct(
        public string $symbol,
        public bool $backfill,
        public string $from,
        public string $to,
        public int $inserted,
        public int $updated,
        public int $skippedTotal,
        public array $skipped,
        public ?string $lastBarDate,
        public bool $snapshotStored,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'symbol' => $this->symbol,
            'mode' => $this->backfill ? 'backfill' : 'incremental',
            'from' => $this->from,
            'to' => $this->to,
            'inserted' => $this->inserted,
            'updated' => $this->updated,
            'skipped_total' => $this->skippedTotal,
            'skipped' => $this->skipped,
            'last_bar' => $this->lastBarDate,
            'snapshot' => $this->snapshotStored,
        ];
    }
}
