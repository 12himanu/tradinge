<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Domain\Journal\Enums\TradeSide;
use App\Domain\Journal\Models\TradeJournalEntry;

/**
 * Closing arithmetic for a journal entry (P5-18).
 *
 * R multiple is the number that matters: money made or lost as a multiple of the risk taken. It
 * only exists when the trade had a stop, because without one there was no defined risk to divide by.
 */
final readonly class TradeCalculator
{
    /**
     * @return array{gross_pnl: float, net_pnl: float, return_pct: float, r_multiple: float|null}
     */
    public function close(TradeJournalEntry $trade, float $exitPrice, float $fees): array
    {
        $entry = (float) $trade->entry_price;
        $quantity = (float) $trade->quantity;
        $long = $trade->side === TradeSide::Long;

        $perShare = $long ? $exitPrice - $entry : $entry - $exitPrice;
        $gross = $perShare * $quantity;
        $net = $gross - $fees;

        $invested = $entry * $quantity;
        $returnPct = $invested > 0 ? $net / $invested * 100 : 0.0;

        return [
            'gross_pnl' => round($gross, 2),
            'net_pnl' => round($net, 2),
            'return_pct' => round($returnPct, 4),
            'r_multiple' => $this->rMultiple($trade, $net, $quantity),
        ];
    }

    private function rMultiple(TradeJournalEntry $trade, float $net, float $quantity): ?float
    {
        if ($trade->stop_loss === null) {
            return null;
        }

        $entry = (float) $trade->entry_price;
        $stop = (float) $trade->stop_loss;
        $riskPerShare = $trade->side === TradeSide::Long ? $entry - $stop : $stop - $entry;
        $risk = $riskPerShare * $quantity;

        return $risk > 0 ? round($net / $risk, 2) : null;
    }
}
