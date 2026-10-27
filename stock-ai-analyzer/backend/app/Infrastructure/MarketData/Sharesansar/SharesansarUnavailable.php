<?php

declare(strict_types=1);

namespace App\Infrastructure\MarketData\Sharesansar;

use RuntimeException;

/**
 * Sharesansar could not be reached, or answered with something that is not the documented shape.
 * Transient from the app's point of view: nothing is imported and the next run tries again.
 */
final class SharesansarUnavailable extends RuntimeException
{
    public static function transport(string $endpoint, string $reason): self
    {
        return new self("Sharesansar {$endpoint} request failed: {$reason}");
    }

    public static function status(string $endpoint, int $status): self
    {
        return new self("Sharesansar {$endpoint} returned HTTP {$status}.");
    }

    public static function malformed(string $endpoint, string $detail): self
    {
        return new self("Sharesansar {$endpoint} returned an unexpected payload: {$detail}");
    }

    public static function noSession(string $detail): self
    {
        return new self("Sharesansar session could not be established: {$detail}");
    }
}
