<?php

declare(strict_types=1);

use App\Domain\Analysis\Contracts\AnalysisEngine;
use App\Domain\Analysis\Exceptions\EngineBusy;
use App\Domain\Analysis\Exceptions\EngineRequestFailed;
use App\Domain\Analysis\Exceptions\EngineUnavailable;
use App\Domain\Analysis\ValueObjects\EngineEvent;
use App\Infrastructure\AI\EngineRequestSigner;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('returns the result from the stream', function () {
    $result = engineResultFixture();
    fakeAnalyzeStream([
        ['event' => 'accepted', 'analysis_id' => 'a1'],
        ['event' => 'stage', 'stage' => 'indicators', 'progress' => 0.2],
        ['event' => 'result', 'data' => $result],
    ]);

    $returned = app(AnalysisEngine::class)->analyze(['analysis_id' => 'a1']);

    expect($returned['tier'])->toBe('A')
        ->and($returned['decision']['action'])->toBe('buy')
        ->and($returned['evidence'])->not->toBeEmpty();
});

it('reports every event to the callback as it arrives', function () {
    fakeAnalyzeStream([
        ['event' => 'accepted', 'analysis_id' => 'a1'],
        ['event' => 'stage', 'stage' => 'indicators', 'progress' => 0.2],
        ['event' => 'stage', 'stage' => 'scoring', 'progress' => 0.8],
        ['event' => 'result', 'data' => engineResultFixture()],
    ]);

    $stages = [];
    app(AnalysisEngine::class)->analyze(
        ['analysis_id' => 'a1'],
        function (EngineEvent $event) use (&$stages): void {
            if ($event->isStage()) {
                $stages[] = [$event->stage(), $event->progress()];
            }
        },
    );

    expect($stages)->toBe([['indicators', 0.2], ['scoring', 0.8]]);
});

it('signs the request body', function () {
    fakeAnalyzeStream([['event' => 'result', 'data' => engineResultFixture()]]);

    app(AnalysisEngine::class)->analyze(['analysis_id' => 'a1']);

    Http::assertSent(function (Request $request): bool {
        $signer = new EngineRequestSigner((string) config('stock_ai.engine.hmac_secret'));
        $expected = $signer->sign($request->header('X-Timestamp')[0], $request->body());

        return $request->hasHeader('X-Signature', $expected)
            && in_array('application/x-ndjson', $request->header('Accept'), true);
    });
});

it('turns an error event into a typed failure', function () {
    fakeAnalyzeStream([
        ['event' => 'accepted', 'analysis_id' => 'a1'],
        ['event' => 'error', 'error' => ['code' => 'INSUFFICIENT_DATA', 'message' => 'Not enough bars.']],
    ]);

    expect(fn () => app(AnalysisEngine::class)->analyze(['analysis_id' => 'a1']))
        ->toThrow(EngineRequestFailed::class, 'Not enough bars.');
});

it('fails when the stream ends without a result', function () {
    fakeAnalyzeStream([['event' => 'accepted'], ['event' => 'stage', 'stage' => 'indicators']]);

    expect(fn () => app(AnalysisEngine::class)->analyze(['analysis_id' => 'a1']))
        ->toThrow(EngineRequestFailed::class, 'without sending a result');
});

it('maps a busy engine to EngineBusy with its retry hint', function () {
    Http::fake(['engine.test/v1/analyze' => Http::response([
        'error' => [
            'code' => 'ENGINE_BUSY',
            'message' => 'All 2 analysis slots are in use.',
            'details' => ['retry_after_seconds' => 45],
        ],
    ], 503)]);

    try {
        app(AnalysisEngine::class)->analyze(['analysis_id' => 'a1']);
        $this->fail('EngineBusy was not thrown.');
    } catch (EngineBusy $e) {
        expect($e->retryAfterSeconds)->toBe(45)->and($e->getMessage())->toContain('slots');
    }
});

it('maps an unreachable engine to EngineUnavailable', function () {
    Http::fake(fn () => throw new ConnectionException('Connection refused'));

    expect(fn () => app(AnalysisEngine::class)->analyze(['analysis_id' => 'a1']))
        ->toThrow(EngineUnavailable::class);
});

it('surfaces a non-2xx error envelope', function () {
    Http::fake(['engine.test/v1/analyze' => Http::response([
        'error' => ['code' => 'INVALID_IMAGE_PATH', 'message' => 'Outside the storage root.'],
    ], 422)]);

    try {
        app(AnalysisEngine::class)->analyze(['analysis_id' => 'a1']);
        $this->fail('EngineRequestFailed was not thrown.');
    } catch (EngineRequestFailed $e) {
        expect($e->errorCode)->toBe('INVALID_IMAGE_PATH')->and($e->status)->toBe(422);
    }
});
