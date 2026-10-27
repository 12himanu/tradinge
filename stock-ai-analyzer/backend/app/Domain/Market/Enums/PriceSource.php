<?php

declare(strict_types=1);

namespace App\Domain\Market\Enums;

use App\Domain\Shared\Enums\Concerns\HasValues;

enum PriceSource: string
{
    use HasValues;

    case Csv = 'csv';
    case Nepse = 'nepse';
    case Yahoo = 'yahoo';
    case Merolagani = 'merolagani';
    case Sharesansar = 'sharesansar';
}
