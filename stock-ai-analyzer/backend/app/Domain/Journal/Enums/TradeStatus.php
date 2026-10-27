<?php

declare(strict_types=1);

namespace App\Domain\Journal\Enums;

use App\Domain\Shared\Enums\Concerns\HasValues;

enum TradeStatus: string
{
    use HasValues;

    case Planned = 'planned';
    case Open = 'open';
    case Closed = 'closed';
    case Cancelled = 'cancelled';
}
