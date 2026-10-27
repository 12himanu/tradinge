<?php

declare(strict_types=1);

namespace App\Presentation\Http\Requests;

use App\Domain\Journal\Enums\ExitReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CloseTradeRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'exit_price' => ['required', 'numeric', 'gt:0'],
            'exit_date' => ['nullable', 'date'],
            'exit_reason' => ['required', Rule::enum(ExitReason::class)],
            'fees' => ['nullable', 'numeric', 'min:0'],
            'followed_plan' => ['sometimes', 'boolean'],
            'lessons' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
