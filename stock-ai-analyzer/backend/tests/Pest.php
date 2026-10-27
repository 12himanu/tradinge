<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');
pest()->extend(TestCase::class)->in('Unit');

/**
 * Headers a browser sends from the Next.js frontend, so Sanctum treats the request as first-party.
 *
 * @return array<string, string>
 */
function spaHeaders(): array
{
    return [
        'Origin' => 'http://localhost:3000',
        'Referer' => 'http://localhost:3000/login',
        'Accept' => 'application/json',
    ];
}

/**
 * A genuine image upload. Charts are decoded and re-encoded, so fakes must be real image bytes.
 */
function chartImage(int $width = 1200, int $height = 700, string $format = 'png', int $seed = 1): UploadedFile
{
    return UploadedFile::fake()->createWithContent("chart.{$format}", chartImageBytes($width, $height, $format, $seed));
}

/**
 * Raw bytes of a dark chart-like image. Different seeds give different bytes (and hashes).
 */
function chartImageBytes(int $width = 1200, int $height = 700, string $format = 'png', int $seed = 1): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, 19, 23, 34));
    imageline($image, 0, $height - 1, $width - 1, $seed % $height, imagecolorallocate($image, 38, 166, 154));

    ob_start();
    match ($format) {
        'png' => imagepng($image),
        'jpeg' => imagejpeg($image, null, 90),
        'webp' => imagewebp($image),
        'gif' => imagegif($image),
    };

    return (string) ob_get_clean();
}

/**
 * Runs the statement inside a savepoint so a constraint violation doesn't abort the test transaction.
 */
function expectConstraintViolation(Closure $statement): void
{
    expect(fn () => DB::transaction($statement))->toThrow(QueryException::class);
}

/**
 * A recorded engine result, produced by running the real engine CLI
 * (`python -m ai_engine.cli analyze --json`). Regenerate after any contract change.
 *
 * @return array<string, mixed>
 */
function engineResultFixture(string $name = 'tier_a_result.json'): array
{
    return json_decode(
        (string) file_get_contents(base_path("tests/Fixtures/engine/{$name}")),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
}

/**
 * The engine's NDJSON wire format: one JSON object per line.
 *
 * @param  list<array<string, mixed>>  $events
 */
function ndjson(array $events): string
{
    return implode('', array_map(
        static fn (array $event): string => json_encode($event, JSON_THROW_ON_ERROR).'
',
        $events,
    ));
}

/**
 * Fake the engine's streaming endpoint with the given NDJSON events.
 *
 * @param  list<array<string, mixed>>  $events
 */
function fakeAnalyzeStream(array $events, int $status = 200): void
{
    Http::fake(['engine.test/v1/analyze' => Http::response(ndjson($events), $status, [
        'Content-Type' => 'application/x-ndjson',
    ])]);
}
