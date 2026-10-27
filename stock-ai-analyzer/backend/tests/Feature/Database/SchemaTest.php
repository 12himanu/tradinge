<?php

declare(strict_types=1);

use App\Domain\Analysis\Enums\AnalysisStatus;
use App\Domain\Analysis\Enums\DataTier;
use App\Domain\Analysis\Enums\Decision;
use App\Domain\Analysis\Enums\DetectionMethod;
use App\Domain\Analysis\Enums\FeedbackIssue;
use App\Domain\Analysis\Enums\IndicatorStatus;
use App\Domain\Analysis\Enums\LevelSource;
use App\Domain\Analysis\Enums\LevelType;
use App\Domain\Analysis\Enums\NarrativeSource;
use App\Domain\Analysis\Enums\PatternCategory;
use App\Domain\Analysis\Enums\PatternStatus;
use App\Domain\Analysis\Enums\PositionStatus;
use App\Domain\Analysis\Models\Analysis;
use App\Domain\Analysis\Models\AnalysisIndicator;
use App\Domain\Analysis\Models\AnalysisLevel;
use App\Domain\Analysis\Models\AnalysisPattern;
use App\Domain\Chart\Enums\ChartPlatform;
use App\Domain\Chart\Enums\UploadStatus;
use App\Domain\Journal\Enums\ExitReason;
use App\Domain\Journal\Enums\TradeSide;
use App\Domain\Journal\Enums\TradeStatus;
use App\Domain\Journal\Models\TradeJournalEntry;
use App\Domain\Market\Enums\InstrumentType;
use App\Domain\Market\Enums\PriceSource;
use App\Domain\Market\Models\PriceBar;
use App\Domain\Prediction\Enums\EvaluationMethod;
use App\Domain\Prediction\Enums\OutcomeResult;
use App\Domain\Prediction\Enums\PredictionKind;
use App\Domain\Prediction\Enums\PredictionStatus;
use App\Domain\Prediction\Enums\Scenario;
use App\Domain\Prediction\Enums\TradeDirection;
use App\Domain\Prediction\Enums\TriggerType;
use App\Domain\Prediction\Models\Outcome;
use App\Domain\Prediction\Models\Prediction;
use App\Domain\Shared\Enums\Bias;
use App\Domain\Shared\Enums\Timeframe;
use App\Domain\Shared\Enums\TradingStyle;
use Illuminate\Support\Facades\DB;

it('creates the full model graph through factories', function () {
    $outcome = Outcome::factory()->create();
    $prediction = $outcome->prediction;
    $analysis = $prediction->analysis;

    AnalysisIndicator::query()->create([
        'analysis_id' => $analysis->id, 'key' => 'rsi_14', 'name' => 'rsi', 'params' => ['period' => 14],
        'value' => 63.4, 'signal' => Bias::Bullish, 'status' => IndicatorStatus::Computed, 'evidence_id' => 'E7',
    ]);
    AnalysisPattern::query()->create([
        'analysis_id' => $analysis->id, 'category' => PatternCategory::Classic, 'type' => 'ascending_triangle',
        'direction' => Bias::Bullish, 'status' => PatternStatus::Forming, 'confidence' => 0.72,
        'detection_method' => DetectionMethod::Algorithmic,
    ]);
    AnalysisLevel::query()->create([
        'analysis_id' => $analysis->id, 'type' => LevelType::Resistance, 'price_low' => 580, 'price_high' => 583.5,
        'strength' => 0.81, 'source' => LevelSource::Pivots,
    ]);
    TradeJournalEntry::factory()->create(['analysis_id' => $analysis->id, 'prediction_id' => $prediction->id]);
    PriceBar::factory()->count(3)->create();

    $analysis->refresh();
    expect($analysis->status)->toBe(AnalysisStatus::Completed)
        ->and($analysis->indicators)->toHaveCount(1)
        ->and($analysis->patterns)->toHaveCount(1)
        ->and($analysis->levels)->toHaveCount(1)
        ->and($analysis->predictions)->toHaveCount(1)
        ->and($prediction->outcome?->result)->toBe(OutcomeResult::Win)
        ->and($analysis->user_id)->toBe($prediction->user_id)
        ->and(PriceBar::query()->count())->toBe(3);
});

