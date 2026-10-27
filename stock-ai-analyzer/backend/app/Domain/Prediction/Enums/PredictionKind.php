<?php

declare(strict_types=1);

namespace App\Domain\Prediction\Enums;

use App\Domain\Shared\Enums\Concerns\HasValues;

enum PredictionKind: string
{
    use HasValues;

    case DirectionalBias = 'directional_bias';
    case TradeSetup = 'trade_setup';
}
