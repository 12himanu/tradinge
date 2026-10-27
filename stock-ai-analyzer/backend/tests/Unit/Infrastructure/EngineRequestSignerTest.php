<?php

declare(strict_types=1);

use App\Infrastructure\AI\EngineRequestSigner;

const TEST_SECRET = 'test-secret-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';

it('matches the cross-language vector shared with the Python engine', function () {
    // Same vector is asserted in ai-engine/tests/test_security.py.
    $signer = new EngineRequestSigner(TEST_SECRET);

    expect($signer->sign('1757680000', '{"symbol":"HDL"}'))
        ->toBe('148d6e7003171d607834c2cc8bed191433ada646f3cf550559d49c378bfa56fe');
});

it('builds timestamp and signature headers', function () {
    $headers = (new EngineRequestSigner(TEST_SECRET))->headers('{}', now: 1757680000);

    expect($headers['X-Timestamp'])->toBe('1757680000')
        ->and($headers['X-Signature'])->toBe(hash_hmac('sha256', '1757680000.{}', TEST_SECRET));
});

it('rejects secrets shorter than 32 characters', function () {
    new EngineRequestSigner('too-short');
})->throws(InvalidArgumentException::class);
