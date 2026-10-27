<?php

declare(strict_types=1);

namespace App\Domain\Chart\Enums;

use App\Domain\Shared\Enums\Concerns\HasValues;

enum ChartPlatform: string
{
    use HasValues;

    case TradingView = 'tradingview';
    case NepseChart = 'nepse_chart';
    case Other = 'other';
}
