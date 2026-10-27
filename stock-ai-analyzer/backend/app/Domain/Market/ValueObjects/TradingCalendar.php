<?php

declare(strict_types=1);

namespace App\Domain\Market\ValueObjects;

use App\Domain\Market\Models\Exchange;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Which calendar dates an exchange trades on: ISO weekdays (NEPSE: Sunday–Thursday) minus holidays.
 */
final readonly class TradingCalendar
{
    /**
     * @param  list<int>  $tradingDays  ISO-8601 weekday numbers (1 = Monday … 7 = Sunday)
     * @param  list<string>  $holidays  Y-m-d dates
     */
    public function __construct(
        public array $tradingDays,
        public array $holidays = [],
    ) {}

    public static function forExchange(Exchange $exchange): self
    {
        // Read raw (JSON-decoded) values: both columns are jsonb and shouldn't be trusted to be well-formed.
        $days = [];
        $rawDays = $exchange->getAttribute('trading_days');
        foreach (is_array($rawDays) ? $rawDays : [] as $day) {
            if (is_numeric($day)) {
                $days[] = (int) $day;
            }
        }

        $holidays = [];
        $rawHolidays = $exchange->getAttribute('holidays');
        foreach (is_array($rawHolidays) ? $rawHolidays : [] as $holiday) {
            if (is_string($holiday)) {
                $holidays[] = $holiday;
            }
        }

        return new self($days, $holidays);
    }

    /**
     * Every weekday trades. Used when no exchange is known, so a horizon still expires somewhere
     * sensible rather than never.
     */
    public static function everyWeekday(): self
    {
        return new self([1, 2, 3, 4, 5]);
    }

    public function isTradingDay(CarbonInterface $date): bool
    {
        return in_array($date->dayOfWeekIso, $this->tradingDays, true)
            && ! in_array($date->format('Y-m-d'), $this->holidays, true);
    }

    /**
     * The date `$sessions` trading days after `$from`. A prediction with a 20-bar horizon expires
     * 20 sessions later, not 20 calendar days later, or weekends would eat a quarter of it.
     */
    public function addTradingDays(CarbonInterface $from, int $sessions): CarbonImmutable
    {
        $date = CarbonImmutable::instance($from);
        if ($this->tradingDays === [] || $sessions <= 0) {
            return $date->addDays(max(0, $sessions));
        }

        $remaining = $sessions;
        // Bounded so a calendar with only holidays configured cannot loop forever.
        $limit = $sessions * 7 + 14;
        while ($remaining > 0 && $limit-- > 0) {
            $date = $date->addDay();
            if ($this->isTradingDay($date)) {
                $remaining--;
            }
        }

        return $date;
    }
}
