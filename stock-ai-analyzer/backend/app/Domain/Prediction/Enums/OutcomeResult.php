<?php

declare(strict_types=1);

namespace App\Domain\Prediction\Enums;

use App\Domain\Shared\Enums\Concerns\HasValues;

enum OutcomeResult: string
{
    use HasValues;

    case Win = 'win';
    case Loss = 'loss';
    case Breakeven = 'breakeven';
    case Expired = 'expired';
    case NotTriggered = 'not_triggered';
    case Invalidated = 'invalidated';
    case NoData = 'no_data';
}
