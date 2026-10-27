<?php

declare(strict_types=1);

use App\Application\Actions\RequestAnalysis;
use App\Application\DTOs\RequestAnalysisData;
use App\Application\Jobs\AnalyzeChartJob;
use App\Domain\Analysis\Enums\AnalysisStatus;
use App\Domain\Analysis\Enums\PositionStatus;
use App\Domain\Analysis\Exceptions\AnalysisAlreadyRunning;
use App\Domain\Analysis\Models\Analysis;
use App\Domain\Chart\Models\ChartUpload;
use App\Domain\Identity\Models\User;
use App\Domain\Market\Models\Exchange;
use App\Domain\Market\Models\PriceBar;
use App\Domain\Market\Models\Stock;
use App\Domain\Shared\Enums\Timeframe;
use App\Domain\Shared\Enums\TradingStyle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

function requestData(array $overrides = []): RequestAnalysisData
{
    return new RequestAnalysisData(
        timeframe: $overrides['timeframe'] ?? Timeframe::D1,
        tradingStyle: $overrides['trading_style'] ?? TradingStyle::Swing,
        positionStatus: $overrides['position_status'] ?? PositionStatus::None,
        positionEntryPrice: $overrides['position_entry_price'] ?? null,
        positionQuantity: $overrides['position_quantity'] ?? null,
        userThesis: $overrides['user_thesis'] ?? null,
        force: $overrides['force'] ?? false,
        symbol: $overrides['symbol'] ?? null,
        exchange: $overrides['exchange'] ?? null,
        confirmSymbol: $overrides['confirm_symbol'] ?? false,
    );
}

beforeEach(function (): void {
    Queue::fake();
    // A default exchange, the way the seeder sets one up: it is what lets a bare ticker resolve.
    $this->exchange = Exchange::factory()->create(['code' => 'NEPSE']);
    $this->user = User::factory()->create(['default_exchange_id' => $this->exchange->id]);
    $this->upload = ChartUpload::factory()->for($this->user)->create();
});

it('creates a queued analysis and dispatches the job', function () {
    $analysis = app(RequestAnalysis::class)($this->user, $this->upload, requestData());

    expect($analysis->status)->toBe(AnalysisStatus::Queued)
        ->and($analysis->chart_upload_id)->toBe($this->upload->id)
        ->and($analysis->queued_at)->not->toBeNull();

    Queue::assertPushed(AnalyzeChartJob::class, fn (AnalyzeChartJob $job) => $job->analysisId === $analysis->id);
});

it('queues on the ai queue so vision work never blocks the default queue', function () {
    app(RequestAnalysis::class)($this->user, $this->upload, requestData());

    Queue::assertPushedOn('ai', AnalyzeChartJob::class);
});

it('reuses an identical completed analysis instead of running it again', function () {
    $existing = Analysis::factory()->for($this->user)->create([
        'chart_upload_id' => $this->upload->id,
        'status' => AnalysisStatus::Completed,
        'timeframe' => Timeframe::D1,
        'trading_style' => TradingStyle::Swing,
        'position_status' => PositionStatus::None,
    ]);

    $analysis = app(RequestAnalysis::class)($this->user, $this->upload, requestData());

    expect($analysis->id)->toBe($existing->id);
    Queue::assertNothingPushed();
});

it('runs again when the inputs differ', function () {
    Analysis::factory()->for($this->user)->create([
        'chart_upload_id' => $this->upload->id,
        'status' => AnalysisStatus::Completed,
        'trading_style' => TradingStyle::Swing,
    ]);

    $analysis = app(RequestAnalysis::class)(
        $this->user,
        $this->upload,
        requestData(['trading_style' => TradingStyle::Positional]),
    );

    expect($analysis->status)->toBe(AnalysisStatus::Queued);
    Queue::assertPushed(AnalyzeChartJob::class);
});

it('runs again when force is set', function () {
    $existing = Analysis::factory()->for($this->user)->create([
        'chart_upload_id' => $this->upload->id,
        'status' => AnalysisStatus::Completed,
        'timeframe' => Timeframe::D1,
        'trading_style' => TradingStyle::Swing,
        'position_status' => PositionStatus::None,
    ]);

    $analysis = app(RequestAnalysis::class)($this->user, $this->upload, requestData(['force' => true]));

    expect($analysis->id)->not->toBe($existing->id)
        ->and($analysis->status)->toBe(AnalysisStatus::Queued);
});

