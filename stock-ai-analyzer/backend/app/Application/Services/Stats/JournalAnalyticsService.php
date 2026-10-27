<?php

declare(strict_types=1);

namespace App\Application\Services\Stats;

use App\Domain\Identity\Models\User;
use App\Domain\Journal\Enums\ExitReason;
use App\Domain\Journal\Enums\TradeStatus;
use App\Domain\Journal\Models\TradeJournalEntry;
use Illuminate\Support\Collection;

/**
 * What your own trading record says about you (P6-09).
 *
 * Every number here is counted, never inferred: "you exited 7 of 11 winners before T1" is a fact
 * about rows in the journal. The app does not speculate about why — that is the user's job, and a
 * confident-sounding psychological reading from twelve trades would be worse than silence.
 */
final readonly class JournalAnalyticsService
{
    /** Flags stay quiet until there are enough trades for the pattern to mean anything. */
    public const int MIN_TRADES_FOR_FLAGS = 5;

    /**
     * @return array{
     *     totals: array<string, mixed>,
     *     by_strategy: list<array<string, mixed>>,
     *     by_tag: list<array<string, mixed>>,
     *     plan_adherence: array<string, mixed>,
     *     flags: list<array{key: string, severity: string, text: string, count: int, of: int}>,
     * }
     */
    public function build(User $user): array
    {
        /** @var Collection<int, TradeJournalEntry> $closed */
        $closed = TradeJournalEntry::query()
            ->whereBelongsTo($user)
            ->where('status', TradeStatus::Closed->value)
            ->with('analysis')
            ->orderBy('exit_date')
            ->get();

        return [
            'totals' => $this->metrics($closed),
            'by_strategy' => $this->byStrategy($closed),
            'by_tag' => $this->byTag($closed),
            'plan_adherence' => $this->planAdherence($closed),
            'flags' => $this->flags($closed),
        ];
    }

    /**
     * @param  Collection<int, TradeJournalEntry>  $trades
     * @return array<string, mixed>
     */
    private function metrics(Collection $trades): array
    {
        $count = $trades->count();
        $wins = $trades->filter(fn (TradeJournalEntry $trade): bool => (float) $trade->net_pnl > 0);
        $rMultiples = $this->column($trades, 'r_multiple');
        $interval = Statistics::wilsonInterval($wins->count(), $count);

        return [
            'trades' => $count,
            'wins' => $wins->count(),
            'win_rate' => $count > 0 ? round($wins->count() / $count, 4) : null,
            'win_rate_interval' => $count > 0 ? $interval : null,
            'net_pnl' => $count > 0 ? round(array_sum($this->column($trades, 'net_pnl')), 2) : null,
            'expectancy_r' => Statistics::mean($rMultiples),
            'median_r' => Statistics::median($rMultiples),
            'profit_factor' => Statistics::profitFactor($rMultiples),
            'sufficient_sample' => $count >= 30,
        ];
    }

    /**
     * @param  Collection<int, TradeJournalEntry>  $trades
     * @return list<array<string, mixed>>
     */
    private function byStrategy(Collection $trades): array
    {
        $rows = [];
        foreach ($trades->groupBy(fn (TradeJournalEntry $trade): string => $trade->strategy ?? 'unlabelled') as $name => $group) {
            /** @var Collection<int, TradeJournalEntry> $group */
            $rows[] = ['group' => (string) $name, ...$this->metrics($group)];
        }
        usort($rows, static fn (array $a, array $b): int => $b['trades'] <=> $a['trades']);

        return $rows;
    }

    /**
     * @param  Collection<int, TradeJournalEntry>  $trades
     * @return list<array<string, mixed>>
     */
    private function byTag(Collection $trades): array
    {
        /** @var array<string, list<TradeJournalEntry>> $buckets */
        $buckets = [];
        foreach ($trades as $trade) {
            foreach ($trade->tags ?? [] as $tag) {
                $buckets[$tag][] = $trade;
            }
        }

        $rows = [];
        foreach ($buckets as $tag => $group) {
            $rows[] = ['group' => $tag, ...$this->metrics(collect($group))];
        }
        usort($rows, static fn (array $a, array $b): int => $b['trades'] <=> $a['trades']);

        return $rows;
    }

    /**
     * Trades where the plan was followed, next to trades where it was not.
     *
     * @param  Collection<int, TradeJournalEntry>  $trades
     * @return array<string, mixed>
     */
    private function planAdherence(Collection $trades): array
    {
        $answered = $trades->filter(fn (TradeJournalEntry $trade): bool => $trade->followed_plan !== null);
        $followed = $answered->filter(fn (TradeJournalEntry $trade): bool => $trade->followed_plan === true);
        $deviated = $answered->filter(fn (TradeJournalEntry $trade): bool => $trade->followed_plan === false);

        return [
            'answered' => $answered->count(),
            'followed' => ['group' => 'followed', ...$this->metrics($followed)],
            'deviated' => ['group' => 'deviated', ...$this->metrics($deviated)],
        ];
    }

    /**
     * Behavioural flags: counted patterns, each with its denominator so the user can judge weight.
     *
     * @param  Collection<int, TradeJournalEntry>  $trades
     * @return list<array{key: string, severity: string, text: string, count: int, of: int}>
     */
    private function flags(Collection $trades): array
    {
        if ($trades->count() < self::MIN_TRADES_FOR_FLAGS) {
            return [];
        }

        $flags = [];

        // Winners closed by hand before the target: the classic way to shrink your average win.
        $winners = $trades->filter(fn (TradeJournalEntry $trade): bool => (float) $trade->net_pnl > 0);
        $earlyExits = $winners->filter(
            fn (TradeJournalEntry $trade): bool => $trade->exit_reason === ExitReason::Manual
                && $trade->target_price !== null
                && $this->shortOfTarget($trade)
        );
        if ($earlyExits->isNotEmpty() && $winners->isNotEmpty()) {
            $flags[] = [
                'key' => 'early_exits',
                'severity' => $earlyExits->count() / $winners->count() >= 0.5 ? 'high' : 'medium',
                'text' => sprintf(
                    'You closed %d of %d winners by hand before the target.',
                    $earlyExits->count(),
                    $winners->count(),
                ),
                'count' => $earlyExits->count(),
                'of' => $winners->count(),
            ];
        }

        // Losses beyond 1R with a stop set: the stop was moved, or it was not honoured.
        $withStop = $trades->filter(fn (TradeJournalEntry $trade): bool => $trade->stop_loss !== null);
        $overruns = $withStop->filter(
            fn (TradeJournalEntry $trade): bool => $trade->r_multiple !== null && (float) $trade->r_multiple < -1.05
        );
        if ($overruns->isNotEmpty()) {
            $flags[] = [
                'key' => 'stops_not_honoured',
                'severity' => 'high',
                'text' => sprintf(
                    '%d of %d trades with a stop lost more than 1R. A gap explains some; a moved stop explains the rest.',
                    $overruns->count(),
                    $withStop->count(),
                ),
                'count' => $overruns->count(),
                'of' => $withStop->count(),
            ];
        }

        // Trades taken against the analysis's own decision.
        $againstDecision = $trades->filter(function (TradeJournalEntry $trade): bool {
            $decision = $trade->analysis?->decision?->value;

            return $decision !== null && in_array($decision, ['avoid', 'wait'], true);
        });
        if ($againstDecision->isNotEmpty()) {
            $flags[] = [
                'key' => 'traded_against_decision',
                'severity' => 'medium',
                'text' => sprintf(
                    '%d of %d trades were taken on an analysis that said wait or avoid.',
                    $againstDecision->count(),
                    $trades->count(),
                ),
                'count' => $againstDecision->count(),
                'of' => $trades->count(),
            ];
        }

        $deviated = $trades->filter(fn (TradeJournalEntry $trade): bool => $trade->followed_plan === false);
        if ($deviated->count() / $trades->count() >= 0.4) {
            $flags[] = [
                'key' => 'plan_adherence',
                'severity' => 'medium',
                'text' => sprintf(
                    'You departed from the plan in %d of %d trades.',
                    $deviated->count(),
                    $trades->count(),
                ),
                'count' => $deviated->count(),
                'of' => $trades->count(),
            ];
        }

        return $flags;
    }

    private function shortOfTarget(TradeJournalEntry $trade): bool
    {
        $exit = $this->number($trade->exit_price);
        $target = $this->number($trade->target_price);
        if ($exit === null || $target === null) {
            return false;
        }

        return $trade->side->value === 'long' ? $exit < $target : $exit > $target;
    }

    /**
     * @param  Collection<int, TradeJournalEntry>  $trades
     * @return list<float>
     */
    private function column(Collection $trades, string $attribute): array
    {
        /** @var list<float> $values */
        $values = $trades
            ->map(fn (TradeJournalEntry $trade): ?float => $this->number($trade->getAttribute($attribute)))
            ->filter(fn (?float $value): bool => $value !== null)
            ->values()
            ->all();

        return $values;
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
