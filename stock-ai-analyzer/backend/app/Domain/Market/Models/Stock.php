<?php

declare(strict_types=1);

namespace App\Domain\Market\Models;

use App\Domain\Market\Enums\InstrumentType;
use Carbon\CarbonImmutable;
use Database\Factories\StockFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use stdClass;

/**
 * @property int $id
 * @property int $exchange_id
 * @property string $symbol
 * @property string $name
 * @property string|null $sector
 * @property array<string, mixed> $metadata
 * @property-read Exchange|null $exchange
 */
#[Fillable(['exchange_id', 'symbol', 'name', 'sector', 'instrument_type', 'is_active', 'metadata'])]
#[UseFactory(StockFactory::class)]
class Stock extends Model
{
    /** @use HasFactory<StockFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'instrument_type' => InstrumentType::class,
            'is_active' => 'boolean',
            'metadata' => 'array',
        ];
    }

    /** @return Attribute<string, string> */
    protected function symbol(): Attribute
    {
        return Attribute::make(set: static fn (string $value): string => Str::upper(trim($value)));
    }

    /** @return BelongsTo<Exchange, $this> */
    public function exchange(): BelongsTo
    {
        return $this->belongsTo(Exchange::class);
    }

    /** @return HasMany<PriceBar, $this> */
    public function priceBars(): HasMany
    {
        return $this->hasMany(PriceBar::class);
    }

    /**
     * Stored bar range per timeframe.
     *
     * @return list<array{timeframe: string, from: string, to: string, bars: int}>
     */
    public function priceCoverage(): array
    {
        return array_values($this->priceBars()
            ->toBase()
            ->selectRaw('timeframe, MIN(bar_time) AS first_bar, MAX(bar_time) AS last_bar, COUNT(*) AS bars')
            ->groupBy('timeframe')
            ->orderBy('timeframe')
            ->get()
            ->map(static fn (stdClass $row): array => [
                'timeframe' => (string) $row->timeframe,
                'from' => CarbonImmutable::parse((string) $row->first_bar)->toIso8601ZuluString(),
                'to' => CarbonImmutable::parse((string) $row->last_bar)->toIso8601ZuluString(),
                'bars' => (int) $row->bars,
            ])
            ->all());
    }
}
