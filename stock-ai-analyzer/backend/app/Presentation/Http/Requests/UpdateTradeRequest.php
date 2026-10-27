<?php

declare(strict_types=1);

namespace App\Presentation\Http\Requests;

use App\Domain\Journal\Enums\TradeStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateTradeRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(TradeStatus::class)],
            'stop_loss' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            'target_price' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            'strategy' => ['sometimes', 'nullable', 'string', 'max:100'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'tags' => ['sometimes', 'array', 'max:10'],
            'tags.*' => ['string', 'max:30'],
            'confidence_level' => ['sometimes', 'nullable', 'integer', 'between:1,5'],
            'lessons' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function values(): array
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return $validated;
    }
}
