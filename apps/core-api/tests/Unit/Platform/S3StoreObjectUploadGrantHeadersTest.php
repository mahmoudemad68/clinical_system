<?php

declare(strict_types=1);

use DateTimeImmutable;
use DateTimeZone;
use Modules\Platform\Services\ObjectStorage\S3StoreObject;
use Modules\Platform\Support\StoredObjectRef;
use Tests\Support\FakeS3Filesystem;
use Tests\TestCase;

uses(TestCase::class);

/**
 * @param  array<string, string>  $headers
 * @return list<string>
 */
function s3GrantHeaderNames(array $headers): array
{
    return array_values(array_map(static fn (string $name): string => strtolower($name), array_keys($headers)));
}

it('projects a provider temporary upload into a client-safe grant without Host', function (array $providerHeaders) {
    $disk = new FakeS3Filesystem;
    $disk->temporaryUploadHeaders = $providerHeaders;
    $disk->signedUploadUrl = 'https://objects.example/upload?X-Amz-Signature=synthetic-signature';
    $store = new S3StoreObject($disk);
    $expires = new DateTimeImmutable('+60 seconds', new DateTimeZone('UTC'));
    $objectId = '00000000-0000-7000-8000-000000000099';
    $locator = 'verification/q/'.str_repeat('ab', 16);
    $ref = new StoredObjectRef('verification', $objectId, $locator);

    $grant = $store->issueUploadGrant($ref, 16, 'application/pdf', $expires);
    $names = s3GrantHeaderNames($grant->headers);
    $debug = json_encode($grant->__debugInfo(), JSON_THROW_ON_ERROR);

    expect($grant->method)->toBe('PUT')
        ->and($grant->url)->toBe($disk->signedUploadUrl)
        ->and($names)->not->toContain('host')
        ->and($names)->not->toContain('connection')
        ->and($names)->not->toContain('transfer-encoding')
        ->and($names)->toContain('content-type')
        ->and($grant->headers['Content-Type'] ?? $grant->headers['content-type'] ?? '')->toBe('application/pdf')
        ->and($debug)->not->toContain($locator)
        ->and($debug)->not->toContain($grant->url)
        ->and($debug)->not->toContain('synthetic-signature')
        ->and($debug)->not->toContain($objectId);
})->with([
    'Host and Content-Type' => [[
        'Host' => '127.0.0.1:19000',
        'Content-Type' => 'application/pdf',
    ]],
    'lowercase host' => [[
        'host' => '127.0.0.1:19000',
        'Content-Type' => 'application/pdf',
    ]],
    'HOST' => [[
        'HOST' => '127.0.0.1:19000',
        'Content-Type' => 'application/pdf',
    ]],
    'Connection' => [[
        'Connection' => 'keep-alive',
        'Content-Type' => 'application/pdf',
    ]],
    'transfer-encoding' => [[
        'transfer-encoding' => 'chunked',
        'Content-Type' => 'application/pdf',
    ]],
]);

it('keeps required provider headers and the exact signed URL', function () {
    $disk = new FakeS3Filesystem;
    $disk->signedUploadUrl = 'https://objects.example/upload?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Signature=synthetic-signature';
    $disk->temporaryUploadHeaders = [
        'Host' => '127.0.0.1:19000',
        'Connection' => 'keep-alive',
        'Transfer-Encoding' => 'chunked',
        'Content-Type' => 'application/pdf',
        'Content-Length' => '16',
        'x-amz-checksum-crc32' => 'abcd1234',
        'X-Amz-Date' => '20260921T000000Z',
        'X-Unfamiliar-Signed' => 'keep-me',
    ];
    $store = new S3StoreObject($disk);
    $expires = new DateTimeImmutable('+60 seconds', new DateTimeZone('UTC'));
    $grant = $store->createUploadGrant('verification', '00000000-0000-7000-8000-000000000099', 16, 'application/pdf', $expires);
    $names = s3GrantHeaderNames($grant->headers);

    expect($grant->url)->toBe($disk->signedUploadUrl)
        ->and($names)->not->toContain('host')
        ->and($names)->not->toContain('connection')
        ->and($names)->not->toContain('transfer-encoding')
        ->and($grant->headers['Content-Type'])->toBe('application/pdf')
        ->and($grant->headers['Content-Length'])->toBe('16')
        ->and($grant->headers['x-amz-checksum-crc32'])->toBe('abcd1234')
        ->and($grant->headers['X-Amz-Date'])->toBe('20260921T000000Z')
        ->and($grant->headers['X-Unfamiliar-Signed'])->toBe('keep-me');
});
