<?php

declare(strict_types=1);

namespace App\Application\Jobs;

use App\Application\Services\Stats\CalibrationService;
use App\Domain\Identity\Models\User;
use App\Domain\Market\Models\PriceBar;
use App\Domain\Prediction\Enums\PredictionStatus;
use App\Domain\Prediction\Models\Outcome;
use App\Domain\Prediction\Models\Prediction;
use App\Domain\Prediction\Services\PredictionEvaluator;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Score every open prediction against the bars that have printed since (P6-03).
 *
 * Each run re-evaluates from `as_of` rather than from a saved cursor. The evaluator is pure and
 * the bar window is at most one horizon, so this is cheap — and it means a corrected or late-
 * imported bar changes the verdict, instead of being locked out by a marker. An `outcomes` row is
 * only written when the prediction actually resolves; until then the prediction just carries its
 * status and `activated_at`.
 */
final class EvaluatePredictionsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public readonly ?string $predictionId = null)
    {
        $this->onQueue('evaluation');
    }

    public function handle(PredictionEvaluator $evaluator, CalibrationService $calibration): void
    {
        $open = Prediction::query()
            ->whereIn('status', [PredictionStatus::Pending->value, PredictionStatus::Active->value])
            ->when($this->predictionId !== null, fn ($query) => $query->where('id', $this->predictionId))
            ->whereNotNull('stock_id')
            ->with(['outcome', 'user'])
            ->orderBy('as_of')
            ->get();

        $resolved = 0;
        /** @var array<string, array{user: User, engine_version: string}> $touched */
        $touched = [];

        foreach ($open as $prediction) {
            /** @var Collection<int, PriceBar> $bars */
            $bars = PriceBar::query()
                ->where('stock_id', $prediction->stock_id)
                ->where('timeframe', $prediction->timeframe->value)
                ->where('bar_time', '>', $prediction->as_of)
                ->orderBy('bar_time')
                ->get();

            if ($bars->isEmpty()) {
                continue;
            }

            $result = $evaluator->evaluate($prediction, $bars);

            DB::transaction(function () use ($prediction, $result, &$resolved, &$touched): void {
                if ($result->isResolved()) {
                    Outcome::query()->updateOrCreate(
                        ['prediction_id' => $prediction->id],
                        $result->toOutcomeAttributes(),
                    );
                    $resolved++;

                    $user = $prediction->loadMissing('user')->user;
                    if ($user !== null) {
                        $touched[$user->id.'|'.$prediction->engine_version] = [
                            'user' => $user,
                            'engine_version' => $prediction->engine_version,
                        ];
                    }
                }

                $prediction->forceFill(array_filter([
                    'status' => $result->status,
                    'activated_at' => $result->activatedAt,
                    'resolved_at' => $result->isResolved() ? $result->resolvedAt : null,
                ], static fn (mixed $value): bool => $value !== null))->save();
            });
        }

        // The curve only moves when an outcome lands, so this is the one moment worth recording it.
        // The snapshot is history, not a cache: `GET /stats/calibration` always recomputes.
        foreach ($touched as $target) {
            $calibration->snapshot($target['user'], $target['engine_version']);
        }

        if ($open->isNotEmpty()) {
            Log::info('predictions.evaluated', [
                'considered' => $open->count(),
                'resolved' => $resolved,
                'at' => CarbonImmutable::now()->toIso8601String(),
            ]);
        }
    }
}
