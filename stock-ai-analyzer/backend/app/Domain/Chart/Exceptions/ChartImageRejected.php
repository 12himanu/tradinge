<?php

declare(strict_types=1);

namespace App\Domain\Chart\Exceptions;

use RuntimeException;

/**
 * A screenshot failed the upload checks (docs/05-api-spec.md §3.1). Carries the public error code and
 * the HTTP status the API answers with.
 */
final class ChartImageRejected extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    private function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public static function tooLarge(int $sizeBytes, int $maxBytes): self
    {
        return new self(
            'FILE_TOO_LARGE',
            sprintf('The image is %s; screenshots must be %s or smaller.', self::megabytes($sizeBytes), self::megabytes($maxBytes)),
            413,
            ['size_bytes' => $sizeBytes, 'max_bytes' => $maxBytes],
        );
    }

    public static function unsupportedType(string $detectedType): self
    {
        return new self(
            'UNSUPPORTED_IMAGE_TYPE',
            'Only PNG, JPEG and WebP screenshots are accepted.',
            415,
            ['detected_type' => $detectedType],
        );
    }

    public static function unreadable(): self
    {
        return new self('INVALID_IMAGE', "This file couldn't be read as an image.", 422);
    }

    public static function dimensions(int $width, int $height, int $minWidth, int $minHeight, int $maxWidth, int $maxHeight): self
    {
        $message = $width < $minWidth || $height < $minHeight
            ? sprintf('The image is %d×%d px, too small to read reliably. Use a screenshot of at least %d×%d px.', $width, $height, $minWidth, $minHeight)
            : sprintf('The image is %d×%d px; the maximum is %d×%d px.', $width, $height, $maxWidth, $maxHeight);

        return new self('INVALID_DIMENSIONS', $message, 422, ['width' => $width, 'height' => $height]);
    }

    private static function megabytes(int $bytes): string
    {
        return rtrim(rtrim(number_format($bytes / 1_048_576, 1), '0'), '.').' MB';
    }
}
