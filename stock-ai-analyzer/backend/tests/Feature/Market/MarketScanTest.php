<?php

declare(strict_types=1);

use App\Application\Services\ScanStock;
use App\Domain\Analysis\Contracts\AnalysisEngine;
use App\Domain\Analysis\Exceptions\EngineRequestFailed;
use App\Domain\Analysis\Exceptions\EngineUnavailable;
use App\Domain\Analysis\Models\Analysis;
use App\Domain\Analysis\ValueObjects\EngineHealth;
use App\Domain\Identity\Models\User;
use App\Domain\Market\Enums\PriceSource;
use App\Domain\Market\Models\Exchange;
use App\Domain\Market\Models\MarketScan;
use App\Domain\Market\Models\PriceBar;
use App\Domain\Market\Models\Stock;
use App\Domain\Prediction\Models\Prediction;
use App\Domain\Shared\Enums\Timeframe;
use App\Domain\Shared\Enums\TradingStyle;
use Carbon\CarbonImmutable;

/**
 * An engine stand-in: returns a fixed result, or throws, and records what it was asked.
 */
final class FakeEngine implements AnalysisEngine
{
    /** @var list<array<string, mixed>> */
    public array $requests = [];

    /**
     * @param  array<string, mixed>|null  $result
     */
    public function __construct(
        private readonly ?array $result = null,
        private readonly ?Throwable $throws = null,
    ) {}

    public function health(): EngineHealth
    {
        return new EngineHealth(true, null, null);
    }

    public function models(): array
    {
        return [];
    }

    public function analyze(array $request, ?Closure $onEvent = null): array
    {
        $this->requests[] = $request;

        if ($this->throws !== null) {
            throw $this->throws;
        }

        return $this->result ?? [];
    }
}

/**
 * @return array<string, mixed>
 */
function engineScanResult(array $overrides = []): array
{
    return [
        'engine_version' => '0.1.0+test',
        'as_of' => '2026-09-18T00:00:00Z',
        'last_price' => 259.0,
        'bar_count' => 250,
        'data_quality' => 1.0,
        'bias' => 'bullish',
        'setup_score' => 83,
        'decision' => ['action' => 'wait', 'rationale' => 'Good chart, poor price.'],
        'score' => ['factors' => [['key' => 'trend_ema_alignment', 'label' => 'EMA alignment', 'available' => true]]],
        'patterns' => [[
            'category' => 'candlestick', 'type' => 'bullish_engulfing', 'direction' => 'bullish',
            'status' => 'confirmed', 'confidence' => 0.99, 'start_time' => '2026-09-18T00:00:00Z',
        ]],
        'levels' => [['type' => 'support', 'price_low' => 245.0, 'price_high' => 247.0, 'strength' => 0.8]],
        'smc' => [
            'structure' => 'bullish',
            'last_event' => 'choch_bullish',
            'events' => [[
                'category' => 'smc', 'type' => 'liquidity_sweep', 'direction' => 'bearish',
                'status' => 'confirmed', 'confidence' => 0.6, 'start_time' => '2026-09-16T00:00:00Z',
            ]],
            'dealing_range' => ['low' => 232.0, 'high' => 256.0, 'equilibrium' => 244.0, 'position' => 1.0, 'zone' => 'premium'],
        ],
        'base_rates' => [[
            'category' => 'candlestick', 'type' => 'bullish_engulfing', 'direction' => 'bullish',
            'sample_size' => 101, 'favourable' => 48, 'adverse' => 53, 'unresolved' => 0,
            'favourable_rate' => 0.475, 'adverse_rate' => 0.525, 'unresolved_rate' => 0.0,
            'horizon_bars' => 20, 'barrier_atr' => 1.0, 'median_barrier_pct' => 2.85,
            'median_bars_to_resolution' => 2,
        ]],
        'outlook' => [
            [
                'bars' => 1, 'label' => 'next trading day', 'sample' => 1240, 'up_rate' => 0.512,
                'median_move_pct' => 0.04, 'typical_move_pct' => 1.11, 'low_move_pct' => -2.4,
                'high_move_pct' => 2.6, 'band_low' => 252.8, 'band_high' => 265.7,
                'reference_price' => 259.0, 'base_rates' => [],
            ],
            [
                'bars' => 5, 'label' => 'next week', 'sample' => 1236, 'up_rate' => 0.534,
                'median_move_pct' => 0.21, 'typical_move_pct' => 2.62, 'low_move_pct' => -5.1,
                'high_move_pct' => 6.0, 'band_low' => 245.8, 'band_high' => 274.5,
                'reference_price' => 259.0, 'base_rates' => [],
            ],
        ],
        'trade_plans' => [['kind' => 'pullback', 'viable' => false, 'entry_price' => 256.0, 'why_not' => 'R:R too low']],
        'warnings' => ['EMA 200 computed on 250 bars'],
        'report_markdown' => '# PCBL',
        // Deliberately present, and deliberately never persisted by a scan.
        'predictions' => [['kind' => 'directional_bias', 'direction' => 'long']],
        ...$overrides,
    ];
}

