<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

use App\Domain\Chart\Exceptions\ChartImageRejected;
use App\Domain\Chart\Models\ChartUpload;
use Carbon\CarbonInterface;
use finfo;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Intervention\Image\Exceptions\ImageException;
use Intervention\Image\Format;
use Intervention\Image\Interfaces\ImageManagerInterface;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Validates, re-encodes and stores chart screenshots (docs/02-architecture.md §7.3).
 *
 * The type is sniffed from the bytes, never the extension. Dimensions are read from the header before
 * any pixels are decoded, which rejects decompression bombs cheaply. The pixels are then re-encoded to
 * PNG: that drops EXIF and other metadata and anything appended to the file (polyglot payloads), so the
 * stored file is always a plain PNG. The SHA-256 is taken over the re-encoded bytes.
 *
 * Paths are relative to the charts disk: {user_id}/{yyyy}/{mm}/{ulid}.png.
 */
final class ChartStorage
{
    /**
     * @param  list<string>  $mimeTypes  accepted types, as detected from the file contents
     */
    public function __construct(
        private readonly FilesystemFactory $filesystems,
        private readonly ImageManagerInterface $images,
        private readonly string $disk,
        private readonly int $maxBytes,
        private readonly int $minWidth,
        private readonly int $minHeight,
        private readonly int $maxWidth,
        private readonly int $maxHeight,
        private readonly array $mimeTypes,
    ) {}

    public function diskName(): string
    {
        return $this->disk;
    }

    /**
     * @throws ChartImageRejected
     */
    public function prepare(UploadedFile $file): PreparedChart
    {
        $size = $file->getSize();
        if ($size === false) {
            throw ChartImageRejected::unreadable();
        }
        if ($size > $this->maxBytes) {
            throw ChartImageRejected::tooLarge($size, $this->maxBytes);
        }

        $path = $file->getRealPath();
        $bytes = $path === false ? false : file_get_contents($path);
        if ($bytes === false || $bytes === '') {
            throw ChartImageRejected::unreadable();
        }

        $mimeType = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if ($mimeType === false || ! in_array($mimeType, $this->mimeTypes, true)) {
            throw ChartImageRejected::unsupportedType($mimeType === false ? 'unknown' : $mimeType);
        }

        // Header only; '@' because malformed input raises a warning, which would become an exception.
        $header = @getimagesizefromstring($bytes);
        if ($header === false) {
            throw ChartImageRejected::unreadable();
        }
        $this->assertDimensions($header[0], $header[1]);

        try {
            $image = $this->images->decodeBinary($bytes)->orient();
            $png = $image->encodeUsingFormat(Format::PNG)->toString();
        } catch (ImageException) {
            throw ChartImageRejected::unreadable();
        }

        // EXIF orientation can swap the axes.
        $this->assertDimensions($image->width(), $image->height());

        return new PreparedChart($png, hash('sha256', $png), $image->width(), $image->height());
    }

    /**
     * Writes atomically (temporary file, then rename) and returns the disk-relative path.
     */
    public function store(PreparedChart $chart, int $userId, string $id, CarbonInterface $now): string
    {
        $path = sprintf('%d/%s/%s.png', $userId, $now->format('Y/m'), $id);
        $partial = $path.'.part';
        $disk = $this->filesystem();

        if (! $disk->put($partial, $chart->contents) || ! $disk->move($partial, $path)) {
            $disk->delete($partial);

            throw new RuntimeException("Could not write chart image [{$path}].");
        }

        return $path;
    }

    public function delete(string $path): void
    {
        $this->filesystem()->delete($path);
    }

    /**
     * Streams a stored image, or returns null when its file is missing.
     */
    public function response(ChartUpload $upload): ?StreamedResponse
    {
        $disk = $this->filesystem($upload->disk);

        if (! $disk instanceof FilesystemAdapter) {
            throw new RuntimeException("Disk [{$upload->disk}] cannot stream files.");
        }
        if (! $disk->exists($upload->path)) {
            return null;
        }

        return $disk->response($upload->path, "{$upload->id}.png", [
            'Content-Type' => $upload->mime_type,
            'Cache-Control' => 'private, max-age=86400',
            'ETag' => '"'.$upload->sha256.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function assertDimensions(int $width, int $height): void
    {
        if ($width < $this->minWidth || $height < $this->minHeight || $width > $this->maxWidth || $height > $this->maxHeight) {
            throw ChartImageRejected::dimensions($width, $height, $this->minWidth, $this->minHeight, $this->maxWidth, $this->maxHeight);
        }
    }

    private function filesystem(?string $disk = null): Filesystem
    {
        return $this->filesystems->disk($disk ?? $this->disk);
    }
}