it('refuses to queue a second run while one is in flight', function (AnalysisStatus $status) {
    Analysis::factory()->for($this->user)->create([
        'chart_upload_id' => $this->upload->id,
        'status' => $status,
    ]);

    expect(fn () => app(RequestAnalysis::class)($this->user, $this->upload, requestData()))
        ->toThrow(AnalysisAlreadyRunning::class);

    Queue::assertNothingPushed();
})->with([AnalysisStatus::Queued, AnalysisStatus::Running]);

it('reuses a partial analysis too: it is a real answer, just a limited one', function () {
    $existing = Analysis::factory()->for($this->user)->create([
        'chart_upload_id' => $this->upload->id,
        'status' => AnalysisStatus::Partial,
        'timeframe' => Timeframe::D1,
        'trading_style' => TradingStyle::Swing,
        'position_status' => PositionStatus::None,
    ]);

    expect(app(RequestAnalysis::class)($this->user, $this->upload, requestData())->id)->toBe($existing->id);
});

it('does not reuse a failed analysis', function () {
    Analysis::factory()->for($this->user)->create([
        'chart_upload_id' => $this->upload->id,
        'status' => AnalysisStatus::Failed,
        'timeframe' => Timeframe::D1,
        'trading_style' => TradingStyle::Swing,
    ]);

    expect(app(RequestAnalysis::class)($this->user, $this->upload, requestData())->status)
        ->toBe(AnalysisStatus::Queued);
});

it('records the position when the user says they are holding', function () {
    $analysis = app(RequestAnalysis::class)($this->user, $this->upload, requestData([
        'position_status' => PositionStatus::Holding,
        'position_entry_price' => 540.5,
        'position_quantity' => 100.0,
    ]));

    expect($analysis->position_status)->toBe(PositionStatus::Holding)
        ->and((float) $analysis->position_entry_price)->toBe(540.5)
        ->and((float) $analysis->position_quantity)->toBe(100.0);
});

/**
 * The price data is part of the inputs (bug fix, 2026-09-17).
 *
 * Before this, reuse was keyed on the chart, timeframe, style and position only. A user whose
 * first run said "Tier C: no bars, no numbers" could import the exact CSV that was missing, re-run,
 * and be handed the same report back with nothing to indicate the data had arrived.
 */
function uploadForStock(User $user): ChartUpload
{
    // On the user's own exchange, so the stock is reachable the way a real one would be.
    $stock = Stock::factory()->create([
        'symbol' => 'NABIL',
        'exchange_id' => $user->default_exchange_id,
    ]);

    return ChartUpload::factory()->for($user)->create([
        'stock_id' => $stock->id,
        'declared_symbol' => 'NABIL',
        'declared_timeframe' => Timeframe::D1,
    ]);
}

/** Import `$count` daily bars ending on `$lastDate`, the way a CSV import would. */
function importBars(ChartUpload $upload, int $count, string $lastDate = '2026-09-16'): void
{
    $date = CarbonImmutable::parse($lastDate);
    for ($i = 0; $i < $count; $i++) {
        PriceBar::factory()->create([
            'stock_id' => $upload->stock_id,
            'timeframe' => Timeframe::D1,
            'bar_time' => $date->subDays($i),
        ]);
    }
}

/** @param  array<string, mixed>  $overrides */
function priorRun(User $user, ChartUpload $upload, array $overrides): Analysis
{
    return Analysis::factory()->for($user)->create([
        'chart_upload_id' => $upload->id,
        'stock_id' => $upload->stock_id,
        'status' => AnalysisStatus::Partial,
        'timeframe' => Timeframe::D1,
        'trading_style' => TradingStyle::Swing,
        'position_status' => PositionStatus::None,
        ...$overrides,
    ]);
}

