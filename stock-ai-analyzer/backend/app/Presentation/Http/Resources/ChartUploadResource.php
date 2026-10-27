<?php

declare(strict_types=1);

namespace App\Presentation\Http\Resources;

use App\Domain\Chart\Models\ChartUpload;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shape: docs/05-api-spec.md §3.1.
 *
 * @mixin ChartUpload
 */
final class ChartUploadResource extends JsonResource
{
    private bool $deduplicated = false;

    /**
     * A setter rather than a constructor argument: collection() passes the item key as the second argument.
     */
    public function withDeduplicated(bool $deduplicated): self
    {
        $this->deduplicated = $deduplicated;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $stock = $this->relationLoaded('stock') ? $this->stock : null;
        $analysis = $this->relationLoaded('latestAnalysis') ? $this->latestAnalysis : null;

        // An analysis of the instrument this chart *used* to be labelled as is not this chart's
        // analysis any more: a re-upload that corrected the symbol must not offer the old report,
        // which the UI links straight to.
        if ($analysis !== null && $analysis->stock_id !== $this->stock_id) {
            $analysis = null;
        }

        return [
            'id' => $this->id,
            // Backed enums serialize to their value.
            'status' => $this->status,
            'declared' => [
                'symbol' => $this->declared_symbol,
                'exchange' => $this->declared_exchange,
                'timeframe' => $this->declared_timeframe,
                'trading_style' => $this->declared_style,
            ],
            'stock' => $stock === null ? null : [
                'id' => $stock->id,
                'symbol' => $stock->symbol,
                'name' => $stock->name,
            ],
            'image' => [
                'url' => route('api.charts.image', ['chartUpload' => $this->id], absolute: false),
                'width' => $this->width,
                'height' => $this->height,
                'size_bytes' => $this->size_bytes,
            ],
            'original_filename' => $this->original_filename,
            'deduplicated' => $this->deduplicated,
            'analysis' => $analysis === null ? null : [
                'id' => $analysis->id,
                'status' => $analysis->status,
            ],
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
