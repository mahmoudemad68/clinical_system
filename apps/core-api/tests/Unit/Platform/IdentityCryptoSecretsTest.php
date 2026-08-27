<?php

declare(strict_types=1);

use Modules\Platform\Services\Crypto\AesGcmEnvelopeEncryptor;
use Modules\Platform\Services\Crypto\HkdfHmacHasher;
use Symfony\Component\Process\Process;

it('constructs the identity encryptor without a runtime key', function () {
    expect(new AesGcmEnvelopeEncryptor([1 => '', 2 => ''], 1))
        ->toBeInstanceOf(AesGcmEnvelopeEncryptor::class);
});

it('fails closed when encrypting without an identity encryption key', function () {
    $encryptor = new AesGcmEnvelopeEncryptor([1 => '', 2 => ''], 1);

    expect(fn () => $encryptor->encrypt('phone', '+201012345678'))
        ->toThrow(RuntimeException::class, 'Identity encryption current version has no key.');
});

it('fails closed when decrypting without any identity encryption key', function () {
    $encryptor = new AesGcmEnvelopeEncryptor([1 => '', 2 => ''], 1);

    expect(fn () => $encryptor->decrypt('phone', str_repeat('A', 40)))
        ->toThrow(RuntimeException::class, 'Identity encryption current version has no key.');
});

it('encrypts and decrypts when a key is present', function () {
    $encryptor = new AesGcmEnvelopeEncryptor([
        1 => 'test_identity_enc_v1_not_a_secret_value!!',
    ], 1);

    $envelope = $encryptor->encrypt('phone', '+201012345678');

    expect($encryptor->decrypt('phone', $envelope))->toBe('+201012345678');
});

it('constructs the identity hmac hasher without a runtime key', function () {
    expect(new HkdfHmacHasher([1 => ''], 1))->toBeInstanceOf(HkdfHmacHasher::class);
});

it('fails closed when hashing without an identity hmac key', function () {
    $hasher = new HkdfHmacHasher([1 => ''], 1);

    expect(fn () => $hasher->digest('national_id', '30001010100011'))
        ->toThrow(RuntimeException::class, 'Identity HMAC current version has no key.');
});

it('discovers laravel packages without identity encryption secrets', function () {
    $root = dirname(__DIR__, 3);
    $env = getenv();
    if (! is_array($env)) {
        $env = [];
    }
    $env['IDENTITY_ENCRYPTION_KEY_V1'] = '';
    $env['IDENTITY_ENCRYPTION_KEY_V2'] = '';
    $env['IDENTITY_HMAC_KEY_V1'] = '';
    $env['IDENTITY_HMAC_KEY_V2'] = '';
    $env['APP_ENV'] = 'testing';

    $process = new Process([PHP_BINARY, 'artisan', 'package:discover', '--ansi'], $root, $env);
    $process->setTimeout(90);
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput()."\n".$process->getOutput());
});
