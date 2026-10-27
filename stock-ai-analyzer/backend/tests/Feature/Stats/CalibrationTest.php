<?php

declare(strict_types=1);

use App\Application\Services\Stats\CalibrationService;
use App\Domain\Analysis\Models\Analysis;
use App\Domain\Identity\Models\User;
use App\Domain\Prediction\Enums\OutcomeResult;
use App\Domain\Prediction\Enums\PredictionKind;
use App\Domain\Prediction\Enums\PredictionStatus;
use App\Domain\Prediction\Models\CalibrationSnapshot;
use App\Domain\Prediction\Models\Outcome;
use App\Domain\Prediction\Models\Prediction;
use App\Domain\Shared\Enums\TradingStyle;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

/**
 * `$wins` of `$count` resolved setups, all scored `$score` by `$engineVersion` (P6-10).
 */
function scoredSetups(
    User $user,
    int $score,
    int $count,
    int $wins,
    string $engineVersion = '0.1.0+cfg.aaa',
    TradingStyle $style = TradingStyle::Swing,
    PredictionKind $kind = PredictionKind::TradeSetup,
): void {
    $analysis = Analysis::factory()->for($user)->create(['trading_style' => $style]);

    for ($i = 0; $i < $count; $i++) {
        $prediction = new Prediction([
            'analysis_id' => $analysis->id,
            'user_id' => $user->id,
            'stock_id' => $analysis->stock_id,
            'kind' => $kind,
            'scenario' => 'bullish',
            'direction' => 'long',
            'timeframe' => $analysis->timeframe,
            'trigger_type' => 'close_above',
            'trigger_price' => 580,
            'entry_price' => 582,
            'stop_price' => 550,
            'target_price_1' => 640,
            'reference_price' => 570,
            'as_of' => now()->subDays(60),
            'horizon_bars' => 20,
            'expires_at' => now()->subDays(30),
            'setup_score' => $score,
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
            'evaluated_through' => now()->subDays(25),
        ]);
    }
}

it('publishes nothing while a bucket is an anecdote', function (): void {
    scoredSetups($this->user, 72, 6, 4);

    $curve = app(CalibrationService::class)->curve($this->user, '0.1.0+cfg.aaa');

    expect($curve['sample_size'])->toBe(6)
        ->and($curve['active'])->toBeFalse()
        ->and($curve['buckets'][0]['n'])->toBe(6)
        ->and($curve['buckets'][0]['observed'])->toBe(0.6667)
        ->and($curve['buckets'][0]['qualified'])->toBeFalse()
        ->and($curve['buckets'][0]['calibrated'])->toBeNull();
});

it('turns a bucket on at thirty resolved outcomes', function (): void {
    scoredSetups($this->user, 72, 30, 18);

    $curve = app(CalibrationService::class)->curve($this->user, '0.1.0+cfg.aaa');

    expect($curve['method'])->toBe('binned')
        ->and($curve['active'])->toBeTrue()
        ->and($curve['buckets'][0]['bucket'])->toBe('70-79')
        ->and($curve['buckets'][0]['calibrated'])->toBe(0.6)
        // The score claimed 72%, reality said 60%: the gap is the whole point of the page.
        ->and($curve['buckets'][0]['predicted'])->toBe(0.72)
        ->and($curve['calibration_error'])->toBe(0.12);
});

it('never pools two engine versions into one curve', function (): void {
    scoredSetups($this->user, 72, 30, 18, '0.1.0+cfg.aaa');
    scoredSetups($this->user, 72, 30, 3, '0.2.0+cfg.bbb');

    $service = app(CalibrationService::class);

    expect($service->curve($this->user, '0.1.0+cfg.aaa')['buckets'][0]['calibrated'])->toBe(0.6)
        ->and($service->curve($this->user, '0.2.0+cfg.bbb')['buckets'][0]['calibrated'])->toBe(0.1);
});

it('ignores directional bias predictions, which are not trade setups', function (): void {
    scoredSetups($this->user, 72, 30, 18, kind: PredictionKind::DirectionalBias);

    expect(app(CalibrationService::class)->curve($this->user, '0.1.0+cfg.aaa')['sample_size'])->toBe(0);
});

it('keeps one user out of another user calibration', function (): void {
    $other = User::factory()->create();
    scoredSetups($other, 72, 30, 30);

    expect(app(CalibrationService::class)->curve($this->user, '0.1.0+cfg.aaa')['sample_size'])->toBe(0);
});

