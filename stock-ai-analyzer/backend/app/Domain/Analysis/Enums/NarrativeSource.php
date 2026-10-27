<?php

declare(strict_types=1);

namespace App\Domain\Analysis\Enums;

use App\Domain\Shared\Enums\Concerns\HasValues;

enum NarrativeSource: string
{
    use HasValues;

    case Llm = 'llm';
    case Template = 'template';
}
