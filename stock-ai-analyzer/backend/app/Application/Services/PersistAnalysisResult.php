<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Application\Services\Stats\CalibrationService;
use App\Domain\Analysis\Events\AnalysisCompleted;
use App\Domain\Analysis\Models\Analysis;
use App\Domain\Analysis\Models\AnalysisIndicator;
use App\Domain\Analysis\Models\AnalysisLevel;
use App\Domain\Analysis\Models\AnalysisPattern;
use App\Domain\Market\ValueObjects\TradingCalendar;
use App\Domain\Prediction\Models\Prediction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Write one engine result to the database in a single transaction (P4D-05).
 *
 * All of it lands or none of it does: a half-written analysis (levels but no decision, or
 * predictions with no analysis) would be scored later as if it were real.
 */
final readonly class PersistAnalysisResult
{
    public function __construct(
        private EngineResultMapper $mapper,
        private CalibrationService $calibration,
    ) {}

    /**
     * @param  array<string, mixed>  $result
     */
    public function __invoke(Analysis $analysis, array $result, int $durationMs): Analysis
    {
        $attributes = $this->calibrate($analysis, $this->mapper->analysisAttributes($result, $durationMs));
        $indicators = $this->mapper->indicators($result);
        $patterns = $this->mapper->patterns($result);
        $levels = $this->mapper->levels($result);

        DB::transaction(function () use ($analysis, $result, $attributes, $indicators, $patterns, $levels): void {
            $analysis->forceFill($attributes)->save();

            // A re-run of the same analysis row replaces its children rather than duplicating them.
            $analysis->indicators()->delete();
            $analysis->patterns()->delete();
            $analysis->levels()->delete();

            foreach ($indicators as $row) {
                AnalysisIndicator::query()->create([...$row, 'analysis_id' => $analysis->id]);
            }
            foreach ($patterns as $row) {
                AnalysisPattern::query()->create([...$row, 'analysis_id' => $analysis->id]);
            }
            foreach ($levels as $row) {
                AnalysisLevel::query()->create([...$row, 'analysis_id' => $analysis->id]);
            }

            $this->writePredictions($analysis, $result);
        });

        AnalysisCompleted::dispatch($analysis->id);

        return $analysis->refresh();
    }

    /**
     * Attach the calibrated probability, when the engine version has earned one (P6-11).
     *
     * The engine deliberately ships `calibrated_probability => null` and the note "uncalibrated":
     * it is stateless and has never seen an outcome. Laravel owns the history, so this is where
     * the raw setup score becomes "68% of setups this engine scored in the 70s reached T1". Until
     * a bucket holds 30 resolved outcomes the report keeps saying uncalibrated, which is the true
     * answer rather than a placeholder.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function calibrate(Analysis $analysis, array $attributes): array
    {
        $engineVersion = is_string($attributes['engine_version'] ?? null) ? $attributes['engine_version'] : null;
        $score = is_int($attributes['setup_score'] ?? null) ? $attributes['setup_score'] : null;

        $user = $analysis->loadMissing('user')->user;

        if ($engineVersion === null || $score === null || $user === null) {
            return $attributes;
        }

        $probability = $this->calibration->probabilityFor(
            $user,
            $engineVersion,
            $score,
            $analysis->trading_style,
        );

        if ($probability === null) {
            return $attributes;
        }

        $attributes['calibrated_probability'] = $probability;

        if (is_array($attributes['report'] ?? null) && is_array($attributes['report']['bias'] ?? null)) {
            $attributes['report']['bias']['calibrated_probability'] = $probability;
            $attributes['report']['bias']['note'] = 'calibrated';
        }

        return $attributes;
    }

    /**
     * Predictions are immutable once written (docs/04 §10), so an analysis that is re-run keeps
     * the originals and only adds new ones when none exist yet.
     *
     * @param  array<string, mixed>  $result
     */
    private function writePredictions(Analysis $analysis, array $result): void
    {
        if ($analysis->predictions()->exists()) {
            return;
        }

        $calendar = $this->calendar($analysis);

        foreach ($this->mapper->predictions($result, $analysis, CarbonImmutable::now()) as $row) {
            /** @var CarbonImmutable $asOf */
            $asOf = $row['as_of'];
            /** @var int $horizon */
            $horizon = $row['horizon_bars'];

            $prediction = new Prediction([
                ...$row,
                'expires_at' => $calendar->addTradingDays($asOf, $horizon),
                'engine_version' => $analysis->engine_version ?? 'unknown',
            ]);
            $prediction->id = (string) Str::ulid();
            $prediction->save();
        }
    }

    /**
     * Horizons are counted in trading days, so a 20-bar horizon on NEPSE is four weeks of
     * Sunday-to-Thursday sessions, not 20 calendar days.
     */
    private function calendar(Analysis $analysis): TradingCalendar
    {
        $exchange = $analysis->loadMissing('stock.exchange')->stock?->exchange;
        $exchange ??= $analysis->loadMissing('user.defaultExchange')->user?->defaultExchange;

        return $exchange === null ? TradingCalendar::everyWeekday() : TradingCalendar::forExchange($exchange);
    }
}
