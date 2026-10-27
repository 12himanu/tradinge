<?php

declare(strict_types=1);

use App\Application\Jobs\AnalyzeChartJob;
use App\Application\Services\AnalysisProgress;
use App\Domain\Analysis\Enums\AnalysisStatus;
use App\Domain\Analysis\Enums\Decision;
use App\Domain\Analysis\Events\AnalysisCompleted;
use App\Domain\Analysis\Exceptions\EngineBusy;
use App\Domain\Analysis\Exceptions\EngineUnavailable;
use App\Domain\Analysis\Models\Analysis;
use App\Domain\Market\Models\PriceBar;
use App\Domain\Market\Models\Stock;
use App\Domain\Shared\Enums\Timeframe;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/**
 * The job's job is to always leave a terminal row behind (P4D-04, P4D-10).
 */
function queuedAnalysis(): Analysis
{
    $stock = Stock::factory()->create(['symbol' => 'HDL']);
    $date = now()->startOfDay()->subDays(70);
    for ($i = 0; $i < 70; $i++) {
        $date = $date->addDay();
        PriceBar::factory()->create([
            'stock_id' => $stock->id,
            'timeframe' => Timeframe::D1,
            'bar_time' => $date,
        ]);
    }

    return Analysis::factory()->create([
        'stock_id' => $stock->id,
        'status' => AnalysisStatus::Queued,
        'timeframe' => Timeframe::D1,
        'queued_at' => now(),
    ]);
}

function runJob(Analysis $analysis): void
{
    app()->call([new AnalyzeChartJob($analysis->id), 'handle']);
}

it('runs an analysis end to end and stores the result', function () {
    Event::fake([AnalysisCompleted::class]);
    $analysis = queuedAnalysis();
    fakeAnalyzeStream([
        ['event' => 'accepted', 'analysis_id' => $analysis->id],
        ['event' => 'stage', 'stage' => 'indicators', 'progress' => 0.2],
        ['event' => 'result', 'data' => engineResultFixture()],
    ]);

    runJob($analysis);
    $analysis->refresh();

    expect($analysis->status)->toBe(AnalysisStatus::Completed)
        ->and($analysis->decision)->toBe(Decision::Buy)
        ->and($analysis->current_stage)->toBeNull()
        ->and($analysis->duration_ms)->toBeGreaterThanOrEqual(0)
        ->and($analysis->predictions)->toHaveCount(2);

    Event::assertDispatched(AnalysisCompleted::class);
});

it('publishes stage progress while it runs', function () {
    $analysis = queuedAnalysis();
    fakeAnalyzeStream([
        ['event' => 'stage', 'stage' => 'indicators', 'progress' => 0.2],
        ['event' => 'stage', 'stage' => 'scoring', 'progress' => 0.8],
        ['event' => 'result', 'data' => engineResultFixture()],
    ]);

    runJob($analysis);

    expect(app(AnalysisProgress::class)->read($analysis->id))
        ->toMatchArray(['stage' => 'done', 'progress' => 1.0]);
});

it('marks the analysis failed when the engine reports an error', function () {
    $analysis = queuedAnalysis();
    fakeAnalyzeStream([
        ['event' => 'accepted', 'analysis_id' => $analysis->id],
        ['event' => 'error', 'error' => ['code' => 'INSUFFICIENT_DATA', 'message' => 'Only 12 bars.']],
    ]);

    runJob($analysis);
    $analysis->refresh();

    expect($analysis->status)->toBe(AnalysisStatus::Failed)
        ->and($analysis->error_code)->toBe('INSUFFICIENT_DATA')
        ->and($analysis->error_message)->toContain('12 bars')
        ->and($analysis->completed_at)->not->toBeNull();
});

it('rethrows transient failures so the queue retries them', function () {
    $analysis = queuedAnalysis();
    Http::fake(fn () => throw new ConnectionException('Connection refused'));

    expect(fn () => runJob($analysis))->toThrow(EngineUnavailable::class);

    expect($analysis->refresh()->status)->toBe(AnalysisStatus::Failed)
        ->and($analysis->error_code)->toBe('ENGINE_UNAVAILABLE');
});

it('does not retry a rejected request', function () {
    $analysis = queuedAnalysis();
    Http::fake(['engine.test/v1/analyze' => Http::response([
        'error' => ['code' => 'INVALID_IMAGE_PATH', 'message' => 'Outside the storage root.'],
    ], 422)]);

    runJob($analysis);   // no exception: retrying would fail identically

    expect($analysis->refresh()->error_code)->toBe('INVALID_IMAGE_PATH');
});

it('backs off when the engine is busy', function () {
    $analysis = queuedAnalysis();
    Http::fake(['engine.test/v1/analyze' => Http::response([
        'error' => ['code' => 'ENGINE_BUSY', 'message' => 'Busy.', 'details' => ['retry_after_seconds' => 30]],
    ], 503)]);

    expect(fn () => runJob($analysis))->toThrow(EngineBusy::class);
    expect($analysis->refresh()->error_code)->toBe('ENGINE_BUSY');
});

it('ignores an analysis that was cancelled before the worker picked it up', function () {
    $analysis = queuedAnalysis();
    $analysis->forceFill(['status' => AnalysisStatus::Cancelled])->save();
    Http::fake();

    runJob($analysis);

    expect($analysis->refresh()->status)->toBe(AnalysisStatus::Cancelled);
    Http::assertNothingSent();
});

it('ignores an analysis row that no longer exists', function () {
    Http::fake();

    app()->call([new AnalyzeChartJob('01JNONEXISTENTANALYSIS0000'), 'handle']);

    Http::assertNothingSent();
});

it('marks the analysis failed when the queue gives up', function () {
    $analysis = queuedAnalysis();

    (new AnalyzeChartJob($analysis->id))->failed(new RuntimeException('Worker died'));

    expect($analysis->refresh()->status)->toBe(AnalysisStatus::Failed)
        ->and($analysis->error_code)->toBe('ANALYSIS_FAILED')
        ->and($analysis->error_message)->toBe('Worker died');
});

it('leaves a completed analysis alone when a late retry fails', function () {
    $analysis = queuedAnalysis();
    fakeAnalyzeStream([['event' => 'result', 'data' => engineResultFixture()]]);
    runJob($analysis);

    (new AnalyzeChartJob($analysis->id))->failed(new RuntimeException('Late failure'));

    expect($analysis->refresh()->status)->toBe(AnalysisStatus::Completed)
        ->and($analysis->error_code)->toBeNull();
});
