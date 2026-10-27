<?php

declare(strict_types=1);

namespace App\Presentation\Http\Requests;

use App\Domain\Journal\Enums\TradeSide;
use App\Domain\Journal\Enums\TradeStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class StoreTradeRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'stock_id' => ['required', 'integer', 'exists:stocks,id'],
            'analysis_id' => ['nullable', 'ulid', 'exists:analyses,id'],
            'prediction_id' => ['nullable', 'ulid', 'exists:predictions,id'],
            'side' => ['required', Rule::enum(TradeSide::class)],
            'status' => ['sometimes', Rule::enum(TradeStatus::class)],
            'entry_date' => ['required', 'date'],
            'entry_price' => ['required', 'numeric', 'gt:0'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'stop_loss' => ['nullable', 'numeric', 'gt:0'],
            'target_price' => ['nullable', 'numeric', 'gt:0'],
            'strategy' => ['nullable', 'string', 'max:100'],
            // The schema requires it: a journal entry without a reason teaches nothing later.
            'reason' => ['required', 'string', 'max:2000'],
            'tags' => ['nullable', 'array', 'max:10'],
            'tags.*' => ['string', 'max:30'],
            'confidence_level' => ['nullable', 'integer', 'between:1,5'],
        ];
    }

    /**
     * A stop on the wrong side of the entry is not a stop. The database enforces this too; catching
     * it here turns a 500 into a field error the form can show.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $entry = (float) $this->float('entry_price');
                $stop = $this->has('stop_loss') ? $this->float('stop_loss') : null;
                $target = $this->has('target_price') ? $this->float('target_price') : null;
                $long = $this->string('side')->toString() === 'long';

                if ($stop !== null && $stop > 0 && ($long ? $stop >= $entry : $stop <= $entry)) {
                    $validator->errors()->add(
                        'stop_loss',
                        $long ? 'The stop must be below the entry for a long.' : 'The stop must be above the entry for a short.'
                    );
                }

                if ($target !== null && $target > 0 && ($long ? $target <= $entry : $target >= $entry)) {
                    $validator->errors()->add(
                        'target_price',
                        $long ? 'The target must be above the entry for a long.' : 'The target must be below the entry for a short.'
                    );
                }
            },
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function values(): array
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();
        $validated['status'] ??= TradeStatus::Open->value;
        $validated['tags'] = $validated['tags'] ?? [];

        return $validated;
    }
}
