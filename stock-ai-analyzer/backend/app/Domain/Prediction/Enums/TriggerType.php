<?php

declare(strict_types=1);

namespace App\Domain\Prediction\Enums;

use App\Domain\Shared\Enums\Concerns\HasValues;

enum TriggerType: string
{
    use HasValues;

    case Immediate = 'immediate';
    case CloseAbove = 'close_above';
    case CloseBelow = 'close_below';
    case Touch = 'touch';
}
