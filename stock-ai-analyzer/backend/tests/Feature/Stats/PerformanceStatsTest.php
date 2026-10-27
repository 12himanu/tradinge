<?php

declare(strict_types=1);

use App\Domain\Analysis\Enums\DataTier;
use App\Domain\Analysis\Enums\Decision;
use App\Domain\Analysis\Models\Analysis;
use App\Domain\Analysis\Models\AnalysisPattern;
use App\Domain\Identity\Models\User;
use App\Domain\Prediction\Enums\OutcomeResult;
use App\Domain\Prediction\Enums\PredictionKind;
use App\Domain\Prediction\Enums\PredictionStatus;
use App\Domain\Prediction\Models\Outcome;
use App\Domain\Prediction\Models\Prediction;
use App\Domain\Shared\Enums\TradingStyle;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

/**
 * A resolved prediction with its outcome. Everything the Performance page shows derives from
 * rows like these (P6-07).
 */
function resolvedPrediction(
    User $user,
    OutcomeResult $result,
    float $rMultiple,
    array $predictionOverrides = [],
    array $analysisOverrides = [],
): Prediction {
    $analysis = Analysis::factory()->for($user)->create([
        'trading_style' => TradingStyle::Swing,
        'decision' => Decision::Buy,
        'data_tier' => DataTier::Ohlcv,
        ...$analysisOverrides,
    ]);

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
        'as_of' => now()->subDays(30),
        'horizon_bars' => 20,
        'expires_at' => now()->subDays(5),
        'setup_score' => 72,
        'status' => PredictionStatus::TargetHit,
        'engine_version' => '0.1.0+cfg.aaa',
        ...$predictionOverrides,
    ]);
    $prediction->id = (string) Str::ulid();
    $prediction->save();

    Outcome::query()->create([
        'prediction_id' => $prediction->id,
        'result' => $result,
        'evaluation_method' => 'auto',
        'triggered_at' => now()->subDays(28),
        'exit_at' => now()->subDays(20),
        'exit_price' => 640,
        'return_pct' => 10,
        'r_multiple' => $rMultiple,
        'mfe_pct' => 8,
        'mae_pct' => 3,
        'bars_to_resolution' => 6,
        'ambiguous_bar' => false,
        'evaluated_through' => now()->subDays(20),
    ]);

    return $prediction;
}

it('reports nothing rather than zeroes when there are no outcomes', function () {
    $data = $this->getJson('/api/v1/stats/performance')->assertOk()->json('data');

    expect($data['overall']['resolved'])->toBe(0)
        ->and($data['overall']['hit_rate'])->toBeNull()
        ->and($data['overall']['expectancy_r'])->toBeNull()
        ->and($data['overall']['sufficient_sample'])->toBeFalse();
});

it('computes hit rate, expectancy and profit factor from resolved setups', function () {
    resolvedPrediction($this->user, OutcomeResult::Win, 2.0);
    resolvedPrediction($this->user, OutcomeResult::Win, 1.5);
    resolvedPrediction($this->user, OutcomeResult::Loss, -1.0);

    $overall = $this->getJson('/api/v1/stats/performance')->assertOk()->json('data.overall');

    expect($overall['resolved'])->toBe(3)
        ->and($overall['wins'])->toBe(2)
        ->and($overall['hit_rate'])->toBe(0.6667)
        // (2.0 + 1.5 - 1.0) / 3
        ->and($overall['expectancy_r'])->toBe(0.8333)
        ->and($overall['profit_factor'])->toBe(3.5);
});

it('always ships the interval and the sample size with a rate', function () {
    resolvedPrediction($this->user, OutcomeResult::Win, 2.0);
    resolvedPrediction($this->user, OutcomeResult::Loss, -1.0);

    $overall = $this->getJson('/api/v1/stats/performance')->assertOk()->json('data.overall');

    expect($overall['hit_rate_interval']['low'])->toBeLessThan(0.5)
        ->and($overall['hit_rate_interval']['high'])->toBeGreaterThan(0.5)
        // Two trades is not evidence, and the payload says so.
        ->and($overall['sufficient_sample'])->toBeFalse();
});

it('keeps untriggered setups out of the hit rate but counts them in the trigger rate', function () {
    resolvedPrediction($this->user, OutcomeResult::Win, 2.0);

    // A setup that expired without ever triggering.
    $expired = resolvedPrediction($this->user, OutcomeResult::Expired, 0.0, [
        'status' => PredictionStatus::Expired,
    ]);
    Outcome::query()->where('prediction_id', $expired->id)->update([
        'triggered_at' => null,
        'r_multiple' => null,
    ]);

    $overall = $this->getJson('/api/v1/stats/performance')->assertOk()->json('data.overall');

    expect($overall['predictions'])->toBe(2)
        ->and($overall['triggered'])->toBe(1)
        ->and($overall['trigger_rate'])->toBe(0.5)
        // Both are resolved, but only one was a win, and the never-triggered one still counts
        // as a resolved prediction that did not reach its target.
        ->and($overall['resolved'])->toBe(2)
        ->and($overall['wins'])->toBe(1);
});

