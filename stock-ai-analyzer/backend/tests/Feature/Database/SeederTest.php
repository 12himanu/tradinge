<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Domain\Market\Models\Exchange;
use App\Domain\Market\Models\Stock;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Hash;

it('seeds NEPSE as a long-only Monday–Friday market with its stock list', function () {
    $this->seed(DatabaseSeeder::class);

    $nepse = Exchange::query()->where('code', 'NEPSE')->firstOrFail();

    expect(Exchange::query()->count())->toBe(3)
        ->and($nepse->allows_short_selling)->toBeFalse()
        // NEPSE moved off the Sunday–Thursday week in April 2026. This is not cosmetic: the value
        // drives prediction expiry dates and which imported bars count as sessions.
        ->and($nepse->trading_days)->toBe([1, 2, 3, 4, 5])
        ->and($nepse->fee_schedule['verified'])->toBeFalse()
        ->and(Stock::query()->where('exchange_id', $nepse->id)->count())->toBeGreaterThanOrEqual(20)
        ->and(Stock::query()->where('symbol', 'HDL')->exists())->toBeTrue();
});

it('does not create an owner account without a password', function () {
    $this->seed(DatabaseSeeder::class);

    expect(User::query()->count())->toBe(0);
});

it('creates the owner account from configuration', function () {
    config(['stock_ai.owner.email' => 'Owner@Example.test', 'stock_ai.owner.password' => 'a-long-local-password']);

    $this->seed(DatabaseSeeder::class);

    $owner = User::query()->with('defaultExchange')->sole();
    expect($owner->email)->toBe('owner@example.test')
        ->and(Hash::check('a-long-local-password', $owner->password))->toBeTrue()
        ->and($owner->defaultExchange?->code)->toBe('NEPSE')
        ->and($owner->settings['currency'])->toBe('NPR');
});

it('is idempotent', function () {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    expect(Exchange::query()->count())->toBe(3)
        ->and(Stock::query()->where('symbol', 'NABIL')->count())->toBe(1);
});
