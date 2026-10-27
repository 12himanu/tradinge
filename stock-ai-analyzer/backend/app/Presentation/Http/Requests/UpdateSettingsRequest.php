<?php

declare(strict_types=1);

namespace App\Presentation\Http\Requests;

use App\Domain\Shared\Enums\TradingStyle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Account settings that feed the engine's sizing and viability gates (docs/05-api-spec.md).
 */
final class UpdateSettingsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'account_size' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:1000000000'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'risk_per_trade_pct' => ['sometimes', 'numeric', 'gt:0', 'max:10'],
            'max_position_pct' => ['sometimes', 'numeric', 'gt:0', 'max:100'],
            'default_style' => ['sometimes', Rule::enum(TradingStyle::class)],
            'min_rr_override' => ['sometimes', 'nullable', 'numeric', 'gte:1', 'max:10'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return $validated;
    }
}
