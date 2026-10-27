<?php

declare(strict_types=1);

use App\Domain\Market\Enums\PriceSource;
use App\Domain\Market\Models\Stock;
use App\Domain\Market\ValueObjects\BarData;
use App\Domain\Shared\Enums\Timeframe;
use App\Infrastructure\MarketData\Contracts\AuthoritativeCalendar;
use App\Infrastructure\MarketData\ProviderResult;
use App\Infrastructure\MarketData\Sharesansar\SharesansarClient;
use App\Infrastructure\MarketData\Sharesansar\SharesansarProvider;
use App\Infrastructure\MarketData\Sharesansar\SharesansarUnavailable;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;

function sharesansarClient(): SharesansarClient
{
    return new SharesansarClient(
        http: app(HttpFactory::class),
        baseUrl: 'https://www.sharesansar.test',
        timeout: 5,
        connectTimeout: 2,
        retries: 1,
        retryDelayMs: 0,
        userAgent: 'tests',
    );
}

function sharesansarStock(string $symbol = 'NABIL'): Stock
{
    $stock = new Stock;
    $stock->symbol = $symbol;

    return $stock;
}

/**
 * One history row as sharesansar sends it: every number a string, some with separators.
 *
 * @param  array<string, string>  $overrides
 * @return array<string, string|int>
 */
function sharesansarRow(string $date, array $overrides = []): array
{
    return [
        'published_date' => $date,
        'open' => '561.00',
        'high' => '563.90',
        'low' => '560.00',
        'close' => '562.90',
        'per_change' => '0.52',
        'traded_quantity' => '71,074.00',
        'traded_amount' => '39,940,701.80',
        'status' => 1,
        'DT_Row_Index' => 1,
        ...$overrides,
    ];
}

/**
 * The page carrying the CSRF token, then one or more history pages in order.
 *
 * @param  list<array{total: int, rows: list<array<string, mixed>>}>  $pages
 */
function fakeSharesansar(array $pages, bool $withCompanyId = true): void
{
    $responses = [];
    foreach ($pages as $page) {
        $responses[] = Http::response([
            'draw' => 1,
            'recordsTotal' => $page['total'],
            'recordsFiltered' => $page['total'],
            'data' => $page['rows'],
        ]);
    }

    $company = '<html><head><meta name="_token" content="tok-123"></head><body>'
        .($withCompanyId ? '<span id="companyid">16</span>' : '')
        .'</body></html>';

    Http::fake([
        'www.sharesansar.test/company/*' => Http::response($company),
        'www.sharesansar.test/company-price-history' => Http::sequence($responses),
    ]);
}

function fetchSharesansar(?CarbonImmutable $since = null, Timeframe $timeframe = Timeframe::D1): ProviderResult
{
    return (new SharesansarProvider(sharesansarClient(), 16, $since))
        ->fetch(sharesansarStock(), $timeframe);
}

it('reports itself as the sharesansar source and trusts its own calendar', function () {
    $provider = new SharesansarProvider(sharesansarClient(), 16);

    expect($provider->source())->toBe(PriceSource::Sharesansar)
        ->and($provider)->toBeInstanceOf(AuthoritativeCalendar::class);
});

it('reads the explicit published date, with no timestamp convention to decode', function () {
    fakeSharesansar([['total' => 2, 'rows' => [
        sharesansarRow('2026-09-18'),
        sharesansarRow('2026-09-17'),
    ]]]);

    expect(array_map(
        static fn (BarData $bar): string => $bar->time->toIso8601ZuluString(),
        fetchSharesansar()->bars,
    ))->toBe(['2026-09-17T00:00:00Z', '2026-09-18T00:00:00Z']);
});

it('carries the turnover merolagani has no field for', function () {
    fakeSharesansar([['total' => 1, 'rows' => [sharesansarRow('2026-09-18')]]]);

    expect(fetchSharesansar()->bars[0])->toMatchObject([
        'open' => '561',
        'high' => '563.9',
        'low' => '560',
        'close' => '562.9',
        'volume' => '71074',
        'turnover' => '39940701.8',
    ]);
});

it('returns bars oldest first, whatever order the pages arrive in', function () {
    fakeSharesansar([
        ['total' => 3, 'rows' => [sharesansarRow('2026-09-18'), sharesansarRow('2026-09-17')]],
        ['total' => 3, 'rows' => [sharesansarRow('2026-09-16')]],
    ]);

    $dates = array_map(
        static fn (BarData $bar): string => $bar->time->toDateString(),
        fetchSharesansar()->bars,
    );

    expect($dates)->toBe(['2026-09-16', '2026-09-17', '2026-09-18']);
});

