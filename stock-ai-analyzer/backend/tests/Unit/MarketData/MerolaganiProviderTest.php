<?php

declare(strict_types=1);

use App\Domain\Market\Enums\PriceSource;
use App\Domain\Market\Models\Stock;
use App\Domain\Market\ValueObjects\BarData;
use App\Domain\Market\ValueObjects\SkippedRow;
use App\Domain\Shared\Enums\Timeframe;
use App\Infrastructure\MarketData\Contracts\AuthoritativeCalendar;
use App\Infrastructure\MarketData\Merolagani\MerolaganiClient;
use App\Infrastructure\MarketData\Merolagani\MerolaganiProvider;
use App\Infrastructure\MarketData\Merolagani\MerolaganiUnavailable;
use App\Infrastructure\MarketData\ProviderResult;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;

function merolaganiClient(): MerolaganiClient
{
    return new MerolaganiClient(
        http: app(HttpFactory::class),
        baseUrl: 'https://eng.merolagani.test',
        timeout: 5,
        connectTimeout: 2,
        retries: 1,
        retryDelayMs: 0,
        userAgent: 'tests',
    );
}

/**
 * A stock that is never saved: the provider only reads its symbol.
 */
function merolaganiStock(string $symbol = 'PCBL'): Stock
{
    $stock = new Stock;
    $stock->symbol = $symbol;

    return $stock;
}

/**
 * @param  array<string, mixed>  $payload
 */
function fakeMerolaganiHistory(array $payload): void
{
    Http::fake([
        'eng.merolagani.test/handlers/technicalcharthandler.ashx*' => Http::response($payload),
    ]);
}

function fetchMerolaganiBars(
    string $symbol = 'PCBL',
    bool $adjusted = true,
    Timeframe $timeframe = Timeframe::D1,
): ProviderResult {
    $provider = new MerolaganiProvider(
        merolaganiClient(),
        new CarbonImmutable('2010-01-01', 'UTC'),
        new CarbonImmutable('2026-09-20', 'UTC'),
        $adjusted,
    );

    return $provider->fetch(merolaganiStock($symbol), $timeframe);
}

it('reports itself as the merolagani source and trusts its own calendar', function () {
    $provider = new MerolaganiProvider(
        merolaganiClient(),
        new CarbonImmutable('2010-01-01', 'UTC'),
        new CarbonImmutable('2026-09-20', 'UTC'),
        true,
    );

    expect($provider->source())->toBe(PriceSource::Merolagani)
        ->and($provider)->toBeInstanceOf(AuthoritativeCalendar::class);
});

it('dates a bar by the UTC date of its timestamp, not the Kathmandu date', function () {
    // The vendor's clock is inconsistent: mid-session in 2010, ~20:45 UTC now — which is 02:30 the
    // next morning in Kathmandu. Reading these locally would move the 2026 bars forward a day.
    fakeMerolaganiHistory([
        's' => 'ok',
        't' => [1262497500, 1789677893, 1789764288],
        'o' => [590.0, 245.0, 252.5],
        'h' => [600.0, 252.0, 259.0],
        'l' => [590.0, 245.0, 249.0],
        'c' => [600.0, 251.8, 259.0],
        'v' => [340.0, 227337.0, 272648.0],
    ]);

    $result = fetchMerolaganiBars();

    expect(array_map(
        static fn (BarData $bar): string => $bar->time->toIso8601ZuluString(),
        $result->bars,
    ))->toBe([
        '2010-01-03T00:00:00Z',
        '2026-09-17T00:00:00Z',
        '2026-09-18T00:00:00Z',
    ]);
});

it('keeps prices as decimal strings and carries volume through', function () {
    fakeMerolaganiHistory([
        's' => 'ok',
        't' => [1789764288],
        'o' => [252.5],
        'h' => [259.0],
        'l' => [249.0],
        'c' => [259.0],
        'v' => [272648.0],
    ]);

    expect(fetchMerolaganiBars()->bars[0])->toMatchObject([
        'open' => '252.5',
        'high' => '259',
        'low' => '249',
        'close' => '259',
        'volume' => '272648',
        // The handler carries no traded amount.
        'turnover' => null,
        'sourceRow' => 1,
    ]);
});

