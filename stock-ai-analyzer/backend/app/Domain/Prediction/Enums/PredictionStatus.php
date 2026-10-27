<?php

declare(strict_types=1);

namespace App\Domain\Prediction\Enums;

use App\Domain\Shared\Enums\Concerns\HasValues;

enum PredictionStatus: string
{
    use HasValues;

    case Pending = 'pending';
    case Active = 'active';
    case TargetHit = 'target_hit';
    case Stopped = 'stopped';
    case Expired = 'expired';
    case Invalidated = 'invalidated';
    case Cancelled = 'cancelled';

    public function isOpen(): bool
    {
        return $this === self::Pending || $this === self::Active;
    }
}
