<?php

declare(strict_types=1);

namespace App\Domain\Analysis\Contracts;

use App\Domain\Analysis\Exceptions\EngineBusy;
use App\Domain\Analysis\Exceptions\EngineRequestFailed;
use App\Domain\Analysis\Exceptions\EngineStreamTimedOut;
use App\Domain\Analysis\Exceptions\EngineUnavailable;
use App\Domain\Analysis\ValueObjects\EngineEvent;
use App\Domain\Analysis\ValueObjects\EngineHealth;
use Closure;

/**
 * The analysis engine boundary, implemented by the Python FastAPI service client.
 */
interface AnalysisEngine
{
    /**
     * Never throws: an unreachable engine is reported as a health status.
     */
    public function health(): EngineHealth;

    /**
     * Active model profile and install status (signed request).
     *
     * @return array<string, mixed>
     *
     * @throws EngineUnavailable
     * @throws EngineRequestFailed
     */
    public function models(): array;

    /**
     * Run one analysis, streaming progress.
     *
     * `$onEvent` is called for every event as it arrives (stage progress included), so the caller
     * can publish progress without waiting for the run to finish. The returned array is the
     * engine's `AnalysisResult`.
     *
     * @param  array<string, mixed>  $request  an `AnalysisRequest` (ai-engine/contracts/analysis_request.schema.json)
     * @param  Closure(EngineEvent): void|null  $onEvent
     * @return array<string, mixed>
     *
     * @throws EngineUnavailable the engine could not be reached
     * @throws EngineBusy every analysis slot is occupied
     * @throws EngineStreamTimedOut accepted, then went quiet
     * @throws EngineRequestFailed the engine reported an error event or a non-2xx status
     */
    public function analyze(array $request, ?Closure $onEvent = null): array;
}
