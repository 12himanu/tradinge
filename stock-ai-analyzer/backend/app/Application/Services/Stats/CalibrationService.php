<?php

declare(strict_types=1);

namespace App\Application\Services\Stats;

use App\Domain\Identity\Models\User;
use App\Domain\Prediction\Enums\OutcomeResult;
use App\Domain\Prediction\Enums\PredictionKind;
use App\Domain\Prediction\Models\CalibrationSnapshot;
use App\Domain\Shared\Enums\TradingStyle;
use Illuminate\Support\Facades\DB;

/**
 * Turns the setup score into a probability the user can actually read (P6-10).
 *
 * A setup score of 72 means nothing on its own — it is the output of weights in `scoring.yaml`,
 * not a forecast. This service asks the only question that matters: **of the setups this engine
 * version scored in the 70s, how many reached T1 before the stop?** That empirical rate is the
 * calibrated probability.
 *
 * Three rules keep it honest:
 *
 * - **Never across engine versions.** A re-weighting changes what a 72 means, so pooling the old
 *   engine's outcomes into the new one's curve would calibrate against a system that no longer
 *   exists. Each version earns its own curve from scratch.
 * - **n >= 30 behind every number shown.** Below that a bucket is an anecdote, and the report goes
 *   on saying "uncalibrated" rather than dressing 4-from-6 up as 67%.
 * - **Binned first, isotonic only when the whole sample can carry it.** Raw bucket rates are
 *   unbiased but noisy and frequently non-monotone; isotonic regression fixes the ordering and
 *   lets thin buckets borrow strength from their neighbours, but it is a model, and fitting a
 *   model to 40 outcomes is how you end up trusting a curve you drew yourself.
 */
