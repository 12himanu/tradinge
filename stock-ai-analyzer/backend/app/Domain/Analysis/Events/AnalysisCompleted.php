<?php

declare(strict_types=1);

namespace App\Domain\Analysis\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * An analysis reached a terminal state with a result persisted. Phase 6 listens to this to
 * refresh stats; the UI polls instead, so nothing here is on the critical path.
 */
final class AnalysisCompleted
{
    use Dispatchable;

    public function __construct(public readonly string $analysisId) {}
}
