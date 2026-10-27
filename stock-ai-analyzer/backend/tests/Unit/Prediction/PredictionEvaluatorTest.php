<?php

declare(strict_types=1);

use App\Domain\Market\Models\PriceBar;
use App\Domain\Prediction\Enums\OutcomeResult;
use App\Domain\Prediction\Enums\PredictionKind;
use App\Domain\Prediction\Enums\PredictionStatus;
use App\Domain\Prediction\Enums\TradeDirection;
use App\Domain\Prediction\Enums\TriggerType;
use App\Domain\Prediction\Models\Prediction;
use App\Domain\Prediction\Services\PredictionEvaluator;
use App\Domain\Shared\Enums\Timeframe;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The evaluator decides whether the app was right. Every rule in docs/04 §10 gets a case here,
 * including the ones that cost us: gaps, ambiguity and expiry (P6-02).
 *
 * No database: predictions and bars are built in memory, so the table below is the whole truth.
 */
const AS_OF = '2026-09-10T00:00:00Z';

function prediction(array $overrides = []): Prediction
{
    $prediction = new Prediction([
        'kind' => PredictionKind::TradeSetup,
        'direction' => TradeDirection::Long,
        'timeframe' => Timeframe::D1,
        'trigger_type' => TriggerType::CloseAbove,
        'trigger_price' => 580,
        'entry_price' => 582,
        'stop_price' => 550,
        'target_price_1' => 640,
        'invalidation_price' => 540,
        'reference_price' => 570,
        'as_of' => CarbonImmutable::parse(AS_OF),
        'horizon_bars' => 20,
        'setup_score' => 70,
        ...$overrides,
    ]);
    $prediction->status = $overrides['status'] ?? PredictionStatus::Pending;
    $prediction->activated_at = $overrides['activated_at'] ?? null;

    return $prediction;
}

/**
 * @param  list<array{0: float, 1: float, 2: float, 3: float}>  $rows  [open, high, low, close]
 * @return Collection<int, PriceBar>
 */
function bars(array $rows, string $from = AS_OF): Collection
{
    $date = CarbonImmutable::parse($from);

    return collect($rows)->map(function (array $row, int $index) use ($date): PriceBar {
        $bar = new PriceBar([
            'timeframe' => Timeframe::D1,
            'open' => $row[0],
            'high' => $row[1],
            'low' => $row[2],
            'close' => $row[3],
        ]);
        $bar->bar_time = $date->addDays($index + 1);

        return $bar;
    });
}

function evaluate(Prediction $prediction, Collection $priceBars)
{
    return (new PredictionEvaluator)->evaluate($prediction, $priceBars);
}

// --------------------------------------------------------------------------- nothing to do

it('leaves a prediction alone when no bars have printed yet', function () {
    $result = evaluate(prediction(), bars([]));

    expect($result->isResolved())->toBeFalse()
        ->and($result->status)->toBe(PredictionStatus::Pending);
});

it('ignores bars at or before as_of, so an analysis is never scored on what it saw', function () {
    $stale = bars([[600, 700, 500, 650]], '2026-09-01T00:00:00Z');

    expect(evaluate(prediction(), $stale)->isResolved())->toBeFalse();
});

// --------------------------------------------------------------------------- triggers

it('activates on a close above the trigger and fills at that close', function () {
    $result = evaluate(prediction(), bars([
        [570, 578, 565, 575],   // no trigger
        [576, 590, 574, 585],   // closes above 580
    ]));

    expect($result->status)->toBe(PredictionStatus::Active)
        ->and($result->entryFill)->toBe(585.0)
        ->and($result->activatedAt?->toDateString())->toBe('2026-09-12');
});

it('activates on a touch and fills at the trigger', function () {
    $result = evaluate(
        prediction(['trigger_type' => TriggerType::Touch, 'trigger_price' => 560, 'stop_price' => 540]),
        bars([[570, 572, 558, 565]]),
    );

    expect($result->entryFill)->toBe(560.0);
});

