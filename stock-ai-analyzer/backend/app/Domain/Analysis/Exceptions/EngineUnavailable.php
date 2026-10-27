<?php

declare(strict_types=1);

namespace App\Domain\Analysis\Exceptions;

use RuntimeException;

/**
 * The engine could not be reached (not running, network). Retryable (docs/02-architecture.md §7.1).
 */
final class EngineUnavailable extends RuntimeException {}