it('skips a bar whose OHLC does not hold together, and keeps the rest', function () {
    fakeMerolaganiHistory([
        's' => 'ok',
        't' => [1789591344, 1789677893, 1789764288],
        // Middle bar: high below the close.
        'o' => [245.1, 245.0, 252.5],
        'h' => [247.0, 240.0, 259.0],
        'l' => [243.1, 239.0, 249.0],
        'c' => [245.0, 251.8, 259.0],
        'v' => [80305.0, 227337.0, 272648.0],
    ]);

    $result = fetchMerolaganiBars();

    expect($result->bars)->toHaveCount(2)
        ->and($result->skipped)->toHaveCount(1)
        ->and($result->skipped[0])->toBeInstanceOf(SkippedRow::class)
        ->and($result->skipped[0]->row)->toBe(2)
        ->and($result->skipped[0]->reason)->toContain('below the open or close');
});

it('skips a bar with a negative volume', function () {
    fakeMerolaganiHistory([
        's' => 'ok',
        't' => [1789764288],
        'o' => [252.5], 'h' => [259.0], 'l' => [249.0], 'c' => [259.0],
        'v' => [-1.0],
    ]);

    $result = fetchMerolaganiBars();

    expect($result->bars)->toBeEmpty()
        ->and($result->skipped[0]->reason)->toBe('Volume must be a non-negative number.');
});

it('stores a bar with no volume rather than dropping it', function () {
    fakeMerolaganiHistory([
        's' => 'ok',
        't' => [1789764288],
        'o' => [252.5], 'h' => [259.0], 'l' => [249.0], 'c' => [259.0],
    ]);

    $result = fetchMerolaganiBars();

    expect($result->bars)->toHaveCount(1)
        ->and($result->bars[0]->volume)->toBeNull();
});

it('treats no_data as an empty result, not a failure', function () {
    fakeMerolaganiHistory(['s' => 'no_data', 'nextTime' => 1789799745]);

    $result = fetchMerolaganiBars();

    expect($result->bars)->toBeEmpty()
        ->and($result->skipped)->toBeEmpty()
        ->and($result->meta['rows_read'])->toBe(0);
});

it('records how the bars were asked for', function () {
    fakeMerolaganiHistory(['s' => 'no_data']);

    expect(fetchMerolaganiBars(adjusted: false)->meta)->toBe([
        'provider' => 'merolagani',
        'endpoint' => 'get_advanced_chart',
        'resolution' => '1D',
        'adjusted' => false,
        'range_from' => '2010-01-01',
        'range_to' => '2026-09-20',
        'rows_read' => 0,
    ]);
});

it('asks for the adjusted series when told to', function () {
    fakeMerolaganiHistory(['s' => 'no_data']);

    fetchMerolaganiBars(adjusted: true);

    Http::assertSent(function ($request): bool {
        $query = [];
        parse_str((string) parse_url((string) $request->url(), PHP_URL_QUERY), $query);

        return $query['type'] === 'get_advanced_chart'
            && $query['symbol'] === 'PCBL'
            && $query['resolution'] === '1D'
            && $query['isAdjust'] === '1';
    });
});

it('refuses a timeframe merolagani does not actually serve as bars', function () {
    fakeMerolaganiHistory(['s' => 'ok', 't' => [], 'o' => [], 'h' => [], 'l' => [], 'c' => []]);

    expect(fn () => fetchMerolaganiBars(timeframe: Timeframe::H1))
        ->toThrow(InvalidArgumentException::class, 'daily bars only');
});

it('fails loudly when a series is shorter than its timestamps', function () {
    fakeMerolaganiHistory([
        's' => 'ok',
        't' => [1789677893, 1789764288],
        'o' => [245.0], 'h' => [252.0], 'l' => [245.0], 'c' => [251.8],
    ]);

    expect(fn () => fetchMerolaganiBars())
        ->toThrow(MerolaganiUnavailable::class, 'has 1 values for 2 timestamps');
});

it('fails loudly on an upstream error status', function () {
    fakeMerolaganiHistory(['s' => 'error', 'errmsg' => 'symbol not found']);

    expect(fn () => fetchMerolaganiBars())
        ->toThrow(MerolaganiUnavailable::class, 'symbol not found');
});

it('fails loudly on an HTTP error', function () {
    Http::fake([
        'eng.merolagani.test/*' => Http::response('gateway timeout', 504),
    ]);

    expect(fn () => fetchMerolaganiBars())
        ->toThrow(MerolaganiUnavailable::class, 'HTTP 504');
});