it('rejects inconsistent OHLC bars', function () {
    expectConstraintViolation(fn () => PriceBar::factory()->create(['open' => 100, 'close' => 101, 'high' => 99, 'low' => 98]));
});

it('rejects values outside the enum sets at the database level', function () {
    $analysis = Analysis::factory()->create();

    expectConstraintViolation(fn () => DB::table('analyses')->where('id', $analysis->id)->update(['status' => 'bogus']));
});

it('enforces price ordering on long trade setups', function () {
    $prediction = Prediction::factory()->create();

    expectConstraintViolation(fn () => DB::table('predictions')->where('id', $prediction->id)->update(['stop_price' => 600]));
});

it('requires a position when the analysis says the user is holding', function () {
    $analysis = Analysis::factory()->create();

    expectConstraintViolation(fn () => DB::table('analyses')->where('id', $analysis->id)->update(['position_status' => 'holding']));
});

it('keeps the track record immutable: an analysis with predictions cannot be hard-deleted', function () {
    $prediction = Prediction::factory()->create();

    expectConstraintViolation(fn () => $prediction->analysis->forceDelete());
});

it('keeps database CHECK constraints in sync with PHP enums', function (string $table, string $column, string $enum) {
    $definition = DB::selectOne(
        'select pg_get_constraintdef(oid) as def from pg_constraint where conname = ?',
        ["{$table}_{$column}_check"],
    )?->def;

    expect($definition)->not->toBeNull();
    preg_match_all("/'([^']+)'::/", (string) $definition, $matches);

    expect(collect($matches[1])->sort()->values()->all())
        ->toEqual(collect($enum::values())->sort()->values()->all());
})->with([
    ['stocks', 'instrument_type', InstrumentType::class],
    ['price_bars', 'timeframe', Timeframe::class],
    ['price_bars', 'source', PriceSource::class],
    ['chart_uploads', 'declared_timeframe', Timeframe::class],
    ['chart_uploads', 'declared_style', TradingStyle::class],
    ['chart_uploads', 'platform', ChartPlatform::class],
    ['chart_uploads', 'status', UploadStatus::class],
    ['analyses', 'timeframe', Timeframe::class],
    ['analyses', 'trading_style', TradingStyle::class],
    ['analyses', 'position_status', PositionStatus::class],
    ['analyses', 'status', AnalysisStatus::class],
    ['analyses', 'data_tier', DataTier::class],
    ['analyses', 'bias', Bias::class],
    ['analyses', 'decision', Decision::class],
    ['analyses', 'narrative_source', NarrativeSource::class],
    ['analysis_indicators', 'signal', Bias::class],
    ['analysis_indicators', 'status', IndicatorStatus::class],
    ['analysis_patterns', 'category', PatternCategory::class],
    ['analysis_patterns', 'direction', Bias::class],
    ['analysis_patterns', 'status', PatternStatus::class],
    ['analysis_patterns', 'detection_method', DetectionMethod::class],
    ['analysis_levels', 'type', LevelType::class],
    ['analysis_levels', 'source', LevelSource::class],
    ['analysis_feedback', 'issue_type', FeedbackIssue::class],
    ['predictions', 'kind', PredictionKind::class],
    ['predictions', 'scenario', Scenario::class],
    ['predictions', 'direction', TradeDirection::class],
    ['predictions', 'timeframe', Timeframe::class],
    ['predictions', 'trigger_type', TriggerType::class],
    ['predictions', 'status', PredictionStatus::class],
    ['outcomes', 'result', OutcomeResult::class],
    ['outcomes', 'evaluation_method', EvaluationMethod::class],
    ['trade_journal_entries', 'side', TradeSide::class],
    ['trade_journal_entries', 'status', TradeStatus::class],
    ['trade_journal_entries', 'exit_reason', ExitReason::class],
]);
