<?php

declare(strict_types=1);

namespace App\Domain\Prediction\Models;

use App\Domain\Analysis\Models\Analysis;
use App\Domain\Identity\Models\User;
use App\Domain\Journal\Models\TradeJournalEntry;
use App\Domain\Market\Models\Stock;
use App\Domain\Prediction\Enums\PredictionKind;
use App\Domain\Prediction\Enums\PredictionStatus;
use App\Domain\Prediction\Enums\Scenario;
use App\Domain\Prediction\Enums\TradeDirection;
use App\Domain\Prediction\Enums\TriggerType;
use App\Domain\Shared\Enums\Timeframe;
use Carbon\CarbonImmutable;
use Database\Factories\PredictionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A machine-checkable statement from an analysis, scored against real bars by the outcome evaluator.
 *
 * @property string $id
 * @property string $analysis_id
 * @property int $user_id
 * @property int|null $stock_id
 * @property PredictionKind $kind
 * @property Scenario $scenario
 * @property TradeDirection $direction
 * @property Timeframe $timeframe
 * @property TriggerType $trigger_type
 * @property numeric-string|null $trigger_price
 * @property numeric-string|null $entry_price
 * @property numeric-string|null $stop_price
 * @property numeric-string|null $target_price_1
 * @property numeric-string|null $target_price_2
 * @property numeric-string|null $invalidation_price
 * @property numeric-string $reference_price
 * @property CarbonImmutable $as_of
 * @property int $horizon_bars
 * @property CarbonImmutable $expires_at
 * @property int|null $setup_score
 * @property numeric-string|null $probability
 * @property numeric-string|null $risk_reward
 * @property PredictionStatus $status
 * @property CarbonImmutable|null $activated_at
 * @property CarbonImmutable|null $resolved_at
 * @property string $engine_version
 * @property-read Analysis|null $analysis
 * @property-read Outcome|null $outcome
 * @property-read Stock|null $stock
 * @property CarbonImmutable|null $created_at
 */
#[Fillable([
    'analysis_id', 'user_id', 'stock_id', 'kind', 'scenario', 'direction', 'timeframe', 'trigger_type',
    'trigger_price', 'entry_price', 'stop_price', 'target_price_1', 'target_price_2', 'invalidation_price',
    'reference_price', 'as_of', 'horizon_bars', 'expires_at', 'setup_score', 'probability', 'risk_reward',
    'status', 'activated_at', 'resolved_at', 'engine_version',
])]
#[UseFactory(PredictionFactory::class)]
class Prediction extends Model
{
    /** @use HasFactory<PredictionFactory> */
    use HasFactory, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => PredictionKind::class,
            'scenario' => Scenario::class,
            'direction' => TradeDirection::class,
            'timeframe' => Timeframe::class,
            'trigger_type' => TriggerType::class,
            'trigger_price' => 'decimal:4',
            'entry_price' => 'decimal:4',
            'stop_price' => 'decimal:4',
            'target_price_1' => 'decimal:4',
            'target_price_2' => 'decimal:4',
            'invalidation_price' => 'decimal:4',
            'reference_price' => 'decimal:4',
            'as_of' => 'datetime',
            'horizon_bars' => 'integer',
            'expires_at' => 'datetime',
            'setup_score' => 'integer',
            'probability' => 'decimal:3',
            'risk_reward' => 'decimal:2',
            'status' => PredictionStatus::class,
            'activated_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Analysis, $this> */
    public function analysis(): BelongsTo
    {
        return $this->belongsTo(Analysis::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Stock, $this> */
    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }

    /** @return HasOne<Outcome, $this> */
    public function outcome(): HasOne
    {
        return $this->hasOne(Outcome::class);
    }

    /** @return HasMany<TradeJournalEntry, $this> */
    public function tradeJournalEntries(): HasMany
    {
        return $this->hasMany(TradeJournalEntry::class);
    }
}
