<?php

declare(strict_types=1);

namespace App\Presentation\Http\Resources;

use App\Domain\Market\Models\MarketScan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MarketScan
 */
final class MarketScanResource extends JsonResource
{
    /**
     * `detail` carries the drawer payload — signals, base rates, levels, plans, factors — and is
     * only included when a single scan was asked for, so the scanner list stays small.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $detail = (bool) ($this->additional['detail'] ?? false);

        return [
            'id' => $this->id,
            'stock' => [
                'id' => $this->stock?->id,
                'symbol' => $this->stock?->symbol,
                'name' => $this->stock?->name,
                'sector' => $this->stock?->sector,
            ],
            'scanned_on' => $this->scanned_on->toDateString(),
            'as_of' => $this->as_of?->toIso8601ZuluString(),
            'timeframe' => $this->timeframe->value,
            'trading_style' => $this->trading_style->value,
            'last_price' => $this->nullableFloat($this->last_price),
            'bias' => $this->bias?->value,
            'setup_score' => $this->setup_score,
            'decision' => $this->decision?->value,
            'decision_rationale' => $this->decision_rationale,
            'structure' => $this->structure,
            'last_event' => $this->last_event,
            'range_zone' => $this->range_zone,
            'range_position' => $this->nullableFloat($this->range_position),
            'trap_count' => $this->trap_count,
            'opportunity_count' => $this->opportunity_count,
            'has_viable_plan' => $this->has_viable_plan,
            'bar_count' => $this->bar_count,
            'data_quality' => $this->nullableFloat($this->data_quality),
            'engine_version' => $this->engine_version,
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            // A setup score ranks; it never becomes a probability here. The only probabilities on
            // this payload are base rates, each carrying the sample it was measured on.
            'calibrated_probability' => null,
            ...$detail ? [
                'signals' => $this->signals,
                'base_rates' => $this->base_rates,
                'outlook' => $this->outlook,
                'levels' => $this->levels,
                'plans' => $this->plans,
                'factors' => $this->factors,
                'warnings' => $this->warnings,
                'report_markdown' => $this->report_markdown,
            ] : [
                // The list still needs enough to render the badges without the full payload.
                'top_signals' => array_slice($this->signals, 0, 3),
            ],
        ];
    }

    private function nullableFloat(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