it('re-runs a bars-less analysis once price data has been imported', function () {
    $upload = uploadForStock($this->user);

    // The first run: no bars, so no as_of and nothing to count. This is the Tier C report.
    $stale = priorRun($this->user, $upload, ['as_of' => null, 'price_bar_count' => 0]);

    // The user imports the CSV that was missing.
    importBars($upload, 83);

    $analysis = app(RequestAnalysis::class)($this->user, $upload, requestData());

    expect($analysis->id)->not->toBe($stale->id)
        ->and($analysis->status)->toBe(AnalysisStatus::Queued)
        ->and($analysis->price_bar_count)->toBe(83)
        ->and($analysis->as_of?->toDateString())->toBe('2026-09-16');

    Queue::assertPushed(AnalyzeChartJob::class);
});

it('re-runs when older history is back-filled, which leaves as_of unmoved', function () {
    $upload = uploadForStock($this->user);
    importBars($upload, 250);

    // Same latest bar, fewer bars behind it: EMA 200 was unavailable then and is available now.
    priorRun($this->user, $upload, [
        'status' => AnalysisStatus::Completed,
        'as_of' => CarbonImmutable::parse('2026-09-16'),
        'price_bar_count' => 83,
    ]);

    $analysis = app(RequestAnalysis::class)($this->user, $upload, requestData());

    expect($analysis->status)->toBe(AnalysisStatus::Queued)
        ->and($analysis->price_bar_count)->toBe(250);
});

it('still reuses a run built on exactly the same bars', function () {
    $upload = uploadForStock($this->user);
    importBars($upload, 83);

    $existing = priorRun($this->user, $upload, [
        'status' => AnalysisStatus::Completed,
        'as_of' => CarbonImmutable::parse('2026-09-16'),
        'price_bar_count' => 83,
    ]);

    expect(app(RequestAnalysis::class)($this->user, $upload, requestData())->id)->toBe($existing->id);
    Queue::assertNothingPushed();
});

it('reuses a pre-migration analysis on its as_of alone, rather than discarding it', function () {
    $upload = uploadForStock($this->user);
    importBars($upload, 83);

    // Written before price_bar_count existed: as_of is all it recorded.
    $legacy = priorRun($this->user, $upload, [
        'status' => AnalysisStatus::Completed,
        'as_of' => CarbonImmutable::parse('2026-09-16'),
        'price_bar_count' => null,
    ]);

    expect(app(RequestAnalysis::class)($this->user, $upload, requestData())->id)->toBe($legacy->id);
});

it('records how much price data a new analysis was built on', function () {
    $upload = uploadForStock($this->user);
    importBars($upload, 120);

    $analysis = app(RequestAnalysis::class)($this->user, $upload, requestData());

    expect($analysis->price_bar_count)->toBe(120)
        ->and($analysis->as_of?->toDateString())->toBe('2026-09-16');
});

/**
 * Attaching a ticker after the fact.
 *
 * A chart uploaded without a symbol is stranded at the vision-only tier, and the obvious remedy —
 * upload it again with the symbol filled in — does not work: the sha256 dedupe hands back the
 * original row and discards what was typed. So the correction has to be possible at analyse time,
 * and these pin that it is.
 */
it('attaches a symbol supplied at analyse time to an upload that has none', function () {
    $stock = Stock::factory()->for($this->exchange)->create(['symbol' => 'NABIL']);

    $analysis = app(RequestAnalysis::class)(
        $this->user,
        $this->upload,
        requestData(['symbol' => 'nabil']),
    );

    expect($this->upload->refresh()->stock_id)->toBe($stock->id)
        ->and($this->upload->declared_symbol)->toBe('NABIL')
        ->and($analysis->stock_id)->toBe($stock->id);
});

it('uses the newly attached symbol to find price bars', function () {
    $stock = Stock::factory()->for($this->exchange)->create(['symbol' => 'NABIL']);
    $date = CarbonImmutable::parse('2026-09-16');
    for ($i = 0; $i < 80; $i++) {
        PriceBar::factory()->create([
            'stock_id' => $stock->id,
            'timeframe' => Timeframe::D1,
            'bar_time' => $date->subDays($i),
        ]);
    }

    $analysis = app(RequestAnalysis::class)(
        $this->user,
        $this->upload,
        requestData(['symbol' => 'NABIL']),
    );

    // The whole point: it is no longer a vision-only run with nothing to say.
    expect($analysis->price_bar_count)->toBe(80)
        ->and($analysis->as_of)->not->toBeNull();
});