function scanExchange(): Exchange
{
    return Exchange::factory()->create([
        'code' => 'NEPSE',
        'timezone' => 'Asia/Kathmandu',
        'trading_days' => [1, 2, 3, 4, 5],
        'lot_size' => 10,
    ]);
}

function scanStock(Exchange $exchange, string $symbol = 'PCBL', int $bars = 250): Stock
{
    $stock = Stock::factory()->create(['exchange_id' => $exchange->id, 'symbol' => $symbol]);

    $date = CarbonImmutable::parse('2026-09-18T00:00:00Z');
    $rows = [];
    for ($index = 0; $index < $bars; $index++) {
        $rows[] = [
            'stock_id' => $stock->id,
            'timeframe' => Timeframe::D1->value,
            'bar_time' => $date->subDays($bars - 1 - $index)->toIso8601String(),
            'open' => 100, 'high' => 104, 'low' => 98, 'close' => 102,
            'volume' => 1000, 'turnover' => null,
            'source' => PriceSource::Merolagani->value,
        ];
    }
    if ($rows !== []) {
        PriceBar::query()->insert($rows);
    }

    return $stock;
}

function fakeEngine(?array $result = null, ?Throwable $throws = null): FakeEngine
{
    $engine = new FakeEngine($result, $throws);
    app()->instance(AnalysisEngine::class, $engine);

    return $engine;
}

it('reads a stock from stored bars with no chart upload anywhere in sight', function () {
    $engine = fakeEngine(engineScanResult());
    $stock = scanStock(scanExchange());

    $scan = app(ScanStock::class)($stock, TradingStyle::Swing);

    expect($scan->bias?->value)->toBe('bullish')
        ->and($scan->setup_score)->toBe(83)
        ->and($scan->decision?->value)->toBe('wait')
        ->and($scan->structure)->toBe('bullish')
        ->and($scan->range_zone)->toBe('premium')
        ->and($scan->scanned_on->toDateString())->toBe('2026-09-18')
        ->and($engine->requests[0]['image_path'])->toBeNull()
        ->and($engine->requests[0]['bars'])->toHaveCount(250);
});

it('never files a prediction from a scan', function () {
    // Scanning the market nightly must not fill the track record with trades nobody took.
    fakeEngine(engineScanResult());
    $stock = scanStock(scanExchange());

    app(ScanStock::class)($stock, TradingStyle::Swing);

    expect(Prediction::query()->count())->toBe(0)
        ->and(Analysis::query()->count())->toBe(0);
});

it('sorts the findings into traps and opportunities', function () {
    fakeEngine(engineScanResult());
    $stock = scanStock(scanExchange());

    $scan = app(ScanStock::class)($stock, TradingStyle::Swing);

    // A bearish sweep and a bullish read at the top of the range; the engulfing bar is the opening.
    expect($scan->trap_count)->toBe(2)
        ->and($scan->opportunity_count)->toBe(1)
        ->and($scan->signals[0]['kind'])->toBe('trap');
});

it('keeps the base rates the engine measured', function () {
    fakeEngine(engineScanResult());
    $stock = scanStock(scanExchange());

    $scan = app(ScanStock::class)($stock, TradingStyle::Swing);

    expect($scan->base_rates)->toHaveCount(1)
        ->and($scan->base_rates[0]['sample_size'])->toBe(101)
        ->and($scan->base_rates[0]['favourable_rate'])->toBe(0.475);

    // The forecast page is built on these, so a scan that drops them is a blank page.
    expect($scan->outlook)->toHaveCount(2)
        ->and($scan->outlook[0]['bars'])->toBe(1)
        ->and($scan->outlook[0]['up_rate'])->toBe(0.512)
        ->and($scan->outlook[1]['bars'])->toBe(5)
        ->and($scan->outlook[1]['sample'])->toBe(1236);
});

