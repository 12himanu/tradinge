<?php

declare(strict_types=1);

namespace App\Domain\Market\Models;

use App\Domain\Analysis\Enums\Decision;
use App\Domain\Shared\Enums\Bias;
use App\Domain\Shared\Enums\Timeframe;
use App\Domain\Shared\Enums\TradingStyle;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One engine read of one stock on one trading day, for the market scanner.
 *
 * Reference data, not the user's record: a scan emits no predictions and never reaches the
 * calibration curve (see the migration for why).
 *
 * @property int $id
 * @property int $stock_id
 * @property CarbonImmutable $scanned_on
 * @property CarbonImmutable|null $as_of
 * @property Timeframe $timeframe
 * @property TradingStyle $trading_style
 * @property numeric-string|null $last_price
 * @property Bias|null $bias
 * @property int|null $setup_score
 * @property Decision|null $decision
 * @property string|null $decision_rationale
 * @property string|null $structure
 * @property string|null $last_event
 * @property string|null $range_zone
 * @property numeric-string|null $range_position
 * @property int $trap_count
 * @property int $opportunity_count
 * @property bool $has_viable_plan
 * @property list<array<string, mixed>> $signals
 * @property list<array<string, mixed>> $base_rates
 * @property list<array<string, mixed>> $outlook
 * @property list<array<string, mixed>> $levels
 * @property list<array<string, mixed>> $plans
 * @property list<array<string, mixed>> $factors
 * @property list<string> $warnings
 * @property string|null $report_markdown
 * @property int $bar_count
 * @property numeric-string|null $data_quality
 * @property string|null $engine_version
 * @property string|null $error_code
 * @property string|null $error_message
 * @property-read Stock|null $stock
 */
#[Fillable([
    'stock_id', 'scanned_on', 'as_of', 'timeframe', 'trading_style', 'last_price', 'bias', 'setup_score',
    'decision', 'decision_rationale', 'structure', 'last_event', 'range_zone', 'range_position',
    'trap_count', 'opportunity_count', 'has_viable_plan', 'signals', 'base_rates', 'outlook', 'levels', 'plans',
    'factors', 'warnings', 'report_markdown', 'bar_count', 'data_quality', 'engine_version',
    'error_code', 'error_message',
])]
class MarketScan extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scanned_on' => 'immutable_date',
            'as_of' => 'immutable_datetime',
            'timeframe' => Timeframe::class,
            'trading_style' => TradingStyle::class,
            'last_price' => 'decimal:4',
            'bias' => Bias::class,
            'setup_score' => 'integer',
            'decision' => Decision::class,
            'range_position' => 'decimal:4',
            'trap_count' => 'integer',
            'opportunity_count' => 'integer',
            'has_viable_plan' => 'boolean',
            'signals' => 'array',
            'base_rates' => 'array',
            'outlook' => 'array',
            'levels' => 'array',
            'plans' => 'array',
            'factors' => 'array',
            'warnings' => 'array',
            'bar_count' => 'integer',
            'data_quality' => 'decimal:3',
        ];
    }

    /** @return BelongsTo<Stock, $this> */
    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }

    public function failed(): bool
    {
        return $this->error_code !== null;
    }
}
