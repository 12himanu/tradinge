<?php

declare(strict_types=1);

namespace App\Application\Jobs;

use App\Application\Services\AnalysisProgress;
use App\Application\Services\EngineRequestBuilder;
use App\Application\Services\PersistAnalysisResult;
use App\Domain\Analysis\Contracts\AnalysisEngine;
use App\Domain\Analysis\Enums\AnalysisStatus;
use App\Domain\Analysis\Exceptions\EngineBusy;
use App\Domain\Analysis\Exceptions\EngineRequestFailed;
use App\Domain\Analysis\Exceptions\EngineStreamTimedOut;
use App\Domain\Analysis\Exceptions\EngineUnavailable;
use App\Domain\Analysis\Models\Analysis;
use App\Domain\Analysis\ValueObjects\EngineEvent;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Run one analysis through the engine and persist it (P4D-04).
 *
 * Failure is expected often enough to be designed for: the engine may be down, busy, slow or
 * unable to read the chart. Every path ends with the analysis in a terminal state carrying an
 * error code the UI can explain — never stuck in `running`.
 */
final class AnalyzeChartJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 900;

    public int $backoff = 30;

    /** Keep the row consistent even if the worker is killed between the engine call and the write. */
    public bool $failOnTimeout = true;

    public function __construct(public readonly string $analysisId)
    {
        $this->onQueue('ai');
    }

    public function handle(
        AnalysisEngine $engine,
        EngineRequestBuilder $builder,
        PersistAnalysisResult $persist,
        AnalysisProgress $progress,
    ): void {
        $analysis = Analysis::query()->find($this->analysisId);
        if ($analysis === null) {
            return;
        }

        // A cancelled or already-finished analysis must not be re-run by a retry.
        if ($analysis->status->isTerminal()) {
            return;
        }

        Context::add('analysis_id', $analysis->id);

        $analysis->forceFill([
            'status' => AnalysisStatus::Running,
            'started_at' => CarbonImmutable::now(),
            'current_stage' => 'request',
        ])->save();
        $progress->publish($analysis->id, 'request', 0.0);

        $startedAt = microtime(true);

        try {
            $result = $engine->analyze(
                $builder->build($analysis),
                function (EngineEvent $event) use ($analysis, $progress): void {
                    if ($event->isStage()) {
                        $stage = $event->stage() ?? 'running';
                        $progress->publish($analysis->id, $stage, $event->progress() ?? 0.0);
                        $analysis->forceFill(['current_stage' => $stage])->saveQuietly();
                    }
                },
            );
        } catch (Throwable $e) {
            $this->fail($analysis, $e, $startedAt, $progress);

            // Retry the transient ones; a bad request or a rejected chart will fail the same way twice.
            if ($e instanceof EngineUnavailable || $e instanceof EngineBusy || $e instanceof EngineStreamTimedOut) {
                throw $e;
            }

            return;
        }

        $persist($analysis, $result, durationMs: (int) round((microtime(true) - $startedAt) * 1000));
        $progress->publish($analysis->id, 'done', 1.0);
    }

    /**
     * The last attempt gave up: make sure the row says so rather than sitting in `running`.
     */
    public function failed(?Throwable $exception): void
    {
        $analysis = Analysis::query()->find($this->analysisId);
        if ($analysis === null || $analysis->status->isTerminal()) {
            return;
        }

        $analysis->forceFill([
            'status' => AnalysisStatus::Failed,
            'error_code' => $this->errorCode($exception),
            'error_message' => $exception?->getMessage() ?? 'The analysis job failed.',
            'completed_at' => CarbonImmutable::now(),
            'current_stage' => null,
        ])->save();
    }

    private function fail(Analysis $analysis, Throwable $e, float $startedAt, AnalysisProgress $progress): void
    {
        $code = $this->errorCode($e);

        Log::warning('analysis.failed', [
            'analysis_id' => $analysis->id,
            'error_code' => $code,
            'message' => $e->getMessage(),
            'attempt' => $this->attempts(),
        ]);

        $analysis->forceFill([
            'status' => AnalysisStatus::Failed,
            'error_code' => $code,
            'error_message' => $e->getMessage(),
            'completed_at' => CarbonImmutable::now(),
            'current_stage' => null,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ])->save();

        $progress->publish($analysis->id, 'failed', 1.0);
    }

    private function errorCode(?Throwable $e): string
    {
        return match (true) {
            $e instanceof EngineRequestFailed => $e->errorCode,
            $e instanceof EngineUnavailable => 'ENGINE_UNAVAILABLE',
            $e instanceof EngineBusy => 'ENGINE_BUSY',
            $e instanceof EngineStreamTimedOut => 'ENGINE_TIMEOUT',
            default => 'ANALYSIS_FAILED',
        };
    }
}
