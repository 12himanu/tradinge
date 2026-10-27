<?php

declare(strict_types=1);

namespace App\Domain\Market\ValueObjects;

/**
 * A source row that did not become a stored bar, and why. `row` is the 1-based line in the file.
 */
final readonly class SkippedRow
{
    public function __construct(
        public ?int $row,
        public string $reason,
    ) {}

    /**
     * @return array{row: int|null, reason: string}
     */
    public function toArray(): array
    {
        return ['row' => $this->row, 'reason' => $this->reason];
    }
}
