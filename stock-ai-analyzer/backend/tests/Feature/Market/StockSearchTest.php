<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Domain\Market\Models\Exchange;
use App\Domain\Market\Models\PriceBar;
use App\Domain\Market\Models\Stock;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Sanctum::actingAs(User::factory()->create());

    $this->nepse = Exchange::factory()->create(['code' => 'NEPSE']);
    Stock::factory()->for($this->nepse)->createMany([
        ['symbol' => 'HDHPC', 'name' => 'Himal Dolakha Hydropower Company Limited'],
        ['symbol' => 'HDL', 'name' => 'Himalayan Distillery Limited'],
        ['symbol' => 'NABIL', 'name' => 'Nabil Bank Limited'],
        ['symbol' => 'NTC', 'name' => 'Nepal Doorsanchar Company Limited'],
    ]);
});

it('ranks the exact symbol first', function () {
    $this->getJson('/api/v1/stocks?search=hdl')
        ->assertOk()
        ->assertJsonPath('data.0.symbol', 'HDL')
        ->assertJsonPath('data.0.exchange', 'NEPSE');
});

it('finds stocks by company name', function () {
    expect($this->getJson('/api/v1/stocks?search=distillery')->assertOk()->json('data.0.symbol'))->toBe('HDL');
});

it('tolerates a mistyped symbol', function () {
    expect($this->getJson('/api/v1/stocks?search=nabl')->assertOk()->json('data.*.symbol'))->toContain('NABIL');
});

it('filters by exchange and hides inactive stocks', function () {
    $nasdaq = Exchange::factory()->create(['code' => 'NASDAQ']);
    Stock::factory()->for($nasdaq)->create(['symbol' => 'HDL', 'name' => 'Some US Company']);
    Stock::factory()->for($this->nepse)->create(['symbol' => 'HDLX', 'name' => 'Delisted', 'is_active' => false]);

    $response = $this->getJson('/api/v1/stocks?search=hdl&exchange=nepse')->assertOk();

    expect($response->json('data.*.exchange'))->each->toBe('NEPSE')
        ->and($response->json('data.*.symbol'))->not->toContain('HDLX');
});

it('returns at most 20 stocks', function () {
    Stock::factory()->count(25)->for($this->nepse)->create();

    expect($this->getJson('/api/v1/stocks')->assertOk()->json('data'))->toHaveCount(20);
});

it('shows a stock with its stored price range per timeframe', function () {
    $stock = Stock::query()->where('symbol', 'HDL')->sole();
    foreach (['2026-09-06', '2026-09-07'] as $date) {
        PriceBar::query()->create([
            'stock_id' => $stock->id, 'timeframe' => '1D', 'bar_time' => "{$date} 00:00:00+00",
            'open' => 550, 'high' => 560, 'low' => 545, 'close' => 558, 'source' => 'csv',
        ]);
    }

    $this->getJson("/api/v1/stocks/{$stock->id}")
        ->assertOk()
        ->assertJsonPath('data.symbol', 'HDL')
        ->assertJsonPath('data.price_coverage', [
            ['timeframe' => '1D', 'from' => '2026-09-06T00:00:00Z', 'to' => '2026-09-07T00:00:00Z', 'bars' => 2],
        ]);
});

it('requires authentication', function () {
    $this->app['auth']->forgetGuards();

    $this->getJson('/api/v1/stocks?search=hdl')->assertUnauthorized();
});
