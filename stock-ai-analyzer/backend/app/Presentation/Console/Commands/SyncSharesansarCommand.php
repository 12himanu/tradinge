<?php

declare(strict_types=1);

namespace App\Presentation\Console\Commands;

use App\Application\Services\SyncSharesansarStock;
use App\Domain\Market\Models\Exchange;
use App\Domain\Market\Models\Stock;
use App\Domain\Shared\Enums\Timeframe;
use App\Infrastructure\MarketData\Sharesansar\SharesansarUnavailable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Imports from sharesansar: the instruments merolagani does not chart, and turnover.
 *
 * `--missing` is the usual use — the securities with no bars at all, which is where sharesansar
 * has something merolagani does not. Naming symbols explicitly works too, but a symbol already
 * holding adjusted merolagani bars is refused unless `--force` is given, because the two sources
 * are on different price scales.
 */
#[AsCommand(name: 'market:sync-sharesansar', description: 'Import NEPSE bars and turnover from sharesansar.')]
final class SyncSharesansarCommand extends Command
{
    /** @var string */
    protected $signature = 'market:sync-sharesansar
        {symbol?* : Symbols to sync}
        {--missing : Every NEPSE stock that has no bars at all}
        {--backfill : Ask for the whole history rather than recent days}
        {--force : Import even over adjusted merolagani bars (mixes price scales)}
        {--limit=0 : Stop after this many symbols; 0 means no limit}';

    /** @var string */
    protected $description = 'Import NEPSE bars and turnover from sharesansar.';

    public function handle(SyncSharesansarStock $sync): int
    {
        /** @var list<string> $symbols */
        $symbols = array_values(array_filter(array_map(
            static fn (mixed $value): string => mb_strtoupper(trim((string) $value)),
            (array) $this->argument('symbol'),
        ), static fn (string $symbol): bool => $symbol !== ''));

        if ($symbols === [] && ! $this->option('missing')) {
            $this->error('Name some symbols, or pass --missing for every stock with no bars.');

            return self::FAILURE;
        }

        $stocks = $this->stocks($symbols, (bool) $this->option('missing'), (int) $this->option('limit'));

        if ($stocks->isEmpty()) {
            $this->info('Nothing to do: no matching stocks.');

            return self::SUCCESS;
        }

        $this->line("Reading {$stocks->count()} symbols from sharesansar…");
        $rows = [];
        $imported = 0;
        $refused = 0;
        $failed = 0;

        foreach ($stocks as $stock) {
            try {
                $result = $sync($stock, (bool) $this->option('backfill'), (bool) $this->option('force'));
            } catch (SharesansarUnavailable $e) {
                $failed++;
                $this->line("  <error>{$stock->symbol}</error> {$e->getMessage()}");

                continue;
            }

            if ($result->inserted === 0 && $result->updated === 0) {
                $refused++;
                $reason = $result->skipped[0] ?? 'no rows';
                $this->line("  <comment>{$stock->symbol}</comment> {$reason}");

                continue;
            }

            $imported += $result->inserted;
            $rows[] = [
                $result->symbol,
                $result->inserted,
                $result->updated,
                $result->skippedTotal,
                $result->lastBarDate ?? '—',
            ];
        }

        $this->newLine();
        if ($rows !== []) {
            $this->table(['Symbol', 'New', 'Updated', 'Skipped', 'Last bar'], array_slice($rows, 0, 50));
            if (count($rows) > 50) {
                $this->line(sprintf('… and %d more.', count($rows) - 50));
            }
        }

        $this->info(sprintf(
            '%d symbols imported (%d new bars), %d had nothing to give, %d failed.',
            count($rows),
            $imported,
            $refused,
            $failed,
        ));

        return $failed > 0 && $rows === [] ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  list<string>  $symbols
     * @return Collection<int, Stock>
     */
    private function stocks(array $symbols, bool $missingOnly, int $limit): Collection
    {
        $exchange = Exchange::query()->where('code', 'NEPSE')->first();

        if ($exchange === null) {
            return new Collection;
        }

        return Stock::query()
            ->where('exchange_id', $exchange->id)
            ->when($symbols !== [], fn ($query) => $query->whereIn('symbol', $symbols))
            ->when($missingOnly, fn ($query) => $query->whereDoesntHave(
                'priceBars',
                fn ($bars) => $bars->where('timeframe', Timeframe::D1->value),
            ))
            ->when($limit > 0, fn ($query) => $query->limit($limit))
            ->orderBy('symbol')
            ->get();
    }
}
