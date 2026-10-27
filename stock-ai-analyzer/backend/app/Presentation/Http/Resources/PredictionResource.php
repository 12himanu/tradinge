<?php

declare(strict_types=1);

namespace App\Presentation\Http\Resources;

use App\Domain\Prediction\Models\Prediction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Prediction */
final class PredictionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'analysis_id' => $this->analysis_id,
            'kind' => $this->kind->value,
            'scenario' => $this->scenario->value,
            'direction' => $this->direction->value,
            'timeframe' => $this->timeframe->value,
            'trigger_type' => $this->trigger_type->value,
            'trigger_price' => $this->number($this->trigger_price),
            'entry_price' => $this->number($this->entry_price),
            'stop_price' => $this->number($this->stop_price),
            'target_price_1' => $this->number($this->target_price_1),
            'target_price_2' => $this->number($this->target_price_2),
            'invalidation_price' => $this->number($this->invalidation_price),
            'reference_price' => $this->number($this->reference_price),
            'as_of' => $this->as_of->toIso8601String(),
            'horizon_bars' => $this->horizon_bars,
            'expires_at' => $this->expires_at->toIso8601String(),
            'setup_score' => $this->setup_score,
            'probability' => $this->number($this->probability),
            'risk_reward' => $this->number($this->risk_reward),
            'status' => $this->status->value,
            'activated_at' => $this->activated_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'outcome' => $this->whenLoaded('outcome', fn (): ?array => $this->outcome === null ? null : [
                'result' => $this->outcome->result,
                'evaluation_method' => $this->outcome->evaluation_method,
                'triggered_at' => $this->outcome->triggered_at?->toIso8601String(),
                'exit_at' => $this->outcome->exit_at?->toIso8601String(),
                'exit_price' => $this->number($this->outcome->exit_price),
                'return_pct' => $this->number($this->outcome->return_pct),
                'r_multiple' => $this->number($this->outcome->r_multiple),
                'mfe_pct' => $this->number($this->outcome->mfe_pct),
                'mae_pct' => $this->number($this->outcome->mae_pct),
                'bars_to_resolution' => $this->outcome->bars_to_resolution,
                'direction_correct' => $this->outcome->direction_correct,
                'ambiguous_bar' => $this->outcome->ambiguous_bar,
            ]),
        ];
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
