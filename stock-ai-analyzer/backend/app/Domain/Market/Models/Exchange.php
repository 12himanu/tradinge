<?php

declare(strict_types=1);

namespace App\Domain\Market\Models;

use Database\Factories\ExchangeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Market rules are configuration: trading calendar, price band, tick/lot size, short selling, fees.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $timezone
 * @property string $currency
 * @property list<int>|null $trading_days
 * @property numeric-string|null $price_band_pct
 * @property numeric-string|null $tick_size
 * @property int $lot_size
 * @property bool $allows_short_selling
 * @property array<string, mixed>|null $fee_schedule
 * @property list<string>|null $holidays
 */
#[Fillable([
    'code', 'name', 'country', 'timezone', 'currency', 'trading_days', 'session_open', 'session_close',
    'price_band_pct', 'tick_size', 'lot_size', 'allows_short_selling', 'fee_schedule', 'holidays',
])]
#[UseFactory(ExchangeFactory::class)]
class Exchange extends Model
{
    /** @use HasFactory<ExchangeFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trading_days' => 'array',
            'price_band_pct' => 'decimal:2',
            'tick_size' => 'decimal:4',
            'lot_size' => 'integer',
            'allows_short_selling' => 'boolean',
            'fee_schedule' => 'array',
            'holidays' => 'array',
        ];
    }

    /** @return HasMany<Stock, $this> */
    public function stocks(): HasMany
    {
        return $this->hasMany(Stock::class);
    }
}
