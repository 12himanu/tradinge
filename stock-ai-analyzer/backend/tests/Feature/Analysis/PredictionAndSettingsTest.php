<?php

declare(strict_types=1);

use App\Domain\Analysis\Models\Analysis;
use App\Domain\Identity\Models\User;
use App\Domain\Prediction\Enums\PredictionStatus;
use App\Domain\Prediction\Models\Prediction;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

function predictionFor(User $user, PredictionStatus $status = PredictionStatus::Pending): Prediction
{
    $analysis = Analysis::factory()->for($user)->create();

    return Prediction::factory()->create([
        'analysis_id' => $analysis->id,
        'user_id' => $user->id,
        'stock_id' => $analysis->stock_id,
        'status' => $status,
    ]);
}

// --------------------------------------------------------------------------- predictions

it('lists open predictions by default', function () {
    predictionFor($this->user, PredictionStatus::Pending);
    predictionFor($this->user, PredictionStatus::Active);
    predictionFor($this->user, PredictionStatus::Expired);

    $statuses = $this->getJson('/api/v1/predictions')->assertOk()->json('data.*.status');

    expect($statuses)->toHaveCount(2)->each->toBeIn(['pending', 'active']);
});

it('can list resolved predictions too', function () {
    predictionFor($this->user, PredictionStatus::TargetHit);

    expect($this->getJson('/api/v1/predictions?status=target_hit')->assertOk()->json('data'))
        ->toHaveCount(1);
});

it('never shows another user\'s predictions', function () {
    predictionFor(User::factory()->create());

    expect($this->getJson('/api/v1/predictions')->assertOk()->json('data'))->toBeEmpty();
});

it('hides another user\'s prediction behind a 404', function () {
    $stranger = predictionFor(User::factory()->create());

    $this->getJson("/api/v1/predictions/{$stranger->id}")->assertNotFound();
    $this->postJson("/api/v1/predictions/{$stranger->id}/cancel")->assertNotFound();
});

it('cancels a prediction without deleting it', function () {
    $prediction = predictionFor($this->user, PredictionStatus::Pending);

    $this->postJson("/api/v1/predictions/{$prediction->id}/cancel")
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');

    // Statistics would flatter themselves if unwanted predictions could vanish.
    expect(Prediction::query()->count())->toBe(1)
        ->and($prediction->refresh()->resolved_at)->not->toBeNull();
});

it('leaves an already-resolved prediction alone', function () {
    $prediction = predictionFor($this->user, PredictionStatus::TargetHit);

    $this->postJson("/api/v1/predictions/{$prediction->id}/cancel")
        ->assertOk()
        ->assertJsonPath('data.status', 'target_hit');
});

// --------------------------------------------------------------------------- settings

it('updates only the settings you send', function () {
    $this->user->forceFill(['settings' => [
        'account_size' => 100000,
        'risk_per_trade_pct' => 1.0,
        'currency' => 'NPR',
    ]])->save();

    $this->patchJson('/api/v1/me/settings', ['account_size' => 500000])
        ->assertOk()
        ->assertJsonPath('data.settings.account_size', 500000)
        // The untouched keys survive: a partial form must not reset the risk settings.
        ->assertJsonPath('data.settings.risk_per_trade_pct', 1)
        ->assertJsonPath('data.settings.currency', 'NPR');
});

it('rejects impossible risk settings', function () {
    $this->patchJson('/api/v1/me/settings', [
        'risk_per_trade_pct' => 50,
        'max_position_pct' => 500,
        'account_size' => -1,
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['risk_per_trade_pct', 'max_position_pct', 'account_size']);
});

it('accepts a trading style the engine knows', function () {
    $this->patchJson('/api/v1/me/settings', ['default_style' => 'positional'])
        ->assertOk()
        ->assertJsonPath('data.settings.default_style', 'positional');

    $this->patchJson('/api/v1/me/settings', ['default_style' => 'scalping'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['default_style']);
});

it('requires authentication', function () {
    auth()->logout();

    $this->patchJson('/api/v1/me/settings', ['account_size' => 1])->assertUnauthorized();
    $this->getJson('/api/v1/predictions')->assertUnauthorized();
});
