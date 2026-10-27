<?php

declare(strict_types=1);

namespace App\Domain\Analysis\Exceptions;

use RuntimeException;

/**
 * Every analysis slot on the engine is occupied (HTTP 503). The job should back off and retry.
 */
final class EngineBusy extends RuntimeException
{
    public function __construct(string $message, public readonly int $retryAfterSeconds = 30)
    {
        parent::__construct($message);
    }
}
