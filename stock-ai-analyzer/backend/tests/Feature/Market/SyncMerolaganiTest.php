<?php

declare(strict_types=1);

use App\Application\Jobs\SyncMerolaganiPricesJob;
use App\Application\Jobs\SyncMerolaganiStockJob;
use App\Application\Services\SyncMerolaganiStock;
use App\Domain\Market\Enums\PriceSource;
use App\Domain\Market\Models\Exchange;
use App\Domain\Market\Models\PriceBar;
use App\Domain\Market\Models\Stock;
use App\Domain\Shared\Enums\Timeframe;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('stock_ai.market_data.merolagani.url', 'https://eng.merolagani.test');
    config()->set('stock_ai.market_data.merolagani.history_start', '2010-01-01');
    config()->set('stock_ai.market_data.merolagani.lookback_days', 10);

    // NEPSE as seeded: Monday-Friday.
    $this->exchange = Exchange::factory()->create([
        'code' => 'NEPSE',
        'timezone' => 'Asia/Kathmandu',
        'trading_days' => [1, 2, 3, 4, 5],
        'holidays' => [],
    ]);
});

/**
 * Timestamps as merolagani stamps them now: late in the UTC day of the session.
 *
 * @param  list<array{0: string, 1: float, 2: float, 3: float, 4: float, 5: float}>  $bars  [date, o, h, l, c, v]
 */
function fakeMerolaganiMarket(array $bars, ?array $summary = null, ?string $page = null): void
{
    $series = ['s' => 'ok', 't' => [], 'o' => [], 'h' => [], 'l' => [], 'c' => [], 'v' => []];

    foreach ($bars as [$date, $open, $high, $low, $close, $volume]) {
        $series['t'][] = CarbonImmutable::parse($date.' 20:44:48', 'UTC')->getTimestamp();
        $series['o'][] = $open;
        $series['h'][] = $high;
        $series['l'][] = $low;
        $series['c'][] = $close;
        $series['v'][] = $volume;
    }

    Http::fake([
        'eng.merolagani.test/handlers/technicalcharthandler.ashx*' => Http::response($series),
        // An unknown symbol gets the literal `null` back, not an empty body.
        'eng.merolagani.test/handlers/webrequesthandler.ashx*' => Http::response($summary ?? 'null'),
        'eng.merolagani.test/CompanyDetail.aspx*' => Http::response($page ?? '', $page === null ? 404 : 200),
    ]);
}

function nepseStock(array $attributes = []): Stock
{
    return Stock::factory()->create([
        'exchange_id' => test()->exchange->id,
        'symbol' => 'PCBL',
        'sector' => null,
        'metadata' => [],
        ...$attributes,
    ]);
}

it('backfills the whole history the first time a stock is synced', function () {
    $stock = nepseStock();
    fakeMerolaganiMarket([
        ['2010-01-03', 590.0, 600.0, 590.0, 600.0, 340.0],
        ['2026-09-17', 245.0, 252.0, 245.0, 251.8, 227337.0],
        ['2026-09-18', 252.5, 259.0, 249.0, 259.0, 272648.0],
    ]);

    $result = app(SyncMerolaganiStock::class)($stock);

    expect($result->backfill)->toBeTrue()
        ->and($result->from)->toBe('2010-01-01')
        ->and($result->inserted)->toBe(3)
        ->and($result->updated)->toBe(0)
        ->and($result->lastBarDate)->toBe('2026-09-18');

    $bars = PriceBar::query()->where('stock_id', $stock->id)->orderBy('bar_time')->get();

    expect($bars)->toHaveCount(3)
        ->and($bars[0]->bar_time->toIso8601ZuluString())->toBe('2010-01-03T00:00:00Z')
        ->and($bars[0]->source)->toBe(PriceSource::Merolagani)
        ->and($bars[0]->timeframe)->toBe(Timeframe::D1)
        ->and((float) $bars[2]->close)->toBe(259.0)
        ->and((float) $bars[2]->volume)->toBe(272648.0)
        ->and($bars[2]->turnover)->toBeNull();
});

