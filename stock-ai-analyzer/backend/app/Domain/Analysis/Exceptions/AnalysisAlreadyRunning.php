<?php

declare(strict_types=1);

namespace App\Domain\Analysis\Exceptions;

use App\Domain\Analysis\Models\Analysis;
use RuntimeException;

/**
 * An analysis for this chart is queued or running. Surfaced as HTTP 409 with the existing id,
 * so the caller can follow that run instead of starting a second one.
 */
final class AnalysisAlreadyRunning extends RuntimeException
{
    public function __construct(public readonly Analysis $analysis)
    {
        parent::__construct("An analysis for this chart is already {$analysis->status->value}.");
    }
}
