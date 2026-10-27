<?php

declare(strict_types=1);

namespace App\Presentation\Console\Commands;

use App\Application\Jobs\SyncMerolaganiPricesJob;
use App\Application\Services\SyncMerolaganiStock;
use App\Domain\Market\Models\Exchange;
use App\Domain\Market\Models\Stock;
use App\Infrastructure\MarketData\Merolagani\MerolaganiUnavailable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Runs the merolagani sync by hand: the first backfill, a catch-up after downtime, or a check that
 * the scheduled 17:00 run will work.
 *
 * Without `--queue` the work happens in this process and prints a table, which is what you want
 * when watching a first backfill. With `--queue` it dispatches the same jobs the scheduler does.
 */
#[AsCommand(name: 'market:sync-merolagani', description: 'Import NEPSE daily bars and fundamentals from merolagani.')]
final class SyncMerolaganiCommand extends Command
{
    /** @var string */
    protected $signature = 'market:sync-merolagani
        {symbol?* : Symbols to sync; defaults to every active NEPSE stock}
        {--backfill : Ask for the whole available history, not just recent days}
        {--queue : Dispatch queued jobs instead of syncing in this process}
        {--spacing=2 : Seconds between queued symbols}';

    /** @var string */
    protected $description = 'Import NEPSE daily bars and fundamentals from merolagani.';

    public function handle(SyncMerolaganiStock $sync): int
    {
        /** @var list<string> $symbols */
        $symbols = array_values(array_filter(array_map(
            static fn (mixed $value): string => mb_strtoupper(trim((string) $value)),
            (array) $this->argument('symbol'),
        ), static fn (string $symbol): bool => $symbol !== ''));

        $backfill = (bool) $this->option('backfill');

        if ($this->option('queue')) {
            SyncMerolaganiPricesJob::dispatch(
                symbols: $symbols !== [] ? $symbols : null,
                backfill: $backfill,
                spacingSeconds: (int) $this->option('spacing'),
            );
            $this->info('Queued the merolagani sync on the market-data queue.');

            return self::SUCCESS;
        }

        $stocks = $this->stocks($symbols);

        if ($stocks->isEmpty()) {
            $this->error($symbols === []
                ? 'No active NEPSE stocks found. Run `php artisan db:seed --class=StockSeeder` first.'
                : 'None of those symbols are NEPSE stocks: '.implode(', ', $symbols));

            return self::FAILURE;
        }

        $rows = [];
        $failed = 0;

        foreach ($stocks as $stock) {
            $this->getOutput()->write("  {$stock->symbol} … ");

            try {
                $result = $sync($stock, $backfill);
            } catch (MerolaganiUnavailable $e) {
                $failed++;
                $this->getOutput()->writeln('<error>failed</error>');
                $this->line('    '.$e->getMessage());

                continue;
            }

            $this->getOutput()->writeln(sprintf(
                '<info>%d new, %d updated</info>',
                $result->inserted,
                $result->updated,
            ));

            foreach ($result->skipped as $reason) {
                $this->line("    skipped: {$reason}");
            }

            $rows[] = [
                $result->symbol,
                $result->backfill ? 'backfill' : 'incremental',
                $result->inserted,
                $result->updated,
                $result->skippedTotal,
                $result->lastBarDate ?? '—',
                $result->snapshotStored ? 'yes' : 'no',
            ];
        }

        $this->newLine();
        $this->table(['Symbol', 'Mode', 'New', 'Updated', 'Skipped', 'Last bar', 'Snapshot'], $rows);

        if ($failed > 0) {
            $this->warn("{$failed} of {$stocks->count()} symbols could not be synced.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $symbols
     * @return Collection<int, Stock>
     */
    private function stocks(array $symbols): Collection
    {
        $exchange = Exchange::query()->where('code', 'NEPSE')->first();

        if ($exchange === null) {
            return new Collection;
        }

        return Stock::query()
            ->where('exchange_id', $exchange->id)
            ->when($symbols === [], fn ($query) => $query->where('is_active', true))
            ->when($symbols !== [], fn ($query) => $query->whereIn('symbol', $symbols))
            ->orderBy('symbol')
            ->get();
    }
}
