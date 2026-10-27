<?php

declare(strict_types=1);

use App\Application\Services\Stats\Statistics;

/**
 * The arithmetic behind every number on the Performance page (P6-07).
 */
it('gives a wide interval for a tiny sample', function () {
    // 2 of 3 looks like 67%, but the honest range is roughly 20% to 94%.
    $interval = Statistics::wilsonInterval(2, 3);

    expect($interval['low'])->toBeLessThan(0.25)
        ->and($interval['high'])->toBeGreaterThan(0.9);
});

it('tightens the interval as the sample grows', function () {
    $small = Statistics::wilsonInterval(20, 30);
    $large = Statistics::wilsonInterval(200, 300);

    $smallWidth = $small['high'] - $small['low'];
    $largeWidth = $large['high'] - $large['low'];

    expect($largeWidth)->toBeLessThan($smallWidth);
});

it('never leaves the zero to one range, even at the extremes', function () {
    $perfect = Statistics::wilsonInterval(5, 5);
    $hopeless = Statistics::wilsonInterval(0, 5);

    expect($perfect['high'])->toBeLessThanOrEqual(1.0)
        ->and($perfect['low'])->toBeGreaterThan(0.0)
        ->and($hopeless['low'])->toBe(0.0)
        ->and($hopeless['high'])->toBeLessThan(1.0);
});

it('returns an empty interval with no trials rather than dividing by zero', function () {
    expect(Statistics::wilsonInterval(0, 0))->toBe(['low' => 0.0, 'high' => 0.0]);
});

it('computes profit factor as gross win over gross loss', function () {
    // Wins 2.0 + 1.0 = 3.0; losses 1.0 + 0.5 = 1.5.
    expect(Statistics::profitFactor([2.0, -1.0, 1.0, -0.5]))->toBe(2.0);
});

it('refuses to report a profit factor before the first loss', function () {
    // "Infinite" would be a claim about a sample that has simply not met a loser yet.
    expect(Statistics::profitFactor([2.0, 1.0]))->toBeNull();
});

it('computes mean and median R', function () {
    expect(Statistics::mean([1.0, 2.0, -1.0]))->toBe(0.6667)
        ->and(Statistics::median([1.0, 2.0, -1.0]))->toBe(1.0)
        ->and(Statistics::median([1.0, 2.0, -1.0, 3.0]))->toBe(1.5)
        ->and(Statistics::mean([]))->toBeNull();
});

it('buckets R into half-R bins that cover every value', function () {
    $bins = Statistics::rHistogram([-1.0, -0.4, 0.2, 1.2, 1.3]);

    expect(array_sum(array_column($bins, 'count')))->toBe(5)
        ->and($bins[0]['from'])->toBe(-1.0)
        ->and(end($bins)['to'])->toBeGreaterThanOrEqual(1.5);
});

it('returns no histogram without data', function () {
    expect(Statistics::rHistogram([]))->toBe([]);
});

it('maps scores to ten-point calibration bands', function () {
    expect(Statistics::scoreBucket(0))->toBe('0-9')
        ->and(Statistics::scoreBucket(72))->toBe('70-79')
        ->and(Statistics::scoreBucket(100))->toBe('90-99')
        ->and(Statistics::scoreBucket(null))->toBeNull();
});
