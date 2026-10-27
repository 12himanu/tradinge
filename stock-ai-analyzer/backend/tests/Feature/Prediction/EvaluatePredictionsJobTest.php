<?php

declare(strict_types=1);

use App\Application\Jobs\EvaluatePredictionsJob;
use App\Application\Services\Stats\CalibrationService;
use App\Domain\Analysis\Models\Analysis;
use App\Domain\Market\Models\PriceBar;
use App\Domain\Market\Models\Stock;
use App\Domain\Prediction\Enums\OutcomeResult;
use App\Domain\Prediction\Enums\PredictionKind;
use App\Domain\Prediction\Enums\PredictionStatus;
use App\Domain\Prediction\Enums\TradeDirection;
use App\Domain\Prediction\Enums\TriggerType;
use App\Domain\Prediction\Models\CalibrationSnapshot;
use App\Domain\Prediction\Models\Outcome;
use App\Domain\Prediction\Models\Prediction;
use App\Domain\Shared\Enums\Timeframe;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * The job's contract: read the bars, write one outcome row, move the prediction's status, and
 * change nothing when run again (P6-03).
 */
function storedPrediction(array $overrides = []): Prediction
{
    $stock = Stock::factory()->create(['symbol' => 'HDL']);
    $analysis = Analysis::factory()->create(['stock_id' => $stock->id]);

    $prediction = new Prediction([
        'analysis_id' => $analysis->id,
        'user_id' => $analysis->user_id,
        'stock_id' => $stock->id,
        'kind' => PredictionKind::TradeSetup,
        'scenario' => 'bullish',
        'direction' => TradeDirection::Long,
        'timeframe' => Timeframe::D1,
        'trigger_type' => TriggerType::CloseAbove,
        'trigger_price' => 580,
        'entry_price' => 582,
        'stop_price' => 550,
        'target_price_1' => 640,
        'invalidation_price' => 540,
        'reference_price' => 570,
        'as_of' => CarbonImmutable::parse('2026-09-10T00:00:00Z'),
        'horizon_bars' => 20,
        'expires_at' => CarbonImmutable::parse('2026-10-10T00:00:00Z'),
        'setup_score' => 70,
        'status' => PredictionStatus::Pending,
        'engine_version' => '0.1.0+cfg.test',
        ...$overrides,
    ]);
    $prediction->id = (string) Str::ulid();
    $prediction->save();

    return $prediction;
}

/**
 * @param  list<array{0: float, 1: float, 2: float, 3: float}>  $rows
 */
function storeBars(Prediction $prediction, array $rows): void
{
    $date = CarbonImmutable::parse('2026-09-10T00:00:00Z');
    foreach ($rows as $index => $row) {
        PriceBar::factory()->create([
            'stock_id' => $prediction->stock_id,
            'timeframe' => Timeframe::D1,
            'bar_time' => $date->addDays($index + 1),
            'open' => $row[0],
            'high' => $row[1],
            'low' => $row[2],
            'close' => $row[3],
        ]);
    }
}

function runEvaluation(): void
{
    app()->call([new EvaluatePredictionsJob, 'handle']);
}

it('records a win and resolves the prediction', function () {
    $prediction = storedPrediction();
    storeBars($prediction, [[576, 590, 574, 585], [586, 645, 584, 640]]);

    runEvaluation();

    $prediction->refresh();
    expect($prediction->status)->toBe(PredictionStatus::TargetHit)
        ->and($prediction->resolved_at)->not->toBeNull()
        ->and($prediction->activated_at)->not->toBeNull();

    $outcome = Outcome::query()->where('prediction_id', $prediction->id)->firstOrFail();
    expect($outcome->result)->toBe(OutcomeResult::Win)
        ->and((float) $outcome->r_multiple)->toBe(1.57)
        ->and($outcome->ambiguous_bar)->toBeFalse();
});

