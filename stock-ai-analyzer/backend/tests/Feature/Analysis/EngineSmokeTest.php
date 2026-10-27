<?php

declare(strict_types=1);

use App\Application\Jobs\AnalyzeChartJob;
use App\Domain\Analysis\Enums\AnalysisStatus;
use App\Domain\Analysis\Enums\DataTier;
use App\Domain\Analysis\Models\Analysis;
use App\Domain\Chart\Models\ChartUpload;
use App\Domain\Market\Models\PriceBar;
use App\Domain\Market\Models\Stock;
use App\Domain\Shared\Enums\Timeframe;
use Illuminate\Support\Facades\Storage;

/**
 * The one test that talks to the real engine over HTTP (P4D-11).
 *
 * Everything else fakes the boundary, which proves the wiring but not that the two services agree.
 * This runs a genuine analysis: Laravel builds the request, the engine computes it, Laravel maps
 * the result into rows. It uses the testing database, so it never touches development data.
 *
 * Enable with RUN_ENGINE_TESTS=1, a running engine, and the engine's own HMAC secret:
 *   docker compose exec -e RUN_ENGINE_TESTS=1 -e SMOKE_ENGINE_SECRET=$ENGINE_HMAC_SECRET backend
 *       php artisan test tests/Feature/Analysis/EngineSmokeTest.php
 */
beforeEach(function (): void {
    if (env('RUN_ENGINE_TESTS') !== '1') {
        $this->markTestSkipped('set RUN_ENGINE_TESTS=1 to run against the real engine');
    }

    // phpunit.xml points every other test at a fake engine and a container-local charts folder.
    // This one needs the real service and the storage volume both services share.
    config([
        'stock_ai.engine.url' => env('SMOKE_ENGINE_URL', 'http://ai-engine:8100'),
        'stock_ai.engine.hmac_secret' => env('SMOKE_ENGINE_SECRET', config('stock_ai.engine.hmac_secret')),
        'filesystems.disks.charts.root' => env('SMOKE_CHARTS_ROOT', '/storage/charts'),
    ]);
});

afterEach(function (): void {
    // The shared volume is real storage, not a fake disk: put back what the test wrote.
    foreach (smokeImages() as $path) {
        Storage::disk('charts')->delete($path);
    }
    smokeImages(reset: true);
});

/**
 * Tracks the images this test wrote to the shared volume.
 *
 * @return list<string>
 */
function smokeImages(?string $add = null, bool $reset = false): array
{
    static $paths = [];

    if ($reset) {
        $paths = [];
    }
    if ($add !== null) {
        $paths[] = $add;
    }

    return $paths;
}

/**
 * An upload whose image really exists on the shared storage the engine reads. Phase 4A ignores the
 * pixels, but the engine still checks the path resolves: a chart that vanished is a storage fault.
 */
function uploadWithRealImage(): ChartUpload
{
    $upload = ChartUpload::factory()->create();
    Storage::disk('charts')->put($upload->path, chartImageBytes(1200, 700));
    smokeImages(add: $upload->path);

    return $upload;
}

/**
 * A rising staircase with pullbacks: enough structure for the engine to find levels and swings.
 */
function seedRealisticBars(Stock $stock, int $count = 160): void
{
    $date = now()->startOfDay()->subDays($count);
    $price = 400.0;

    for ($i = 0; $i < $count; $i++) {
        $date = $date->addDay();
        $leg = $i % 20;
        $price += $leg < 14 ? 3.0 : -4.0;          // advance, then retrace
        $wiggle = sin($i / 3) * 2.0;

        $open = $price + $wiggle;
        $close = $price - $wiggle;
        PriceBar::factory()->create([
            'stock_id' => $stock->id,
            'timeframe' => Timeframe::D1,
            'bar_time' => $date,
            'open' => round($open, 2),
            'high' => round(max($open, $close) + 3.5, 2),
            'low' => round(min($open, $close) - 3.5, 2),
            'close' => round($close, 2),
            'volume' => 90_000 + ($i % 7) * 12_000,
        ]);
    }
}

it('runs a real analysis through the engine and stores it', function () {
    $stock = Stock::factory()->create(['symbol' => 'SMOKE']);
    seedRealisticBars($stock);

    $analysis = Analysis::factory()->create([
        'stock_id' => $stock->id,
        'chart_upload_id' => uploadWithRealImage()->id,
        'status' => AnalysisStatus::Queued,
        'timeframe' => Timeframe::D1,
        'queued_at' => now(),
    ]);

    app()->call([new AnalyzeChartJob($analysis->id), 'handle']);
    $analysis->refresh();

    expect($analysis->status)->toBe(AnalysisStatus::Completed)
        ->and($analysis->data_tier)->toBe(DataTier::Ohlcv)
        ->and($analysis->decision)->not->toBeNull()
        ->and($analysis->setup_score)->toBeGreaterThanOrEqual(0)
        ->and($analysis->engine_version)->toContain('+cfg.')
        ->and($analysis->report_markdown)->toContain('Decision:')
        ->and($analysis->duration_ms)->toBeGreaterThan(0);

    // The deterministic stages have a 2 s budget (docs/04 §12); the round trip should be close to it.
    expect($analysis->duration_ms)->toBeLessThan(10_000);

    expect($analysis->indicators)->not->toBeEmpty()
        ->and($analysis->levels)->not->toBeEmpty()
        ->and($analysis->evidence['items'])->not->toBeEmpty();

    foreach ($analysis->predictions as $prediction) {
        expect($prediction->expires_at->isAfter($prediction->as_of))->toBeTrue()
            ->and($prediction->engine_version)->toBe($analysis->engine_version);
    }
});

it('degrades to Tier C when there is not enough history', function () {
    $stock = Stock::factory()->create(['symbol' => 'THIN']);
    seedRealisticBars($stock, 25);

    $analysis = Analysis::factory()->create([
        'stock_id' => $stock->id,
        'chart_upload_id' => uploadWithRealImage()->id,
        'status' => AnalysisStatus::Queued,
        'timeframe' => Timeframe::D1,
        'queued_at' => now(),
    ]);

    app()->call([new AnalyzeChartJob($analysis->id), 'handle']);
    $analysis->refresh();

    expect($analysis->status)->toBe(AnalysisStatus::Partial)
        ->and($analysis->data_tier)->toBe(DataTier::VisionOnly)
        ->and($analysis->setup_score)->toBeNull()
        ->and($analysis->decision_limited)->toBeTrue()
        ->and($analysis->predictions)->toHaveCount(0)
        ->and($analysis->warnings)->not->toBeEmpty();
});
