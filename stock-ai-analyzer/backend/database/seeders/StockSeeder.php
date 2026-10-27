<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Market\Models\Exchange;
use App\Domain\Market\Models\Stock;
use Illuminate\Database\Seeder;
use RuntimeException;
use SplFileObject;

/**
 * Loads listed securities from database/seeders/data/nepse_stocks.csv (symbol,name,sector,instrument_type).
 * The shipped file is a starter list; replace it with the official NEPSE company list and re-run.
 */
class StockSeeder extends Seeder
{
    private const string CSV_PATH = __DIR__.'/data/nepse_stocks.csv';

    public function run(): void
    {
        $exchange = Exchange::query()->where('code', 'NEPSE')->firstOrFail();

        foreach ($this->rows() as $row) {
            Stock::query()->updateOrCreate(
                ['exchange_id' => $exchange->id, 'symbol' => strtoupper($row['symbol'])],
                [
                    'name' => $row['name'],
                    'sector' => $row['sector'] !== '' ? $row['sector'] : null,
                    'instrument_type' => $row['instrument_type'] !== '' ? $row['instrument_type'] : 'equity',
                    'is_active' => true,
                ],
            );
        }
    }

    /**
     * @return iterable<array{symbol: string, name: string, sector: string, instrument_type: string}>
     */
    private function rows(): iterable
    {
        $file = new SplFileObject(self::CSV_PATH);
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD);

        $header = null;
        foreach ($file as $line) {
            if (! is_array($line) || $line === [null]) {
                continue;
            }
            $cells = array_map(static fn (mixed $cell): string => trim((string) $cell), $line);
            if ($header === null) {
                $header = $cells;

                continue;
            }
            $row = array_combine($header, $cells);
            if (! isset($row['symbol'], $row['name'], $row['sector'], $row['instrument_type'])) {
                throw new RuntimeException('nepse_stocks.csv must have columns symbol,name,sector,instrument_type');
            }

            yield [
                'symbol' => $row['symbol'],
                'name' => $row['name'],
                'sector' => $row['sector'],
                'instrument_type' => $row['instrument_type'],
            ];
        }
    }
}
