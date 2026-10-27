<?php

declare(strict_types=1);

namespace App\Application\Jobs;

use App\Application\Services\AnalysisProgress;
use App\Domain\Analysis\Enums\AnalysisStatus;
use App\Domain\Analysis\Models\Analysis;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Fail analyses that stopped making progress (P4D-08).
 *
 * A worker killed mid-run, an OOM, or a container restart leaves a row saying `running` forever.
 * The UI would poll it until the user gave up. This sweeps them into a terminal state with an
 * error code, every five minutes.
 */
final class StuckAnalysisWatchdog implements ShouldQueue
{
    use Queueable;

    /** Comfortably above the engine's own analyze timeout, so only genuinely dead runs are caught. */
    public const int RUNNING_GRACE_MINUTES = 20;

    /** A job that never started: Horizon is down, or the `ai` queue has no worker. */
    public const int QUEUED_GRACE_MINUTES = 30;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(AnalysisProgress $progress): void
    {
        $now = CarbonImmutable::now();

        $stuck = Analysis::query()
            ->whereIn('status', [AnalysisStatus::Queued->value, AnalysisStatus::Running->value])
            ->where(function ($query) use ($now): void {
                $query
                    ->where(function ($running) use ($now): void {
                        $running->where('status', AnalysisStatus::Running->value)
                            ->where('started_at', '<', $now->subMinutes(self::RUNNING_GRACE_MINUTES));
                    })
                    ->orWhere(function ($queued) use ($now): void {
                        $queued->where('status', AnalysisStatus::Queued->value)
                            ->where('queued_at', '<', $now->subMinutes(self::QUEUED_GRACE_MINUTES));
                    });
            })
            ->get();

        foreach ($stuck as $analysis) {
            $wasQueued = $analysis->status === AnalysisStatus::Queued;

            $analysis->forceFill([
                'status' => AnalysisStatus::Failed,
                'error_code' => $wasQueued ? 'ANALYSIS_NEVER_STARTED' : 'ANALYSIS_STALLED',
                'error_message' => $wasQueued
                    ? 'The analysis was never picked up by a worker. Check that Horizon is running.'
                    : 'The analysis stopped responding and was timed out. Try running it again.',
                'completed_at' => $now,
                'current_stage' => null,
            ])->save();

            $progress->forget($analysis->id);

            Log::warning('analysis.watchdog.failed', [
                'analysis_id' => $analysis->id,
                'was' => $wasQueued ? 'queued' : 'running',
            ]);
        }
    }
}
