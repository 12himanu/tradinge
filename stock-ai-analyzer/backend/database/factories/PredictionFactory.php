<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Analysis\Models\Analysis;
use App\Domain\Market\Models\Stock;
use App\Domain\Prediction\Enums\PredictionKind;
use App\Domain\Prediction\Enums\PredictionStatus;
use App\Domain\Prediction\Enums\Scenario;
use App\Domain\Prediction\Enums\TradeDirection;
use App\Domain\Prediction\Enums\TriggerType;
use App\Domain\Prediction\Models\Prediction;
use App\Domain\Shared\Enums\Timeframe;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Test fixtures only: a pending long breakout setup.
 *
 * @extends Factory<Prediction>
 */
class PredictionFactory extends Factory
{
    protected $model = Prediction::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'analysis_id' => Analysis::factory()->completed(),
            'user_id' => fn (array $attributes) => Analysis::query()->whereKey($attributes['analysis_id'])->value('user_id'),
            'stock_id' => Stock::factory(),
            'kind' => PredictionKind::TradeSetup,
            'scenario' => Scenario::Bullish,
            'direction' => TradeDirection::Long,
            'timeframe' => Timeframe::D1,
            'trigger_type' => TriggerType::CloseAbove,
            'trigger_price' => 580,
            'entry_price' => 582,
            'stop_price' => 548,
            'target_price_1' => 620,
            'target_price_2' => 650,
            'invalidation_price' => 520,
            'reference_price' => 552.1,
            'as_of' => now()->subDay()->startOfDay(),
            'horizon_bars' => 20,
            'expires_at' => now()->addDays(28),
            'setup_score' => 70,
            'risk_reward' => 1.12,
            'status' => PredictionStatus::Pending,
            'engine_version' => '0.1.0+cfg.test',
        ];
    }

    public function directionalBias(): static
    {
        return $this->state(fn () => [
            'kind' => PredictionKind::DirectionalBias,
            'scenario' => Scenario::Primary,
            'trigger_type' => TriggerType::Immediate,
            'trigger_price' => null,
            'entry_price' => null,
            'stop_price' => null,
            'target_price_1' => null,
            'target_price_2' => null,
            'invalidation_price' => null,
            'risk_reward' => null,
        ]);
    }
}
