<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

use App\Domain\Shared\Enums\Concerns\HasValues;

enum TradingStyle: string
{
    use HasValues;

    case Swing = 'swing';
    case Positional = 'positional';
    case Investing = 'investing';
    case Intraday = 'intraday';
}
