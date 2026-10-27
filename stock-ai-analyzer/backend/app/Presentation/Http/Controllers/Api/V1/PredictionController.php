<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controllers\Api\V1;

use App\Domain\Identity\Models\User;
use App\Domain\Prediction\Enums\PredictionStatus;
use App\Domain\Prediction\Models\Prediction;
use App\Presentation\Http\Resources\PredictionResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Recorded predictions (docs/05-api-spec.md §3.4).
 *
 * Predictions are immutable once written: the only thing that changes is their status, and only
 * the Phase 6 evaluator or an explicit cancel does that. Nothing here edits one.
 */
final class PredictionController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        $predictions = Prediction::query()
            ->whereBelongsTo($user)
            ->with('stock')
            ->when(
                $request->string('status')->isNotEmpty(),
                fn ($query) => $query->where('status', $request->string('status')),
                // Open predictions are what a trader watches; resolved ones are history.
                fn ($query) => $query->whereIn('status', [
                    PredictionStatus::Pending->value,
                    PredictionStatus::Active->value,
                ]),
            )
            ->when(
                $request->integer('stock_id') > 0,
                fn ($query) => $query->where('stock_id', $request->integer('stock_id'))
            )
            ->orderBy('expires_at')
            ->paginate(min(100, max(1, $request->integer('per_page', 25))));

        return PredictionResource::collection($predictions);
    }

    public function show(Prediction $prediction): PredictionResource
    {
        Gate::authorize('view', $prediction);

        return new PredictionResource($prediction->load('stock'));
    }

    /**
     * Cancel a prediction you no longer stand behind. It is not deleted: outcome statistics would
     * flatter themselves if unwanted predictions could disappear.
     */
    public function cancel(Prediction $prediction): PredictionResource
    {
        Gate::authorize('update', $prediction);

        if (in_array($prediction->status, [PredictionStatus::Pending, PredictionStatus::Active], true)) {
            $prediction->forceFill([
                'status' => PredictionStatus::Cancelled,
                'resolved_at' => now(),
            ])->save();
        }

        return new PredictionResource($prediction->load('stock'));
    }
}
