<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Domain\Analysis\Contracts\AnalysisEngine;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Readiness of every dependency the app needs: PostgreSQL, Redis and the AI engine (which in turn
 * reports Ollama and model availability).
 */
final readonly class SystemHealthCheck
{
    public function __construct(private AnalysisEngine $engine) {}

    /**
     * @return array{status: 'ok'|'degraded', checks: array<string, array<string, mixed>>}
     */
    public function run(): array
    {
        $checks = [
            'database' => $this->probe('database', static fn () => DB::select('select 1')),
            'redis' => $this->probe('redis', static fn () => Redis::connection()->ping()),
            'engine' => $this->engine(),
        ];

        $healthy = array_all($checks, static fn (array $check): bool => $check['status'] === 'ok');

        return ['status' => $healthy ? 'ok' : 'degraded', 'checks' => $checks];
    }

    /**
     * @return array<string, mixed>
     */
    private function probe(string $name, Closure $probe): array
    {
        $started = hrtime(true);

        try {
            $probe();
        } catch (Throwable $e) {
            Log::warning("health.{$name}.down", ['error' => $e->getMessage()]);

            return ['status' => 'down', 'error' => $this->exposeErrors() ? $e->getMessage() : null];
        }

        return ['status' => 'ok', 'latency_ms' => round((hrtime(true) - $started) / 1e6, 1)];
    }

    /**
     * @return array<string, mixed>
     */
    private function engine(): array
    {
        $health = $this->engine->health();

        return [
            'status' => $health->status,
            'version' => $health->engineVersion,
            'model_profile' => $health->modelProfile,
            'ollama' => $health->ollama,
            'models' => $health->models,
            'error' => $this->exposeErrors() ? $health->error : null,
        ];
    }

    private function exposeErrors(): bool
    {
        return (bool) config('app.debug');
    }
}
