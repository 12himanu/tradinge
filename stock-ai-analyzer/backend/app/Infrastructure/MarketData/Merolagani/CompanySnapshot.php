<?php

declare(strict_types=1);

namespace App\Infrastructure\MarketData\Merolagani;

use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMNodeList;
use DOMXPath;

/**
 * The fundamentals panel of CompanyDetail.aspx, normalized for `stocks.metadata`.
 *
 * These are reference figures for context, never analysis inputs: no number here reaches the
 * engine, and none of them prices a decision. The engine works from `price_bars` only.
 *
 * Two sources, because one handler does not carry everything the page shows:
 *  - `get_company_summary` (JSON) gives price, 52-week range, EPS, P/E, market cap, dividends.
 *  - the rendered page gives sector, shares outstanding, book value, PBV and the moving averages.
 *
 * The page is optional. A snapshot built from the JSON alone is still valid, which is what keeps a
 * restyled page from being able to break a price sync.
 */
final readonly class CompanySnapshot
{
    /**
     * Page label => metadata key. Matched on the label's ending, because a label rendered next to a
     * nested table (`% Bonus`, `30-Day Avg Volume`) picks up that table's text in front of it.
     */
    private const array PAGE_FIELDS = [
        'Shares Outstanding' => 'shares_outstanding',
        'Book Value' => 'book_value',
        'PBV' => 'pbv',
        '120 Day Average' => 'one_twenty_day_average',
        '30-Day Avg Volume' => 'thirty_day_average_volume',
        'Paidup Value' => 'paidup_value',
    ];

    /**
     * @param  array<string, string|float>  $values
     */
    private function __construct(
        public array $values,
        public ?string $sector,
        public ?string $name,
    ) {}

    /**
     * @param  array<string, mixed>|null  $summary  decoded get_company_summary payload
     * @param  string|null  $page  rendered CompanyDetail.aspx HTML, when available
     */
    public static function from(?array $summary, ?string $page, CarbonImmutable $fetchedAt): self
    {
        $summary ??= [];

        $values = [
            'fetched_at' => $fetchedAt->toIso8601ZuluString(),
            // Date only: the clock merolagani prints beside it is not the time of the last trade.
            'trade_date' => self::tradeDate($summary['tradeDate'] ?? null),
            'last_price' => self::number($summary['ltp'] ?? null),
            'percent_change' => self::number($summary['percentChange'] ?? null),
            'fifty_two_week_high' => self::number($summary['fiftyTwoHigh'] ?? null),
            'fifty_two_week_low' => self::number($summary['fiftyTwoLow'] ?? null),
            'one_eighty_day_average' => self::number($summary['oneEightyAvg'] ?? null),
            'one_year_yield_pct' => self::number($summary['oneYearYield'] ?? null),
            'eps' => self::number($summary['eps'] ?? null),
            'eps_fiscal_year' => self::text($summary['epsFY'] ?? null),
            'eps_quarter' => self::text($summary['epsQuarter'] ?? null),
            'pe_ratio' => self::number($summary['peRatio'] ?? null),
            'market_cap' => self::number($summary['marketCap'] ?? null),
            'cash_dividend_pct' => self::number($summary['percentCash'] ?? null),
            'bonus_dividend_pct' => self::number($summary['percentBonus'] ?? null),
            'dividend_fiscal_year' => self::text($summary['cashFY'] ?? $summary['bonusFY'] ?? null),
            'right_share' => self::text($summary['rightShare'] ?? null),
            'right_share_fiscal_year' => self::text($summary['rightFY'] ?? null),
        ];

        $sector = null;
        if ($page !== null) {
            $labels = self::labelledCells($page);

            foreach (self::PAGE_FIELDS as $label => $key) {
                $values[$key] = self::number(self::cell($labels, $label));
            }
            $sector = self::text(self::cell($labels, 'Sector'));
        }

        return new self(
            values: array_filter($values, static fn (string|float|null $value): bool => $value !== null),
            sector: $sector,
            name: self::text($summary['name'] ?? null),
        );
    }

    /**
     * Merged into `stocks.metadata` under its own key, so nothing else stored there is disturbed.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public function mergeInto(array $metadata): array
    {
        $metadata['merolagani'] = $this->values;

        return $metadata;
    }

    /**
     * Every `<tr>` on the page that has a label cell and a value cell, as plain text.
     *
     * Walked as a document rather than matched as text. Some of these rows hold a nested table in
     * their label cell, and any regex over the markup either cuts the row short at that table or
     * mistakes one of its cells for the pair being looked for. Taking only the direct `th`/`td`
     * children of a `tr` sidesteps both, because a nested table's cells are not direct children.
     *
     * Best effort by design: this is a rendered ASP.NET view that can be restyled at any time, and
     * an empty result here costs a few reference figures, never a bar.
     *
     * @return list<array{label: string, value: string}>
     */
    private static function labelledCells(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }

        $document = new DOMDocument;
        // The page is HTML4-ish ASP.NET output full of things libxml objects to; none of it matters
        // to a table walk, so its complaints are collected and dropped rather than printed.
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            return [];
        }

        $rows = (new DOMXPath($document))->query('//tr');

        if (! $rows instanceof DOMNodeList) {
            return [];
        }

        $cells = [];
        foreach ($rows as $row) {
            if (! $row instanceof DOMElement) {
                continue;
            }

            $label = self::firstChildText($row, 'th');
            $value = self::firstChildText($row, 'td');

            if ($label !== null && $value !== null) {
                $cells[] = ['label' => $label, 'value' => $value];
            }
        }

        return $cells;
    }

    /**
     * Text of the first direct `$tag` child of `$row`, collapsed to one line.
     */
    private static function firstChildText(DOMNode $row, string $tag): ?string
    {
        foreach ($row->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->nodeName) === $tag) {
                $text = trim((string) preg_replace('/\s+/u', ' ', $child->textContent));

                return $text !== '' ? $text : null;
            }
        }

        return null;
    }

    /**
     * @param  list<array{label: string, value: string}>  $cells
     */
    private static function cell(array $cells, string $label): ?string
    {
        foreach ($cells as $cell) {
            if ($cell['label'] === $label || str_ends_with($cell['label'], ' '.$label)) {
                return $cell['value'] !== '' ? $cell['value'] : null;
            }
        }

        return null;
    }

    /**
     * The date part of `MM/DD/YYYY hh:mm:ss`, as `Y-m-d`.
     */
    private static function tradeDate(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('#^(\d{2})/(\d{2})/(\d{4})#', trim($value), $match) !== 1) {
            return null;
        }

        return sprintf('%s-%s-%s', $match[3], $match[1], $match[2]);
    }

    /**
     * Thousands separators, percent signs and blanks are all normal in these fields.
     */
    private static function number(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return is_finite((float) $value) ? (float) $value : null;
        }
        if (! is_string($value)) {
            return null;
        }

        $clean = str_replace([',', ' ', '%', "\u{00A0}"], '', trim($value));

        return preg_match('/^-?\d+(\.\d+)?$/', $clean) === 1 ? (float) $clean : null;
    }

    private static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
