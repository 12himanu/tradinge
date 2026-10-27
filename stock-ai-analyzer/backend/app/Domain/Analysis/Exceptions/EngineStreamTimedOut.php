<?php

declare(strict_types=1);

namespace App\Domain\Analysis\Exceptions;

use RuntimeException;

/**
 * The engine accepted the run but stopped sending events. Retryable, like EngineUnavailable.
 */
final class EngineStreamTimedOut extends RuntimeException {}
