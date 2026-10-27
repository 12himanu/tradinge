<?php

declare(strict_types=1);

namespace App\Domain\Analysis\Enums;

use App\Domain\Shared\Enums\Concerns\HasValues;

enum FeedbackIssue: string
{
    use HasValues;

    case WrongSymbol = 'wrong_symbol';
    case WrongLevels = 'wrong_levels';
    case WrongPattern = 'wrong_pattern';
    case MisreadChart = 'misread_chart';
    case NotUseful = 'not_useful';
    case Helpful = 'helpful';
}
