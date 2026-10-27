<?php

declare(strict_types=1);

namespace App\Infrastructure\MarketData;

use RuntimeException;

/**
 * The CSV as a whole can't be imported (as opposed to individual bad rows, which are skipped and reported).
 */
final class InvalidCsvFile extends RuntimeException
{
    public static function empty(): self
    {
        return new self('The file is empty.');
    }

    public static function noRows(): self
    {
        return new self('The file has a header but no data rows.');
    }

    /**
     * @param  list<string>  $missing
     * @param  list<string>  $found
     */
    public static function missingColumns(array $missing, array $found): self
    {
        return new self(sprintf(
            'Missing required column(s): %s. Found: %s. See docs/csv-format.md for accepted headers.',
            implode(', ', $missing),
            $found === [] ? 'none' : implode(', ', array_slice($found, 0, 12)),
        ));
    }

    public static function unknownDateFormat(string $sample): self
    {
        return new self(sprintf('Unrecognized date format "%s". Use YYYY-MM-DD, DD/MM/YYYY or Unix seconds.', mb_substr($sample, 0, 40)));
    }

    public static function inconsistentDates(): self
    {
        return new self('Dates mix day-first and month-first order. Export the file with ISO dates (YYYY-MM-DD).');
    }

    public static function tooManyRows(int $max): self
    {
        return new self(sprintf('The file has more than %s rows. Split it and import the parts separately.', number_format($max)));
    }
}