it('keeps Friday bars, which the old Sunday-Thursday calendar would have rejected', function () {
    $stock = nepseStock();
    // 2026-09-18 is a Friday; NEPSE trades it.
    fakeMerolaganiMarket([['2026-09-18', 252.5, 259.0, 249.0, 259.0, 272648.0]]);

    $result = app(SyncMerolaganiStock::class)($stock);

    expect($result->inserted)->toBe(1)
        ->and($result->skippedTotal)->toBe(0);
});

it('keeps historic Sunday bars, from before NEPSE moved its week', function () {
    $stock = nepseStock();
    // 2010-01-03 was a Sunday, a trading day back then but not under the current calendar.
    fakeMerolaganiMarket([['2010-01-03', 590.0, 600.0, 590.0, 600.0, 340.0]]);

    $result = app(SyncMerolaganiStock::class)($stock);

    expect($result->inserted)->toBe(1)
        ->and($result->skippedTotal)->toBe(0);
});

it('asks only for recent days once a stock has bars, and restates them', function () {
    $stock = nepseStock();
    PriceBar::factory()->create([
        'stock_id' => $stock->id,
        'timeframe' => Timeframe::D1,
        'bar_time' => CarbonImmutable::parse('2026-09-17T00:00:00Z'),
        'open' => 245.0, 'high' => 252.0, 'low' => 245.0, 'close' => 250.0,
        'source' => PriceSource::Merolagani,
    ]);

    fakeMerolaganiMarket([
        // The same date, restated by the vendor, plus a new one.
        ['2026-09-17', 245.0, 252.0, 245.0, 251.8, 227337.0],
        ['2026-09-18', 252.5, 259.0, 249.0, 259.0, 272648.0],
    ]);

    $result = app(SyncMerolaganiStock::class)($stock);

    expect($result->backfill)->toBeFalse()
        ->and($result->from)->toBe('2026-09-07')
        ->and($result->inserted)->toBe(1)
        ->and($result->updated)->toBe(1);

    $restated = PriceBar::query()
        ->where('stock_id', $stock->id)
        ->where('bar_time', CarbonImmutable::parse('2026-09-17T00:00:00Z'))
        ->sole();

    expect((float) $restated->close)->toBe(251.8);
});

it('is safe to run twice', function () {
    $stock = nepseStock();
    fakeMerolaganiMarket([['2026-09-18', 252.5, 259.0, 249.0, 259.0, 272648.0]]);

    app(SyncMerolaganiStock::class)($stock);
    $second = app(SyncMerolaganiStock::class)($stock);

    expect($second->inserted)->toBe(0)
        ->and($second->updated)->toBe(1)
        ->and(PriceBar::query()->where('stock_id', $stock->id)->count())->toBe(1);
});

it('stores the fundamentals snapshot and fills in a missing sector', function () {
    $stock = nepseStock();
    fakeMerolaganiMarket(
        [['2026-09-18', 252.5, 259.0, 249.0, 259.0, 272648.0]],
        summary: ['name' => 'Prime Commercial Bank Ltd.', 'ltp' => '259', 'peRatio' => '13.19'],
        page: '<table><tr><th><span>Sector</span></th><td>Commercial Banks</td></tr></table>',
    );

    $result = app(SyncMerolaganiStock::class)($stock);
    $stock->refresh();

    expect($result->snapshotStored)->toBeTrue()
        ->and($stock->sector)->toBe('Commercial Banks')
        // jsonb normalizes 259.0 to 259, so compare by value rather than by type.
        ->and($stock->metadata['merolagani']['last_price'])->toEqual(259)
        ->and($stock->metadata['merolagani']['pe_ratio'])->toEqual(13.19);
});

