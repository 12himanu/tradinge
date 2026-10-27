<?php

declare(strict_types=1);

namespace App\Domain\Analysis\Enums;

use App\Domain\Shared\Enums\Concerns\HasValues;

enum Decision: string
{
    use HasValues;

    case Buy = 'buy';
    case Wait = 'wait';
    case Hold = 'hold';
    case Sell = 'sell';
    case Avoid = 'avoid';
}
