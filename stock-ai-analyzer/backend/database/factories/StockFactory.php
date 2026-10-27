<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Market\Enums\InstrumentType;
use App\Domain\Market\Models\Exchange;
use App\Domain\Market\Models\Stock;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Stock>
 */
class StockFactory extends Factory
{
    protected $model = Stock::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'exchange_id' => Exchange::factory(),
            'symbol' => strtoupper(fake()->unique()->lexify('????')),
            'name' => fake()->company(),
            'sector' => fake()->randomElement(['Commercial Banks', 'Hydro Power', 'Manufacturing And Processing']),
            'instrument_type' => InstrumentType::Equity,
            'is_active' => true,
            'metadata' => [],
        ];
    }
}
