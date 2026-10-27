<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Application\Actions\ImportPriceBars;
use App\Application\DTOs\MerolaganiSyncResult;
use App\Domain\Market\Models\PriceBar;
use App\Domain\Market\Models\Stock;
use App\Domain\Market\ValueObjects\SkippedRow;
use App\Domain\Shared\Enums\Timeframe;
use App\Infrastructure\MarketData\Merolagani\CompanySnapshot;
use App\Infrastructure\MarketData\Merolagani\MerolaganiClient;
use App\Infrastructure\MarketData\Merolagani\MerolaganiProvider;
use App\Infrastructure\MarketData\Merolagani\MerolaganiUnavailable;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Brings one symbol up to date from merolagani: daily bars, then the fundamentals snapshot.
 *
 * The first run for a stock asks for everything the vendor holds (PCBL goes back to January 2010);
 * later runs ask for a short window ending today. The window matters because merolagani publishes
 * the day's bar some hours after the 15:00 close and occasionally restates one, so a run that only
 * asked for today would miss a late or corrected bar. Re-asking is free: ImportPriceBars upserts on
 * (stock, timeframe, bar_time), so an unchanged bar is an update of identical values.
 */
final readonly class SyncMerolaganiStock
{
    public function __construct(
        private MerolaganiClient $client,
        private ImportPriceBars $import,
        private CarbonImmutable $historyStart,
        private int $lookbackDays,
        private bool $adjusted,
    ) {}

    public function __invoke(Stock $stock, bool $backfill = false): MerolaganiSyncResult
    {
        $lastBar = $this->lastBarTime($stock);
        $backfill = $backfill || $lastBar === null;

        $from = $backfill
            ? $this->historyStart
            : $lastBar->subDays($this->lookbackDays)->startOfDay();
        // Tomorrow, because the vendor stamps a bar late in the UTC day and its clock is not exact.
        $to = CarbonImmutable::now('UTC')->addDay()->endOfDay();

        $summary = $this->importBars(
            $stock,
            new MerolaganiProvider($this->client, $from, $to, $this->adjusted),
        );

        return new MerolaganiSyncResult(
            symbol: $stock->symbol,
            backfill: $backfill,
            from: $from->toDateString(),
            to: $to->toDateString(),
            inserted: $summary['inserted'],
            updated: $summary['updated'],
            skippedTotal: $summary['skipped_total'],
            skipped: $summary['skipped'],
            lastBarDate: $this->lastBarTime($stock)?->toDateString(),
            snapshotStored: $this->storeSnapshot($stock),
        );
    }

    /**
     * @return array{inserted: int, updated: int, skipped_total: int, skipped: list<string>}
     */
    private function importBars(Stock $stock, MerolaganiProvider $provider): array
    {
        $summary = ($this->import)($stock, Timeframe::D1, $provider);

        // One line per distinct reason: a systematic problem repeats on hundreds of bars, and the
        // reason is what tells you what went wrong, not which position it was at.
        $reasons = array_values(array_unique(array_map(
            static fn (SkippedRow $row): string => $row->reason,
            $summary->skipped,
        )));

        return [
            'inserted' => $summary->inserted,
            'updated' => $summary->updated,
            'skipped_total' => count($summary->skipped),
            'skipped' => $reasons,
        ];
    }

    /**
     * Reference figures into `stocks.metadata`, and the sector when the stock has none yet.
     *
     * The rendered page is fetched only for a stock still missing its sector, so the cost is one
     * page per stock ever rather than one per stock per day. Everything here is best effort: the
     * bars are already stored by this point and a missing snapshot must not undo them.
     */
    private function storeSnapshot(Stock $stock): bool
    {
        try {
            $summary = $this->client->companySummary($stock->symbol);
        } catch (MerolaganiUnavailable $e) {
            // Swallowed on purpose: the bars are already stored, and a missing reference figure is
            // not worth re-running the import for. The next run picks the snapshot up.
            Log::info('merolagani.snapshot.unavailable', [
                'symbol' => $stock->symbol,
                'reason' => $e->getMessage(),
            ]);

            return false;
        }

        $page = $stock->sector === null ? $this->client->companyPage($stock->symbol) : null;

        if ($summary === null && $page === null) {
            return false;
        }

        $snapshot = CompanySnapshot::from($summary, $page, CarbonImmutable::now('UTC'));

        $stock->metadata = $snapshot->mergeInto($stock->metadata);

        if ($stock->sector === null && $snapshot->sector !== null) {
            $stock->sector = $snapshot->sector;
        }

        $stock->save();

        return true;
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
