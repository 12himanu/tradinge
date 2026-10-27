<?php

declare(strict_types=1);

namespace App\Presentation\Http\Requests;

use App\Domain\Analysis\Enums\Decision;
use App\Domain\Shared\Enums\Bias;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Filters for `GET /market-scan`.
 */
final class MarketScanRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'scanned_on' => ['nullable', 'date_format:Y-m-d'],
            'bias' => ['nullable', 'string', 'in:'.implode(',', Bias::values())],
            'decision' => ['nullable', 'string', 'in:'.implode(',', Decision::values())],
            'range_zone' => ['nullable', 'string', 'in:premium,discount,equilibrium'],
            'min_score' => ['nullable', 'integer', 'between:0,100'],
            'traps' => ['nullable', 'boolean'],
            'opportunities' => ['nullable', 'boolean'],
            'viable' => ['nullable', 'boolean'],
            'q' => ['nullable', 'string', 'max:64'],
            'sort' => ['nullable', 'string', 'in:score,score_asc,traps,opportunities,symbol'],
            'per_page' => ['nullable', 'integer', 'between:1,200'],
        ];
    }

    public function scannedOn(): ?string
    {
        return $this->stringOrNull('scanned_on');
    }

    public function bias(): ?string
    {
        return $this->stringOrNull('bias');
    }

    public function decision(): ?string
    {
        return $this->stringOrNull('decision');
    }

    public function rangeZone(): ?string
    {
        return $this->stringOrNull('range_zone');
    }

    public function minScore(): ?int
    {
        $value = $this->validated('min_score');

        return is_numeric($value) ? (int) $value : null;
    }

    public function hasTraps(): bool
    {
        return $this->boolean('traps');
    }

    public function hasOpportunities(): bool
    {
        return $this->boolean('opportunities');
    }

    public function viablePlanOnly(): bool
    {
        return $this->boolean('viable');
    }

    public function search(): ?string
    {
        $term = $this->stringOrNull('q');

        return $term === null ? null : trim($term);
    }

    public function sort(): string
    {
        return $this->stringOrNull('sort') ?? 'score';
    }

    public function perPage(): int
    {
        $value = $this->validated('per_page');

        return is_numeric($value) ? (int) $value : 50;
    }

    private function stringOrNull(string $key): ?string
    {
        $value = $this->validated($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
