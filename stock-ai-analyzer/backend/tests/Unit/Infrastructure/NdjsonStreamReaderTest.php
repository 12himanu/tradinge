<?php

declare(strict_types=1);

use App\Domain\Analysis\Exceptions\EngineStreamTimedOut;
use App\Infrastructure\AI\NdjsonStreamReader;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;

/**
 * A stream that hands out its chunks one read at a time, the way a socket does.
 */
function chunkedStream(string ...$chunks): StreamInterface
{
    return Utils::streamFor(implode('', $chunks));
}

it('reads one object per line', function () {
    $reader = new NdjsonStreamReader;
    $stream = chunkedStream(
        '{"event":"accepted","analysis_id":"a1"}'."\n",
        '{"event":"stage","stage":"indicators","progress":0.2}'."\n",
        '{"event":"result","data":{"tier":"A"}}'."\n",
    );

    $events = iterator_to_array($reader->read($stream));

    expect($events)->toHaveCount(3)
        ->and($events[0]['event'])->toBe('accepted')
        ->and($events[1]['stage'])->toBe('indicators')
        ->and($events[2]['data']['tier'])->toBe('A');
});

it('joins objects split across chunk boundaries', function () {
    // 8 KB of padding forces the reader to stitch a line together from several reads.
    $padding = str_repeat('x', NdjsonStreamReader::CHUNK_BYTES);
    $stream = chunkedStream('{"event":"result","data":{"note":"'.$padding.'"}}'."\n");

    $events = iterator_to_array((new NdjsonStreamReader)->read($stream));

    expect($events)->toHaveCount(1)
        ->and($events[0]['data']['note'])->toHaveLength(NdjsonStreamReader::CHUNK_BYTES);
});

it('reads a final line that has no trailing newline', function () {
    $events = iterator_to_array((new NdjsonStreamReader)->read(chunkedStream('{"event":"result"}')));

    expect($events)->toHaveCount(1)->and($events[0]['event'])->toBe('result');
});

it('ignores blank lines', function () {
    $stream = chunkedStream("\n", '{"event":"stage"}'."\n", "\n", "   \n");

    expect(iterator_to_array((new NdjsonStreamReader)->read($stream)))->toHaveCount(1);
});

it('throws on malformed json rather than skipping it', function () {
    $stream = chunkedStream('{"event":"stage"'."\n");

    expect(fn () => iterator_to_array((new NdjsonStreamReader)->read($stream)))
        ->toThrow(JsonException::class);
});

it('gives up when a single event exceeds the size limit', function () {
    $reader = new NdjsonStreamReader(maxLineBytes: 1024);
    $stream = chunkedStream('{"event":"result","data":"'.str_repeat('y', 4096).'"}'."\n");

    expect(fn () => iterator_to_array($reader->read($stream)))
        ->toThrow(EngineStreamTimedOut::class, 'exceeded');
});

it('aborts when the overall budget is spent', function () {
    $reader = new NdjsonStreamReader(perReadTimeout: 60, overallTimeout: 0);
    $stream = chunkedStream('{"event":"stage"}'."\n", '{"event":"result"}'."\n");

    expect(fn () => iterator_to_array($reader->read($stream)))
        ->toThrow(EngineStreamTimedOut::class, 'budget');
});
