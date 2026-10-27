<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Application\Actions\ImportPriceBars;
use App\Application\DTOs\MerolaganiSyncResult;
use App\Domain\Market\Enums\PriceSource;
use App\Domain\Market\Models\PriceBar;
use App\Domain\Market\Models\Stock;
use App\Domain\Market\ValueObjects\SkippedRow;
use App\Domain\Shared\Enums\Timeframe;
use App\Infrastructure\MarketData\Sharesansar\SharesansarClient;
use App\Infrastructure\MarketData\Sharesansar\SharesansarProvider;
use Carbon\CarbonImmutable;

/**
 * Fills the two holes merolagani leaves: instruments it does not chart, and the traded amount.
 *
 * Merolagani's chart handler answers `no_data` for debentures, government savings bonds and
 * promoter shares — around a hundred NEPSE securities. Sharesansar carries about a third of them,
 * and carries turnover for everything.
 *
 * ## The rule about mixing sources
 *
 * Merolagani is imported bonus- and rights-adjusted; sharesansar is raw. Splicing one onto the
 * other inside a single symbol would put two price scales in one series, and every indicator
 * drawn across the join would be reading a gap that never happened. So a symbol that already holds
 * adjusted merolagani bars is refused unless `$force` says otherwise, and the refusal is recorded
 * rather than silently skipped.
 *
 * Turnover is the exception, and the reason `ImportPriceBars` upserts it with COALESCE: it is not
 * a price, nothing rescales it, and a source that has it may fill it in beside bars that do not.
 */
final readonly class SyncSharesansarStock
{
    /** Pages are 50 rows; this bounds a single symbol's backfill at roughly forty years of sessions. */
    private const int MAX_PAGES = 200;

    public function __construct(
        private SharesansarClient $client,
        private ImportPriceBars $import,
        private int $lookbackDays,
    ) {}

    public function __invoke(Stock $stock, bool $backfill = false, bool $force = false): MerolaganiSyncResult
    {
        $counts = $this->existing($stock);

        if (! $force && $counts['adjusted'] > 0) {
            return $this->refusal($stock, sprintf(
                'Holds %d adjusted merolagani bars; sharesansar is unadjusted. Use --force to replace them.',
                $counts['adjusted'],
            ));
        }

        $companyId = $this->companyId($stock);

        if ($companyId === null) {
            return $this->refusal($stock, 'Sharesansar has no company page for this symbol.');
        }

        $lastBar = $this->lastBarTime($stock);
        $backfill = $backfill || $lastBar === null;
        // $lastBar is non-null here: a null one forced $backfill true just above.
        $since = $backfill ? null : $lastBar->subDays($this->lookbackDays)->startOfDay();

        $summary = ($this->import)(
            $stock,
            Timeframe::D1,
            new SharesansarProvider($this->client, $companyId, $since, self::MAX_PAGES),
        );

        $reasons = array_values(array_unique(array_map(
            static fn (SkippedRow $row): string => $row->reason,
            $summary->skipped,
        )));

        return new MerolaganiSyncResult(
            symbol: $stock->symbol,
            backfill: $backfill,
            from: $since?->toDateString() ?? 'all',
            to: CarbonImmutable::now('UTC')->toDateString(),
            inserted: $summary->inserted,
            updated: $summary->updated,
            skippedTotal: count($summary->skipped),
            skipped: $reasons,
            lastBarDate: $this->lastBarTime($stock)?->toDateString(),
            snapshotStored: false,
        );
    }

    private function refusal(Stock $stock, string $reason): MerolaganiSyncResult
    {
        return new MerolaganiSyncResult(
            symbol: $stock->symbol,
            backfill: false,
            from: '—',
            to: '—',
            inserted: 0,
            updated: 0,
            skippedTotal: 1,
            skipped: [$reason],
            lastBarDate: $this->lastBarTime($stock)?->toDateString(),
            snapshotStored: false,
        );
    }

    /**
     * The sharesansar company id, cached on the stock: the history endpoint takes the id and not
     * the symbol, and looking it up costs a page load each time.
     */
    private function companyId(Stock $stock): ?int
    {
        $metadata = $stock->metadata;
        $cached = $metadata['sharesansar']['company_id'] ?? null;

        if (is_int($cached)) {
            return $cached;
        }

        $companyId = $this->client->companyId($stock->symbol);

        $metadata['sharesansar'] = [
            'company_id' => $companyId,
            'checked_at' => CarbonImmutable::now('UTC')->toIso8601ZuluString(),
        ];
        $stock->metadata = $metadata;
        $stock->save();

        return $companyId;
    }

    /**
     * @return array{adjusted: int, total: int}
     */
    private function existing(Stock $stock): array
    {
        $rows = PriceBar::query()
            ->where('stock_id', $stock->id)
            ->where('timeframe', Timeframe::D1->value)
            ->selectRaw('count(*) AS total')
            ->selectRaw('count(*) FILTER (WHERE source = ?) AS adjusted', [PriceSource::Merolagani->value])
            ->toBase()
            ->first();

        return [
            'adjusted' => (int) ($rows->adjusted ?? 0),
            'total' => (int) ($rows->total ?? 0),
        ];
    }

    private function lastBarTime(Stock $stock): ?CarbonImmutable
    {
        $latest = PriceBar::query()
            ->where('stock_id', $stock->id)
            ->where('timeframe', Timeframe::D1->value)
            ->max('bar_time');

        return is_string($latest) ? CarbonImmutable::parse($latest, 'UTC') : null;
    }
}
