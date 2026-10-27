<?php

declare(strict_types=1);

namespace App\Domain\Journal\Enums;

use App\Domain\Shared\Enums\Concerns\HasValues;

enum ExitReason: string
{
    use HasValues;

    case Target = 'target';
    case Stop = 'stop';
    case Manual = 'manual';
    case Time = 'time';
}
