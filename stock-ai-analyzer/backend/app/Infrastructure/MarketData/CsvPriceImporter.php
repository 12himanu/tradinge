<?php

declare(strict_types=1);

namespace App\Infrastructure\MarketData;

use App\Domain\Market\ValueObjects\BarData;
use App\Domain\Market\ValueObjects\SkippedRow;
use App\Domain\Shared\Enums\Timeframe;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Generator;
use InvalidArgumentException;
use SplFileObject;

/**
 * Parses an OHLCV CSV export into bars (docs/csv-format.md).
 *
 * Accepts `date,open,high,low,close,volume[,turnover]`, TradingView's `time,open,high,low,close,Volume`
 * and common NEPSE-site headers. Detects the delimiter and the date format. Rows that can't become a
 * valid bar are reported with their line number; nothing is dropped silently. Streams the file twice
 * (date-format sample, then parse) instead of loading it into memory.
 */
final class CsvPriceImporter
{
    /** Canonical column => accepted (normalized) header names, in priority order. */
    private const array COLUMNS = [
        'date' => ['date', 'time', 'datetime', 'timestamp', 'trading_date', 'business_date', 'published_date'],
        'open' => ['open', 'open_price', 'opening_price'],
        'high' => ['high', 'high_price', 'max_price'],
        'low' => ['low', 'low_price', 'min_price'],
        'close' => ['close', 'close_price', 'closing_price', 'ltp', 'last_traded_price'],
        'volume' => ['volume', 'vol', 'traded_quantity', 'total_traded_quantity', 'traded_shares', 'qty'],
        'turnover' => ['turnover', 'amount', 'traded_amount', 'total_traded_amount', 'total_traded_value'],
    ];

    private const array REQUIRED = ['date', 'open', 'high', 'low', 'close'];

    private const array DELIMITERS = [',' => 'comma', ';' => 'semicolon', "\t" => 'tab', '|' => 'pipe'];

    private const string ISO_DATE = '/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2}(\.\d+)?)?\s*(Z|[+-]\d{2}:?\d{2})?)?$/';

    private const int DATE_SAMPLE_SIZE = 500;

    public function __construct(private readonly int $maxRows = 100_000) {}

    /**
     * @param  string  $timezone  exchange timezone, used for dates without an offset
     */
    public function parse(string $path, Timeframe $timeframe, string $timezone): ProviderResult
    {
        $file = new SplFileObject($path, 'r');
        $tz = new DateTimeZone($timezone);

        [$headerLine, $header] = $this->firstNonEmptyLine($file) ?? throw InvalidCsvFile::empty();
        $delimiter = $this->detectDelimiter($header);
        $columns = $this->mapColumns($this->fields($this->stripBom($header), $delimiter));

        $samples = [];
        foreach ($this->rows($file, $delimiter, $headerLine) as $fields) {
            $value = $fields[$columns['date']] ?? '';
            if ($value !== '') {
                $samples[] = $value;
            }
            if (count($samples) >= self::DATE_SAMPLE_SIZE) {
                break;
            }
        }
        [$dateFormat, $assumed] = $this->detectDateFormat($samples);

        $bars = [];
        $skipped = [];
        $read = 0;

        foreach ($this->rows($file, $delimiter, $headerLine) as $line => $fields) {
            if (++$read > $this->maxRows) {
                throw InvalidCsvFile::tooManyRows($this->maxRows);
            }

            $result = $this->toBar($line, $fields, $columns, $dateFormat, $timeframe, $tz);
            if ($result instanceof BarData) {
                $bars[] = $result;
            } else {
                $skipped[] = $result;
            }
        }

        return new ProviderResult($bars, $skipped, [
            'delimiter' => self::DELIMITERS[$delimiter],
            'date_format' => $dateFormat,
            'date_format_assumed' => $assumed,
            'rows_read' => $read,
        ]);
    }

