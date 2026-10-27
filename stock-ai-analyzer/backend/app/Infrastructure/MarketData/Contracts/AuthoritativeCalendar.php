<?php

declare(strict_types=1);

namespace App\Infrastructure\MarketData\Contracts;

/**
 * Marks a provider whose bars are themselves the record of which days the exchange traded, so
 * ImportPriceBars must not filter them against `exchanges.trading_days`.
 *
 * The weekday filter exists to catch bad rows in a hand-made CSV. For an exchange data vendor the
 * reasoning runs the other way: a bar exists because a session happened. A static weekday list
 * cannot describe a history that spans a calendar change — NEPSE traded Sunday–Thursday until
 * April 2026 and Monday–Friday after it, so any single list rejects years of real bars.
 *
 * Duplicate detection, OHLC validation and every other check still apply.
 */
interface AuthoritativeCalendar {}
