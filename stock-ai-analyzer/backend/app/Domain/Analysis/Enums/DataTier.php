<?php

declare(strict_types=1);

namespace App\Domain\Analysis\Enums;

use App\Domain\Shared\Enums\Concerns\HasValues;

enum DataTier: string
{
    use HasValues;

    case Ohlcv = 'ohlcv';
    case Digitized = 'digitized';
    case VisionOnly = 'vision_only';
}
