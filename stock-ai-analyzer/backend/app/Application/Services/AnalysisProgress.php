<?php

declare(strict_types=1);

namespace App\Application\Services;

use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Live stage progress for `GET /analysis/{id}/status` (P4D-04).
 *
 * Progress is cache state, not database state: it changes several times a second during a run and
 * is worthless once the run ends, so it lives in Redis with a short TTL. The database still holds
 * `current_stage` for anyone reading the row directly.
 */
final readonly class AnalysisProgress
{
    public const int TTL_SECONDS = 1800;

    public function __construct(private Cache $cache) {}

    public function publish(string $analysisId, string $stage, float $progress): void
    {
        $this->cache->put(
            $this->key($analysisId),
            [
                'stage' => $stage,
                'progress' => round(max(0.0, min(1.0, $progress)), 3),
                'at' => microtime(true),
            ],
            self::TTL_SECONDS,
        );
    }

    /**
     * @return array{stage: string, progress: float, at: float}|null
     */
    public function read(string $analysisId): ?array
    {
        $value = $this->cache->get($this->key($analysisId));
        if (! is_array($value) || ! is_string($value['stage'] ?? null)) {
            return null;
        }

        return [
            'stage' => $value['stage'],
            'progress' => is_numeric($value['progress'] ?? null) ? (float) $value['progress'] : 0.0,
            'at' => is_numeric($value['at'] ?? null) ? (float) $value['at'] : 0.0,
        ];
    }

    public function forget(string $analysisId): void
    {
        $this->cache->forget($this->key($analysisId));
    }

    private function key(string $analysisId): string
    {
        return "analysis:progress:{$analysisId}";
    }
}
