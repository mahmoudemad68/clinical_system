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

it('fails closed for empty, short, and sub-floor encryption keys', function (string $material) {
    $encryptor = new AesGcmEnvelopeEncryptor([1 => $material], 1);

    expect(fn () => $encryptor->encrypt('phone', '+201012345678'))
        ->toThrow(RuntimeException::class);
})->with([
    '',
    'x',
    str_repeat('x', 16),
    str_repeat('x', 31),
]);

it('encrypts when encryption key material is at least 32 characters', function () {
    $encryptor = new AesGcmEnvelopeEncryptor([1 => str_repeat('k', 32)], 1);

    expect($encryptor->decrypt('phone', $encryptor->encrypt('phone', '+201012345678')))->toBe('+201012345678');
});

it('fails closed for empty, short, and sub-floor hmac keys', function (string $material) {
    $hasher = new HkdfHmacHasher([1 => $material], 1);

    expect(fn () => $hasher->digest('phone_lookup', '+201012345678'))
        ->toThrow(RuntimeException::class);
})->with([
    '',
    'x',
    str_repeat('x', 16),
    str_repeat('x', 31),
]);

it('hashes when hmac key material is at least 32 characters', function () {
    $hasher = new HkdfHmacHasher([1 => str_repeat('h', 32)], 1);

    expect(strlen($hasher->digest('phone_lookup', '+201012345678')))->toBe(32);
});

it('fails closed when the configured current encryption version has no key', function () {
    $encryptor = new AesGcmEnvelopeEncryptor([1 => str_repeat('k', 32), 2 => ''], 2);

    expect(fn () => $encryptor->encrypt('phone', '+201012345678'))
        ->toThrow(RuntimeException::class, 'Identity encryption current version has no key.');
});

it('fails closed when the configured current hmac version has no key', function () {
    $hasher = new HkdfHmacHasher([1 => str_repeat('h', 32), 2 => ''], 2);

    expect(fn () => $hasher->digest('phone_lookup', '+201012345678'))
        ->toThrow(RuntimeException::class, 'Identity HMAC current version has no key.');
});

it('fails closed when decrypting with the wrong old key', function () {
    $writer = new AesGcmEnvelopeEncryptor([1 => str_repeat('a', 32)], 1);
    $envelope = $writer->encrypt('phone', '+201012345678');
    $reader = new AesGcmEnvelopeEncryptor([1 => str_repeat('b', 32)], 1);

    expect(fn () => $reader->decrypt('phone', $envelope))
        ->toThrow(RuntimeException::class, 'Envelope decryption failed.');
});

it('fails closed when the envelope tag is tampered', function () {
    $encryptor = new AesGcmEnvelopeEncryptor([1 => str_repeat('k', 32)], 1);
    $envelope = $encryptor->encrypt('phone', '+201012345678');
    $tampered = substr($envelope, 0, -1).chr(ord($envelope[-1]) ^ 0xFF);

    expect(fn () => $encryptor->decrypt('phone', $tampered))
        ->toThrow(RuntimeException::class, 'Envelope decryption failed.');
});

it('fails closed when the envelope ciphertext is tampered', function () {
    $encryptor = new AesGcmEnvelopeEncryptor([1 => str_repeat('k', 32)], 1);
    $envelope = $encryptor->encrypt('phone', '+201012345678');
    $flip = 2 + 12;
    $tampered = substr($envelope, 0, $flip).chr(ord($envelope[$flip]) ^ 0xFF).substr($envelope, $flip + 1);

    expect(fn () => $encryptor->decrypt('phone', $tampered))
        ->toThrow(RuntimeException::class, 'Envelope decryption failed.');
});

it('fails closed when decrypting with a different purpose aad', function () {
    $encryptor = new AesGcmEnvelopeEncryptor([1 => str_repeat('k', 32)], 1);
    $envelope = $encryptor->encrypt('phone', '+201012345678');

    expect(fn () => $encryptor->decrypt('national_id', $envelope))
        ->toThrow(RuntimeException::class, 'Envelope decryption failed.');
});

it('fails closed on an unknown envelope version', function () {
    $encryptor = new AesGcmEnvelopeEncryptor([1 => str_repeat('k', 32)], 1);
    $envelope = $encryptor->encrypt('phone', '+201012345678');
    $unknown = pack('n', 99).substr($envelope, 2);

    expect(fn () => $encryptor->decrypt('phone', $unknown))
        ->toThrow(RuntimeException::class, 'Envelope version is not readable.');
});

it('reports the envelope version used for a current write', function () {
    $encryptor = new AesGcmEnvelopeEncryptor([
        1 => str_repeat('a', 32),
        2 => str_repeat('b', 32),
    ], 2);
    $envelope = $encryptor->encrypt('phone', '+201012345678');

    expect($encryptor->envelopeVersion($envelope))->toBe(2)
        ->and($encryptor->currentVersion())->toBe(2);
});
