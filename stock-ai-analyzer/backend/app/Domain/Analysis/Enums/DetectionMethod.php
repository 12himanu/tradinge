<?php

declare(strict_types=1);

namespace App\Domain\Analysis\Enums;

use App\Domain\Shared\Enums\Concerns\HasValues;

enum DetectionMethod: string
{
    use HasValues;

    case Algorithmic = 'algorithmic';
    case Vision = 'vision';
    case Both = 'both';
}
