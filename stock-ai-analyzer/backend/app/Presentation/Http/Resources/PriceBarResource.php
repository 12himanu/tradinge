<?php

declare(strict_types=1);

namespace App\Presentation\Http\Resources;

use App\Domain\Market\Models\PriceBar;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PriceBar
 */
final class PriceBarResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'time' => CarbonImmutable::parse($this->bar_time)->toIso8601ZuluString(),
            'open' => (float) $this->open,
            'high' => (float) $this->high,
            'low' => (float) $this->low,
            'close' => (float) $this->close,
            'volume' => $this->volume === null ? null : (float) $this->volume,
            'turnover' => $this->turnover === null ? null : (float) $this->turnover,
        ];
    }
}
