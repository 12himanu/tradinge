<?php

declare(strict_types=1);

namespace App\Domain\Journal\Models;

use App\Domain\Analysis\Models\Analysis;
use App\Domain\Chart\Models\ChartUpload;
use App\Domain\Identity\Models\User;
use App\Domain\Journal\Enums\ExitReason;
use App\Domain\Journal\Enums\TradeSide;
use App\Domain\Journal\Enums\TradeStatus;
use App\Domain\Market\Models\Stock;
use App\Domain\Prediction\Models\Prediction;
use Carbon\CarbonImmutable;
use Database\Factories\TradeJournalEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $id
 * @property int $user_id
 * @property int|null $stock_id
 * @property string|null $analysis_id
 * @property string|null $prediction_id
 * @property string|null $entry_chart_upload_id
 * @property TradeSide $side
 * @property TradeStatus $status
 * @property CarbonImmutable|null $entry_date
 * @property numeric-string $entry_price
 * @property numeric-string $quantity
 * @property numeric-string|null $stop_loss
 * @property numeric-string|null $target_price
 * @property string|null $strategy
 * @property string|null $reason
 * @property list<string>|null $tags
 * @property int|null $confidence_level
 * @property CarbonImmutable|null $exit_date
 * @property numeric-string|null $exit_price
 * @property ExitReason|null $exit_reason
 * @property numeric-string|null $fees
 * @property numeric-string|null $gross_pnl
 * @property numeric-string|null $net_pnl
 * @property numeric-string|null $return_pct
 * @property numeric-string|null $r_multiple
 * @property bool|null $followed_plan
 * @property string|null $lessons
 * @property CarbonImmutable|null $created_at
 * @property-read Stock|null $stock
 * @property-read Analysis|null $analysis
 */
#[Fillable([
    'user_id', 'stock_id', 'analysis_id', 'prediction_id', 'entry_chart_upload_id', 'side', 'status',
    'entry_date', 'entry_price', 'quantity', 'stop_loss', 'target_price', 'strategy', 'reason', 'tags',
    'confidence_level', 'exit_date', 'exit_price', 'exit_reason', 'fees', 'gross_pnl', 'net_pnl',
    'return_pct', 'r_multiple', 'followed_plan', 'lessons',
])]
#[UseFactory(TradeJournalEntryFactory::class)]
class TradeJournalEntry extends Model
{
    /** @use HasFactory<TradeJournalEntryFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'side' => TradeSide::class,
            'status' => TradeStatus::class,
            'entry_date' => 'date',
            'entry_price' => 'decimal:4',
            'quantity' => 'decimal:2',
            'stop_loss' => 'decimal:4',
            'target_price' => 'decimal:4',
            'tags' => 'array',
            'confidence_level' => 'integer',
            'exit_date' => 'date',
            'exit_price' => 'decimal:4',
            'exit_reason' => ExitReason::class,
            'fees' => 'decimal:2',
            'gross_pnl' => 'decimal:2',
            'net_pnl' => 'decimal:2',
            'return_pct' => 'decimal:4',
            'r_multiple' => 'decimal:2',
            'followed_plan' => 'boolean',
        ];
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

    /** @return BelongsTo<Analysis, $this> */
    public function analysis(): BelongsTo
    {
        return $this->belongsTo(Analysis::class);
    }

    /** @return BelongsTo<Prediction, $this> */
    public function prediction(): BelongsTo
    {
        return $this->belongsTo(Prediction::class);
    }

    /** @return BelongsTo<ChartUpload, $this> */
    public function entryChartUpload(): BelongsTo
    {
        return $this->belongsTo(ChartUpload::class, 'entry_chart_upload_id');
    }
}
