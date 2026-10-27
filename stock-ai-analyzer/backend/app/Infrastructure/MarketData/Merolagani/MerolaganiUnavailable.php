<?php

declare(strict_types=1);

namespace App\Infrastructure\MarketData\Merolagani;

use RuntimeException;

/**
 * Merolagani could not be reached, or answered with something that is not the documented shape.
 * Always a transient, retryable condition from the app's point of view: nothing is imported and
 * the next scheduled run tries again.
 */
final class MerolaganiUnavailable extends RuntimeException
{
    public static function transport(string $endpoint, string $reason): self
    {
        return new self("Merolagani {$endpoint} request failed: {$reason}");
    }

    public static function status(string $endpoint, int $status): self
    {
        return new self("Merolagani {$endpoint} returned HTTP {$status}.");
    }

    public static function malformed(string $endpoint, string $detail): self
    {
        return new self("Merolagani {$endpoint} returned an unexpected payload: {$detail}");
    }

    public static function upstream(string $endpoint, string $message): self
    {
        return new self("Merolagani {$endpoint} reported an error: {$message}");
    }
}
