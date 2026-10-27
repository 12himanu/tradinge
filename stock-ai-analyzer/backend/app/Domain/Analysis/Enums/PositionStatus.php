<?php

declare(strict_types=1);

namespace App\Domain\Analysis\Enums;

use App\Domain\Shared\Enums\Concerns\HasValues;

enum PositionStatus: string
{
    use HasValues;

    case None = 'none';
    case Holding = 'holding';
}