it('does not fetch the page again once a stock has a sector', function () {
    $stock = nepseStock(['sector' => 'Commercial Banks']);
    fakeMerolaganiMarket(
        [['2026-09-18', 252.5, 259.0, 249.0, 259.0, 272648.0]],
        summary: ['ltp' => '259'],
    );

    app(SyncMerolaganiStock::class)($stock);

    Http::assertNotSent(fn ($request): bool => str_contains((string) $request->url(), 'CompanyDetail.aspx'));
});

it('stores the bars even when merolagani has no snapshot for the symbol', function () {
    $stock = nepseStock(['sector' => 'Commercial Banks']);
    fakeMerolaganiMarket([['2026-09-18', 252.5, 259.0, 249.0, 259.0, 272648.0]], summary: null);

    $result = app(SyncMerolaganiStock::class)($stock);

    expect($result->inserted)->toBe(1)
        ->and($result->snapshotStored)->toBeFalse();
});

it('keeps the bars when the snapshot request itself fails', function () {
    // The bars are the product; a reference figure is not worth re-running the import for.
    $stock = nepseStock(['sector' => 'Commercial Banks']);
    fakeMerolaganiMarket([['2026-09-18', 252.5, 259.0, 249.0, 259.0, 272648.0]]);
    Http::fake([
        'eng.merolagani.test/handlers/webrequesthandler.ashx*' => Http::response('down', 503),
        'eng.merolagani.test/handlers/technicalcharthandler.ashx*' => Http::response([
            's' => 'ok',
            't' => [CarbonImmutable::parse('2026-09-18 20:44:48', 'UTC')->getTimestamp()],
            'o' => [252.5], 'h' => [259.0], 'l' => [249.0], 'c' => [259.0], 'v' => [272648.0],
        ]),
    ]);

    $result = app(SyncMerolaganiStock::class)($stock);

    expect($result->inserted)->toBe(1)
        ->and($result->snapshotStored)->toBeFalse()
        ->and(PriceBar::query()->where('stock_id', $stock->id)->count())->toBe(1);
});

it('does not touch another exchange, or an inactive stock, on a scheduled run', function () {
    Bus::fake();

    $active = nepseStock(['symbol' => 'PCBL']);
    nepseStock(['symbol' => 'NABIL', 'is_active' => false]);
    $other = Exchange::factory()->create(['code' => 'NASDAQ']);
    Stock::factory()->create(['exchange_id' => $other->id, 'symbol' => 'AAPL']);

    (new SyncMerolaganiPricesJob)->handle();

    Bus::assertBatched(function ($batch) use ($active): bool {
        return $batch->jobs->count() === 1
            && $batch->jobs->first() instanceof SyncMerolaganiStockJob
            && $batch->jobs->first()->stockId === $active->id;
    });
});

it('spaces queued symbols out so merolagani is not hit all at once', function () {
    Bus::fake();

    nepseStock(['symbol' => 'PCBL']);
    nepseStock(['symbol' => 'NABIL']);
    nepseStock(['symbol' => 'ADBL']);

    (new SyncMerolaganiPricesJob(spacingSeconds: 5))->handle();

    Bus::assertBatched(function ($batch): bool {
        $delays = $batch->jobs->map(fn (SyncMerolaganiStockJob $job) => $job->delay)->all();

        return count($delays) === 3
            && $delays[2]->getTimestamp() - $delays[0]->getTimestamp() === 10;
    });
});

it('syncs one named symbol from the console and reports what it did', function () {
    nepseStock(['symbol' => 'PCBL']);
    nepseStock(['symbol' => 'NABIL']);
    fakeMerolaganiMarket([['2026-09-18', 252.5, 259.0, 249.0, 259.0, 272648.0]], summary: ['ltp' => '259']);

    $this->artisan('market:sync-merolagani', ['symbol' => ['pcbl']])
        ->expectsOutputToContain('PCBL')
        ->assertSuccessful();

    expect(PriceBar::query()->count())->toBe(1);
});

