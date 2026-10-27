<?php

declare(strict_types=1);

namespace App\Infrastructure\AI;

use App\Domain\Analysis\Exceptions\EngineStreamTimedOut;
use Generator;
use JsonException;
use Psr\Http\Message\StreamInterface;

/**
 * Reads an NDJSON response body one object per line, as the engine flushes it (P4D-02).
 *
 * The engine may go quiet for a long time inside one stage, so the caller gets two limits: a
 * per-read timeout measured from the last decoded line, and an overall deadline for the run.
 * Both are checked between chunk reads, so a socket that blocks forever is ultimately bounded by
 * the HTTP client's own timeout.
 */
final readonly class NdjsonStreamReader
{
    public const int CHUNK_BYTES = 8192;

    public function __construct(
        private int $perReadTimeout = 120,
        private int $overallTimeout = 900,
        private int $maxLineBytes = 16 * 1024 * 1024,
    ) {}

    /**
     * @return Generator<int, array<string, mixed>>
     *
     * @throws EngineStreamTimedOut
     * @throws JsonException when a line is not valid JSON
     */
    public function read(StreamInterface $stream): Generator
    {
        $buffer = '';
        $startedAt = microtime(true);
        $lastLineAt = $startedAt;

        while (! $stream->eof()) {
            $chunk = $stream->read(self::CHUNK_BYTES);
            $now = microtime(true);

            if ($chunk === '') {
                $this->guardTimeouts($now, $startedAt, $lastLineAt);

                continue;
            }

            $buffer .= $chunk;
            if (strlen($buffer) > $this->maxLineBytes) {
                throw new EngineStreamTimedOut(
                    "A single engine event exceeded {$this->maxLineBytes} bytes; aborting the stream."
                );
            }

            while (($newline = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $newline);
                $buffer = substr($buffer, $newline + 1);

                $decoded = $this->decode($line);
                if ($decoded !== null) {
                    $lastLineAt = microtime(true);
                    yield $decoded;
                }
            }

            $this->guardTimeouts(microtime(true), $startedAt, $lastLineAt);
        }

        $decoded = $this->decode($buffer);
        if ($decoded !== null) {
            yield $decoded;
        }
    }

    /**
     * @return array<string, mixed>|null null for blank padding lines
     *
     * @throws JsonException
     */
    private function decode(string $line): ?array
    {
        $line = trim($line);
        if ($line === '') {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    private function guardTimeouts(float $now, float $startedAt, float $lastLineAt): void
    {
        if ($now - $lastLineAt > $this->perReadTimeout) {
            throw new EngineStreamTimedOut(
                "The engine sent nothing for {$this->perReadTimeout}s; treating the run as stalled."
            );
        }

        if ($now - $startedAt > $this->overallTimeout) {
            throw new EngineStreamTimedOut(
                "The analysis exceeded the {$this->overallTimeout}s budget."
            );
        }
    }
}
