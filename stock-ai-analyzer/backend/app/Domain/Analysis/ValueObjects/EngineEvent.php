<?php

declare(strict_types=1);

namespace App\Domain\Analysis\ValueObjects;

/**
 * One decoded line from the engine's NDJSON stream (docs/04 §2; ai_engine/api/analyze.py).
 */
final readonly class EngineEvent
{
    private function __construct(
        public string $type,
        /** @var array<string, mixed> */
        public array $payload,
    ) {}

    /**
     * @param  array<string, mixed>  $decoded
     */
    public static function fromArray(array $decoded): self
    {
        $type = is_string($decoded['event'] ?? null) ? $decoded['event'] : 'unknown';

        return new self($type, $decoded);
    }

    public function isStage(): bool
    {
        return $this->type === 'stage';
    }

    public function isResult(): bool
    {
        return $this->type === 'result';
    }

    public function isError(): bool
    {
        return $this->type === 'error';
    }

    public function stage(): ?string
    {
        return is_string($this->payload['stage'] ?? null) ? $this->payload['stage'] : null;
    }

    public function progress(): ?float
    {
        $progress = $this->payload['progress'] ?? null;

        return is_int($progress) || is_float($progress) ? (float) $progress : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        $data = $this->payload['data'] ?? null;

        return is_array($data) ? $data : [];
    }

    public function errorCode(): string
    {
        $error = $this->payload['error'] ?? null;

        return is_array($error) && is_string($error['code'] ?? null) ? $error['code'] : 'ENGINE_ERROR';
    }

    public function errorMessage(): string
    {
        $error = $this->payload['error'] ?? null;

        return is_array($error) && is_string($error['message'] ?? null)
            ? $error['message']
            : 'The engine reported an error without a message.';
    }
}