it('is idempotent: running it again changes nothing', function () {
    $prediction = storedPrediction();
    storeBars($prediction, [[576, 590, 574, 585], [586, 645, 584, 640]]);

    runEvaluation();
    $first = Outcome::query()->where('prediction_id', $prediction->id)->firstOrFail();

    runEvaluation();

    expect(Outcome::query()->count())->toBe(1)
        ->and(Outcome::query()->first()->updated_at?->toIso8601String())
        ->toBe($first->updated_at?->toIso8601String());
});

it('activates an unresolved prediction without writing an outcome yet', function () {
    $prediction = storedPrediction();
    storeBars($prediction, [[576, 590, 574, 585]]);   // triggers, nothing else

    runEvaluation();

    $prediction->refresh();
    expect($prediction->status)->toBe(PredictionStatus::Active)
        ->and($prediction->activated_at)->not->toBeNull()
        ->and($prediction->resolved_at)->toBeNull();

    // An outcome row means the prediction finished; this one has not.
    expect(Outcome::query()->count())->toBe(0);
});

it('resolves once the deciding bar arrives', function () {
    $prediction = storedPrediction();
    storeBars($prediction, [[576, 590, 574, 585]]);
    runEvaluation();

    // The next session prints and the target is reached.
    PriceBar::factory()->create([
        'stock_id' => $prediction->stock_id,
        'timeframe' => Timeframe::D1,
        'bar_time' => CarbonImmutable::parse('2026-09-12T00:00:00Z'),
        'open' => 586,
        'high' => 645,
        'low' => 584,
        'close' => 640,
    ]);

    runEvaluation();

    expect($prediction->refresh()->status)->toBe(PredictionStatus::TargetHit)
        ->and(Outcome::query()->count())->toBe(1);
});

it('never scores a prediction on bars it could have seen', function () {
    $prediction = storedPrediction();
    // A bar dated before as_of, which would have been a target hit.
    PriceBar::factory()->create([
        'stock_id' => $prediction->stock_id,
        'timeframe' => Timeframe::D1,
        'bar_time' => CarbonImmutable::parse('2026-09-09T00:00:00Z'),
        'open' => 600,
        'high' => 700,
        'low' => 590,
        'close' => 690,
    ]);

    runEvaluation();

    expect($prediction->refresh()->status)->toBe(PredictionStatus::Pending)
        ->and(Outcome::query()->count())->toBe(0);
});

it('ignores predictions that are already resolved', function () {
    $prediction = storedPrediction(['status' => PredictionStatus::Stopped]);
    storeBars($prediction, [[576, 590, 574, 585], [586, 645, 584, 640]]);

    runEvaluation();

    expect($prediction->refresh()->status)->toBe(PredictionStatus::Stopped)
        ->and(Outcome::query()->count())->toBe(0);
});

it('skips predictions with no linked stock, which can only be resolved by hand', function () {
    $prediction = storedPrediction();
    $prediction->forceFill(['stock_id' => null])->save();

    runEvaluation();

    expect(Outcome::query()->count())->toBe(0);
});

it('records a calibration snapshot when an outcome lands', function () {
    $prediction = storedPrediction();
    storeBars($prediction, [[576, 590, 574, 585], [586, 645, 584, 640]]);

    runEvaluation();

    $snapshot = CalibrationSnapshot::query()->firstOrFail();
    expect($snapshot->user_id)->toBe($prediction->user_id)
        ->and($snapshot->engine_version)->toBe('0.1.0+cfg.test')
        ->and($snapshot->scope)->toBe(CalibrationService::GLOBAL_SCOPE)
        ->and($snapshot->sample_size)->toBe(1)
        // One outcome is nowhere near enough to publish a probability, but it is worth recording.
        ->and($snapshot->buckets[0]['qualified'])->toBeFalse();
});

it('writes no snapshot when nothing resolved', function () {
    $prediction = storedPrediction();
    storeBars($prediction, [[560, 570, 555, 565]]);

    runEvaluation();

    expect(CalibrationSnapshot::query()->count())->toBe(0);
});