it('fills a gapped-through touch at the open, not the trigger', function () {
    $result = evaluate(
        prediction(['trigger_type' => TriggerType::Touch, 'trigger_price' => 560, 'stop_price' => 500]),
        bars([[550, 555, 545, 552]]),
    );

    // The price was never available at 560 that day; it opened below.
    expect($result->entryFill)->toBe(550.0);
});

it('treats an immediate setup as already filled', function () {
    $result = evaluate(
        prediction(['trigger_type' => TriggerType::Immediate, 'entry_price' => 570]),
        bars([[570, 575, 565, 572]]),
    );

    expect($result->entryFill)->toBe(570.0)->and($result->status)->toBe(PredictionStatus::Active);
});

// --------------------------------------------------------------------------- resolutions

it('records a win when the target is reached', function () {
    $result = evaluate(prediction(), bars([
        [576, 590, 574, 585],   // trigger, fill 585
        [586, 645, 584, 640],   // target 640
    ]));

    expect($result->status)->toBe(PredictionStatus::TargetHit)
        ->and($result->result)->toBe(OutcomeResult::Win)
        ->and($result->exitPrice)->toBe(640.0)
        // Risk was 585 - 550 = 35; reward 55.
        ->and($result->rMultiple)->toBe(1.57);
});

it('records a loss when the stop is hit', function () {
    $result = evaluate(prediction(), bars([
        [576, 590, 574, 585],
        [584, 586, 548, 552],
    ]));

    expect($result->status)->toBe(PredictionStatus::Stopped)
        ->and($result->result)->toBe(OutcomeResult::Loss)
        ->and($result->exitPrice)->toBe(550.0)
        ->and($result->rMultiple)->toBe(-1.0);
});

it('fills a gap through the stop at the open, not at the stop', function () {
    $result = evaluate(prediction(), bars([
        [576, 590, 574, 585],
        [520, 530, 515, 525],   // gapped below the 550 stop
    ]));

    expect($result->exitPrice)->toBe(520.0)
        // The loss is worse than 1R, and the record says so.
        ->and($result->rMultiple)->toBeLessThan(-1.0);
});

it('counts a bar that hit both the stop and the target as a loss', function () {
    $result = evaluate(prediction(), bars([
        [576, 590, 574, 585],
        [586, 645, 548, 600],   // both levels inside one bar
    ]));

    expect($result->result)->toBe(OutcomeResult::Loss)
        ->and($result->ambiguousBar)->toBeTrue();
});

it('invalidates a setup that breaks down before triggering', function () {
    $result = evaluate(prediction(), bars([
        [570, 575, 530, 535],   // closes below the 540 invalidation
    ]));

    expect($result->status)->toBe(PredictionStatus::Invalidated)
        ->and($result->result)->toBe(OutcomeResult::Invalidated)
        ->and($result->entryFill)->toBeNull();
});

it('expires a setup that never triggered', function () {
    $result = evaluate(
        prediction(['horizon_bars' => 3]),
        bars([[570, 575, 565, 572], [571, 576, 566, 573], [572, 577, 567, 574]]),
    );

    expect($result->status)->toBe(PredictionStatus::Expired)
        ->and($result->result)->toBe(OutcomeResult::Expired)
        ->and($result->entryFill)->toBeNull();
});

it('marks an open position to market when the horizon runs out', function () {
    $result = evaluate(
        prediction(['horizon_bars' => 2]),
        bars([[576, 590, 574, 585], [586, 600, 584, 595]]),
    );

    expect($result->status)->toBe(PredictionStatus::Expired)
        ->and($result->exitPrice)->toBe(595.0)
        ->and($result->rMultiple)->toBe(0.29);
});

it('tracks how far the trade went for and against you', function () {
    $result = evaluate(prediction(), bars([
        [576, 590, 574, 585],   // fill 585
        [586, 620, 560, 600],   // +35 favourable, -25 adverse
    ]));

    expect($result->mfePct)->toBeGreaterThan(5.9)
        ->and($result->maePct)->toBeGreaterThan(4.2);
});

