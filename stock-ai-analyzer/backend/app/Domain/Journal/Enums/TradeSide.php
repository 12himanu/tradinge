<?php

declare(strict_types=1);

namespace App\Domain\Journal\Enums;

use App\Domain\Shared\Enums\Concerns\HasValues;

enum TradeSide: string
{
    use HasValues;

    case Long = 'long';
    case Short = 'short';
}
