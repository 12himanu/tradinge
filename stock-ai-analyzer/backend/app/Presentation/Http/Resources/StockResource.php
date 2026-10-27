<?php

declare(strict_types=1);

namespace App\Presentation\Http\Resources;

use App\Domain\Market\Models\Stock;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\MissingValue;

/**
 * @mixin Stock
 */
final class StockResource extends JsonResource
{
    /** @var list<array{timeframe: string, from: string, to: string, bars: int}>|null */
    private ?array $coverage = null;

    /**
     * A setter rather than a constructor argument: collection() passes the item key as the second argument.
     *
     * @param  list<array{timeframe: string, from: string, to: string, bars: int}>  $coverage
     */
    public function withCoverage(array $coverage): self
    {
        $this->coverage = $coverage;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'symbol' => $this->symbol,
            'name' => $this->name,
            'sector' => $this->sector,
            'instrument_type' => $this->instrument_type,
            'exchange' => $this->whenLoaded('exchange', fn (): ?string => $this->exchange?->code),
            'price_coverage' => $this->coverage ?? new MissingValue,
        ];
    }
}
