<?php

declare(strict_types=1);

use App\Domain\Analysis\Contracts\AnalysisEngine;
use App\Domain\Analysis\Exceptions\EngineRequestFailed;
use App\Domain\Analysis\Exceptions\EngineUnavailable;
use App\Infrastructure\AI\EngineRequestSigner;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('parses a healthy engine response', function () {
    Http::fake(['engine.test/health' => Http::response([
        'status' => 'ok',
        'engine_version' => '0.1.0+cfg.abcd1234',
        'model_profile' => 'gpu_4gb',
        'ollama' => ['reachable' => true, 'version' => '0.12.0'],
        'models' => [['tag' => 'qwen3:4b', 'status' => 'present']],
    ])]);

    $health = app(AnalysisEngine::class)->health();

    expect($health->isOk())->toBeTrue()
        ->and($health->engineVersion)->toBe('0.1.0+cfg.abcd1234')
        ->and($health->modelProfile)->toBe('gpu_4gb')
        ->and($health->models)->toHaveCount(1);
});

it('reports unreachable instead of throwing', function () {
    Http::fake(fn () => throw new ConnectionException('Connection refused'));

    $health = app(AnalysisEngine::class)->health();

    expect($health->status)->toBe('unreachable')->and($health->error)->toContain('Connection refused');
});

it('treats a non-2xx health response as unreachable', function () {
    Http::fake(['engine.test/health' => Http::response('bad gateway', 502)]);

    expect(app(AnalysisEngine::class)->health()->status)->toBe('unreachable');
});

it('signs internal requests with the shared HMAC scheme and forwards the request id', function () {
    Http::fake(['engine.test/v1/models' => Http::response(['active_profile' => 'gpu_4gb', 'profiles' => [], 'models' => []])]);

    $models = app(AnalysisEngine::class)->models();

    expect($models['active_profile'])->toBe('gpu_4gb');

    $signer = new EngineRequestSigner((string) config('stock_ai.engine.hmac_secret'));
    Http::assertSent(function (Request $request) use ($signer): bool {
        $timestamp = $request->header('X-Timestamp')[0] ?? '';

        return $request->url() === 'http://engine.test/v1/models'
            && abs(time() - (int) $timestamp) <= 5
            && ($request->header('X-Signature')[0] ?? null) === $signer->sign($timestamp, '')
            && $request->hasHeader('X-Request-Id');
    });
});

it('maps the engine error envelope to a typed exception', function () {
    Http::fake(['engine.test/v1/models' => Http::response(
        ['error' => ['code' => 'UNAUTHORIZED', 'message' => 'Invalid signature', 'details' => []]],
        401,
    )]);

    expect(fn () => app(AnalysisEngine::class)->models())->toThrow(
        fn (EngineRequestFailed $e) => expect($e->errorCode)->toBe('UNAUTHORIZED')->and($e->status)->toBe(401)
    );
});

it('throws EngineUnavailable when the engine cannot be reached', function () {
    Http::fake(fn () => throw new ConnectionException('Connection refused'));

    expect(fn () => app(AnalysisEngine::class)->models())->toThrow(EngineUnavailable::class);
});

it('refuses signed calls when no secret is configured', function () {
    config(['stock_ai.engine.hmac_secret' => null]);

    expect(fn () => app(AnalysisEngine::class)->models())->toThrow(
        fn (EngineRequestFailed $e) => expect($e->errorCode)->toBe('ENGINE_NOT_CONFIGURED')
    );
});
