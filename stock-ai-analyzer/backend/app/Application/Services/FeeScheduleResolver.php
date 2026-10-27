<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Domain\Market\Models\Exchange;

/**
 * Turns an exchange's fee schedule into the flat buy/sell percentages the engine's R:R uses.
 *
 * NEPSE charges a tiered broker commission plus a regulator fee on both sides, and a flat DP
 * charge per scrip on the sell. The engine models cost as a fraction of traded value, so the
 * flat charge is amortised over the position the plan would actually take. Capital gains tax is
 * deliberately excluded: it is a tax on profit, not a cost of the round trip, and folding it into
 * R:R would understate every setup.
 */
final readonly class FeeScheduleResolver
{
    public const float DEFAULT_PCT = 0.4;

    /**
     * @return array{buy_pct: float, sell_pct: float}
     */
    public function resolve(?Exchange $exchange, float $nominalPositionValue): array
    {
        $schedule = $exchange?->fee_schedule;
        if (! is_array($schedule) || $schedule === []) {
            return ['buy_pct' => self::DEFAULT_PCT, 'sell_pct' => self::DEFAULT_PCT];
        }

        $commission = $this->commissionPct($schedule, $nominalPositionValue);
        $regulator = $this->floatValue($schedule['regulator_fee_pct'] ?? null);
        $side = $commission + $regulator;

        $dpCharge = $this->floatValue($schedule['dp_charge_per_scrip'] ?? null);
        $dpPct = $nominalPositionValue > 0 ? $dpCharge / $nominalPositionValue * 100 : 0.0;

        return [
            'buy_pct' => round($side + $dpPct, 4),
            'sell_pct' => round($side + $dpPct, 4),
        ];
    }

    /**
     * @param  array<string, mixed>  $schedule
     */
    private function commissionPct(array $schedule, float $value): float
    {
        $tiers = $schedule['broker_commission'] ?? null;
        if (! is_array($tiers)) {
            return self::DEFAULT_PCT;
        }

        $minimum = null;
        foreach ($tiers as $tier) {
            if (! is_array($tier)) {
                continue;
            }
            $rate = $this->floatValue($tier['rate_pct'] ?? null);
            $minimum ??= $rate;
            $upTo = $tier['up_to'] ?? null;

            if ($upTo === null || $value <= $this->floatValue($upTo)) {
                return $rate;
            }
        }

        return $minimum ?? self::DEFAULT_PCT;
    }

    private function floatValue(mixed $value): float
    {
        return is_int($value) || is_float($value) || is_numeric($value) ? (float) $value : 0.0;
    }
}
