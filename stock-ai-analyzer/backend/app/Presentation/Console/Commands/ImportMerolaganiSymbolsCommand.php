<?php

declare(strict_types=1);

namespace App\Presentation\Console\Commands;

use App\Domain\Market\Enums\InstrumentType;
use App\Domain\Market\Models\Exchange;
use App\Domain\Market\Models\Stock;
use App\Infrastructure\MarketData\Merolagani\MerolaganiClient;
use App\Infrastructure\MarketData\Merolagani\MerolaganiUnavailable;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Fills `stocks` from merolagani's symbol list, so any listed security can be analysed without
 * hand-editing database/seeders/data/nepse_stocks.csv (which ships a 22-row starter list).
 *
 * Existing rows keep their name, sector and instrument type: the seeder and the daily sync are the
 * owners of those, and a vendor list should not quietly rewrite them. Nothing is deactivated or
 * deleted either — a delisting is a decision, not an import.
 */
#[AsCommand(name: 'market:import-merolagani-symbols', description: 'Create stocks for every security merolagani lists.')]
final class ImportMerolaganiSymbolsCommand extends Command
{
    /**
     * Instrument type inferred from the company name, since the symbol list carries no sector and
     * types everything as "Stock". Ordered: the first match wins.
     */
    private const array TYPE_HINTS = [
        'debenture' => InstrumentType::Debenture,
        'bond' => InstrumentType::Debenture,
        'mutual fund' => InstrumentType::MutualFund,
        'scheme' => InstrumentType::MutualFund,
        'yojana' => InstrumentType::MutualFund,
        'kosh' => InstrumentType::MutualFund,
    ];

    /** @var string */
    protected $signature = 'market:import-merolagani-symbols {--dry-run : List what would change without writing}';

    /** @var string */
    protected $description = 'Create stocks for every security merolagani lists.';

    public function handle(MerolaganiClient $client): int
    {
        $exchange = Exchange::query()->where('code', 'NEPSE')->first();

        if ($exchange === null) {
            $this->error('No NEPSE exchange. Run `php artisan db:seed --class=ExchangeSeeder` first.');

            return self::FAILURE;
        }

        try {
            $listed = $client->listedSymbols();
        } catch (MerolaganiUnavailable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $known = Stock::query()
            ->where('exchange_id', $exchange->id)
            ->pluck('symbol')
            ->all();

        $new = array_values(array_filter(
            $listed,
            static fn (array $entry): bool => ! in_array(mb_strtoupper($entry['symbol']), $known, true),
        ));

        $this->line(sprintf('%d securities listed, %d already known, %d new.', count($listed), count($known), count($new)));

        if ($new === []) {
            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->table(
                ['Symbol', 'Name', 'Type'],
                array_map(
                    fn (array $entry): array => [$entry['symbol'], $entry['name'], $this->instrumentType($entry['name'])->value],
                    $new,
                ),
            );

            return self::SUCCESS;
        }

        foreach ($new as $entry) {
            Stock::query()->create([
                'exchange_id' => $exchange->id,
                'symbol' => $entry['symbol'],
                'name' => $entry['name'],
                'sector' => null,
                'instrument_type' => $this->instrumentType($entry['name']),
                'is_active' => true,
                'metadata' => [],
            ]);
        }

        $this->info(sprintf('Created %d stocks. Sectors are filled in by the next merolagani sync.', count($new)));

        return self::SUCCESS;
    }

    private function instrumentType(string $name): InstrumentType
    {
        $haystack = mb_strtolower($name);

        foreach (self::TYPE_HINTS as $needle => $type) {
            if (str_contains($haystack, $needle)) {
                return $type;
            }
        }

        return InstrumentType::Equity;
    }
}
