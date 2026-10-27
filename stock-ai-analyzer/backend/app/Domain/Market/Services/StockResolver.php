<?php

declare(strict_types=1);

namespace App\Domain\Market\Services;

use App\Domain\Market\Models\Stock;

/**
 * Maps a declared (exchange, symbol) pair to a known stock. Unknown symbols are allowed: the upload keeps
 * the declared symbol and stock_id stays null (Tier B/C analysis).
 */
final class StockResolver
{
    /**
     * Uppercases and removes all whitespace: " nabil " and "N ABIL" both become "NABIL".
     */
    public static function normalizeSymbol(string $symbol): string
    {
        return strtoupper((string) preg_replace('/\s+/u', '', $symbol));
    }

    public function resolve(?string $exchangeCode, ?string $symbol): ?Stock
    {
        if ($exchangeCode === null || $symbol === null) {
            return null;
        }

        $symbol = self::normalizeSymbol($symbol);
        $exchangeCode = self::normalizeSymbol($exchangeCode);

        if ($symbol === '' || $exchangeCode === '') {
            return null;
        }

        return Stock::query()
            ->whereRelation('exchange', 'code', $exchangeCode)
            ->where('symbol', $symbol)
            ->first();
    }
}
