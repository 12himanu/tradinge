<?php

declare(strict_types=1);

namespace App\Presentation\Console\Commands;

use App\Application\Jobs\ScanMarketJob;
use App\Application\Services\ScanStock;
use App\Domain\Market\Models\Exchange;
use App\Domain\Market\Models\Stock;
use App\Domain\Shared\Enums\TradingStyle;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Runs the market scan by hand, in the foreground, with a table of what it found.
 */
#[AsCommand(name: 'market:scan', description: 'Read every stock with the engine and file the results.')]
final class ScanMarketCommand extends Command
{
    /** @var string */
    protected $signature = 'market:scan
        {symbol?* : Symbols to scan; defaults to every active NEPSE stock with enough bars}
        {--style=swing : Trading style profile (intraday, swing, positional, investing)}
        {--queue : Dispatch queued jobs instead of scanning in this process}';

    /** @var string */
    protected $description = 'Read every stock with the engine and file the results.';

    public function handle(ScanStock $scan): int
    {
        $styleOption = $this->option('style');
        $style = is_string($styleOption) ? TradingStyle::tryFrom($styleOption) : null;

        if ($style === null) {
            $this->error('Unknown style. Use one of: '.implode(', ', TradingStyle::values()));

            return self::FAILURE;
        }

        /** @var list<string> $symbols */
        $symbols = array_values(array_filter(array_map(
            static fn (mixed $value): string => mb_strtoupper(trim((string) $value)),
            (array) $this->argument('symbol'),
        ), static fn (string $symbol): bool => $symbol !== ''));

        if ($this->option('queue')) {
            ScanMarketJob::dispatch($symbols !== [] ? $symbols : null, $style->value);
            $this->info('Queued the market scan on the market-data queue.');

            return self::SUCCESS;
        }

        $stocks = $this->stocks($symbols);

        if ($stocks->isEmpty()) {
            $this->error($symbols === []
                ? 'No NEPSE stocks with stored bars. Run `php artisan market:sync-merolagani` first.'
                : 'None of those symbols are NEPSE stocks: '.implode(', ', $symbols));

            return self::FAILURE;
        }

        $rows = [];
        $skipped = 0;

        $bar = $this->output->createProgressBar($stocks->count());
        $bar->start();

        foreach ($stocks as $stock) {
            $result = $scan($stock, $style);
            $bar->advance();

            if ($result->failed()) {
                $skipped++;

                continue;
            }

            $rows[] = [
                $stock->symbol,
                $result->bias->value ?? '—',
                $result->setup_score ?? '—',
                $result->decision->value ?? '—',
                $result->range_zone ?? '—',
                $result->trap_count,
                $result->opportunity_count,
                $result->has_viable_plan ? 'yes' : 'no',
            ];
        }

        $bar->finish();
        $this->newLine(2);

        // Best first: the scanner's whole job is to put the few worth looking at on top.
        usort($rows, static fn (array $a, array $b): int => (int) $b[2] <=> (int) $a[2]);

        $this->table(
            ['Symbol', 'Bias', 'Score', 'Decision', 'Zone', 'Traps', 'Opps', 'Plan'],
            array_slice($rows, 0, 40),
        );

        if (count($rows) > 40) {
            $this->line(sprintf('… and %d more. See the Scanner page for all of them.', count($rows) - 40));
        }
        if ($skipped > 0) {
            $this->warn("{$skipped} stocks could not be read (too few bars, or the engine declined).");
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
            ->whereHas('priceBars')
            ->orderBy('symbol')
            ->get();
    }
}
