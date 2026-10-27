<?php

declare(strict_types=1);

use App\Application\Services\Stats\Statistics;

/**
 * Pool-adjacent-violators, which is what keeps the calibration curve from claiming that a
 * 70-scored setup is less likely to work than a 60-scored one (P6-10).
 */
it('leaves an already monotone sequence untouched', function (): void {
    $fitted = Statistics::isotonic([0.2, 0.4, 0.5, 0.8], [10.0, 10.0, 10.0, 10.0]);

    expect(array_column($fitted, 'value'))->toBe([0.2, 0.4, 0.5, 0.8]);
});

it('pools only the buckets that are out of order', function (): void {
    // 0.9 then 0.3 is the violation; they merge to 0.6 and the neighbours stay as they were.
    $fitted = Statistics::isotonic([0.1, 0.9, 0.3, 0.95], [10.0, 10.0, 10.0, 10.0]);

    expect(array_column($fitted, 'value'))->toBe([0.1, 0.6, 0.6, 0.95]);
});

it('weights the pooled mean by sample size', function (): void {
    // 90 outcomes at 0.8 and 10 at 0.1 must land near 0.8, not at their unweighted midpoint 0.45.
    $fitted = Statistics::isotonic([0.8, 0.1], [90.0, 10.0]);

    expect(array_column($fitted, 'value'))->toBe([0.73, 0.73]);
});

it('reports the support behind each fitted value', function (): void {
    // The thin bucket of 4 inherits the 34 outcomes of the block it was pooled into: that is the
    // sample the minimum-n rule should be applied to, not its own.
    $fitted = Statistics::isotonic([0.9, 0.2], [30.0, 4.0]);

    expect(array_column($fitted, 'support'))->toBe([34.0, 34.0])
        ->and($fitted[0]['value'])->toBe(0.8176);
});

it('cascades a merge backwards through several blocks', function (): void {
    $fitted = Statistics::isotonic([0.5, 0.6, 0.7, 0.1], [10.0, 10.0, 10.0, 10.0]);

    expect(array_column($fitted, 'value'))->toBe([0.475, 0.475, 0.475, 0.475]);
});

it('returns nothing for an empty sample', function (): void {
    expect(Statistics::isotonic([], []))->toBe([]);
});

it('scores a perfect forecast at zero and a coin toss at a quarter', function (): void {
    expect(Statistics::brierScore([[1.0, 1.0], [0.0, 0.0]]))->toBe(0.0)
        ->and(Statistics::brierScore([[0.5, 1.0], [0.5, 0.0]]))->toBe(0.25)
        ->and(Statistics::brierScore([]))->toBeNull();
});
