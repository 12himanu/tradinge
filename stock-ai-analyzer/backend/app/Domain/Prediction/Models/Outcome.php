<?php

declare(strict_types=1);

namespace App\Domain\Prediction\Models;

use App\Domain\Prediction\Enums\EvaluationMethod;
use App\Domain\Prediction\Enums\OutcomeResult;
use Carbon\CarbonImmutable;
use Database\Factories\OutcomeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $prediction_id
 * @property OutcomeResult $result
 * @property EvaluationMethod $evaluation_method
 * @property CarbonImmutable|null $triggered_at
 * @property CarbonImmutable|null $exit_at
 * @property numeric-string|null $exit_price
 * @property numeric-string|null $return_pct
 * @property numeric-string|null $r_multiple
 * @property numeric-string|null $mfe_pct
 * @property numeric-string|null $mae_pct
 * @property int|null $bars_to_resolution
 * @property bool|null $direction_correct
 * @property numeric-string|null $brier_component
 * @property bool $ambiguous_bar
 * @property CarbonImmutable $evaluated_through
 * @property string|null $notes
 */
#[Fillable([
    'prediction_id', 'result', 'evaluation_method', 'triggered_at', 'exit_at', 'exit_price', 'return_pct',
    'r_multiple', 'mfe_pct', 'mae_pct', 'bars_to_resolution', 'direction_correct', 'brier_component',
    'ambiguous_bar', 'evaluated_through', 'notes',
])]
#[UseFactory(OutcomeFactory::class)]
class Outcome extends Model
{
    /** @use HasFactory<OutcomeFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'result' => OutcomeResult::class,
            'evaluation_method' => EvaluationMethod::class,
            'triggered_at' => 'datetime',
            'exit_at' => 'datetime',
            'exit_price' => 'decimal:4',
            'return_pct' => 'decimal:4',
            'r_multiple' => 'decimal:2',
            'mfe_pct' => 'decimal:4',
            'mae_pct' => 'decimal:4',
            'bars_to_resolution' => 'integer',
            'direction_correct' => 'boolean',
            'brier_component' => 'decimal:5',
            'ambiguous_bar' => 'boolean',
            'evaluated_through' => 'datetime',
        ];
    }

    /** @return BelongsTo<Prediction, $this> */
    public function prediction(): BelongsTo
    {
        return $this->belongsTo(Prediction::class);
    }
}
