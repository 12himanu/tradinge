<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

use App\Domain\Shared\Enums\Concerns\HasValues;

enum Bias: string
{
    use HasValues;

    case Bullish = 'bullish';
    case Bearish = 'bearish';
    case Neutral = 'neutral';
}