    /**
     * @param  list<string>  $fields
     * @param  array<string, int>  $columns
     */
    private function toBar(int $line, array $fields, array $columns, string $dateFormat, Timeframe $timeframe, DateTimeZone $tz): BarData|SkippedRow
    {
        $rawDate = $fields[$columns['date']] ?? '';
        $moment = $this->parseDate($rawDate, $dateFormat, $tz);
        if ($moment === null) {
            return new SkippedRow($line, sprintf('Unrecognized date "%s".', mb_substr($rawDate, 0, 40)));
        }

        $prices = [];
        foreach (['open', 'high', 'low', 'close'] as $column) {
            $value = $this->decimal($fields[$columns[$column]] ?? '');
            if (! is_string($value)) {
                return new SkippedRow($line, "Missing or non-numeric {$column}.");
            }
            $prices[$column] = $value;
        }

        [$open, $high, $low, $close] = [(float) $prices['open'], (float) $prices['high'], (float) $prices['low'], (float) $prices['close']];

        $problem = match (true) {
            min($open, $high, $low, $close) <= 0 => 'Prices must be positive.',
            $low > $high => sprintf('Low %s is above high %s.', $prices['low'], $prices['high']),
            $high < max($open, $close) => sprintf('High %s is below the open or close.', $prices['high']),
            $low > min($open, $close) => sprintf('Low %s is above the open or close.', $prices['low']),
            default => null,
        };
        if ($problem !== null) {
            return new SkippedRow($line, $problem);
        }

        $optional = [];
        foreach (['volume', 'turnover'] as $column) {
            $value = isset($columns[$column]) ? $this->decimal($fields[$columns[$column]] ?? '') : null;
            if ($value === false || (is_string($value) && (float) $value < 0)) {
                return new SkippedRow($line, ucfirst($column).' must be a non-negative number.');
            }
            $optional[$column] = $value;
        }

        return new BarData(
            time: $this->barTime($moment, $timeframe, $tz),
            open: $prices['open'],
            high: $prices['high'],
            low: $prices['low'],
            close: $prices['close'],
            volume: $optional['volume'],
            turnover: $optional['turnover'],
            sourceRow: $line,
        );
    }

    /**
     * Intraday bars keep their instant (in UTC). Daily and weekly bars become 00:00 UTC of their
     * calendar date on the exchange, whatever time or offset the source used.
     */
    private function barTime(CarbonImmutable $moment, Timeframe $timeframe, DateTimeZone $tz): CarbonImmutable
    {
        if ($timeframe->isIntraday()) {
            return $moment->utc();
        }

        return CarbonImmutable::parse($moment->setTimezone($tz)->format('Y-m-d'), 'UTC');
    }

    /**
     * @param  list<string>  $samples
     * @return array{0: string, 1: bool} format, and whether day-first order was assumed
     */
    private function detectDateFormat(array $samples): array
    {
        $first = $samples[0] ?? throw InvalidCsvFile::noRows();

        if (preg_match('/^\d{12,13}$/', $first) === 1) {
            return ['unix_ms', false];
        }
        if (preg_match('/^\d{9,10}$/', $first) === 1) {
            return ['unix', false];
        }
        if (preg_match(self::ISO_DATE, $first) === 1) {
            return ['iso', false];
        }
        if (preg_match('#^\d{4}/\d{1,2}/\d{1,2}#', $first) === 1) {
            return ['Y/m/d', false];
        }
        if (preg_match('#^\d{1,2}([/.-])\d{1,2}\1\d{4}#', $first, $match) === 1) {
            $separator = $match[1];
            $dayFirst = false;
            $monthFirst = false;

            foreach ($samples as $sample) {
                if (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-]\d{4}#', $sample, $parts) === 1) {
                    $dayFirst = $dayFirst || (int) $parts[1] > 12;
                    $monthFirst = $monthFirst || (int) $parts[2] > 12;
                }
            }

            if ($dayFirst && $monthFirst) {
                throw InvalidCsvFile::inconsistentDates();
            }

            // No sample disambiguates (every day ≤ 12): assume day-first, as NEPSE sites write dates.
            return $monthFirst
                ? ["m{$separator}d{$separator}Y", false]
                : ["d{$separator}m{$separator}Y", ! $dayFirst];
        }