it('walks pages until it has the whole history', function () {
    $day = fn (int $offset): string => CarbonImmutable::parse('2026-09-18')->subDays($offset)->toDateString();
    fakeSharesansar([
        ['total' => 55, 'rows' => array_map(fn (int $d) => sharesansarRow($day($d)), range(0, 49))],
        ['total' => 55, 'rows' => array_map(fn (int $d) => sharesansarRow($day($d)), range(50, 54))],
    ]);

    $result = fetchSharesansar();

    expect($result->bars)->toHaveCount(55)
        ->and($result->meta['pages_read'])->toBe(2)
        ->and($result->meta['records_total'])->toBe(55);
});

it('stops paging once it reaches the date it was asked to stop at', function () {
    // An incremental run must not walk twenty years of pages to add three bars.
    fakeSharesansar([
        ['total' => 500, 'rows' => [
            sharesansarRow('2026-09-18'),
            sharesansarRow('2026-09-17'),
            sharesansarRow('2026-09-01'),
            sharesansarRow('2026-08-31'),
        ]],
    ]);

    $result = fetchSharesansar(since: CarbonImmutable::parse('2026-09-10', 'UTC'));

    expect($result->bars)->toHaveCount(2)
        ->and($result->meta['pages_read'])->toBe(1);
});

it('skips a bar whose OHLC does not hold together, and keeps the rest', function () {
    fakeSharesansar([['total' => 2, 'rows' => [
        sharesansarRow('2026-09-18'),
        // High below the close, but still above the low, so it is this check that fires.
        sharesansarRow('2026-09-17', ['high' => '561.50']),
    ]]]);

    $result = fetchSharesansar();

    expect($result->bars)->toHaveCount(1)
        ->and($result->skipped)->toHaveCount(1)
        ->and($result->skipped[0]->reason)->toContain('below the open or close');
});

it('skips a row with an unusable date', function () {
    fakeSharesansar([['total' => 1, 'rows' => [sharesansarRow('18/09/2026')]]]);

    $result = fetchSharesansar();

    expect($result->bars)->toBeEmpty()
        ->and($result->skipped[0]->reason)->toContain('Unrecognized date');
});

it('stores a bar whose volume and turnover are blank', function () {
    fakeSharesansar([['total' => 1, 'rows' => [
        sharesansarRow('2026-09-18', ['traded_quantity' => '', 'traded_amount' => '-']),
    ]]]);

    $bar = fetchSharesansar()->bars[0];

    expect($bar->volume)->toBeNull()
        ->and($bar->turnover)->toBeNull()
        ->and($bar->close)->toBe('562.9');
});

it('records that what it read is unadjusted', function () {
    fakeSharesansar([['total' => 0, 'rows' => []]]);

    expect(fetchSharesansar()->meta)->toMatchArray([
        'provider' => 'sharesansar',
        'adjusted' => false,
        'company_id' => 16,
        'resolution' => '1D',
    ]);
});

it('refuses a timeframe sharesansar does not serve', function () {
    fakeSharesansar([['total' => 0, 'rows' => []]]);

    expect(fn () => fetchSharesansar(timeframe: Timeframe::H1))
        ->toThrow(InvalidArgumentException::class, 'daily bars only');
});

it('sends the CSRF token it read from the page', function () {
    fakeSharesansar([['total' => 1, 'rows' => [sharesansarRow('2026-09-18')]]]);

    fetchSharesansar();

    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), 'company-price-history')
        && $request->header('X-CSRF-Token') === ['tok-123']);
});

it('never asks for more rows than the server will give', function () {
    // Above 50 the endpoint answers 202 with an empty body, so a bigger page silently returns nothing.
    fakeSharesansar([['total' => 1, 'rows' => [sharesansarRow('2026-09-18')]]]);

    sharesansarClient()->priceHistory(16, 0, 500);

    Http::assertSent(function ($request): bool {
        if (! str_contains((string) $request->url(), 'company-price-history')) {
            return true;
        }

        return ($request->data()['length'] ?? null) === SharesansarClient::PAGE_SIZE;
    });
});

it('fails loudly when the page carries no CSRF token', function () {
    Http::fake([
        'www.sharesansar.test/company/*' => Http::response('<html><body>maintenance</body></html>'),
    ]);

    expect(fn () => sharesansarClient()->priceHistory(16))
        ->toThrow(SharesansarUnavailable::class, 'no _token meta tag');
});

it('fails loudly on an HTTP error', function () {
    Http::fake([
        'www.sharesansar.test/company/*' => Http::response('<meta name="_token" content="tok">'),
        'www.sharesansar.test/company-price-history' => Http::response('nope', 503),
    ]);

    expect(fn () => sharesansarClient()->priceHistory(16))
        ->toThrow(SharesansarUnavailable::class, 'HTTP 503');
});

it('reads the company id from the page, because the endpoint will not take a symbol', function () {
    fakeSharesansar([['total' => 0, 'rows' => []]]);

    expect(sharesansarClient()->companyId('NABIL'))->toBe(16);
});

it('returns no company id for a symbol sharesansar does not carry', function () {
    Http::fake(['www.sharesansar.test/company/*' => Http::response('not found', 404)]);

    expect(sharesansarClient()->companyId('NOPE'))->toBeNull();
});
