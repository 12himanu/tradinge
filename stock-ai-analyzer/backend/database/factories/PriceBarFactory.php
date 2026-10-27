<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Market\Enums\PriceSource;
use App\Domain\Market\Models\PriceBar;
use App\Domain\Market\Models\Stock;
use App\Domain\Shared\Enums\Timeframe;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic bars for tests only. Never seeded into a development database.
 *
 * @extends Factory<PriceBar>
 */
class PriceBarFactory extends Factory
{
    protected $model = PriceBar::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $open = fake()->randomFloat(2, 100, 1000);
        $close = round($open * (1 + fake()->randomFloat(4, -0.05, 0.05)), 2);
        $high = round(max($open, $close) * (1 + fake()->randomFloat(4, 0, 0.02)), 2);
        $low = round(min($open, $close) * (1 - fake()->randomFloat(4, 0, 0.02)), 2);

        return [
            'stock_id' => Stock::factory(),
            'timeframe' => Timeframe::D1,
            'bar_time' => CarbonImmutable::parse('2025-01-01')->addDays(fake()->unique()->numberBetween(0, 5000)),
            'open' => $open,
            'high' => $high,
            'low' => $low,
            'close' => $close,
            'volume' => fake()->numberBetween(1_000, 500_000),
            'turnover' => null,
            'source' => PriceSource::Csv,
        ];
    }
}
