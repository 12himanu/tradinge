<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use Laravel\Sanctum\Sanctum;

it('logs in with valid credentials and returns the user', function () {
    $user = User::factory()->create(['email' => 'owner@example.test', 'password' => 'correct-horse-battery']);

    $this->withHeaders(spaHeaders())
        ->postJson('/api/v1/auth/login', ['email' => 'OWNER@example.test', 'password' => 'correct-horse-battery'])
        ->assertOk()
        ->assertJsonPath('data.email', 'owner@example.test')
        ->assertJsonMissingPath('data.password');

    $this->assertAuthenticatedAs($user, 'web');
});

it('rejects invalid credentials with a validation error', function () {
    User::factory()->create(['email' => 'owner@example.test', 'password' => 'correct-horse-battery']);

    $this->withHeaders(spaHeaders())
        ->postJson('/api/v1/auth/login', ['email' => 'owner@example.test', 'password' => 'wrong-password'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');

    $this->assertGuest('web');
});

it('rejects login without a first-party browser session', function () {
    $this->postJson('/api/v1/auth/login', ['email' => 'owner@example.test', 'password' => 'whatever-password'])
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'BAD_REQUEST');
});

it('throttles repeated login attempts', function () {
    foreach (range(1, 5) as $attempt) {
        $this->withHeaders(spaHeaders())
            ->postJson('/api/v1/auth/login', ['email' => 'owner@example.test', 'password' => 'wrong-password'])
            ->assertUnprocessable();
    }

    $this->withHeaders(spaHeaders())
        ->postJson('/api/v1/auth/login', ['email' => 'owner@example.test', 'password' => 'wrong-password'])
        ->assertTooManyRequests()
        ->assertJsonPath('error.code', 'TOO_MANY_REQUESTS')
        ->assertHeader('Retry-After');
});

it('returns the current user from /me', function () {
    Sanctum::actingAs(User::factory()->create(['name' => 'Owner']));

    $this->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.name', 'Owner')
        ->assertJsonPath('data.timezone', 'Asia/Kathmandu');
});

it('returns the error envelope for guests', function () {
    $this->getJson('/api/v1/me')
        ->assertUnauthorized()
        ->assertExactJson(['error' => ['code' => 'UNAUTHENTICATED', 'message' => 'Authentication required.', 'details' => []]]);
});

it('logs out and ends the session', function () {
    $this->actingAs(User::factory()->create(), 'web');

    $this->withHeaders(spaHeaders())->postJson('/api/v1/auth/logout')->assertNoContent();

    $this->assertGuest('web');
});

it('echoes or assigns a request id', function () {
    $this->getJson('/api/v1/me', ['X-Request-Id' => 'trace-123'])->assertHeader('X-Request-Id', 'trace-123');

    $generated = $this->getJson('/api/v1/me')->headers->get('X-Request-Id');
    expect($generated)->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/');
});
