<?php

declare(strict_types=1);

namespace App\Domain\Analysis\Enums;

use App\Domain\Shared\Enums\Concerns\HasValues;

enum LevelType: string
{
    use HasValues;

    case Support = 'support';
    case Resistance = 'resistance';
    case Liquidity = 'liquidity';
    case Equilibrium = 'equilibrium';
    case Premium = 'premium';
    case Discount = 'discount';
}
