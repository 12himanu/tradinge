<?php

declare(strict_types=1);

namespace App\Application\Services\Stats;

/**
 * The statistics behind the Performance page (P6-07).
 *
 * Every rate here ships with an interval and an `n`, because the whole point of the learning loop
 * is to stop the user (and the app) believing a 3-for-4 start means a 75% edge.
 */
final readonly class Statistics
{
    /** 1.96 standard deviations: the 95% two-sided normal quantile. */
    public const float Z_95 = 1.959964;

    /**
     * Wilson score interval for a binomial proportion.
     *
     * Chosen over the textbook normal interval because it stays inside [0, 1] and behaves sensibly
     * at small n and at rates near 0 or 1 — exactly the regime this app lives in for months.
     *
     * @return array{low: float, high: float}
     */
    public static function wilsonInterval(int $successes, int $trials, float $z = self::Z_95): array
    {
        if ($trials <= 0) {
            return ['low' => 0.0, 'high' => 0.0];
        }

        $p = $successes / $trials;
        $z2 = $z * $z;
        $denominator = 1 + $z2 / $trials;
        $centre = $p + $z2 / (2 * $trials);
        $spread = $z * sqrt($p * (1 - $p) / $trials + $z2 / (4 * $trials * $trials));

        return [
            'low' => round(max(0.0, ($centre - $spread) / $denominator), 4),
            'high' => round(min(1.0, ($centre + $spread) / $denominator), 4),
        ];
    }

    /**
     * Gross profit divided by gross loss. `null` when nothing lost yet: "infinite" would be a lie
     * about a sample that simply hasn't met its first loser.
     *
     * @param  list<float>  $rMultiples
     */
    public static function profitFactor(array $rMultiples): ?float
    {
        $won = 0.0;
        $lost = 0.0;
        foreach ($rMultiples as $r) {
            if ($r > 0) {
                $won += $r;
            } else {
                $lost += abs($r);
            }
        }

        return $lost > 0.0 ? round($won / $lost, 3) : null;
    }

    /**
     * @param  list<float>  $values
     */
    public static function mean(array $values): ?float
    {
        return $values === [] ? null : round(array_sum($values) / count($values), 4);
    }

    /**
     * @param  list<float>  $values
     */
    public static function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $middle = intdiv(count($values), 2);

        return round(
            count($values) % 2 === 1
                ? $values[$middle]
                : ($values[$middle - 1] + $values[$middle]) / 2,
            4,
        );
    }

    /**
     * Counts per 0.5R bucket, for the R distribution histogram.
     *
     * @param  list<float>  $rMultiples
     * @return list<array{from: float, to: float, count: int}>
     */
    public static function rHistogram(array $rMultiples, float $bucket = 0.5): array
    {
        if ($rMultiples === []) {
            return [];
        }

        $low = floor(min($rMultiples) / $bucket) * $bucket;
        $high = ceil(max($rMultiples) / $bucket) * $bucket;
        $binCount = max(1, (int) round(($high - $low) / $bucket));

        /** @var list<int> $counts */
        $counts = array_fill(0, $binCount, 0);
        foreach ($rMultiples as $r) {
            $index = max(0, min($binCount - 1, (int) floor(($r - $low) / $bucket)));
            $counts[$index]++;
        }

        $bins = [];
        foreach ($counts as $index => $count) {
            $bins[] = [
                'from' => round($low + $index * $bucket, 2),
                'to' => round($low + ($index + 1) * $bucket, 2),
                'count' => $count,
            ];
        }

        return $bins;
    }

    /**
     * Weighted isotonic regression by pool-adjacent-violators (PAVA).
     *
     * The calibration curve should never say that a 70 is less likely to work than a 60. Raw
     * bucket hit rates violate that constantly at small n — one unlucky run of 40 buckets inverts
     * the order. PAVA merges only the buckets that are actually out of order and replaces them
     * with their weighted mean, which is the closest non-decreasing curve in weighted least
     * squares. Buckets that already agree with the ordering are left exactly as they are.
     *
     * The fitted value comes back with the **support** behind it: the total sample of the block
     * it was pooled from. A bucket of 12 that merged into a block of 60 is backed by 60 outcomes,
     * and that is the number the caller should apply its minimum-sample rule to.
     *
     * @param  list<float>  $values  observed rates, ordered by score
     * @param  list<float>  $weights  sample size behind each rate, same order
     * @return list<array{value: float, support: float}> one entry per input, in the same order
     */
    public static function isotonic(array $values, array $weights): array
    {
        if ($values === []) {
            return [];
        }

        /** @var list<array{sum: float, weight: float, count: int}> $blocks */
        $blocks = [];

        foreach ($values as $index => $value) {
            $weight = max($weights[$index] ?? 1.0, 1e-9);
            $block = ['sum' => $value * $weight, 'weight' => $weight, 'count' => 1];

            // Merge backwards while the previous block sits above this one.
            while ($blocks !== []) {
                $previous = $blocks[count($blocks) - 1];
                if ($previous['sum'] / $previous['weight'] <= $block['sum'] / $block['weight']) {
                    break;
                }
                array_pop($blocks);
                $block = [
                    'sum' => $block['sum'] + $previous['sum'],
                    'weight' => $block['weight'] + $previous['weight'],
                    'count' => $block['count'] + $previous['count'],
                ];
            }

            $blocks[] = $block;
        }

        $fitted = [];
        foreach ($blocks as $block) {
            $mean = round($block['sum'] / $block['weight'], 4);
            $support = round($block['weight'], 4);
            for ($i = 0; $i < $block['count']; $i++) {
                $fitted[] = ['value' => $mean, 'support' => $support];
            }
        }

        return $fitted;
    }

    /**
     * Mean squared error between a forecast probability and what happened (1 or 0).
     *
     * 0 is perfect, 0.25 is the score of always saying "50%", and anything above that is worse
     * than a coin toss with an opinion.
     *
     * @param  list<array{float, float}>  $pairs  [forecast probability, realised outcome]
     */
    public static function brierScore(array $pairs): ?float
    {
        if ($pairs === []) {
            return null;
        }

        $total = 0.0;
        foreach ($pairs as [$probability, $outcome]) {
            $total += ($probability - $outcome) ** 2;
        }

        return round($total / count($pairs), 5);
    }

    /**
     * The 10-point score band a setup score falls in (docs/04 §10 calibration buckets).
     */
    public static function scoreBucket(?int $score): ?string
    {
        if ($score === null) {
            return null;
        }
        $low = intdiv(max(0, min(100, $score)), 10) * 10;
        $low = min(90, $low);

        return sprintf('%d-%d', $low, $low + 9);
    }
}
