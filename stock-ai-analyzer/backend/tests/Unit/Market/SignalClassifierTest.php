<?php

declare(strict_types=1);

use App\Domain\Market\Services\SignalClassifier;
use App\Domain\Market\ValueObjects\ScanSignal;

/**
 * @param  list<array<string, mixed>>  $events
 * @param  list<array<string, mixed>>  $patterns
 * @param  array<string, mixed>|null  $range
 * @return list<ScanSignal>
 */
function classify(
    array $events = [],
    array $patterns = [],
    ?array $range = null,
    ?string $bias = 'bullish',
    ?string $structure = 'bullish',
): array {
    return (new SignalClassifier)->classify($events, $patterns, $range, $bias, $structure);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function smcEvent(string $type, string $direction = 'bullish', array $overrides = []): array
{
    return [
        'category' => 'smc',
        'type' => $type,
        'direction' => $direction,
        'status' => 'confirmed',
        'confidence' => 0.6,
        'start_time' => '2026-09-18T00:00:00Z',
        'price_low' => 100.0,
        'price_high' => 110.0,
        ...$overrides,
    ];
}

/**
 * @param  list<ScanSignal>  $signals
 * @return list<ScanSignal>
 */
function ofKind(array $signals, string $kind): array
{
    return array_values(array_filter($signals, static fn (ScanSignal $s): bool => $s->kind === $kind));
}

function firstOfType(array $signals, string $type): ?ScanSignal
{
    foreach ($signals as $signal) {
        if ($signal->type === $type) {
            return $signal;
        }
    }

    return null;
}

it('calls a liquidity sweep a trap whichever way it ran', function (string $direction) {
    $signals = classify(events: [smcEvent('liquidity_sweep', $direction)]);

    expect($signals[0]->kind)->toBe(SignalClassifier::TRAP);
})->with(['bullish', 'bearish']);

it('calls resting stops a trap', function (string $type) {
    $signals = classify(events: [smcEvent($type)]);

    expect($signals[0]->kind)->toBe(SignalClassifier::TRAP)
        ->and($signals[0]->headline)->toContain('stops resting');
})->with(['equal_highs', 'equal_lows']);

it('calls a change of character against the bias a trap', function () {
    $signals = classify(events: [smcEvent('choch', 'bearish')], bias: 'bullish');

    expect(firstOfType($signals, 'choch')?->kind)->toBe(SignalClassifier::TRAP);
});

it('calls a change of character with the bias mere context', function () {
    $signals = classify(events: [smcEvent('choch', 'bullish')], bias: 'bullish');

    expect(firstOfType($signals, 'choch')?->kind)->toBe(SignalClassifier::CONTEXT);
});

it('calls an unfilled gap with the bias an opportunity, and against it a trap', function () {
    $with = classify(events: [smcEvent('fvg', 'bullish', ['status' => 'unmitigated'])], bias: 'bullish');
    $against = classify(events: [smcEvent('fvg', 'bearish', ['status' => 'unmitigated'])], bias: 'bullish');

    expect($with[0]->kind)->toBe(SignalClassifier::OPPORTUNITY)
        ->and($against[0]->kind)->toBe(SignalClassifier::TRAP);
});

it('demotes a gap that has already been filled', function () {
    $signals = classify(events: [smcEvent('fvg', 'bullish', ['status' => 'mitigated'])]);

    expect($signals[0]->kind)->toBe(SignalClassifier::CONTEXT);
});

it('demotes an order block that no longer holds', function () {
    $signals = classify(events: [smcEvent('order_block', 'bullish', ['status' => 'invalidated'])]);

    expect($signals[0]->kind)->toBe(SignalClassifier::CONTEXT);
});

it('keeps only the newest break of structure per direction', function () {
    // The engine reports the last several; only the newest says where the market stands now.
    $signals = classify(events: [
        smcEvent('choch', 'bearish', ['start_time' => '2026-01-05T00:00:00Z']),
        smcEvent('choch', 'bearish', ['start_time' => '2026-09-18T00:00:00Z']),
        smcEvent('choch', 'bullish', ['start_time' => '2026-06-01T00:00:00Z']),
    ], bias: 'bullish');

    $chochs = array_values(array_filter($signals, static fn (ScanSignal $s): bool => $s->type === 'choch'));

    expect($chochs)->toHaveCount(2)
        ->and(firstOfType($signals, 'choch')?->at)->toBe('2026-09-18T00:00:00Z');
});

it('keeps every zone, because two gaps at different prices are two different places', function () {
    $signals = classify(events: [
        smcEvent('fvg', 'bullish', ['status' => 'unmitigated', 'price_low' => 100.0, 'price_high' => 104.0]),
        smcEvent('fvg', 'bullish', ['status' => 'unmitigated', 'price_low' => 88.0, 'price_high' => 91.0]),
    ]);

    expect(ofKind($signals, SignalClassifier::OPPORTUNITY))->toHaveCount(2);
});

it('warns that a bullish read at the top of the range is the expensive end', function () {
    $signals = classify(
        range: ['zone' => 'premium', 'position' => 0.95, 'low' => 80.0, 'high' => 120.0],
        bias: 'bullish',
    );

    expect($signals[0]->kind)->toBe(SignalClassifier::TRAP)
        ->and($signals[0]->headline)->toContain('expensive end')
        ->and($signals[0]->headline)->toContain('95%');
});

it('treats the cheap end with an upward bias as an opportunity', function () {
    $signals = classify(
        range: ['zone' => 'discount', 'position' => 0.1, 'low' => 80.0, 'high' => 120.0],
        bias: 'bullish',
    );

    expect($signals[0]->kind)->toBe(SignalClassifier::OPPORTUNITY);
});

it('says little about a mid-range price', function () {
    $signals = classify(
        range: ['zone' => 'equilibrium', 'position' => 0.5, 'low' => 80.0, 'high' => 120.0],
        bias: 'bullish',
    );

    expect($signals[0]->kind)->toBe(SignalClassifier::CONTEXT)
        ->and($signals[0]->confidence)->toBe(0.0);
});

it('weights a range signal by how far into the extreme price sits', function () {
    $edge = classify(range: ['zone' => 'premium', 'position' => 1.0, 'low' => 80.0, 'high' => 120.0]);
    $barely = classify(range: ['zone' => 'premium', 'position' => 0.65, 'low' => 80.0, 'high' => 120.0]);

    expect($edge[0]->confidence)->toBeGreaterThan($barely[0]->confidence);
});

it('puts traps before opportunities before context', function () {
    $signals = classify(
        events: [
            smcEvent('fvg', 'bullish', ['status' => 'unmitigated']),
            smcEvent('liquidity_sweep', 'bearish'),
            smcEvent('order_block', 'bullish', ['status' => 'invalidated']),
        ],
        bias: 'bullish',
    );

    expect(array_map(static fn (ScanSignal $s): string => $s->kind, $signals))
        ->toBe([SignalClassifier::TRAP, SignalClassifier::OPPORTUNITY, SignalClassifier::CONTEXT]);
});

it('treats a pattern agreeing with both bias and structure as an opportunity', function () {
    $signals = classify(
        patterns: [[
            'category' => 'candlestick', 'type' => 'bullish_engulfing', 'direction' => 'bullish',
            'status' => 'confirmed', 'confidence' => 0.9, 'start_time' => '2026-09-18T00:00:00Z',
        ]],
        bias: 'bullish',
        structure: 'bullish',
    );

    expect($signals[0]->kind)->toBe(SignalClassifier::OPPORTUNITY);
});

it('treats a pattern fighting the bias as a trap', function () {
    $signals = classify(
        patterns: [[
            'category' => 'candlestick', 'type' => 'shooting_star', 'direction' => 'bearish',
            'status' => 'confirmed', 'confidence' => 0.8, 'start_time' => '2026-09-18T00:00:00Z',
        ]],
        bias: 'bullish',
        structure: 'bullish',
    );

    expect($signals[0]->kind)->toBe(SignalClassifier::TRAP);
});

it('has no opinion when there is no bias to read against', function () {
    $signals = classify(
        patterns: [[
            'category' => 'candlestick', 'type' => 'doji', 'direction' => 'neutral',
            'status' => 'confirmed', 'confidence' => 0.4, 'start_time' => '2026-09-18T00:00:00Z',
        ]],
        bias: null,
        structure: null,
    );

    expect($signals[0]->kind)->toBe(SignalClassifier::CONTEXT);
});

it('carries the evidence behind every label', function () {
    $signals = classify(events: [smcEvent('order_block', 'bullish', [
        'price_low' => 238.0, 'price_high' => 240.5, 'confidence' => 0.6,
    ])]);

    expect($signals[0]->toArray())->toMatchArray([
        'kind' => SignalClassifier::OPPORTUNITY,
        'category' => 'smc',
        'type' => 'order_block',
        'direction' => 'bullish',
        'confidence' => 0.6,
        'price_low' => 238.0,
        'price_high' => 240.5,
        'at' => '2026-09-18T00:00:00Z',
    ]);
});

it('returns nothing when the engine found nothing', function () {
    expect(classify())->toBe([]);
});
