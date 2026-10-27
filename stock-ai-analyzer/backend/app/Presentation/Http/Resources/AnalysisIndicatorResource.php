<?php

declare(strict_types=1);

namespace App\Presentation\Http\Resources;

use App\Domain\Analysis\Models\AnalysisIndicator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AnalysisIndicator */
final class AnalysisIndicatorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'params' => $this->params,
            'value' => is_numeric($this->value) ? (float) $this->value : null,
            'components' => $this->components,
            'signal' => $this->signal,
            'status' => $this->status->value,
            'unavailable_reason' => $this->unavailable_reason,
            'evidence_id' => $this->evidence_id,
        ];
    }
}
