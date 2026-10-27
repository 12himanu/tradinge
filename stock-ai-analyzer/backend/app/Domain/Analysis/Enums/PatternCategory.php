<?php

declare(strict_types=1);

namespace App\Domain\Analysis\Enums;

use App\Domain\Shared\Enums\Concerns\HasValues;

enum PatternCategory: string
{
    use HasValues;

    case Classic = 'classic';
    case Candlestick = 'candlestick';
    case Smc = 'smc';
}