// --------------------------------------------------------------------------- shorts

it('scores a short the other way round', function () {
    $short = prediction([
        'direction' => TradeDirection::Short,
        'trigger_type' => TriggerType::CloseBelow,
        'trigger_price' => 560,
        'stop_price' => 590,
        'target_price_1' => 500,
        'invalidation_price' => 600,
    ]);

    $result = evaluate($short, bars([
        [570, 575, 550, 555],   // closes below 560, fill 555
        [554, 560, 495, 500],   // target
    ]));

    expect($result->result)->toBe(OutcomeResult::Win)
        ->and($result->exitPrice)->toBe(500.0)
        ->and($result->rMultiple)->toBe(1.57);
});

it('stops a short out above the entry', function () {
    $short = prediction([
        'direction' => TradeDirection::Short,
        'trigger_type' => TriggerType::CloseBelow,
        'trigger_price' => 560,
        'stop_price' => 590,
        'target_price_1' => 500,
        'invalidation_price' => 620,
    ]);

    $result = evaluate($short, bars([[570, 575, 550, 555], [556, 595, 554, 592]]));

    expect($result->result)->toBe(OutcomeResult::Loss)->and($result->exitPrice)->toBe(590.0);
});

// --------------------------------------------------------------------------- directional bias

it('scores a directional bias at the horizon bar', function () {
    $bias = prediction([
        'kind' => PredictionKind::DirectionalBias,
        'trigger_type' => TriggerType::Immediate,
        'horizon_bars' => 3,
        'reference_price' => 570,
        'setup_score' => 80,
    ]);

    $result = evaluate($bias, bars([
        [570, 580, 565, 575],
        [575, 585, 570, 580],
        [580, 600, 578, 595],   // horizon bar closes above the reference
    ]));

    expect($result->result)->toBe(OutcomeResult::Win)
        ->and($result->directionCorrect)->toBeTrue()
        // Brier: (0.80 - 1)² = 0.04.
        ->and($result->brierScore)->toBe(0.04)
        ->and($result->exitPrice)->toBe(595.0);
});

it('counts a wrong directional call as a loss with a worse Brier score', function () {
    $bias = prediction([
        'kind' => PredictionKind::DirectionalBias,
        'trigger_type' => TriggerType::Immediate,
        'horizon_bars' => 2,
        'reference_price' => 570,
        'setup_score' => 80,
    ]);

    $result = evaluate($bias, bars([[570, 575, 560, 565], [565, 568, 540, 545]]));

    expect($result->result)->toBe(OutcomeResult::Loss)
        ->and($result->directionCorrect)->toBeFalse()
        // (0.80 - 0)² = 0.64: confident and wrong is the expensive combination.
        ->and($result->brierScore)->toBe(0.64);
});

it('waits for the full horizon before scoring a bias', function () {
    $bias = prediction([
        'kind' => PredictionKind::DirectionalBias,
        'trigger_type' => TriggerType::Immediate,
        'horizon_bars' => 5,
    ]);

    expect(evaluate($bias, bars([[570, 580, 565, 575]]))->isResolved())->toBeFalse();
});

// --------------------------------------------------------------------------- resuming

it('continues an already-active setup from its recorded fill', function () {
    $active = prediction([
        'status' => PredictionStatus::Active,
        'entry_price' => 582,
        'activated_at' => CarbonImmutable::parse('2026-09-11T00:00:00Z'),
    ]);

    $result = evaluate($active, bars([[583, 645, 580, 641]]));

    expect($result->result)->toBe(OutcomeResult::Win)
        ->and($result->entryFill)->toBe(582.0)
        ->and($result->activatedAt?->toDateString())->toBe('2026-09-11');
});

it('leaves R null when the prediction carried no stop', function () {
    $result = evaluate(
        prediction(['stop_price' => null, 'invalidation_price' => null]),
        bars([[576, 590, 574, 585], [586, 645, 584, 640]]),
    );

    expect($result->result)->toBe(OutcomeResult::Win)->and($result->rMultiple)->toBeNull();
});