it('sends more bars than a style window, because base rates need the history', function () {
    $engine = fakeEngine(engineScanResult());
    $stock = scanStock(scanExchange(), bars: 900);

    app(ScanStock::class)($stock, TradingStyle::Swing);

    expect($engine->requests[0]['bars'])->toHaveCount(900);
});

it('records why a stock could not be read instead of leaving a hole', function () {
    fakeEngine(engineScanResult());
    $stock = scanStock(scanExchange(), bars: 20);

    $scan = app(ScanStock::class)($stock, TradingStyle::Swing);

    expect($scan->error_code)->toBe('INSUFFICIENT_DATA')
        ->and($scan->error_message)->toContain('20 stored')
        ->and($scan->bias)->toBeNull();
});

it('records an engine refusal against the stock, in the engine own words', function () {
    fakeEngine(throws: new EngineRequestFailed('INSUFFICIENT_DATA', 'ATR could not be computed', 422));
    $stock = scanStock(scanExchange());

    $scan = app(ScanStock::class)($stock, TradingStyle::Swing);

    expect($scan->error_code)->toBe('INSUFFICIENT_DATA')
        ->and($scan->error_message)->toContain('ATR');
});

it('lets an unreachable engine bubble up so the queue retries it', function () {
    fakeEngine(throws: new EngineUnavailable('connection refused'));
    $stock = scanStock(scanExchange());

    expect(fn () => app(ScanStock::class)($stock, TradingStyle::Swing))
        ->toThrow(EngineUnavailable::class);

    expect(MarketScan::query()->count())->toBe(0);
});

it('revises the same row when rescanned on the same session', function () {
    fakeEngine(engineScanResult());
    $stock = scanStock(scanExchange());

    app(ScanStock::class)($stock, TradingStyle::Swing);
    fakeEngine(engineScanResult(['setup_score' => 41, 'bias' => 'bearish']));
    $second = app(ScanStock::class)($stock, TradingStyle::Swing);

    expect(MarketScan::query()->count())->toBe(1)
        ->and($second->setup_score)->toBe(41)
        ->and($second->bias?->value)->toBe('bearish');
});

it('builds the engine request from the owner settings', function () {
    $engine = fakeEngine(engineScanResult());
    $stock = scanStock(scanExchange());
    $user = User::factory()->create(['settings' => [
        'account_size' => 500_000.0, 'risk_per_trade_pct' => 2.0, 'max_position_pct' => 30.0, 'currency' => 'NPR',
    ]]);

    app(ScanStock::class)($stock, TradingStyle::Swing, $user);

    expect($engine->requests[0]['account'])->toMatchArray([
        'equity' => 500_000.0,
        'risk_pct' => 2.0,
        'max_position_pct' => 30.0,
        'lot_size' => 10,
    ]);
});

// ------------------------------------------------------------------------------------- the API

it('requires authentication', function () {
    $this->getJson('/api/v1/market-scan')->assertUnauthorized();
});

it('lists the latest session ranked by score', function () {
    $exchange = scanExchange();
    fakeEngine(engineScanResult());
    $high = scanStock($exchange, 'PCBL');
    app(ScanStock::class)($high, TradingStyle::Swing);

    fakeEngine(engineScanResult(['setup_score' => 30]));
    $low = scanStock($exchange, 'NABIL');
    app(ScanStock::class)($low, TradingStyle::Swing);

    $response = $this->actingAs(User::factory()->create())->getJson('/api/v1/market-scan');

    $response->assertOk()
        ->assertJsonPath('data.0.stock.symbol', 'PCBL')
        ->assertJsonPath('data.1.stock.symbol', 'NABIL')
        ->assertJsonPath('meta.scanned_on', '2026-09-18')
        ->assertJsonPath('meta.summary.scanned', 2)
        ->assertJsonPath('meta.summary.bullish', 2);
});

it('never puts a probability on a setup score', function () {
    fakeEngine(engineScanResult());
    app(ScanStock::class)(scanStock(scanExchange()), TradingStyle::Swing);

    $response = $this->actingAs(User::factory()->create())->getJson('/api/v1/market-scan');

    $response->assertOk()
        ->assertJsonPath('data.0.calibrated_probability', null)
        ->assertJsonPath('data.0.setup_score', 83);
    expect($response->json('meta.score_note'))->toContain('not a probability');
});

it('leaves unreadable stocks out of the ranking but counts them', function () {
    $exchange = scanExchange();
    fakeEngine(engineScanResult());
    app(ScanStock::class)(scanStock($exchange, 'PCBL'), TradingStyle::Swing);
    app(ScanStock::class)(scanStock($exchange, 'TINY', bars: 10), TradingStyle::Swing);

    $response = $this->actingAs(User::factory()->create())->getJson('/api/v1/market-scan');

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.summary.unreadable', 1);
});

