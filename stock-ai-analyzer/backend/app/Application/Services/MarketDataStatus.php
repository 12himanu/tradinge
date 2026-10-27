<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Domain\Market\Models\MarketScan;
use App\Domain\Market\Models\PriceBar;
use App\Domain\Market\Models\Stock;
use App\Domain\Shared\Enums\Timeframe;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * What the market-data pipeline is doing, and what it has produced.
 *
 * The scraper runs as a queued batch, so "is it running" is a question about `job_batches` rather
 * than about any object this process holds. Reading the batch table directly keeps the answer true
 * across web requests, workers and restarts — a flag in the cache would go stale the moment a
 * worker died mid-run.
 */
final readonly class MarketDataStatus
{
    /** The batch names the jobs register themselves under. */
    private const string SYNC_BATCH = 'merolagani-sync';

    private const string SCAN_BATCH = 'market-scan';

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'sync' => $this->batch(self::SYNC_BATCH),
            'scan' => $this->batch(self::SCAN_BATCH),
            'coverage' => $this->coverage(),
        ];
    }

    public function syncRunning(): bool
    {
        return ($this->batch(self::SYNC_BATCH)['running'] ?? false) === true;
    }

    /**
     * The most recent batch of a given name, as progress a UI can render.
     *
     * @return array<string, mixed>
     */
    private function batch(string $name): array
    {
        /** @var stdClass|null $row */
        $row = DB::table('job_batches')
            ->where('name', $name)
            ->orderByDesc('created_at')
            ->first();

        if ($row === null) {
            return [
                'running' => false,
                'total' => 0,
                'pending' => 0,
                'failed' => 0,
                'progress' => null,
                'started_at' => null,
                'finished_at' => null,
            ];
        }

        $total = (int) $row->total_jobs;
        $pending = (int) $row->pending_jobs;
        $done = max(0, $total - $pending);

        return [
            'running' => $row->finished_at === null && $row->cancelled_at === null,
            'total' => $total,
            'pending' => $pending,
            'done' => $done,
            'failed' => (int) $row->failed_jobs,
            // A fraction, so the client does not have to guess at the arithmetic.
            'progress' => $total > 0 ? round($done / $total, 4) : null,
            'started_at' => $this->timestamp($row->created_at),
            'finished_at' => $this->timestamp($row->finished_at),
        ];
    }

    /**
     * How much data there is, which is the question "did the scraper work" really asks.
     *
     * @return array<string, mixed>
     */
    private function coverage(): array
    {
        /** @var stdClass|null $bars */
        $bars = PriceBar::query()
            ->where('timeframe', Timeframe::D1->value)
            ->selectRaw('count(*) AS bars')
            ->selectRaw('count(DISTINCT stock_id) AS stocks')
            ->selectRaw('max(bar_time) AS latest_bar')
            ->toBase()
            ->first();

        return [
            'stocks_total' => Stock::query()->count(),
            'stocks_with_bars' => (int) ($bars->stocks ?? 0),
            'bars' => (int) ($bars->bars ?? 0),
            'latest_bar' => $bars?->latest_bar === null
                ? null
                : CarbonImmutable::parse((string) $bars->latest_bar)->toDateString(),
            'scanned' => MarketScan::query()->whereNull('error_code')->count(),
            'last_scan' => $this->lastScan(),
        ];
    }

    private function lastScan(): ?string
    {
        $latest = MarketScan::query()->max('scanned_on');

        return is_string($latest) ? CarbonImmutable::parse($latest)->toDateString() : null;
    }

    private function timestamp(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        // Laravel stores batch timestamps as unix integers, not as a date string.
        return is_numeric($value)
            ? CarbonImmutable::createFromTimestampUTC((int) $value)->toIso8601ZuluString()
            : CarbonImmutable::parse((string) $value)->toIso8601ZuluString();
    }
}
