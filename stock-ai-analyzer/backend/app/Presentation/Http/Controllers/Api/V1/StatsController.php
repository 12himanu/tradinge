<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controllers\Api\V1;

use App\Application\Services\Stats\CalibrationService;
use App\Application\Services\Stats\JournalAnalyticsService;
use App\Application\Services\Stats\PerformanceStatsService;
use App\Domain\Identity\Models\User;
use App\Domain\Prediction\Models\Prediction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Performance and journal analytics (docs/05-api-spec.md §3.6).
 *
 * Everything here is computed from recorded outcomes on request. There is no cached "score" to
 * drift out of date, and nothing is estimated when the sample is empty: the response says `null`
 * with an `n` of zero rather than inventing a rate.
 */
final class StatsController
{
    public function performance(Request $request, PerformanceStatsService $stats): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return new JsonResponse([
            'data' => $stats->build(
                $user,
                $request->string('group_by')->toString() ?: 'overall',
                [
                    'engine_version' => $request->string('engine_version')->value() ?: null,
                    'style' => $request->string('style')->value() ?: null,
                    'tier' => $request->string('tier')->value() ?: null,
                    'from' => $request->string('from')->value() ?: null,
                    'to' => $request->string('to')->value() ?: null,
                ],
            ),
        ]);
    }

    /**
     * The score → hit-rate curve (P6-10).
     *
     * `engine_version` is not optional in spirit: pooling versions compares two different scoring
     * systems. It defaults to the newest version the user has predictions for rather than to "all".
     */
    public function calibration(Request $request, CalibrationService $calibration): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $engineVersion = $request->string('engine_version')->value() ?: $this->latestEngineVersion($user);
        $scope = $request->string('scope')->value() ?: CalibrationService::GLOBAL_SCOPE;

        return new JsonResponse([
            'data' => $calibration->curve($user, $engineVersion, $scope),
        ]);
    }

    private function latestEngineVersion(User $user): ?string
    {
        return Prediction::query()
            ->whereBelongsTo($user)
            ->orderByDesc('created_at')
            ->value('engine_version');
    }

    public function journal(Request $request, JournalAnalyticsService $analytics): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return new JsonResponse(['data' => $analytics->build($user)]);
    }
}
