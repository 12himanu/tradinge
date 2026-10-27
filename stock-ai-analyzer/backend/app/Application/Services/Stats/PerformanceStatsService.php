<?php

declare(strict_types=1);

namespace App\Application\Services\Stats;

use App\Domain\Analysis\Models\Analysis;
use App\Domain\Identity\Models\User;
use App\Domain\Prediction\Enums\OutcomeResult;
use App\Domain\Prediction\Enums\PredictionKind;
use App\Domain\Prediction\Models\Outcome;
use App\Domain\Prediction\Models\Prediction;
use Illuminate\Support\Collection;

/**
 * How the app's predictions actually turned out (P6-07).
 *
 * Deliberate choices, all of which make the numbers less flattering and more useful:
 *
 * - Hit rate counts **resolved trade setups only**. A setup that never triggered was not a losing
 *   call, so folding it into the hit rate would inflate the denominator; it lives in the trigger
 *   rate instead.
 * - Every rate ships with a Wilson 95% interval and its `n`. A 2-from-3 start must look like what
 *   it is.
 * - Stats never pool across engine versions unless asked: a re-weighting changes what the score
 *   means, so mixing them compares two different systems.
 */
final readonly class PerformanceStatsService
{
    public const array GROUPINGS = ['overall', 'pattern', 'style', 'decision', 'score_bucket', 'data_tier'];

    /**
     * @param  array{engine_version?: string|null, style?: string|null, tier?: string|null, from?: string|null, to?: string|null}  $filters
     * @return array{
     *     group_by: string,
     *     filters: array<string, mixed>,
     *     overall: array<string, mixed>,
     *     groups: list<array<string, mixed>>,
     *     r_histogram: list<array{from: float, to: float, count: int}>,
     * }
     */
    public function build(User $user, string $groupBy = 'overall', array $filters = []): array
    {
        $groupBy = in_array($groupBy, self::GROUPINGS, true) ? $groupBy : 'overall';

        /** @var Collection<int, Prediction> $predictions */
        $predictions = Prediction::query()
            ->whereBelongsTo($user)
            ->with(['outcome', 'analysis'])
            ->when(
                ($filters['engine_version'] ?? null) !== null,
                fn ($query) => $query->where('engine_version', $filters['engine_version'])
            )
            ->when(($filters['from'] ?? null) !== null, fn ($query) => $query->where('as_of', '>=', $filters['from']))
            ->when(($filters['to'] ?? null) !== null, fn ($query) => $query->where('as_of', '<=', $filters['to']))
            ->get()
            ->filter(fn (Prediction $prediction): bool => $this->matchesAnalysisFilters($prediction, $filters))
            ->values();

        $setups = $predictions->filter(
            fn (Prediction $prediction): bool => $prediction->kind === PredictionKind::TradeSetup
        );
        $biases = $predictions->filter(
            fn (Prediction $prediction): bool => $prediction->kind === PredictionKind::DirectionalBias
        );

        $groups = $groupBy === 'overall'
            ? []
            : $this->groups($setups, $groupBy);

        return [
            'group_by' => $groupBy,
            'filters' => array_filter($filters, static fn (mixed $value): bool => $value !== null && $value !== ''),
            'overall' => [
                ...$this->metrics($setups),
                'brier_score' => $this->brier($biases),
                'directional_accuracy' => $this->directionalAccuracy($biases),
                'engine_versions' => $predictions->pluck('engine_version')->unique()->sort()->values()->all(),
            ],
            'groups' => $groups,
            'r_histogram' => Statistics::rHistogram($this->rMultiples($setups)),
        ];
    }

    /**
     * @param  Collection<int, Prediction>  $setups
     * @return list<array<string, mixed>>
     */
    private function groups(Collection $setups, string $groupBy): array
    {
        $grouped = $setups->groupBy(fn (Prediction $prediction): string => $this->groupKey($prediction, $groupBy));

        $rows = [];
        foreach ($grouped as $key => $members) {
            /** @var Collection<int, Prediction> $members */
            $rows[] = ['group' => (string) $key, ...$this->metrics($members)];
        }

        // Biggest sample first: a row built on 2 predictions should not head the table.
        usort($rows, static fn (array $a, array $b): int => $b['resolved'] <=> $a['resolved']);

        return $rows;
    }

    private function groupKey(Prediction $prediction, string $groupBy): string
    {
        $analysis = $prediction->analysis;

        if ($analysis === null) {
            return $groupBy === 'score_bucket'
                ? (Statistics::scoreBucket($prediction->setup_score) ?? 'unscored')
                : 'unknown';
        }

        return match ($groupBy) {
            'style' => $analysis->trading_style->value,
            'decision' => $analysis->decision->value ?? 'none',
            'data_tier' => $analysis->data_tier->value ?? 'unknown',
            'score_bucket' => Statistics::scoreBucket($prediction->setup_score) ?? 'unscored',
            'pattern' => $this->primaryPattern($analysis),
            default => 'overall',
        };
    }

    /**
     * The classic pattern the analysis leant on, which is what "by pattern" means to a trader.
     */
    private function primaryPattern(Analysis $analysis): string
    {
        $pattern = $analysis->patterns()
            ->where('category', 'classic')
            ->orderByDesc('confidence')
            ->first();

        return $pattern === null ? 'none' : $pattern->type;
    }

    /**
     * @param  Collection<int, Prediction>  $setups
     * @return array<string, mixed>
     */
    private function metrics(Collection $setups): array
    {
        $total = $setups->count();
        $outcomes = $setups->map(fn (Prediction $prediction): ?Outcome => $prediction->outcome)
            ->filter()
            ->values();

        $resolved = $outcomes->filter(
            fn (Outcome $outcome): bool => in_array(
                $outcome->result,
                [OutcomeResult::Win, OutcomeResult::Loss, OutcomeResult::Breakeven, OutcomeResult::Expired],
                true,
            )
        );

        // "Triggered" means the setup actually became a position.
        $triggered = $outcomes->filter(fn (Outcome $outcome): bool => $outcome->triggered_at !== null);
        $wins = $resolved->filter(fn (Outcome $outcome): bool => $outcome->result === OutcomeResult::Win);

        $rMultiples = $this->rMultiplesFromOutcomes($resolved);
        $hitInterval = Statistics::wilsonInterval($wins->count(), $resolved->count());

        return [
            'predictions' => $total,
            'triggered' => $triggered->count(),
            'trigger_rate' => $total > 0 ? round($triggered->count() / $total, 4) : null,
            'resolved' => $resolved->count(),
            'wins' => $wins->count(),
            'hit_rate' => $resolved->count() > 0 ? round($wins->count() / $resolved->count(), 4) : null,
            'hit_rate_interval' => $resolved->count() > 0 ? $hitInterval : null,
            'expectancy_r' => Statistics::mean($rMultiples),
            'median_r' => Statistics::median($rMultiples),
            'profit_factor' => Statistics::profitFactor($rMultiples),
            'mfe_pct_median' => Statistics::median($this->column($resolved, 'mfe_pct')),
            'mae_pct_median' => Statistics::median($this->column($resolved, 'mae_pct')),
            'ambiguous_bars' => $resolved->filter(fn (Outcome $outcome): bool => $outcome->ambiguous_bar)->count(),
            // Below this, the numbers are anecdotes. The UI says so rather than hiding them.
            'sufficient_sample' => $resolved->count() >= 30,
        ];
    }

    /**
     * @param  Collection<int, Prediction>  $biases
     */
    private function brier(Collection $biases): ?float
    {
        $components = $biases
            ->map(fn (Prediction $prediction): ?float => $this->number($prediction->outcome?->brier_component))
            ->filter(fn (?float $value): bool => $value !== null)
            ->values()
            ->all();

        /** @var list<float> $components */
        return Statistics::mean($components);
    }

    /**
     * @param  Collection<int, Prediction>  $biases
     * @return array{correct: int, total: int, rate: float|null, interval: array{low: float, high: float}|null}
     */
    private function directionalAccuracy(Collection $biases): array
    {
        $scored = $biases->filter(
            fn (Prediction $prediction): bool => $prediction->outcome?->direction_correct !== null
        );
        $correct = $scored->filter(fn (Prediction $prediction): bool => $prediction->outcome?->direction_correct === true);

        return [
            'correct' => $correct->count(),
            'total' => $scored->count(),
            'rate' => $scored->count() > 0 ? round($correct->count() / $scored->count(), 4) : null,
            'interval' => $scored->count() > 0
                ? Statistics::wilsonInterval($correct->count(), $scored->count())
                : null,
        ];
    }

    /**
     * @param  Collection<int, Prediction>  $setups
     * @return list<float>
     */
    private function rMultiples(Collection $setups): array
    {
        $outcomes = $setups->map(fn (Prediction $prediction): ?Outcome => $prediction->outcome)->filter();

        /** @var Collection<int, Outcome> $outcomes */
        return $this->rMultiplesFromOutcomes($outcomes);
    }

    /**
     * @param  Collection<int, Outcome>  $outcomes
     * @return list<float>
     */
    private function rMultiplesFromOutcomes(Collection $outcomes): array
    {
        return $this->column($outcomes, 'r_multiple');
    }

    /**
     * @param  Collection<int, Outcome>  $outcomes
     * @return list<float>
     */
    private function column(Collection $outcomes, string $attribute): array
    {
        /** @var list<float> $values */
        $values = $outcomes
            ->map(fn (Outcome $outcome): ?float => $this->number($outcome->getAttribute($attribute)))
            ->filter(fn (?float $value): bool => $value !== null)
            ->values()
            ->all();

        return $values;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function matchesAnalysisFilters(Prediction $prediction, array $filters): bool
    {
        $analysis = $prediction->analysis;
        $style = $filters['style'] ?? null;
        $tier = $filters['tier'] ?? null;

        if ($style !== null && $style !== '' && $analysis?->trading_style->value !== $style) {
            return false;
        }

        return ! ($tier !== null && $tier !== '' && $analysis?->data_tier?->value !== $tier);
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
