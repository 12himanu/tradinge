<?php

declare(strict_types=1);

namespace App\Domain\Prediction\Enums;

use App\Domain\Shared\Enums\Concerns\HasValues;

enum Scenario: string
{
    use HasValues;

    case Primary = 'primary';
    case Bullish = 'bullish';
    case Bearish = 'bearish';
}
