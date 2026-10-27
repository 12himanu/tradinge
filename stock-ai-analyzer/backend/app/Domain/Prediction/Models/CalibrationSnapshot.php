<?php

declare(strict_types=1);

namespace App\Domain\Prediction\Models;

use App\Domain\Identity\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Setup-score → empirical hit-rate mapping for one engine version and scope (Phase 6).
 *
 * A row is a dated record of what the curve looked like, never a cache: the live curve is always
 * recomputed from outcomes, so a late-corrected bar moves it. Snapshots exist to answer "what was
 * the app claiming back then", which is the only way to tell whether calibration improved.
 *
 * @property int $id
 * @property int $user_id
 * @property string $engine_version
 * @property string $scope
 * @property string $method
 * @property int $sample_size
 * @property numeric-string|null $brier_score
 * @property list<array<string, mixed>> $buckets
 * @property CarbonImmutable $created_at
 */
#[Fillable(['engine_version', 'scope', 'method', 'sample_size', 'brier_score', 'buckets'])]
class CalibrationSnapshot extends Model
{
    public const null UPDATED_AT = null;

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sample_size' => 'integer',
            'created_at' => 'datetime',
            'brier_score' => 'decimal:5',
            'buckets' => 'array',
        ];
    }
}
