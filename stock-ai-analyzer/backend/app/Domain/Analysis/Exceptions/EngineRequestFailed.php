<?php

declare(strict_types=1);

namespace App\Domain\Analysis\Exceptions;

use RuntimeException;
use Throwable;

/**
 * The engine answered with an error envelope, or the client is misconfigured.
 */
final class EngineRequestFailed extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
