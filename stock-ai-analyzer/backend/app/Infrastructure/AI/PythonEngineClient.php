<?php

declare(strict_types=1);

namespace App\Infrastructure\AI;

use App\Domain\Analysis\Contracts\AnalysisEngine;
use App\Domain\Analysis\Exceptions\EngineBusy;
use App\Domain\Analysis\Exceptions\EngineRequestFailed;
use App\Domain\Analysis\Exceptions\EngineUnavailable;
use App\Domain\Analysis\ValueObjects\EngineEvent;
use App\Domain\Analysis\ValueObjects\EngineHealth;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

final readonly class PythonEngineClient implements AnalysisEngine
{
    private const string NDJSON = 'application/x-ndjson';

    public function __construct(
        private HttpFactory $http,
        private string $baseUrl,
        private ?EngineRequestSigner $signer,
        private NdjsonStreamReader $reader = new NdjsonStreamReader,
        private int $connectTimeout = 3,
        private int $healthTimeout = 5,
        private int $requestTimeout = 30,
        private int $analyzeTimeout = 900,
    ) {}

    public function health(): EngineHealth
    {
        try {
            $response = $this->request()->timeout($this->healthTimeout)->get('/health');
        } catch (ConnectionException $e) {
            return EngineHealth::unreachable($e->getMessage());
        }

        if (! $response->successful()) {
            return EngineHealth::unreachable("Engine returned HTTP {$response->status()}.");
        }

        $body = $this->jsonObject($response);
        $ollama = $body['ollama'] ?? null;
        $models = $body['models'] ?? [];

        return new EngineHealth(
            status: ($body['status'] ?? null) === 'ok' ? 'ok' : 'degraded',
            engineVersion: is_string($body['engine_version'] ?? null) ? $body['engine_version'] : null,
            modelProfile: is_string($body['model_profile'] ?? null) ? $body['model_profile'] : null,
            ollama: is_array($ollama) ? $ollama : null,
            models: is_array($models) ? array_values(array_filter($models, 'is_array')) : [],
            error: null,
        );
    }

    public function models(): array
    {
        return $this->signedGet('/v1/models');
    }

    public function analyze(array $request, ?Closure $onEvent = null): array
    {
        if ($this->signer === null) {
            throw new EngineRequestFailed('ENGINE_NOT_CONFIGURED', 'ENGINE_HMAC_SECRET is not set.');
        }

        $body = json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        try {
            $response = $this->request()
                ->timeout($this->analyzeTimeout)
                ->withHeaders([
                    ...$this->signer->headers($body),
                    'Content-Type' => 'application/json',
                    'Accept' => self::NDJSON,
                ])
                ->withOptions(['stream' => true])
                ->send('POST', '/v1/analyze', ['body' => $body]);
        } catch (ConnectionException $e) {
            throw new EngineUnavailable("Cannot reach the analysis engine at {$this->baseUrl}.", previous: $e);
        }

        if ($response->status() === 503) {
            $details = $response->json('error.details');
            $retryAfter = is_array($details) && is_int($details['retry_after_seconds'] ?? null)
                ? $details['retry_after_seconds']
                : 30;

            throw new EngineBusy($this->errorMessage($response), $retryAfter);
        }

        if ($response->failed()) {
            throw new EngineRequestFailed(
                $this->errorCode($response),
                $this->errorMessage($response),
                $response->status(),
            );
        }

        return $this->consume($response, $onEvent);
    }

    /**
     * Drain the NDJSON stream, forwarding every event and keeping the result.
     *
     * @param  Closure(EngineEvent): void|null  $onEvent
     * @return array<string, mixed>
     */
    private function consume(Response $response, ?Closure $onEvent): array
    {
        $result = null;

        foreach ($this->reader->read($response->toPsrResponse()->getBody()) as $decoded) {
            $event = EngineEvent::fromArray($decoded);

            if ($onEvent !== null) {
                $onEvent($event);
            }

            if ($event->isError()) {
                throw new EngineRequestFailed($event->errorCode(), $event->errorMessage(), 200);
            }

            if ($event->isResult()) {
                $result = $event->data();
            }
        }

        if ($result === null) {
            throw new EngineRequestFailed(
                'ENGINE_NO_RESULT',
                'The engine closed the stream without sending a result.',
                200,
            );
        }

        return $result;
    }

    private function errorCode(Response $response): string
    {
        $code = $response->json('error.code');

        return is_string($code) ? $code : 'ENGINE_ERROR';
    }

    private function errorMessage(Response $response): string
    {
        $message = $response->json('error.message');

        return is_string($message) ? $message : "Engine returned HTTP {$response->status()}.";
    }

    /**
     * @return array<string, mixed>
     */
    private function signedGet(string $path): array
    {
        if ($this->signer === null) {
            throw new EngineRequestFailed('ENGINE_NOT_CONFIGURED', 'ENGINE_HMAC_SECRET is not set.');
        }

        try {
            $response = $this->request()
                ->timeout($this->requestTimeout)
                ->withHeaders($this->signer->headers(''))
                ->get($path);
        } catch (ConnectionException $e) {
            throw new EngineUnavailable("Cannot reach the analysis engine at {$this->baseUrl}.", previous: $e);
        }

        if ($response->failed()) {
            $error = $response->json('error');
            $code = is_array($error) && is_string($error['code'] ?? null) ? $error['code'] : 'ENGINE_ERROR';
            $message = is_array($error) && is_string($error['message'] ?? null)
                ? $error['message']
                : "Engine returned HTTP {$response->status()}.";

            throw new EngineRequestFailed($code, $message, $response->status());
        }

        return $this->jsonObject($response);
    }

    private function request(): PendingRequest
    {
        $requestId = Context::get('request_id');

        return $this->http
            ->baseUrl($this->baseUrl)
            ->acceptJson()
            ->connectTimeout($this->connectTimeout)
            ->withHeaders(['X-Request-Id' => is_string($requestId) ? $requestId : (string) Str::ulid()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonObject(Response $response): array
    {
        $body = $response->json();

        return is_array($body) ? $body : [];
    }
}
