<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Analysis\Enums\AnalysisStatus;
use App\Domain\Analysis\Enums\DataTier;
use App\Domain\Analysis\Enums\Decision;
use App\Domain\Analysis\Enums\NarrativeSource;
use App\Domain\Analysis\Enums\PositionStatus;
use App\Domain\Analysis\Models\Analysis;
use App\Domain\Chart\Models\ChartUpload;
use App\Domain\Shared\Enums\Bias;
use App\Domain\Shared\Enums\Timeframe;
use App\Domain\Shared\Enums\TradingStyle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Test fixtures only. Values in the completed state are placeholders, not engine output.
 *
 * @extends Factory<Analysis>
 */
class AnalysisFactory extends Factory
{
    protected $model = Analysis::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'chart_upload_id' => ChartUpload::factory(),
            'user_id' => fn (array $attributes) => ChartUpload::query()->whereKey($attributes['chart_upload_id'])->value('user_id'),
            'timeframe' => Timeframe::D1,
            'trading_style' => TradingStyle::Swing,
            'position_status' => PositionStatus::None,
            'status' => AnalysisStatus::Queued,
            'warnings' => [],
            'queued_at' => now(),
        ];
    }

    public function running(): static
    {
        return $this->state(fn () => [
            'status' => AnalysisStatus::Running,
            'current_stage' => 'vision',
            'started_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => AnalysisStatus::Completed,
            'data_tier' => DataTier::Ohlcv,
            'data_quality' => 0.98,
            'as_of' => now()->subDay()->startOfDay(),
            'last_price' => 552.1,
            'bias' => Bias::Bullish,
            'setup_score' => 70,
            'decision' => Decision::Wait,
            'narrative_source' => NarrativeSource::Template,
            'report' => ['schema_version' => '1.0'],
            'evidence' => [],
            'engine_version' => '0.1.0+cfg.test',
            'prompt_version' => 'p.test',
            'models' => [],
            'started_at' => now()->subMinutes(2),
            'completed_at' => now(),
            'duration_ms' => 98_700,
        ]);
    }

    public function failed(string $code = 'ENGINE_UNAVAILABLE'): static
    {
        return $this->state(fn () => [
            'status' => AnalysisStatus::Failed,
            'error_code' => $code,
            'error_message' => 'The analysis engine is offline.',
            'completed_at' => now(),
        ]);
    }
}
