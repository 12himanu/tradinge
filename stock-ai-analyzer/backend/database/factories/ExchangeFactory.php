<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Market\Models\Exchange;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Exchange>
 */
class ExchangeFactory extends Factory
{
    protected $model = Exchange::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->lexify('X???')),
            'name' => fake()->company().' Exchange',
            'country' => 'NP',
            'timezone' => 'Asia/Kathmandu',
            'currency' => 'NPR',
            'trading_days' => [7, 1, 2, 3, 4],
            'session_open' => '11:00:00',
            'session_close' => '15:00:00',
            'price_band_pct' => 10,
            'tick_size' => 0.1,
            'lot_size' => 1,
            'allows_short_selling' => false,
            'fee_schedule' => ['verified' => false],
            'holidays' => [],
        ];
    }
}
