<?php

declare(strict_types=1);

namespace App\Domain\Prediction\Enums;

use App\Domain\Shared\Enums\Concerns\HasValues;

enum EvaluationMethod: string
{
    use HasValues;

    case Auto = 'auto';
    case Manual = 'manual';
}