final readonly class CalibrationService
{
    /** Below this many outcomes in a bucket (or isotonic block), nothing is published. */
    public const int MIN_BUCKET_SAMPLE = 30;

    /** Isotonic needs a real sample before it stops being creative writing. */
    public const int MIN_ISOTONIC_SAMPLE = 200;

    public const string GLOBAL_SCOPE = 'global';

    /** A resolved setup: the prediction had its chance and the market answered. */
    private const array RESOLVED = [
        OutcomeResult::Win,
        OutcomeResult::Loss,
        OutcomeResult::Breakeven,
        OutcomeResult::Expired,
    ];

    /**
     * The calibration curve for one engine version and scope.
     *
     * @return array{
     *     engine_version: string|null,
     *     scope: string,
     *     method: string,
     *     sample_size: int,
     *     active: bool,
     *     min_bucket_sample: int,
     *     brier_score: float|null,
     *     brier_score_calibrated: float|null,
     *     calibration_error: float|null,
     *     buckets: list<array<string, mixed>>,
     *     engine_versions: list<string>,
     * }
     */
    public function curve(User $user, ?string $engineVersion = null, string $scope = self::GLOBAL_SCOPE): array
    {
        $samples = $this->samples($user, $engineVersion, $this->styleOf($scope));
        $total = count($samples);
        $method = $total >= self::MIN_ISOTONIC_SAMPLE ? 'isotonic' : 'binned';
        $buckets = $this->buckets($samples, $method);

        $qualified = array_filter($buckets, static fn (array $bucket): bool => $bucket['qualified'] === true);

        return [
            'engine_version' => $engineVersion,
            'scope' => $scope,
            'method' => $method,
            'sample_size' => $total,
            'active' => $qualified !== [],
            'min_bucket_sample' => self::MIN_BUCKET_SAMPLE,
            'brier_score' => Statistics::brierScore(
                array_map(static fn (array $row): array => [$row['score'] / 100, $row['won']], $samples)
            ),
            'brier_score_calibrated' => $this->calibratedBrier($samples, $buckets),
            'calibration_error' => $this->calibrationError($buckets, $total),
            'buckets' => $buckets,
            'engine_versions' => $this->engineVersions($user),
        ];
    }

    /**
     * The calibrated probability to attach to a fresh analysis, or `null` while the sample is thin.
     *
     * The style-specific curve wins when it qualifies — a swing setup and an intraday setup with
     * the same score are not the same bet — and the global curve is the fallback.
     */
    public function probabilityFor(
        User $user,
        string $engineVersion,
        ?int $score,
        ?TradingStyle $style = null,
    ): ?float {
        if ($score === null) {
            return null;
        }

        $scopes = $style === null
            ? [self::GLOBAL_SCOPE]
            : [self::scopeForStyle($style), self::GLOBAL_SCOPE];

        foreach ($scopes as $scope) {
            $curve = $this->curve($user, $engineVersion, $scope);
            $probability = $this->lookup($curve['buckets'], $score);
            if ($probability !== null) {
                return $probability;
            }
        }

        return null;
    }

    /**
     * Persist the curve as it stands, so how calibration moved over time stays auditable rather
     * than being silently recomputed out of existence.
     */
    public function snapshot(
        User $user,
        string $engineVersion,
        string $scope = self::GLOBAL_SCOPE,
    ): ?CalibrationSnapshot {
        $curve = $this->curve($user, $engineVersion, $scope);

        if ($curve['sample_size'] === 0) {
            return null;
        }

        $snapshot = new CalibrationSnapshot([
            'engine_version' => $engineVersion,
            'scope' => $scope,
            'method' => $curve['method'],
            'sample_size' => $curve['sample_size'],
            'brier_score' => $curve['brier_score'],
            'buckets' => $curve['buckets'],
        ]);
        $snapshot->user_id = $user->id;
        $snapshot->save();

        return $snapshot;
    }

    public static function scopeForStyle(TradingStyle $style): string
    {
        return 'style:'.$style->value;
    }

    /**
     * @param  list<array<string, mixed>>  $buckets
     */
    private function lookup(array $buckets, int $score): ?float
    {
        foreach ($buckets as $bucket) {
            if ($bucket['qualified'] === true && $score >= $bucket['low'] && $score <= $bucket['high']) {
                /** @var float|null $calibrated */
                $calibrated = $bucket['calibrated'];

                return $calibrated;
            }
        }

        return null;
    }

    /**
     * Resolved trade setups as (score, won) pairs, ordered by score.
     *
     * Only `win` counts as a hit: the question being calibrated is "did this reach T1 before the
     * stop", and a breakeven or an expiry did not. Setups that never triggered have no outcome row
     * at all, so they never reach here — they belong to the trigger rate, not the hit rate.
     *
     * @return list<array{score: int, won: float}>
     */
    private function samples(User $user, ?string $engineVersion, ?TradingStyle $style): array
    {
        $rows = DB::table('predictions')
            ->join('outcomes', 'outcomes.prediction_id', '=', 'predictions.id')
            ->leftJoin('analyses', 'analyses.id', '=', 'predictions.analysis_id')
            ->where('predictions.user_id', $user->id)
            ->where('predictions.kind', PredictionKind::TradeSetup->value)
            ->whereNotNull('predictions.setup_score')
            ->whereIn(
                'outcomes.result',
                array_map(static fn (OutcomeResult $result): string => $result->value, self::RESOLVED)
            )
            ->when(
                $engineVersion !== null,
                fn ($query) => $query->where('predictions.engine_version', $engineVersion)
            )
            ->when(
                $style instanceof TradingStyle,
                fn ($query) => $query->where('analyses.trading_style', $style?->value)
            )
            ->orderBy('predictions.setup_score')
            ->get(['predictions.setup_score as score', 'outcomes.result as result']);

        $samples = [];
        foreach ($rows as $row) {
            /** @var object{score: int|string, result: string} $row */
            $samples[] = [
                'score' => (int) $row->score,
                'won' => $row->result === OutcomeResult::Win->value ? 1.0 : 0.0,
            ];
        }

        return $samples;
    }

    /**
     * @param  list<array{score: int, won: float}>  $samples
     * @return list<array<string, mixed>>
     */
    private function buckets(array $samples, string $method): array
    {
        /** @var array<string, array{low: int, high: int, scores: list<int>, wins: int}> $bins */
        $bins = [];
        foreach ($samples as $sample) {
            $label = Statistics::scoreBucket($sample['score']);
            if ($label === null) {
                continue;
            }
            $low = (int) explode('-', $label)[0];
            $bins[$label] ??= ['low' => $low, 'high' => $low + 9, 'scores' => [], 'wins' => 0];
            $bins[$label]['scores'][] = $sample['score'];
            $bins[$label]['wins'] += (int) $sample['won'];
        }

        ksort($bins, SORT_NATURAL);

        $rows = [];
        foreach ($bins as $label => $bin) {
            $n = count($bin['scores']);
            $rows[] = [
                'bucket' => $label,
                'low' => $bin['low'],
                'high' => $bin['high'],
                'n' => $n,
                'wins' => $bin['wins'],
                // The mean score in the bucket, not its midpoint: a bucket full of 61s should not
                // be plotted at 65.
                'predicted' => round(array_sum($bin['scores']) / $n / 100, 4),
                'observed' => round($bin['wins'] / $n, 4),
                'interval' => Statistics::wilsonInterval($bin['wins'], $n),
            ];
        }

        return $method === 'isotonic' ? $this->fitIsotonic($rows) : $this->fitBinned($rows);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function fitBinned(array $rows): array
    {
        return array_map(static function (array $row): array {
            /** @var int $n */
            $n = $row['n'];
            $qualified = $n >= self::MIN_BUCKET_SAMPLE;

            return [
                ...$row,
                'support' => $n,
                'calibrated' => $qualified ? $row['observed'] : null,
                'qualified' => $qualified,
            ];
        }, $rows);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function fitIsotonic(array $rows): array
    {
        /** @var list<float> $observed */
        $observed = array_map(static fn (array $row): float => (float) $row['observed'], $rows);
        /** @var list<float> $weights */
        $weights = array_map(static fn (array $row): float => (float) $row['n'], $rows);

        $fitted = Statistics::isotonic($observed, $weights);

        $out = [];
        foreach ($rows as $index => $row) {
            $support = $fitted[$index]['support'];
            $qualified = $support >= self::MIN_BUCKET_SAMPLE;
            $out[] = [
                ...$row,
                'support' => (int) round($support),
                'calibrated' => $qualified ? $fitted[$index]['value'] : null,
                'qualified' => $qualified,
            ];
        }

        return $out;
    }

    /**
     * What the Brier score would have been had the calibrated probability been published instead
     * of the raw score. If this is not lower than `brier_score`, calibration is not earning its
     * keep yet.
     *
     * @param  list<array{score: int, won: float}>  $samples
     * @param  list<array<string, mixed>>  $buckets
     */
    private function calibratedBrier(array $samples, array $buckets): ?float
    {
        $pairs = [];
        foreach ($samples as $sample) {
            $probability = $this->lookup($buckets, $sample['score']);
            if ($probability === null) {
                continue;
            }
            $pairs[] = [$probability, $sample['won']];
        }

        return Statistics::brierScore($pairs);
    }

    /**
     * Expected calibration error: the sample-weighted gap between what the score claimed and what
     * happened. 0.05 means the score is off by five points of probability on average.
     *
     * @param  list<array<string, mixed>>  $buckets
     */
    private function calibrationError(array $buckets, int $total): ?float
    {
        if ($total === 0) {
            return null;
        }

        $error = 0.0;
        foreach ($buckets as $bucket) {
            /** @var int $n */
            $n = $bucket['n'];
            $error += ($n / $total) * abs((float) $bucket['observed'] - (float) $bucket['predicted']);
        }

        return round($error, 4);
    }

    /**
     * @return list<string>
     */
    private function engineVersions(User $user): array
    {
        /** @var list<string> $versions */
        $versions = DB::table('predictions')
            ->where('user_id', $user->id)
            ->distinct()
            ->orderBy('engine_version')
            ->pluck('engine_version')
            ->all();

        return $versions;
    }

    private function styleOf(string $scope): ?TradingStyle
    {
        if (! str_starts_with($scope, 'style:')) {
            return null;
        }

        return TradingStyle::tryFrom(substr($scope, 6));
    }
}
