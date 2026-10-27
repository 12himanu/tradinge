<?php

declare(strict_types=1);

namespace App\Presentation\Http\Resources;

use App\Domain\Analysis\Models\Analysis;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The full analysis, as the report page reads it (docs/05-api-spec.md §3.2).
 *
 * @mixin Analysis
 */
final class AnalysisResource extends JsonResource
{
    private bool $withDetail = true;

    public function withoutDetail(): self
    {
        $this->withDetail = false;

        return $this;
    }

    /**
     * A listing: the header fields only. The report and evidence are large, and history pages
     * would ship megabytes of prose nobody reads.
     */
    public static function summaries(mixed $resource): AnonymousResourceCollection
    {
        $collection = self::collection($resource);
        $collection->collection?->transform(static fn (self $item): self => $item->withoutDetail());

        return $collection;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $summary = [
            'id' => $this->id,
            'status' => $this->status->value,
            'current_stage' => $this->current_stage,
            'chart_upload_id' => $this->chart_upload_id,
            'stock' => $this->whenLoaded('stock', fn () => new StockResource($this->stock)),
            'timeframe' => $this->timeframe->value,
            'trading_style' => $this->trading_style->value,
            'position_status' => $this->position_status->value,
            'data_tier' => $this->data_tier?->value,
            'data_quality' => $this->nullableFloat($this->data_quality),
            'as_of' => $this->as_of?->toIso8601String(),
            'price_bar_count' => $this->price_bar_count,
            'last_price' => $this->nullableFloat($this->last_price),
            'bias' => $this->bias?->value,
            'setup_score' => $this->setup_score,
            'calibrated_probability' => $this->nullableFloat($this->calibrated_probability),
            'decision' => $this->decision?->value,
            'decision_limited' => (bool) $this->decision_limited,
            'narrative_source' => $this->narrative_source?->value,
            'engine_version' => $this->engine_version,
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'duration_ms' => $this->duration_ms,
            'queued_at' => $this->queued_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];

        if (! $this->withDetail) {
            return $summary;
        }

        return [
            ...$summary,
            'user_thesis' => $this->user_thesis,
            'report' => $this->report,
            'report_markdown' => $this->report_markdown,
            'warnings' => $this->warnings ?? [],
            'evidence' => $this->evidence['items'] ?? [],
            'score' => $this->evidence['score'] ?? null,
            'smc' => $this->evidence['smc'] ?? null,
            'timings' => $this->evidence['timings'] ?? [],
            'indicators' => $this->whenLoaded(
                'indicators',
                fn () => AnalysisIndicatorResource::collection($this->indicators)
            ),
            'patterns' => $this->whenLoaded(
                'patterns',
                fn () => AnalysisPatternResource::collection($this->patterns)
            ),
            'levels' => $this->whenLoaded(
                'levels',
                fn () => AnalysisLevelResource::collection($this->levels)
            ),
            'predictions' => $this->whenLoaded(
                'predictions',
                fn () => PredictionResource::collection($this->predictions)
            ),
        ];
    }

    private function nullableFloat(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
