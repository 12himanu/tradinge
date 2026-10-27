<?php

declare(strict_types=1);

use App\Application\Jobs\ScanMarketJob;
use App\Application\Jobs\SyncMerolaganiPricesJob;
use App\Domain\Identity\Models\User;
use App\Domain\Market\Enums\PriceSource;
use App\Domain\Market\Models\Exchange;
use App\Domain\Market\Models\PriceBar;
use App\Domain\Market\Models\Stock;
use App\Domain\Shared\Enums\Timeframe;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

/**
 * A row in `job_batches` shaped the way Laravel writes one.
 */
function batchRow(string $name, int $total, int $pending, ?int $finishedAt = null): void
{
    DB::table('job_batches')->insert([
        'id' => (string) Str::ulid(),
        'name' => $name,
        'total_jobs' => $total,
        'pending_jobs' => $pending,
        'failed_jobs' => 0,
        'failed_job_ids' => '[]',
        'options' => '',
        'created_at' => now()->getTimestamp(),
        'cancelled_at' => null,
        'finished_at' => $finishedAt,
    ]);
}

function controlStock(string $symbol = 'PCBL', int $bars = 3): Stock
{
    $exchange = Exchange::query()->firstWhere('code', 'NEPSE')
        ?? Exchange::factory()->create(['code' => 'NEPSE']);
    $stock = Stock::factory()->create(['exchange_id' => $exchange->id, 'symbol' => $symbol]);

    $date = CarbonImmutable::parse('2026-09-18T00:00:00Z');
    for ($index = 0; $index < $bars; $index++) {
        PriceBar::factory()->create([
            'stock_id' => $stock->id,
            'timeframe' => Timeframe::D1,
            'bar_time' => $date->subDays($index),
            'source' => PriceSource::Merolagani,
        ]);
    }

    return $stock;
}

it('requires authentication for every market-data control', function (string $method, string $path) {
    $this->json($method, $path)->assertUnauthorized();
})->with([
    ['GET', '/api/v1/market-data/status'],
    ['POST', '/api/v1/market-data/sync'],
    ['POST', '/api/v1/market-data/scan'],
]);

it('reports how much data there is', function () {
    controlStock('PCBL', 5);

    $response = $this->actingAs(User::factory()->create())->getJson('/api/v1/market-data/status');

    $response->assertOk()
        ->assertJsonPath('data.coverage.stocks_with_bars', 1)
        ->assertJsonPath('data.coverage.bars', 5)
        ->assertJsonPath('data.coverage.latest_bar', '2026-09-18')
        ->assertJsonPath('data.sync.running', false);
});

it('reports a running sync with its progress', function () {
    batchRow('merolagani-sync', total: 100, pending: 40);

    $response = $this->actingAs(User::factory()->create())->getJson('/api/v1/market-data/status');

    $response->assertOk()
        ->assertJsonPath('data.sync.running', true)
        ->assertJsonPath('data.sync.total', 100)
        ->assertJsonPath('data.sync.done', 60)
        ->assertJsonPath('data.sync.progress', 0.6);
});

it('reports a finished sync as no longer running', function () {
    batchRow('merolagani-sync', total: 10, pending: 0, finishedAt: now()->getTimestamp());

    $this->actingAs(User::factory()->create())
        ->getJson('/api/v1/market-data/status')
        ->assertOk()
        ->assertJsonPath('data.sync.running', false)
        // A whole 1.0 encodes as `1`; JSON has one number type and JavaScript cannot tell them apart.
        ->assertJsonPath('data.sync.progress', 1);
});

it('queues a sync', function () {
    Bus::fake();

    $this->actingAs(User::factory()->create())
        ->postJson('/api/v1/market-data/sync')
        ->assertStatus(202);

    Bus::assertDispatched(SyncMerolaganiPricesJob::class);
});

it('refuses a second sync while one is running', function () {
    // Two concurrent runs would double the load on somebody else's server for the same rows.
    Bus::fake();
    batchRow('merolagani-sync', total: 100, pending: 40);

    $this->actingAs(User::factory()->create())
        ->postJson('/api/v1/market-data/sync')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'SYNC_IN_PROGRESS');

    Bus::assertNotDispatched(SyncMerolaganiPricesJob::class);
});

it('queues a scan on its own, for when the bars are already current', function () {
    Bus::fake();

    $this->actingAs(User::factory()->create())
        ->postJson('/api/v1/market-data/scan')
        ->assertStatus(202);

    Bus::assertDispatched(ScanMarketJob::class);
});

it('finds a stock by its ticker, so pages can use a readable URL', function () {
    $stock = controlStock('PCBL');

    $this->actingAs(User::factory()->create())
        ->getJson('/api/v1/stocks/symbol/pcbl')
        ->assertOk()
        ->assertJsonPath('data.id', $stock->id)
        ->assertJsonPath('data.symbol', 'PCBL');
});

it('404s for a ticker that does not exist', function () {
    $this->actingAs(User::factory()->create())
        ->getJson('/api/v1/stocks/symbol/NOPE')
        ->assertNotFound();
});
