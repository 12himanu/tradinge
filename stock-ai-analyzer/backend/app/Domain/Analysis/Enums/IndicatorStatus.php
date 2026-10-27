<?php

declare(strict_types=1);

namespace App\Domain\Analysis\Enums;

use App\Domain\Shared\Enums\Concerns\HasValues;

enum IndicatorStatus: string
{
    use HasValues;

    case Computed = 'computed';
    case VisionRead = 'vision_read';
    case Unavailable = 'unavailable';
}
