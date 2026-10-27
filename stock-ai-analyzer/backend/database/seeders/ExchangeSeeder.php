<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Market\Models\Exchange;
use Illuminate\Database\Seeder;

/**
 * Exchange rules are configuration. Values marked `verified: false` are commonly cited figures that
 * must be checked against current NEPSE/SEBON circulars before net R:R is relied on.
 */
class ExchangeSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->exchanges() as $exchange) {
            Exchange::query()->updateOrCreate(['code' => $exchange['code']], $exchange);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function exchanges(): array
    {
        return [
            [
                'code' => 'NEPSE',
                'name' => 'Nepal Stock Exchange',
                'country' => 'NP',
                'timezone' => 'Asia/Kathmandu',
                'currency' => 'NPR',
                // Monday–Friday (ISO weekday numbers). NEPSE moved off the Sunday–Thursday week in
                // April 2026; see 2026_09_19_090100_correct_nepse_trading_days.
                'trading_days' => [1, 2, 3, 4, 5],
                'session_open' => '11:00:00',
                'session_close' => '15:00:00',
                'price_band_pct' => 10.00,
                'tick_size' => 0.1,
                'lot_size' => 10,
                'allows_short_selling' => false,
                'fee_schedule' => [
                    'verified' => false,
                    'source_note' => 'Commonly cited NEPSE/SEBON rates (2024). Verify price band, lot size, '
                        .'tick size and every fee against current circulars.',
                    'broker_commission' => [
                        ['up_to' => 50_000, 'rate_pct' => 0.36],
                        ['up_to' => 500_000, 'rate_pct' => 0.33],
                        ['up_to' => 2_000_000, 'rate_pct' => 0.31],
                        ['up_to' => 10_000_000, 'rate_pct' => 0.27],
                        ['up_to' => null, 'rate_pct' => 0.24],
                    ],
                    'min_commission' => 10,
                    'regulator_fee_pct' => 0.015,
                    'dp_charge_per_scrip' => 25,
                    'capital_gains_tax_pct' => ['short_term' => 7.5, 'long_term' => 5.0, 'long_term_after_days' => 365],
                ],
                'holidays' => [],
            ],
            [
                'code' => 'NASDAQ',
                'name' => 'Nasdaq Stock Market',
                'country' => 'US',
                'timezone' => 'America/New_York',
                'currency' => 'USD',
                'trading_days' => [1, 2, 3, 4, 5],
                'session_open' => '09:30:00',
                'session_close' => '16:00:00',
                'price_band_pct' => null,
                'tick_size' => 0.01,
                'lot_size' => 1,
                'allows_short_selling' => true,
                'fee_schedule' => ['verified' => false],
                'holidays' => [],
            ],
            [
                'code' => 'NSE',
                'name' => 'National Stock Exchange of India',
                'country' => 'IN',
                'timezone' => 'Asia/Kolkata',
                'currency' => 'INR',
                'trading_days' => [1, 2, 3, 4, 5],
                'session_open' => '09:15:00',
                'session_close' => '15:30:00',
                'price_band_pct' => null,
                'tick_size' => 0.05,
                'lot_size' => 1,
                'allows_short_selling' => true,
                'fee_schedule' => ['verified' => false],
                'holidays' => [],
            ],
        ];
    }
}
