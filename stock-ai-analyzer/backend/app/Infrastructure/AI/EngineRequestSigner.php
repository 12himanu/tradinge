<?php

declare(strict_types=1);

namespace App\Infrastructure\AI;

use InvalidArgumentException;
use SensitiveParameter;

/**
 * Signs engine requests: X-Signature = hex(HMAC-SHA256(secret, timestamp + "." + body)).
 * Must match ai-engine/ai_engine/api/security.py (both suites test the same vector).
 */
final readonly class EngineRequestSigner
{
    private const int MIN_SECRET_LENGTH = 32;

    public function __construct(#[SensitiveParameter] private string $secret)
    {
        if (strlen($secret) < self::MIN_SECRET_LENGTH) {
            throw new InvalidArgumentException('ENGINE_HMAC_SECRET must be at least 32 characters.');
        }
    }

    public function sign(string $timestamp, string $body): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$body, $this->secret);
    }

    /**
     * @return array{X-Timestamp: string, X-Signature: string}
     */
    public function headers(string $body, ?int $now = null): array
    {
        $timestamp = (string) ($now ?? time());

        return ['X-Timestamp' => $timestamp, 'X-Signature' => $this->sign($timestamp, $body)];
    }
}
