<?php

declare(strict_types=1);

namespace App\Domain\Prediction\Enums;

use App\Domain\Shared\Enums\Concerns\HasValues;

enum TradeDirection: string
{
    use HasValues;

    case Long = 'long';
    case Short = 'short';
    case Flat = 'flat';
}