it('groups by trading style', function () {
    resolvedPrediction($this->user, OutcomeResult::Win, 2.0, [], ['trading_style' => TradingStyle::Swing]);
    resolvedPrediction($this->user, OutcomeResult::Loss, -1.0, [], ['trading_style' => TradingStyle::Positional]);

    $groups = $this->getJson('/api/v1/stats/performance?group_by=style')->assertOk()->json('data.groups');

    expect($groups)->toHaveCount(2)
        ->and(collect($groups)->pluck('group')->sort()->values()->all())->toBe(['positional', 'swing']);
});

it('groups by score bucket in ten-point bands', function () {
    resolvedPrediction($this->user, OutcomeResult::Win, 2.0, ['setup_score' => 72]);
    resolvedPrediction($this->user, OutcomeResult::Win, 1.0, ['setup_score' => 78]);
    resolvedPrediction($this->user, OutcomeResult::Loss, -1.0, ['setup_score' => 55]);

    $groups = collect($this->getJson('/api/v1/stats/performance?group_by=score_bucket')->assertOk()->json('data.groups'));

    expect($groups->pluck('group')->sort()->values()->all())->toBe(['50-59', '70-79'])
        ->and($groups->firstWhere('group', '70-79')['resolved'])->toBe(2);
});

it('groups by the pattern the analysis leant on', function () {
    $prediction = resolvedPrediction($this->user, OutcomeResult::Win, 2.0);
    AnalysisPattern::query()->create([
        'analysis_id' => $prediction->analysis_id,
        'category' => 'classic',
        'type' => 'ascending_triangle',
        'direction' => 'bullish',
        'status' => 'confirmed',
        'confidence' => 0.8,
        'detection_method' => 'algorithmic',
        'key_points' => [],
    ]);

    $groups = $this->getJson('/api/v1/stats/performance?group_by=pattern')->assertOk()->json('data.groups');

    expect($groups[0]['group'])->toBe('ascending_triangle');
});

it('filters by engine version, so two scoring systems are never pooled', function () {
    resolvedPrediction($this->user, OutcomeResult::Win, 2.0, ['engine_version' => '0.1.0+cfg.aaa']);
    resolvedPrediction($this->user, OutcomeResult::Loss, -1.0, ['engine_version' => '0.2.0+cfg.bbb']);

    $filtered = $this->getJson('/api/v1/stats/performance?engine_version=0.1.0%2Bcfg.aaa')
        ->assertOk()
        ->json('data.overall');

    expect($filtered['resolved'])->toBe(1)->and($filtered['wins'])->toBe(1);
});

it('returns an R histogram that accounts for every resolved trade', function () {
    resolvedPrediction($this->user, OutcomeResult::Win, 2.0);
    resolvedPrediction($this->user, OutcomeResult::Loss, -1.0);

    $histogram = $this->getJson('/api/v1/stats/performance')->assertOk()->json('data.r_histogram');

    expect(collect($histogram)->sum('count'))->toBe(2);
});

it('scores directional accuracy and the Brier score separately from setups', function () {
    $bias = resolvedPrediction($this->user, OutcomeResult::Win, 0.0, [
        'kind' => PredictionKind::DirectionalBias,
        'status' => PredictionStatus::Expired,
    ]);
    Outcome::query()->where('prediction_id', $bias->id)->update([
        'direction_correct' => true,
        'brier_component' => 0.04,
        'r_multiple' => null,
    ]);

    $overall = $this->getJson('/api/v1/stats/performance')->assertOk()->json('data.overall');

    expect($overall['directional_accuracy']['total'])->toBe(1)
        ->and($overall['directional_accuracy']['rate'])->toBe(1)
        ->and($overall['brier_score'])->toBe(0.04)
        // The bias is not a trade setup, so it stays out of the hit rate.
        ->and($overall['resolved'])->toBe(0);
});

it('never shows another user\'s outcomes', function () {
    resolvedPrediction(User::factory()->create(), OutcomeResult::Win, 2.0);

    expect($this->getJson('/api/v1/stats/performance')->assertOk()->json('data.overall.resolved'))->toBe(0);
});

it('requires authentication', function () {
    auth()->logout();

    $this->getJson('/api/v1/stats/performance')->assertUnauthorized();
});
