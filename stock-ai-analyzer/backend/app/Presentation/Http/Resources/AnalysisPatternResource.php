<?php

declare(strict_types=1);

namespace App\Presentation\Http\Resources;

use App\Domain\Analysis\Models\AnalysisPattern;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AnalysisPattern */
final class AnalysisPatternResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'category' => $this->category->value,
            'type' => $this->type,
            'direction' => $this->direction,
            'status' => $this->status->value,
            'confidence' => (float) $this->confidence,
            'detection_method' => $this->detection_method->value,
            'start_time' => $this->start_time?->toIso8601String(),
            'end_time' => $this->end_time?->toIso8601String(),
            'price_low' => is_numeric($this->price_low) ? (float) $this->price_low : null,
            'price_high' => is_numeric($this->price_high) ? (float) $this->price_high : null,
            'key_points' => $this->key_points,
            'confirmation_rule' => $this->confirmation_rule,
            'measured_target' => is_numeric($this->measured_target) ? (float) $this->measured_target : null,
            'invalidation_price' => is_numeric($this->invalidation_price) ? (float) $this->invalidation_price : null,
            'evidence_id' => $this->evidence_id,
        ];
    }
}
