<?php

declare(strict_types=1);

namespace App\Domain\Analysis\Models;

use App\Domain\Analysis\Enums\LevelSource;
use App\Domain\Analysis\Enums\LevelType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $analysis_id
 * @property LevelType $type
 * @property numeric-string $price_low
 * @property numeric-string $price_high
 * @property numeric-string $strength
 * @property int|null $touches
 * @property LevelSource $source
 * @property int|null $pixel_y
 * @property string|null $evidence_id
 */
#[Fillable(['analysis_id', 'type', 'price_low', 'price_high', 'strength', 'touches', 'source', 'pixel_y', 'evidence_id'])]
class AnalysisLevel extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => LevelType::class,
            'price_low' => 'decimal:4',
            'price_high' => 'decimal:4',
            'strength' => 'decimal:3',
            'touches' => 'integer',
            'source' => LevelSource::class,
            'pixel_y' => 'integer',
        ];
    }

    /** @return BelongsTo<Analysis, $this> */
    public function analysis(): BelongsTo
    {
        return $this->belongsTo(Analysis::class);
    }
}
