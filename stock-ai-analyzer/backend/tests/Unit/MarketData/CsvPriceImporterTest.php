<?php

declare(strict_types=1);

use App\Domain\Market\ValueObjects\BarData;
use App\Domain\Market\ValueObjects\SkippedRow;
use App\Domain\Shared\Enums\Timeframe;
use App\Infrastructure\MarketData\CsvPriceImporter;
use App\Infrastructure\MarketData\InvalidCsvFile;
use App\Infrastructure\MarketData\ProviderResult;

function parseCsv(string $contents, Timeframe $timeframe = Timeframe::D1, int $maxRows = 100_000): ProviderResult
{
    $path = (string) tempnam(sys_get_temp_dir(), 'csv');
    file_put_contents($path, $contents);

    try {
        return (new CsvPriceImporter($maxRows))->parse($path, $timeframe, 'Asia/Kathmandu');
    } finally {
        unlink($path);
    }
}

/**
 * @return list<string>
 */
function barTimes(ProviderResult $result): array
{
    return array_map(static fn (BarData $bar): string => $bar->time->toIso8601ZuluString(), $result->bars);
}

it('parses the documented format', function () {
    $result = parseCsv(<<<'CSV'
        date,open,high,low,close,volume,turnover
        2026-09-06,550,560.5,545,558.2,12450,6950000
        2026-09-07,558.2,570,555,566,15000,
        CSV);

    expect(barTimes($result))->toBe(['2026-09-06T00:00:00Z', '2026-09-07T00:00:00Z'])
        ->and($result->bars[0])->toMatchObject(['open' => '550', 'high' => '560.5', 'low' => '545', 'close' => '558.2', 'volume' => '12450', 'turnover' => '6950000', 'sourceRow' => 2])
        ->and($result->bars[1]->turnover)->toBeNull()
        ->and($result->skipped)->toBe([])
        ->and($result->meta)->toMatchArray(['delimiter' => 'comma', 'date_format' => 'iso', 'rows_read' => 2]);
});

it('parses a TradingView export with Unix times', function () {
    $result = parseCsv("time,open,high,low,close,Volume\n1788652800,550,560,545,558,12450\n");

    expect(barTimes($result))->toBe(['2026-09-06T00:00:00Z'])
        ->and($result->bars[0]->volume)->toBe('12450')
        ->and($result->meta['date_format'])->toBe('unix');
});

it('detects semicolons, quoted thousands separators and day-first dates', function () {
    $result = parseCsv(<<<'CSV'
        Date;Open;High;Low;Close;Volume
        13/08/2026;"1,050.00";"1,080.00";"1,040.00";"1,075.50";"12,345"
        14/08/2026;1075.5;1090;1070;1085;9000
        CSV);

    expect(barTimes($result))->toBe(['2026-08-13T00:00:00Z', '2026-08-14T00:00:00Z'])
        ->and($result->bars[0]->open)->toBe('1050.00')
        ->and($result->bars[0]->volume)->toBe('12345')
        ->and($result->meta)->toMatchArray(['delimiter' => 'semicolon', 'date_format' => 'd/m/Y', 'date_format_assumed' => false]);
});

it('detects month-first dates', function () {
    $result = parseCsv("date,open,high,low,close\n08/13/2026,1,2,0.5,1.5\n");

    expect(barTimes($result))->toBe(['2026-08-13T00:00:00Z'])
        ->and($result->meta['date_format'])->toBe('m/d/Y');
});

it('assumes day-first when no date disambiguates, and says so', function () {
    $result = parseCsv("date,open,high,low,close\n05/08/2026,1,2,0.5,1.5\n");

    expect(barTimes($result))->toBe(['2026-08-05T00:00:00Z'])
        ->and($result->meta)->toMatchArray(['date_format' => 'd/m/Y', 'date_format_assumed' => true]);
});

it('maps NEPSE-site headers', function () {
    $result = parseCsv(<<<'CSV'
        S.N.,Published Date,Open Price,High Price,Low Price,Close Price,Total Traded Quantity,Total Traded Amount
        1,2026-09-06,550,560,545,558,"12,450","6,950,000"
        CSV);

    expect($result->bars)->toHaveCount(1)
        ->and($result->bars[0])->toMatchObject(['close' => '558', 'volume' => '12450', 'turnover' => '6950000']);
});

it('reports rows that cannot become valid bars, with their line numbers', function () {
    $result = parseCsv(<<<'CSV'
        date,open,high,low,close,volume
        2026-09-06,550,560,545,558,100
        not-a-date,550,560,545,558,100
        2026-09-08,550,,545,558,100
        2026-09-09,550,540,545,558,100
        2026-09-10,550,560,555,558,100
        2026-09-13,-5,560,545,558,100
        2026-09-14,550,560,545,558,-1
        CSV);

    expect($result->bars)->toHaveCount(1)
        ->and(array_map(static fn (SkippedRow $row): array => $row->toArray(), $result->skipped))->toBe([
            ['row' => 3, 'reason' => 'Unrecognized date "not-a-date".'],
            ['row' => 4, 'reason' => 'Missing or non-numeric high.'],
            ['row' => 5, 'reason' => 'Low 545 is above high 540.'],
            ['row' => 6, 'reason' => 'Low 555 is above the open or close.'],
            ['row' => 7, 'reason' => 'Prices must be positive.'],
            ['row' => 8, 'reason' => 'Volume must be a non-negative number.'],
        ]);
});

it('keeps intraday times, converted from the exchange timezone to UTC', function () {
    $result = parseCsv("datetime,open,high,low,close\n2026-09-06 11:00,1,2,0.5,1.5\n", Timeframe::H1);

    expect(barTimes($result))->toBe(['2026-09-06T05:15:00Z']);
});

it('handles a UTF-8 byte order mark and CRLF line endings', function () {
    $result = parseCsv("\u{FEFF}date,open,high,low,close\r\n2026-09-06,1,2,0.5,1.5\r\n\r\n");

    expect(barTimes($result))->toBe(['2026-09-06T00:00:00Z']);
});

it('requires the date and OHLC columns', function () {
    parseCsv("date,open,close\n2026-09-06,1,2\n");
})->throws(InvalidCsvFile::class, 'Missing required column(s): high, low.');

it('rejects an empty file', function () {
    parseCsv('');
})->throws(InvalidCsvFile::class, 'The file is empty.');

it('rejects a header without data rows', function () {
    parseCsv("date,open,high,low,close\n");
})->throws(InvalidCsvFile::class, 'no data rows');

it('rejects dates that mix day-first and month-first order', function () {
    parseCsv("date,open,high,low,close\n13/08/2026,1,2,0.5,1.5\n08/14/2026,1,2,0.5,1.5\n");
})->throws(InvalidCsvFile::class, 'mix day-first and month-first');

it('refuses files above the row limit', function () {
    parseCsv("date,open,high,low,close\n2026-09-06,1,2,0.5,1.5\n2026-09-07,1,2,0.5,1.5\n2026-09-08,1,2,0.5,1.5\n", maxRows: 2);
})->throws(InvalidCsvFile::class, 'more than 2 rows');
