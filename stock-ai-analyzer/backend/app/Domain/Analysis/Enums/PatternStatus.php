<?php

declare(strict_types=1);

namespace App\Domain\Analysis\Enums;

use App\Domain\Shared\Enums\Concerns\HasValues;

enum PatternStatus: string
{
    use HasValues;

    case Forming = 'forming';
    case Confirmed = 'confirmed';
    case Failed = 'failed';
    case Mitigated = 'mitigated';
    case Unmitigated = 'unmitigated';
    /** An SMC zone price has closed through: the level no longer holds. */
    case Invalidated = 'invalidated';
}
