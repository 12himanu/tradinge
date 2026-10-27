<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controllers\Api\V1;

use App\Application\Actions\RequestAnalysis;
use App\Application\Services\AnalysisProgress;
use App\Domain\Analysis\Enums\AnalysisStatus;
use App\Domain\Analysis\Exceptions\AnalysisAlreadyRunning;
use App\Domain\Analysis\Models\Analysis;
use App\Domain\Analysis\Models\AnalysisFeedback;
use App\Domain\Chart\Models\ChartUpload;
use App\Domain\Identity\Models\User;
use App\Domain\Market\Models\PriceBar;
use App\Presentation\Http\Requests\AnalysisFeedbackRequest;
use App\Presentation\Http\Requests\RequestAnalysisRequest;
use App\Presentation\Http\Resources\AnalysisResource;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class AnalysisController
{
    /** A horizon is at most ~60 bars; this is a generous ceiling on the path we draw. */
    private const int MAX_PATH_BARS = 120;

    /**
     * Queue an analysis for a chart (P4D-07). Returns 202 for a new run, 200 when an identical
     * completed run is reused, and 409 when one is already in flight.
     */
    public function store(
        RequestAnalysisRequest $request,
        ChartUpload $chartUpload,
        RequestAnalysis $requestAnalysis,
    ): JsonResponse {
        Gate::authorize('view', $chartUpload);

        /** @var User $user */
        $user = $request->user();

        try {
            $analysis = $requestAnalysis($user, $chartUpload, $request->toData($chartUpload));
        } catch (AnalysisAlreadyRunning $e) {
            throw new ConflictHttpException(
                $e->getMessage().' Follow analysis '.$e->analysis->id.' instead.',
                $e,
            );
        }

        $reused = $analysis->status->isTerminal();

        return (new AnalysisResource($this->loadDetail($analysis)))
            ->response()
            ->setStatusCode($reused ? 200 : 202);
    }

    public function show(Analysis $analysis): AnalysisResource
    {
        Gate::authorize('view', $analysis);

        return new AnalysisResource($this->loadDetail($analysis));
    }

    /**
     * Lightweight polling target: status, stage and progress, without the report payload.
     */
    public function status(Analysis $analysis, AnalysisProgress $progress): JsonResponse
    {
        Gate::authorize('view', $analysis);

        $live = $progress->read($analysis->id);

        return new JsonResponse([
            'data' => [
                'id' => $analysis->id,
                'status' => $analysis->status->value,
                'stage' => $live['stage'] ?? $analysis->current_stage,
                'progress' => $live['progress'] ?? ($analysis->status->isTerminal() ? 1.0 : 0.0),
                'terminal' => $analysis->status->isTerminal(),
                'error_code' => $analysis->error_code,
                'error_message' => $analysis->error_message,
                'duration_ms' => $analysis->duration_ms,
            ],
        ]);
    }

    /**
     * Cancel a queued or running analysis. The engine stream stops when the job notices; the row
     * becomes terminal immediately so the UI and the watchdog agree on what happened.
     */
    public function cancel(Analysis $analysis, AnalysisProgress $progress): AnalysisResource
    {
        Gate::authorize('update', $analysis);

        if (! $analysis->status->isTerminal()) {
            $analysis->forceFill([
                'status' => AnalysisStatus::Cancelled,
                'current_stage' => null,
                'completed_at' => CarbonImmutable::now(),
            ])->save();
            $progress->forget($analysis->id);
        }

        return new AnalysisResource($this->loadDetail($analysis));
    }

    public function feedback(AnalysisFeedbackRequest $request, Analysis $analysis): JsonResponse
    {
        Gate::authorize('view', $analysis);

        /** @var User $user */
        $user = $request->user();

        $feedback = AnalysisFeedback::query()->updateOrCreate(
            ['analysis_id' => $analysis->id, 'user_id' => $user->id],
            $request->validatedAttributes(),
        );

        return new JsonResponse(['data' => [
            'id' => $feedback->id,
            'rating' => $feedback->rating,
            'issue' => $feedback->issue_type,
            'comment' => $feedback->comment,
        ]], 201);
    }

    /**
     * Analysis history for the signed-in user (docs/05-api-spec.md `GET /history`).
     */
    public function history(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        $analyses = Analysis::query()
            ->whereBelongsTo($user)
            ->with('stock')
            ->when($request->string('decision')->isNotEmpty(), fn ($query) => $query->where('decision', $request->string('decision')))
            ->when($request->string('bias')->isNotEmpty(), fn ($query) => $query->where('bias', $request->string('bias')))
            ->when($request->string('tier')->isNotEmpty(), fn ($query) => $query->where('data_tier', $request->string('tier')))
            ->when($request->string('status')->isNotEmpty(), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->integer('stock_id') > 0, fn ($query) => $query->where('stock_id', $request->integer('stock_id')))
            ->latest()
            ->paginate(min(100, max(1, $request->integer('per_page', 20))));

        return AnalysisResource::summaries($analyses);
    }

    /**
     * The price path since `as_of`, for the AI-vs-actual panel (P6-06).
     *
     * Only bars after the analysis date are returned: the panel exists to show what happened
     * *next*, and sending earlier bars would let the chart imply the engine saw them.
     */
    public function pricePath(Analysis $analysis): JsonResponse
    {
        Gate::authorize('view', $analysis);

        $bars = [];
        if ($analysis->stock_id !== null && $analysis->as_of !== null) {
            $bars = PriceBar::query()
                ->where('stock_id', $analysis->stock_id)
                ->where('timeframe', $analysis->timeframe->value)
                ->where('bar_time', '>', $analysis->as_of)
                ->orderBy('bar_time')
                ->limit(self::MAX_PATH_BARS)
                ->get()
                ->map(fn (PriceBar $bar): array => [
                    'date' => $bar->bar_time->toIso8601ZuluString(),
                    'open' => (float) $bar->open,
                    'high' => (float) $bar->high,
                    'low' => (float) $bar->low,
                    'close' => (float) $bar->close,
                ])
                ->all();
        }

        return new JsonResponse(['data' => [
            'as_of' => $analysis->as_of?->toIso8601ZuluString(),
            'reference_price' => is_numeric($analysis->last_price) ? (float) $analysis->last_price : null,
            'bars' => $bars,
        ]]);
    }

    private function loadDetail(Analysis $analysis): Analysis
    {
        return $analysis->load([
            'stock', 'indicators', 'patterns', 'levels', 'predictions', 'predictions.outcome',
        ]);
    }
}
