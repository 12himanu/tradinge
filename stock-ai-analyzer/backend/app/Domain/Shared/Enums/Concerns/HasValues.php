<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums\Concerns;

trait HasValues
{
    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