it('filters by bias, range zone and the presence of traps', function () {
    $exchange = scanExchange();
    fakeEngine(engineScanResult());
    app(ScanStock::class)(scanStock($exchange, 'PCBL'), TradingStyle::Swing);

    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/api/v1/market-scan?bias=bearish')->assertJsonCount(0, 'data');
    $this->actingAs($user)->getJson('/api/v1/market-scan?bias=bullish')->assertJsonCount(1, 'data');
    $this->actingAs($user)->getJson('/api/v1/market-scan?range_zone=discount')->assertJsonCount(0, 'data');
    $this->actingAs($user)->getJson('/api/v1/market-scan?traps=1')->assertJsonCount(1, 'data');
    $this->actingAs($user)->getJson('/api/v1/market-scan?min_score=90')->assertJsonCount(0, 'data');
});

it('rejects a filter it does not understand', function () {
    $this->actingAs(User::factory()->create())
        ->getJson('/api/v1/market-scan?bias=sideways')
        ->assertStatus(422);
});

it('keeps the list light and the detail complete', function () {
    fakeEngine(engineScanResult());
    $stock = scanStock(scanExchange());
    app(ScanStock::class)($stock, TradingStyle::Swing);
    $user = User::factory()->create();

    $list = $this->actingAs($user)->getJson('/api/v1/market-scan')->json('data.0');
    expect($list)->not->toHaveKey('base_rates')
        ->and($list)->not->toHaveKey('outlook')
        ->and($list)->toHaveKey('top_signals');

    $detail = $this->actingAs($user)->getJson("/api/v1/market-scan/{$stock->id}")->json('data');
    expect($detail)->toHaveKeys(['signals', 'base_rates', 'outlook', 'levels', 'plans', 'factors', 'report_markdown'])
        ->and($detail['base_rates'][0]['sample_size'])->toBe(101)
        ->and($detail['outlook'][1]['label'])->toBe('next week');
});

it('shows a stock whose last session is older than the market', function () {
    // Illiquid NEPSE securities go days without trading. Keying the list to one date would hide
    // exactly the stocks a scanner exists to surface.
    $exchange = scanExchange();
    fakeEngine(engineScanResult());
    app(ScanStock::class)(scanStock($exchange, 'PCBL'), TradingStyle::Swing);

    fakeEngine(engineScanResult(['as_of' => '2026-09-04T00:00:00Z']));
    $stale = Stock::factory()->create(['exchange_id' => $exchange->id, 'symbol' => 'QUIET']);
    $date = CarbonImmutable::parse('2026-09-04T00:00:00Z');
    $rows = [];
    for ($index = 0; $index < 250; $index++) {
        $rows[] = [
            'stock_id' => $stale->id, 'timeframe' => Timeframe::D1->value,
            'bar_time' => $date->subDays(249 - $index)->toIso8601String(),
            'open' => 100, 'high' => 104, 'low' => 98, 'close' => 102,
            'volume' => 1000, 'turnover' => null, 'source' => PriceSource::Merolagani->value,
        ];
    }
    PriceBar::query()->insert($rows);
    app(ScanStock::class)($stale, TradingStyle::Swing);

    $response = $this->actingAs(User::factory()->create())->getJson('/api/v1/market-scan');

    $symbols = array_column(array_column($response->json('data'), 'stock'), 'symbol');
    expect($symbols)->toContain('PCBL')->toContain('QUIET')
        ->and($response->json('meta.summary.scanned'))->toBe(2)
        ->and($response->json('meta.session_only'))->toBeFalse();
});

it('narrows to one session when asked for one', function () {
    $exchange = scanExchange();
    fakeEngine(engineScanResult());
    app(ScanStock::class)(scanStock($exchange, 'PCBL'), TradingStyle::Swing);

    $response = $this->actingAs(User::factory()->create())
        ->getJson('/api/v1/market-scan?scanned_on=2026-09-04');

    $response->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('meta.session_only', true);
});

it('says so plainly when a stock has never been scanned', function () {
    $stock = scanStock(scanExchange());

    $this->actingAs(User::factory()->create())
        ->getJson("/api/v1/market-scan/{$stock->id}")
        ->assertNotFound()
        ->assertJsonPath('error.code', 'NOT_SCANNED');
});