it('fails the console run when a symbol cannot be reached', function () {
    nepseStock();
    Http::fake(['eng.merolagani.test/*' => Http::response('nope', 503)]);

    $this->artisan('market:sync-merolagani', ['symbol' => ['PCBL']])->assertFailed();

    expect(PriceBar::query()->count())->toBe(0);
});

it('creates stocks for securities merolagani lists but the seeder does not', function () {
    nepseStock(['symbol' => 'PCBL', 'name' => 'Prime Commercial Bank Ltd.']);

    Http::fake(['eng.merolagani.test/handlers/technicalcharthandler.ashx*' => Http::response([
        ['symbol' => 'PCBL', 'description' => 'Prime Commercial Bank Ltd.', 'type' => 'Stock'],
        ['symbol' => 'NABIL', 'description' => 'Nabil Bank Limited', 'type' => 'Stock'],
        ['symbol' => 'ADBLB87', 'description' => 'Agricultural Bond (9 Year)', 'type' => 'Stock'],
        ['symbol' => 'NIBSF2', 'description' => 'NIBL Samriddhi Fund 2 Mutual Fund', 'type' => 'Stock'],
    ])]);

    $this->artisan('market:import-merolagani-symbols')->assertSuccessful();

    expect(Stock::query()->count())->toBe(4)
        ->and(Stock::query()->where('symbol', 'ADBLB87')->sole()->instrument_type->value)->toBe('debenture')
        ->and(Stock::query()->where('symbol', 'NIBSF2')->sole()->instrument_type->value)->toBe('mutual_fund')
        ->and(Stock::query()->where('symbol', 'NABIL')->sole()->instrument_type->value)->toBe('equity');
});

it('survives a symbol merolagani lists twice', function () {
    // HURJA is listed twice today: once with its name, once with an empty one. Inserting both would
    // violate the unique (exchange, symbol) index and abandon the rest of the import.
    Http::fake(['eng.merolagani.test/handlers/technicalcharthandler.ashx*' => Http::response([
        ['symbol' => 'HURJA', 'description' => 'Himalaya Urja Bikas Company Limited', 'type' => 'Stock'],
        ['symbol' => 'HURJA', 'description' => '', 'type' => 'Stock'],
        ['symbol' => 'NABIL', 'description' => 'Nabil Bank Limited', 'type' => 'Stock'],
    ])]);

    $this->artisan('market:import-merolagani-symbols')->assertSuccessful();

    expect(Stock::query()->where('symbol', 'HURJA')->count())->toBe(1)
        ->and(Stock::query()->where('symbol', 'HURJA')->sole()->name)->toBe('Himalaya Urja Bikas Company Limited')
        // The entry after the duplicate still made it in.
        ->and(Stock::query()->where('symbol', 'NABIL')->exists())->toBeTrue();
});

it('prefers the named entry whichever order the duplicates arrive in', function () {
    Http::fake(['eng.merolagani.test/handlers/technicalcharthandler.ashx*' => Http::response([
        ['symbol' => 'HURJA', 'description' => '', 'type' => 'Stock'],
        ['symbol' => 'HURJA', 'description' => 'Himalaya Urja Bikas Company Limited', 'type' => 'Stock'],
    ])]);

    $this->artisan('market:import-merolagani-symbols')->assertSuccessful();

    expect(Stock::query()->where('symbol', 'HURJA')->sole()->name)
        ->toBe('Himalaya Urja Bikas Company Limited');
});

it('leaves an existing stock alone when importing the symbol list', function () {
    $stock = nepseStock(['symbol' => 'PCBL', 'name' => 'My own label', 'sector' => 'Commercial Banks']);

    Http::fake(['eng.merolagani.test/handlers/technicalcharthandler.ashx*' => Http::response([
        ['symbol' => 'PCBL', 'description' => 'Prime Commercial Bank Ltd.', 'type' => 'Stock'],
    ])]);

    $this->artisan('market:import-merolagani-symbols')->assertSuccessful();

    expect($stock->refresh()->name)->toBe('My own label')
        ->and(Stock::query()->count())->toBe(1);
});