        throw InvalidCsvFile::unknownDateFormat($first);
    }

    private function parseDate(string $value, string $format, DateTimeZone $tz): ?CarbonImmutable
    {
        try {
            return match ($format) {
                'unix' => preg_match('/^\d{9,10}$/', $value) === 1 ? CarbonImmutable::createFromTimestampUTC((int) $value) : null,
                'unix_ms' => preg_match('/^\d{12,13}$/', $value) === 1 ? CarbonImmutable::createFromTimestampMsUTC((int) $value) : null,
                'iso' => preg_match(self::ISO_DATE, $value) === 1 ? CarbonImmutable::parse($value, $tz) : null,
                default => $this->fromFormat($value, $format, $tz),
            };
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function fromFormat(string $value, string $format, DateTimeZone $tz): ?CarbonImmutable
    {
        foreach (['', ' H:i', ' H:i:s'] as $time) {
            $parsed = DateTimeImmutable::createFromFormat('!'.$format.$time, $value, $tz);

            // getLastErrors() is false only when the parse produced no warnings (e.g. no 31/02 rollover).
            if ($parsed !== false && DateTimeImmutable::getLastErrors() === false) {
                return CarbonImmutable::instance($parsed);
            }
        }

        return null;
    }

    /**
     * @return string|false|null normalized decimal, false when present but not numeric, null when empty
     */
    private function decimal(string $value): string|false|null
    {
        $clean = str_replace([',', ' ', "\u{00A0}"], '', $value);

        if ($clean === '' || $clean === '-') {
            return null;
        }
        if (preg_match('/^-?\d+(\.\d+)?$/', $clean) === 1) {
            return $clean;
        }
        if (preg_match('/^-?\d+(\.\d+)?[eE][+-]?\d+$/', $clean) === 1) {
            return rtrim(rtrim(sprintf('%.4F', (float) $clean), '0'), '.');
        }

        return false;
    }

    /**
     * @param  list<string>  $headers
     * @return array<string, int> canonical column => field index
     */
    private function mapColumns(array $headers): array
    {
        $normalized = array_map(
            static fn (string $header): string => trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($header)), '_'),
            $headers,
        );

        $map = [];
        foreach (self::COLUMNS as $column => $aliases) {
            foreach ($aliases as $alias) {
                $index = array_search($alias, $normalized, true);
                if ($index !== false) {
                    $map[$column] = $index;
                    break;
                }
            }
        }

        $missing = array_values(array_diff(self::REQUIRED, array_keys($map)));
        if ($missing !== []) {
            throw InvalidCsvFile::missingColumns($missing, array_values(array_filter($headers, static fn (string $h): bool => $h !== '')));
        }

        return $map;
    }

    /**
     * @return array{0: int, 1: string}|null line number and content of the first non-blank line
     */
    private function firstNonEmptyLine(SplFileObject $file): ?array
    {
        $file->rewind();
        $line = 0;

        while (! $file->eof()) {
            $content = rtrim($file->fgets(), "\r\n");
            $line++;

            if (trim($this->stripBom($content)) !== '') {
                return [$line, $content];
            }
        }

        return null;
    }

    /**
     * @return Generator<int, list<string>> line number => trimmed fields, blank lines skipped
     */
    private function rows(SplFileObject $file, string $delimiter, int $headerLine): Generator
    {
        $file->rewind();
        $line = 0;

        while (! $file->eof()) {
            $content = rtrim($file->fgets(), "\r\n");
            $line++;

            if ($line <= $headerLine || trim($content) === '') {
                continue;
            }

            yield $line => $this->fields($content, $delimiter);
        }
    }

    /**
     * @return list<string>
     */
    private function fields(string $line, string $delimiter): array
    {
        return array_map(
            static fn (?string $field): string => trim((string) $field),
            str_getcsv($line, $delimiter, '"', ''),
        );
    }

    private function detectDelimiter(string $header): string
    {
        $best = ',';
        $bestCount = 0;

        foreach (array_keys(self::DELIMITERS) as $delimiter) {
            $count = substr_count($header, $delimiter);
            if ($count > $bestCount) {
                [$best, $bestCount] = [$delimiter, $count];
            }
        }

        return $best;
    }

    private function stripBom(string $line): string
    {
        return str_starts_with($line, "\u{FEFF}") ? substr($line, 3) : $line;
    }
}