it('switches to isotonic once the whole sample can carry a model', function (): void {
    // 210 outcomes, with the 60s luckier than the 70s: raw bins would say a 65 beats a 75.
    scoredSetups($this->user, 65, 70, 49);   // 0.70
    scoredSetups($this->user, 75, 70, 28);   // 0.40
    scoredSetups($this->user, 85, 70, 56);   // 0.80

    $curve = app(CalibrationService::class)->curve($this->user, '0.1.0+cfg.aaa');
    $calibrated = array_column($curve['buckets'], 'calibrated');

    expect($curve['method'])->toBe('isotonic')
        ->and($curve['sample_size'])->toBe(210)
        // The inversion is pooled away; the curve never decreases.
        ->and($calibrated)->toBe([0.55, 0.55, 0.8])
        ->and($curve['brier_score_calibrated'])->toBeLessThan($curve['brier_score']);
});

it('prefers the style curve over the global one when it qualifies', function (): void {
    scoredSetups($this->user, 72, 30, 27, style: TradingStyle::Swing);      // 0.90 swing
    scoredSetups($this->user, 72, 30, 3, style: TradingStyle::Intraday);    // 0.10 intraday

    $service = app(CalibrationService::class);

    expect($service->probabilityFor($this->user, '0.1.0+cfg.aaa', 72, TradingStyle::Swing))->toBe(0.9)
        ->and($service->probabilityFor($this->user, '0.1.0+cfg.aaa', 72, TradingStyle::Intraday))->toBe(0.1)
        // Global pools both, so it lands in between.
        ->and($service->probabilityFor($this->user, '0.1.0+cfg.aaa', 72))->toBe(0.5);
});

it('falls back to the global curve when the style has too few outcomes', function (): void {
    scoredSetups($this->user, 72, 30, 18, style: TradingStyle::Swing);
    scoredSetups($this->user, 72, 4, 4, style: TradingStyle::Positional);

    $probability = app(CalibrationService::class)
        ->probabilityFor($this->user, '0.1.0+cfg.aaa', 72, TradingStyle::Positional);

    // 22 wins from 34 pooled, not the 4-from-4 the positional bucket alone would have claimed.
    expect($probability)->toBe(0.6471);
});

it('returns nothing for a score no bucket covers', function (): void {
    scoredSetups($this->user, 72, 30, 18);

    expect(app(CalibrationService::class)->probabilityFor($this->user, '0.1.0+cfg.aaa', 31))->toBeNull()
        ->and(app(CalibrationService::class)->probabilityFor($this->user, '0.1.0+cfg.aaa', null))->toBeNull();
});

it('records a snapshot of the curve and skips an empty one', function (): void {
    $service = app(CalibrationService::class);

    expect($service->snapshot($this->user, '0.1.0+cfg.aaa'))->toBeNull();

    scoredSetups($this->user, 72, 30, 18);
    $snapshot = $service->snapshot($this->user, '0.1.0+cfg.aaa');

    expect($snapshot)->toBeInstanceOf(CalibrationSnapshot::class)
        ->and($snapshot?->sample_size)->toBe(30)
        ->and($snapshot?->method)->toBe('binned')
        ->and($snapshot?->user_id)->toBe($this->user->id)
        ->and($snapshot?->buckets[0]['calibrated'])->toBe(0.6);
});

it('serves the curve over the API, defaulting to the newest engine version', function (): void {
    scoredSetups($this->user, 72, 30, 18, '0.1.0+cfg.aaa');

    $this->getJson('/api/v1/stats/calibration')
        ->assertOk()
        ->assertJsonPath('data.engine_version', '0.1.0+cfg.aaa')
        ->assertJsonPath('data.scope', 'global')
        ->assertJsonPath('data.active', true)
        ->assertJsonPath('data.min_bucket_sample', 30)
        ->assertJsonPath('data.buckets.0.bucket', '70-79')
        ->assertJsonPath('data.buckets.0.calibrated', 0.6);
});

it('serves a style-scoped curve when asked', function (): void {
    scoredSetups($this->user, 72, 30, 27, style: TradingStyle::Swing);
    scoredSetups($this->user, 72, 30, 3, style: TradingStyle::Intraday);

    $this->getJson('/api/v1/stats/calibration?scope=style:intraday&engine_version=0.1.0%2Bcfg.aaa')
        ->assertOk()
        ->assertJsonPath('data.scope', 'style:intraday')
        ->assertJsonPath('data.sample_size', 30)
        ->assertJsonPath('data.buckets.0.calibrated', 0.1);
});

it('requires authentication', function (): void {
    auth()->forgetGuards();

    $this->getJson('/api/v1/stats/calibration')->assertUnauthorized();
});
