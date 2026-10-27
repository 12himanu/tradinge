<?php

declare(strict_types=1);

namespace App\Domain\Analysis\Models;

use App\Domain\Analysis\Enums\DetectionMethod;
use App\Domain\Analysis\Enums\PatternCategory;
use App\Domain\Analysis\Enums\PatternStatus;
use App\Domain\Shared\Enums\Bias;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $analysis_id
 * @property PatternCategory $category
 * @property string $type
 * @property string $direction
 * @property PatternStatus $status
 * @property numeric-string $confidence
 * @property DetectionMethod $detection_method
 * @property CarbonImmutable|null $start_time
 * @property CarbonImmutable|null $end_time
 * @property numeric-string|null $price_low
 * @property numeric-string|null $price_high
 * @property array<string, mixed>|null $key_points
 * @property string|null $confirmation_rule
 * @property numeric-string|null $measured_target
 * @property numeric-string|null $invalidation_price
 * @property string|null $evidence_id
 */
#[Fillable([
    'analysis_id', 'category', 'type', 'direction', 'status', 'confidence', 'detection_method', 'start_time',
    'end_time', 'price_low', 'price_high', 'key_points', 'confirmation_rule', 'measured_target',
    'invalidation_price', 'evidence_id',
])]
class AnalysisPattern extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => PatternCategory::class,
            'direction' => Bias::class,
            'status' => PatternStatus::class,
            'confidence' => 'decimal:3',
            'detection_method' => DetectionMethod::class,
            'start_time' => 'datetime',
            'end_time' => 'datetime',
            'price_low' => 'decimal:4',
            'price_high' => 'decimal:4',
            'key_points' => 'array',
            'measured_target' => 'decimal:4',
            'invalidation_price' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<Analysis, $this> */
    public function analysis(): BelongsTo
    {
        return $this->belongsTo(Analysis::class);
    }
}
