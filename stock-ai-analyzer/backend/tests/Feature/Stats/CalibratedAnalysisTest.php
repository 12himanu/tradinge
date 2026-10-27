<?php

declare(strict_types=1);

use App\Application\Services\PersistAnalysisResult;
use App\Domain\Analysis\Models\Analysis;
use App\Domain\Identity\Models\User;
use App\Domain\Prediction\Enums\OutcomeResult;
use App\Domain\Prediction\Enums\PredictionKind;
use App\Domain\Prediction\Enums\PredictionStatus;
use App\Domain\Prediction\Models\Outcome;
use App\Domain\Prediction\Models\Prediction;
use App\Domain\Shared\Enums\TradingStyle;
use Illuminate\Support\Str;

/**
 * A fresh analysis carries a calibrated probability once history justifies one (P6-11).
 *
 * The engine is stateless and always sends `calibrated_probability: null` with the note
 * "uncalibrated"; these tests pin the moment Laravel overwrites that with a number it earned.
 */

/**
 * `$wins` of `$count` resolved setups in the 70s, from the same engine version the fixture uses.
 */
function calibrationHistory(User $user, string $engineVersion, int $count, int $wins): void
{
    $analysis = Analysis::factory()->for($user)->create(['trading_style' => TradingStyle::Swing]);

    for ($i = 0; $i < $count; $i++) {
        $prediction = new Prediction([
            'analysis_id' => $analysis->id,
            'user_id' => $user->id,
            'stock_id' => $analysis->stock_id,
            'kind' => PredictionKind::TradeSetup,
            'scenario' => 'bullish',
            'direction' => 'long',
            'timeframe' => $analysis->timeframe,
            'trigger_type' => 'close_above',
            'trigger_price' => 580,
            'entry_price' => 582,
            'stop_price' => 550,
            'target_price_1' => 640,
            'reference_price' => 570,
            'as_of' => now()->subDays(90),
            'horizon_bars' => 20,
            'expires_at' => now()->subDays(60),
            'setup_score' => 77,
            'status' => PredictionStatus::TargetHit,
            'engine_version' => $engineVersion,
        ]);
        $prediction->id = (string) Str::ulid();
        $prediction->save();

        Outcome::query()->create([
            'prediction_id' => $prediction->id,
            'result' => $i < $wins ? OutcomeResult::Win : OutcomeResult::Loss,
            'evaluation_method' => 'auto',
            'r_multiple' => $i < $wins ? 2.0 : -1.0,
            'evaluated_through' => now()->subDays(55),
        ]);
    }
}

it('keeps saying uncalibrated when there is no history', function () {
    $analysis = Analysis::factory()->create(['trading_style' => TradingStyle::Swing]);

    app(PersistAnalysisResult::class)($analysis, engineResultFixture(), durationMs: 10);
    $analysis->refresh();

    expect($analysis->setup_score)->toBe(77)
        ->and($analysis->calibrated_probability)->toBeNull()
        ->and($analysis->report['bias']['note'])->toBe('uncalibrated')
        ->and($analysis->report['bias']['calibrated_probability'])->toBeNull();
});

it('attaches the empirical hit rate once the score bucket qualifies', function () {
    $result = engineResultFixture();
    $analysis = Analysis::factory()->create(['trading_style' => TradingStyle::Swing]);

    calibrationHistory($analysis->user, $result['engine_version'], count: 30, wins: 18);

    app(PersistAnalysisResult::class)($analysis, $result, durationMs: 10);
    $analysis->refresh();

    // 18 of 30 setups scored in the 70s reached T1. The score still says 77; the report now says
    // what 77 has actually been worth.
    expect((float) $analysis->calibrated_probability)->toBe(0.6)
        ->and($analysis->report['bias']['note'])->toBe('calibrated')
        ->and($analysis->report['bias']['calibrated_probability'])->toBe(0.6)
        ->and($analysis->setup_score)->toBe(77);
});

it('does not borrow another engine version history', function () {
    $result = engineResultFixture();
    $analysis = Analysis::factory()->create(['trading_style' => TradingStyle::Swing]);

    calibrationHistory($analysis->user, 'some.other.version', count: 40, wins: 32);

    app(PersistAnalysisResult::class)($analysis, $result, durationMs: 10);

    expect($analysis->refresh()->calibrated_probability)->toBeNull();
});

it('leaves a Tier C analysis uncalibrated because it has no score', function () {
    $result = engineResultFixture('tier_c_result.json');
    $analysis = Analysis::factory()->create(['trading_style' => TradingStyle::Swing]);

    calibrationHistory($analysis->user, $result['engine_version'] ?? 'unknown', count: 40, wins: 32);

    app(PersistAnalysisResult::class)($analysis, $result, durationMs: 10);

    expect($analysis->refresh()->calibrated_probability)->toBeNull();
});

it('exposes the calibrated probability on the report endpoint', function () {
    $result = engineResultFixture();
    $user = User::factory()->create();
    $analysis = Analysis::factory()->for($user)->create(['trading_style' => TradingStyle::Swing]);

    calibrationHistory($user, $result['engine_version'], count: 30, wins: 18);
    app(PersistAnalysisResult::class)($analysis, $result, durationMs: 10);

    $this->actingAs($user)
        ->getJson("/api/v1/analysis/{$analysis->id}")
        ->assertOk()
        ->assertJsonPath('data.calibrated_probability', 0.6);
});
