<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Prediction\Enums\EvaluationMethod;
use App\Domain\Prediction\Enums\OutcomeResult;
use App\Domain\Prediction\Models\Outcome;
use App\Domain\Prediction\Models\Prediction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Outcome>
 */
class OutcomeFactory extends Factory
{
    protected $model = Outcome::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'prediction_id' => Prediction::factory(),
            'result' => OutcomeResult::Win,
            'evaluation_method' => EvaluationMethod::Auto,
            'triggered_at' => now()->subDays(5),
            'exit_at' => now()->subDay(),
            'exit_price' => 620,
            'return_pct' => 6.5292,
            'r_multiple' => 1.12,
            'mfe_pct' => 7.1,
            'mae_pct' => -1.8,
            'bars_to_resolution' => 4,
            'ambiguous_bar' => false,
            'evaluated_through' => now()->subDay(),
        ];
    }
}
