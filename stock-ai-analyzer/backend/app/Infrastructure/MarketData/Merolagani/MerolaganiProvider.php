<?php

declare(strict_types=1);

namespace App\Infrastructure\MarketData\Merolagani;

use App\Domain\Market\Enums\PriceSource;
use App\Domain\Market\Models\Stock;
use App\Domain\Market\ValueObjects\BarData;
use App\Domain\Market\ValueObjects\SkippedRow;
use App\Domain\Shared\Enums\Timeframe;
use App\Infrastructure\MarketData\Contracts\AuthoritativeCalendar;
use App\Infrastructure\MarketData\Contracts\MarketDataProvider;
use App\Infrastructure\MarketData\ProviderResult;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Daily NEPSE bars from merolagani, as a MarketDataProvider so they go through the same
 * ImportPriceBars action as a CSV upload: same duplicate handling, same upsert, same summary.
 *
 * Only `1D` is offered. The handler answers other resolutions, but with raw trade prints that
 * TradingView aggregates in the browser rather than with server-side intraday or weekly bars, so
 * anything else here would be inventing bars. Weekly bars are an aggregation of these.
 *
 * ## Dating a bar
 *
 * The timestamps carry the right calendar date in **UTC** and an unreliable time of day: bars up to
 * February 2018 are stamped 05:45 UTC (11:30 in Kathmandu, mid-session), later ones around 20:45
 * UTC, which is 02:30 the *next* morning in Kathmandu. Reading them in exchange-local time
 * therefore moves every recent bar forward a day — Sunday sessions land on Monday and Thursday
 * sessions on Friday. Checked against the `tradeDate` merolagani itself prints for the latest bar,
 * the UTC date is the business date, so that is the date used. It also matches how price_bars
 * stores daily bars: 00:00 UTC of the trading date.
 */
final class MerolaganiProvider implements AuthoritativeCalendar, MarketDataProvider
{
    /**
     * Prices land in numeric(14,4), so four decimals is all that survives storage anyway.
     */
    private const int PRICE_SCALE = 4;

    /** Series key => the name used when a bar is reported as skipped. */
    private const array PRICE_NAMES = ['o' => 'open', 'h' => 'high', 'l' => 'low', 'c' => 'close'];

    public function __construct(
        private readonly MerolaganiClient $client,
        private readonly CarbonImmutable $from,
        private readonly CarbonImmutable $to,
        private readonly bool $adjusted,
    ) {}

    public function source(): PriceSource
    {
        return PriceSource::Merolagani;
    }

    public function fetch(Stock $stock, Timeframe $timeframe): ProviderResult
    {
        if ($timeframe !== Timeframe::D1) {
            throw new InvalidArgumentException(
                "Merolagani provides daily bars only; asked for {$timeframe->value}."
            );
        }

        $series = $this->client->dailyBars($stock->symbol, $this->from, $this->to, $this->adjusted);

        $bars = [];
        $skipped = [];

        foreach ($series['t'] as $index => $timestamp) {
            // `row` is 1-based so a reported position matches how a person counts the series.
            $row = $index + 1;
            $result = $this->toBar($row, $timestamp, $index, $series);

            if ($result instanceof BarData) {
                $bars[] = $result;
            } else {
                $skipped[] = $result;
            }
        }

        return new ProviderResult($bars, $skipped, [
            'provider' => 'merolagani',
            'endpoint' => 'get_advanced_chart',
            'resolution' => '1D',
            'adjusted' => $this->adjusted,
            'range_from' => $this->from->toDateString(),
            'range_to' => $this->to->toDateString(),
            'rows_read' => count($series['t']),
        ]);
    }

    /**
     * @param  array{t: list<int>, o: list<float>, h: list<float>, l: list<float>, c: list<float>, v: list<float|null>}  $series
     */
    private function toBar(int $row, mixed $timestamp, int $index, array $series): BarData|SkippedRow
    {
        if (! is_int($timestamp) && ! (is_float($timestamp) && $timestamp === floor($timestamp))) {
            return new SkippedRow($row, 'Timestamp is not a whole number of seconds.');
        }

        $prices = [];
        foreach (['o', 'h', 'l', 'c'] as $key) {
            $value = $this->decimal($series[$key][$index] ?? null);
            if ($value === null) {
                return new SkippedRow($row, sprintf('Missing or non-numeric %s.', self::PRICE_NAMES[$key]));
            }
            $prices[$key] = $value;
        }

        [$open, $high, $low, $close] = [
            (float) $prices['o'], (float) $prices['h'], (float) $prices['l'], (float) $prices['c'],
        ];

        // The same checks as a CSV import, in the same order, so a bad bar reads the same either way.
        $problem = match (true) {
            min($open, $high, $low, $close) <= 0 => 'Prices must be positive.',
            $low > $high => sprintf('Low %s is above high %s.', $prices['l'], $prices['h']),
            $high < max($open, $close) => sprintf('High %s is below the open or close.', $prices['h']),
            $low > min($open, $close) => sprintf('Low %s is above the open or close.', $prices['l']),
            default => null,
        };
        if ($problem !== null) {
            return new SkippedRow($row, $problem);
        }

        $volume = $this->decimal($series['v'][$index] ?? null);
        if ($volume !== null && (float) $volume < 0) {
            return new SkippedRow($row, 'Volume must be a non-negative number.');
        }

        return new BarData(
            // 00:00 UTC of the trading date. See the class note on why this reads UTC, not Kathmandu.
            time: CarbonImmutable::createFromTimestampUTC((int) $timestamp)->startOfDay(),
            open: $prices['o'],
            high: $prices['h'],
            low: $prices['l'],
            close: $prices['c'],
            volume: $volume,
            // The handler carries no traded amount; turnover stays null rather than being guessed.
            turnover: null,
            sourceRow: $row,
        );
    }

    /**
     * A decimal string for numeric(14,4), or null when the value is absent or not a finite number.
     */
    private function decimal(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        if (! is_float($value) || ! is_finite($value)) {
            return null;
        }

        return rtrim(rtrim(number_format($value, self::PRICE_SCALE, '.', ''), '0'), '.') ?: '0';
    }
}