it('does not hand back the earlier vision-only run once a symbol is attached', function () {
    $first = app(RequestAnalysis::class)($this->user, $this->upload, requestData());
    $first->forceFill(['status' => AnalysisStatus::Completed])->save();

    Stock::factory()->for($this->exchange)->create(['symbol' => 'NABIL']);
    $second = app(RequestAnalysis::class)(
        $this->user,
        $this->upload,
        requestData(['symbol' => 'NABIL']),
    );

    expect($second->id)->not->toBe($first->id);
});

/**
 * Correcting a chart that is attached to the wrong ticker.
 *
 * This used to be refused: an upload that already resolved was left alone, so a chart of one
 * company stayed labelled as another one no matter what the user typed — and nothing said so, so
 * the same report about the wrong instrument came back looking like an answer. A symbol passed in
 * here is the person who is looking at the screenshot saying what it is.
 */
it('re-points an upload attached to the wrong ticker', function () {
    $upload = uploadForStock($this->user);
    $right = Stock::factory()->for($this->exchange)->create(['symbol' => 'PCBL']);

    $analysis = app(RequestAnalysis::class)($this->user, $upload, requestData(['symbol' => 'pcbl']));

    expect($upload->refresh()->stock_id)->toBe($right->id)
        ->and($upload->declared_symbol)->toBe('PCBL')
        ->and($analysis->stock_id)->toBe($right->id);
});

it('does not hand back the run for the ticker it was corrected from', function () {
    $upload = uploadForStock($this->user);
    $wrong = app(RequestAnalysis::class)($this->user, $upload, requestData());
    $wrong->forceFill(['status' => AnalysisStatus::Completed])->save();

    Stock::factory()->for($this->exchange)->create(['symbol' => 'PCBL']);
    $corrected = app(RequestAnalysis::class)($this->user, $upload, requestData(['symbol' => 'PCBL']));

    expect($corrected->id)->not->toBe($wrong->id);
});

/**
 * The way past a chart-identity refusal.
 *
 * The engine reads the chart's title with a small vision model and refuses a screenshot it reads
 * as another company. When it is wrong, re-running cannot help — the same image reads the same way
 * — so the confirmation rides on the upload and the engine is told not to ask again.
 */
it('records a confirmation that the chart really is the ticker it carries', function () {
    $upload = uploadForStock($this->user);

    app(RequestAnalysis::class)($this->user, $upload, requestData(['confirm_symbol' => true]));

    expect($upload->refresh()->symbol_confirmed)->toBeTrue();
});

it('drops the confirmation when the chart is re-pointed at another ticker', function () {
    $upload = uploadForStock($this->user);
    $upload->forceFill(['symbol_confirmed' => true])->save();
    Stock::factory()->for($this->exchange)->create(['symbol' => 'PCBL']);

    app(RequestAnalysis::class)($this->user, $upload, requestData(['symbol' => 'PCBL']));

    // Vouching for NABIL says nothing about PCBL.
    expect($upload->refresh()->symbol_confirmed)->toBeFalse();
});

it('keeps the ticker it has when the correction names nothing we know', function () {
    // An unknown symbol names nothing to re-point at; dropping the link would only lose information.
    $upload = uploadForStock($this->user);
    $original = $upload->stock_id;

    app(RequestAnalysis::class)($this->user, $upload, requestData(['symbol' => 'NOTREAL']));

    expect($upload->refresh()->stock_id)->toBe($original)
        ->and($upload->declared_symbol)->toBe('NABIL');
});

it('stays unresolved when the symbol is not a stock we know', function () {
    $analysis = app(RequestAnalysis::class)(
        $this->user,
        $this->upload,
        requestData(['symbol' => 'NOTREAL']),
    );

    expect($this->upload->refresh()->stock_id)->toBeNull()
        ->and($analysis->stock_id)->toBeNull();
});
