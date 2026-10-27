<?php

declare(strict_types=1);

namespace App\Domain\Analysis\Enums;

use App\Domain\Shared\Enums\Concerns\HasValues;

enum LevelSource: string
{
    use HasValues;

    case Pivots = 'pivots';
    case VolumeProfile = 'volume_profile';
    case Smc = 'smc';
    case OcrLabel = 'ocr_label';
}
