<?php

declare(strict_types=1);

namespace App\Presentation\Http\Resources;

use App\Domain\Analysis\Models\AnalysisLevel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AnalysisLevel */
final class AnalysisLevelResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => $this->type->value,
            'price_low' => (float) $this->price_low,
            'price_high' => (float) $this->price_high,
            'strength' => (float) $this->strength,
            'touches' => $this->touches,
            'source' => $this->source->value,
            'pixel_y' => $this->pixel_y,
            'evidence_id' => $this->evidence_id,
        ];
    }
}
