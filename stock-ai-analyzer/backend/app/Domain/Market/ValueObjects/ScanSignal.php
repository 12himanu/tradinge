<?php

declare(strict_types=1);

namespace App\Domain\Market\ValueObjects;

/**
 * One structural finding from a scan, labelled trap, opportunity or context.
 *
 * `headline` is plain English for the scanner; every other field is the evidence behind it, so a
 * reader can go and look at the bars rather than take the label on trust.
 */
final readonly class ScanSignal
{
    public function __construct(
        public string $kind,
        public string $category,
        public string $type,
        public string $direction,
        public string $status,
        public string $headline,
        public float $confidence,
        public ?float $priceLow,
        public ?float $priceHigh,
        public ?string $at,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'category' => $this->category,
            'type' => $this->type,
            'direction' => $this->direction,
            'status' => $this->status,
            'headline' => $this->headline,
            'confidence' => $this->confidence,
            'price_low' => $this->priceLow,
            'price_high' => $this->priceHigh,
            'at' => $this->at,
        ];
    }
}
