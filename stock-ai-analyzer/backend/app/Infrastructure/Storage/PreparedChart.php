<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

/**
 * A validated screenshot, re-encoded to PNG and hashed, ready to be written to the charts disk.
 */
final readonly class PreparedChart
{
    public function __construct(
        public string $contents,
        public string $sha256,
        public int $width,
        public int $height,
        public string $mimeType = 'image/png',
    ) {}

    public function sizeBytes(): int
    {
        return strlen($this->contents);
    }
}
