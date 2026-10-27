<?php

declare(strict_types=1);

namespace App\Domain\Analysis\Models;

use App\Domain\Analysis\Enums\IndicatorStatus;
use App\Domain\Shared\Enums\Bias;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $analysis_id
 * @property string $key
 * @property string $name
 * @property array<string, mixed> $params
 * @property numeric-string|null $value
 * @property array<string, mixed>|null $components
 * @property string|null $signal
 * @property IndicatorStatus $status
 * @property string|null $unavailable_reason
 * @property string|null $evidence_id
 */
#[Fillable([
    'analysis_id', 'key', 'name', 'params', 'value', 'components', 'signal', 'status', 'unavailable_reason', 'evidence_id',
])]
class AnalysisIndicator extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'params' => 'array',
            'value' => 'decimal:6',
            'components' => 'array',
            'signal' => Bias::class,
            'status' => IndicatorStatus::class,
        ];
    }

    /** @return BelongsTo<Analysis, $this> */
    public function analysis(): BelongsTo
    {
        return $this->belongsTo(Analysis::class);
    }
}
