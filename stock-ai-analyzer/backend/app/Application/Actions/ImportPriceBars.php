<?php

declare(strict_types=1);

namespace App\Application\Actions;

use App\Application\DTOs\ImportSummary;
use App\Domain\Market\Models\PriceBar;
use App\Domain\Market\Models\Stock;
use App\Domain\Market\ValueObjects\BarData;
use App\Domain\Market\ValueObjects\SkippedRow;
use App\Domain\Market\ValueObjects\TradingCalendar;
use App\Domain\Shared\Enums\Timeframe;
use App\Infrastructure\MarketData\Contracts\AuthoritativeCalendar;
use App\Infrastructure\MarketData\Contracts\MarketDataProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * P3-10: fetch bars from a provider, drop duplicates and non-trading days, upsert the rest.
 * Idempotent: re-importing the same data updates rows in place.
 */
final class ImportPriceBars
{
    private const int CHUNK_SIZE = 1000;

    public function __invoke(Stock $stock, Timeframe $timeframe, MarketDataProvider $provider): ImportSummary
    {
        $exchange = $stock->loadMissing('exchange')->exchange
            ?? throw new LogicException("Stock [{$stock->id}] has no exchange.");
        $calendar = TradingCalendar::forExchange($exchange);
        $result = $provider->fetch($stock, $timeframe);
        // An exchange data vendor's bars define the trading calendar rather than being checked
        // against it (AuthoritativeCalendar).
        $checkCalendar = ! $provider instanceof AuthoritativeCalendar;

        $skipped = $result->skipped;
        $firstRowAt = [];
        $rows = [];

        foreach ($result->bars as $bar) {
            $key = $bar->time->format('Y-m-d H:i:s');

            if (isset($firstRowAt[$key])) {
                $skipped[] = new SkippedRow($bar->sourceRow, sprintf(
                    'Duplicate %s (first seen on row %d).',
                    $this->label($bar, $timeframe),
                    $firstRowAt[$key],
                ));

                continue;
            }

            $tradingDate = $checkCalendar ? $this->tradingDate($bar, $timeframe, $exchange->timezone) : null;
            if ($tradingDate !== null && ! $calendar->isTradingDay($tradingDate)) {
                $skipped[] = new SkippedRow($bar->sourceRow, sprintf(
                    '%s is not a %s trading day.',
                    $tradingDate->format('D j M Y'),
                    $exchange->code,
                ));

                continue;
            }

            $firstRowAt[$key] = $bar->sourceRow ?? 0;
            $rows[] = [
                'stock_id' => $stock->id,
                'timeframe' => $timeframe->value,
                'bar_time' => $bar->time->toIso8601String(),
                'open' => $bar->open,
                'high' => $bar->high,
                'low' => $bar->low,
                'close' => $bar->close,
                'volume' => $bar->volume,
                'turnover' => $bar->turnover,
                'source' => $provider->source()->value,
            ];
        }

        $inserted = 0;
        $updated = 0;

        foreach (array_chunk($rows, self::CHUNK_SIZE) as $chunk) {
            $existing = DB::transaction(function () use ($stock, $timeframe, $chunk): int {
                $existing = PriceBar::query()
                    ->where('stock_id', $stock->id)
                    ->where('timeframe', $timeframe->value)
                    ->whereIn('bar_time', array_column($chunk, 'bar_time'))
                    ->count();

                $this->upsert($chunk);

                return $existing;
            });

            $inserted += count($chunk) - $existing;
            $updated += $existing;
        }

        usort($skipped, static fn (SkippedRow $a, SkippedRow $b): int => ($a->row ?? PHP_INT_MAX) <=> ($b->row ?? PHP_INT_MAX));

        return new ImportSummary($inserted, $updated, $skipped, $result->meta, $stock->priceCoverage());
    }

    /**
     * Upserts a chunk, preserving a field the provider did not carry.
     *
     * Written as raw SQL because `Builder::upsert()` can only assign `excluded.col`, and that is
     * wrong for a column one source has and another does not. Merolagani reports no traded amount,
     * so a plain assignment would erase turnover that a CSV or sharesansar had already supplied —
     * a nightly sync quietly deleting good data. `COALESCE` makes the rule explicit: a provider
     * overwrites what it measured and leaves alone what it never saw.
     *
     * OHLC is always assigned outright. Those four are the provider's core claim, and a correction
     * has to be able to lower a high.
     *
     * @param  list<array<string, mixed>>  $chunk
     */
    private function upsert(array $chunk): void
    {
        $columns = ['stock_id', 'timeframe', 'bar_time', 'open', 'high', 'low', 'close', 'volume', 'turnover', 'source'];

        $placeholders = implode(', ', array_fill(
            0,
            count($chunk),
            '('.implode(', ', array_fill(0, count($columns), '?')).')',
        ));

        $bindings = [];
        foreach ($chunk as $row) {
            foreach ($columns as $column) {
                $bindings[] = $row[$column];
            }
        }

        DB::statement(
            'INSERT INTO price_bars ('.implode(', ', $columns).') VALUES '.$placeholders.' '
            .'ON CONFLICT (stock_id, timeframe, bar_time) DO UPDATE SET '
            .'open = excluded.open, high = excluded.high, low = excluded.low, close = excluded.close, '
            .'volume = COALESCE(excluded.volume, price_bars.volume), '
            .'turnover = COALESCE(excluded.turnover, price_bars.turnover), '
            .'source = excluded.source',
            $bindings,
        );
    }

    /**
     * The exchange-local date a bar traded on; null for weekly bars, which span several days.
     */
    private function tradingDate(BarData $bar, Timeframe $timeframe, string $timezone): ?CarbonImmutable
    {
        return match (true) {
            $timeframe === Timeframe::W1 => null,
            $timeframe->isIntraday() => $bar->time->setTimezone($timezone),
            default => $bar->time,
        };
    }

    private function label(BarData $bar, Timeframe $timeframe): string
    {
        return $timeframe->isIntraday() ? 'bar at '.$bar->time->toIso8601ZuluString() : 'date '.$bar->time->format('Y-m-d');
    }
}
