<?php

declare(strict_types=1);

namespace App\Presentation\Http\Requests;

use App\Domain\Shared\Enums\Timeframe;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PriceBarsRequest extends FormRequest
{
    public const int MAX_BARS = 5000;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'timeframe' => ['sometimes', Rule::enum(Timeframe::class)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'limit' => ['nullable', 'integer', 'between:1,'.self::MAX_BARS],
        ];
    }

    public function timeframe(): Timeframe
    {
        return $this->enum('timeframe', Timeframe::class) ?? Timeframe::D1;
    }

    public function from(): ?CarbonImmutable
    {
        return $this->filled('from') ? CarbonImmutable::parse($this->string('from')->toString(), 'UTC')->startOfDay() : null;
    }

    public function to(): ?CarbonImmutable
    {
        return $this->filled('to') ? CarbonImmutable::parse($this->string('to')->toString(), 'UTC')->endOfDay() : null;
    }

    public function limit(): int
    {
        return $this->filled('limit') ? $this->integer('limit') : self::MAX_BARS;
    }
}
