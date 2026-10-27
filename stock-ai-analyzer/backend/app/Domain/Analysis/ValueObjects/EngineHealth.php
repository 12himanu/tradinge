<?php

declare(strict_types=1);

namespace App\Domain\Analysis\ValueObjects;

final readonly class EngineHealth
{
    /**
     * @param  'ok'|'degraded'|'unreachable'  $status
     * @param  array<string, mixed>|null  $ollama
     * @param  list<array<string, mixed>>  $models
     */
    public function __construct(
        public string $status,
        public ?string $engineVersion,
        public ?string $modelProfile,
        public ?array $ollama,
        public array $models,
        public ?string $error,
    ) {}

    public static function unreachable(string $error): self
    {
        return new self('unreachable', null, null, null, [], $error);
    }

    public function isOk(): bool
    {
        return $this->status === 'ok';
    }
}
