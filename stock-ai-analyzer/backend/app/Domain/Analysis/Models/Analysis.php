<?php

declare(strict_types=1);

namespace App\Domain\Analysis\Models;

use App\Domain\Analysis\Enums\AnalysisStatus;
use App\Domain\Analysis\Enums\DataTier;
use App\Domain\Analysis\Enums\Decision;
use App\Domain\Analysis\Enums\NarrativeSource;
use App\Domain\Analysis\Enums\PositionStatus;
use App\Domain\Chart\Models\ChartUpload;
use App\Domain\Identity\Models\User;
use App\Domain\Market\Models\Stock;
use App\Domain\Prediction\Models\Prediction;
use App\Domain\Shared\Enums\Bias;
use App\Domain\Shared\Enums\Timeframe;
use App\Domain\Shared\Enums\TradingStyle;
use Carbon\CarbonImmutable;
use Database\Factories\AnalysisFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One run of the AI engine over a chart upload. Every number in `report` traces to `evidence`
 * (docs/04-ai-agent-workflow.md §1).
 *
 * @property string $id
 * @property int $user_id
 * @property string $chart_upload_id
 * @property int|null $stock_id
 * @property Timeframe $timeframe
 * @property TradingStyle $trading_style
 * @property PositionStatus $position_status
 * @property numeric-string|null $position_entry_price
 * @property numeric-string|null $position_quantity
 * @property string|null $user_thesis
 * @property AnalysisStatus $status
 * @property string|null $current_stage
 * @property DataTier|null $data_tier
 * @property numeric-string|null $data_quality
 * @property CarbonImmutable|null $as_of
 * @property int|null $price_bar_count
 * @property numeric-string|null $last_price
 * @property Bias|null $bias
 * @property int|null $setup_score
 * @property numeric-string|null $calibrated_probability
 * @property Decision|null $decision
 * @property bool $decision_limited
 * @property NarrativeSource|null $narrative_source
 * @property array<string, mixed>|null $report
 * @property string|null $report_markdown
 * @property array<string, mixed>|null $evidence
 * @property list<string>|null $warnings
 * @property string|null $engine_version
 * @property string|null $prompt_version
 * @property array<string, mixed>|null $models
 * @property string|null $error_code
 * @property string|null $error_message
 * @property-read Stock|null $stock
 * @property-read User|null $user
 * @property-read ChartUpload|null $chartUpload
 * @property CarbonImmutable|null $queued_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 * @property int|null $duration_ms
 * @property CarbonImmutable|null $created_at
 */
#[Fillable([
    'user_id', 'chart_upload_id', 'stock_id', 'timeframe', 'trading_style', 'position_status',
    'position_entry_price', 'position_quantity', 'user_thesis', 'status', 'current_stage', 'data_tier',
    'data_quality', 'as_of', 'price_bar_count', 'last_price', 'bias', 'setup_score', 'calibrated_probability', 'decision',
    'decision_limited', 'narrative_source', 'report', 'report_markdown', 'evidence', 'warnings',
    'engine_version', 'prompt_version', 'models', 'error_code', 'error_message', 'queued_at', 'started_at',
    'completed_at', 'duration_ms',
])]
#[UseFactory(AnalysisFactory::class)]
class Analysis extends Model
{
    /** @use HasFactory<AnalysisFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    protected $table = 'analyses';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'timeframe' => Timeframe::class,
            'trading_style' => TradingStyle::class,
            'position_status' => PositionStatus::class,
            'position_entry_price' => 'decimal:4',
            'position_quantity' => 'decimal:2',
            'status' => AnalysisStatus::class,
            'data_tier' => DataTier::class,
            'data_quality' => 'decimal:3',
            'as_of' => 'datetime',
            'price_bar_count' => 'integer',
            'last_price' => 'decimal:4',
            'bias' => Bias::class,
            'setup_score' => 'integer',
            'calibrated_probability' => 'decimal:3',
            'decision' => Decision::class,
            'decision_limited' => 'boolean',
            'narrative_source' => NarrativeSource::class,
            'report' => 'array',
            'evidence' => 'array',
            'warnings' => 'array',
            'models' => 'array',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'duration_ms' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<ChartUpload, $this> */
    public function chartUpload(): BelongsTo
    {
        return $this->belongsTo(ChartUpload::class);
    }

    /** @return BelongsTo<Stock, $this> */
    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }

    /** @return HasMany<AnalysisIndicator, $this> */
    public function indicators(): HasMany
    {
        return $this->hasMany(AnalysisIndicator::class);
    }

    /** @return HasMany<AnalysisPattern, $this> */
    public function patterns(): HasMany
    {
        return $this->hasMany(AnalysisPattern::class);
    }

    /** @return HasMany<AnalysisLevel, $this> */
    public function levels(): HasMany
    {
        return $this->hasMany(AnalysisLevel::class);
    }

    /** @return HasMany<Prediction, $this> */
    public function predictions(): HasMany
    {
        return $this->hasMany(Prediction::class);
    }

    /** @return HasMany<AnalysisFeedback, $this> */
    public function feedback(): HasMany
    {
        return $this->hasMany(AnalysisFeedback::class);
    }
}
