<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

use App\Domain\Shared\Enums\Concerns\HasValues;

enum Timeframe: string
{
    use HasValues;

    case M15 = '15m';
    case H1 = '1h';
    case H4 = '4h';
    case D1 = '1D';
    case W1 = '1W';

    public function isIntraday(): bool
    {
        return in_array($this, [self::M15, self::H1, self::H4], true);
    }
}
