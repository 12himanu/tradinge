<?php

declare(strict_types=1);

namespace App\Domain\Market\Models;

use App\Domain\Market\Enums\PriceSource;
use App\Domain\Shared\Enums\Timeframe;
use Carbon\CarbonImmutable;
use Database\Factories\PriceBarFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Real market data (CSV import or provider). Never contains bars digitized from screenshots.
 *
 * @property int $id
 * @property int $stock_id
 * @property Timeframe $timeframe
 * @property CarbonImmutable $bar_time
 * @property numeric-string $open
 * @property numeric-string $high
 * @property numeric-string $low
 * @property numeric-string $close
 * @property numeric-string|null $volume
 * @property numeric-string|null $turnover
 * @property PriceSource $source
 */
#[Fillable(['stock_id', 'timeframe', 'bar_time', 'open', 'high', 'low', 'close', 'volume', 'turnover', 'source'])]
#[UseFactory(PriceBarFactory::class)]
class PriceBar extends Model
{
    /** @use HasFactory<PriceBarFactory> */
    use HasFactory;

    public const null UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'timeframe' => Timeframe::class,
            'bar_time' => 'datetime',
            'open' => 'decimal:4',
            'high' => 'decimal:4',
            'low' => 'decimal:4',
            'close' => 'decimal:4',
            'volume' => 'decimal:2',
            'turnover' => 'decimal:2',
            'source' => PriceSource::class,
        ];
    }

    /** @return BelongsTo<Stock, $this> */
    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }
}
