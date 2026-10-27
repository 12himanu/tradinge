<?php

declare(strict_types=1);

namespace App\Presentation\Http\Resources;

use App\Domain\Journal\Models\TradeJournalEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TradeJournalEntry */
final class TradeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'stock' => $this->whenLoaded('stock', fn () => [
                'id' => $this->stock?->id,
                'symbol' => $this->stock?->symbol,
                'name' => $this->stock?->name,
            ]),
            'analysis_id' => $this->analysis_id,
            'prediction_id' => $this->prediction_id,
            'side' => $this->side,
            'status' => $this->status,
            'entry_date' => $this->entry_date?->toDateString(),
            'entry_price' => $this->number($this->entry_price),
            'quantity' => $this->number($this->quantity),
            'stop_loss' => $this->number($this->stop_loss),
            'target_price' => $this->number($this->target_price),
            'strategy' => $this->strategy,
            'reason' => $this->reason,
            'tags' => $this->tags ?? [],
            'confidence_level' => $this->confidence_level,
            'exit_date' => $this->exit_date?->toDateString(),
            'exit_price' => $this->number($this->exit_price),
            'exit_reason' => $this->exit_reason,
            'fees' => $this->number($this->fees),
            'gross_pnl' => $this->number($this->gross_pnl),
            'net_pnl' => $this->number($this->net_pnl),
            'return_pct' => $this->number($this->return_pct),
            'r_multiple' => $this->number($this->r_multiple),
            'followed_plan' => $this->followed_plan,
            'lessons' => $this->lessons,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
