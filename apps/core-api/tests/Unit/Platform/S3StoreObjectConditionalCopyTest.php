<?php

declare(strict_types=1);

use Illuminate\Contracts\Filesystem\Filesystem;
use Modules\Platform\Exceptions\TransientProviderFailure;
use Modules\Platform\Services\ObjectStorage\S3StoreObject;
use Modules\Platform\Support\StoredObjectRef;
use Tests\Support\FakeS3Filesystem;
use Tests\TestCase;

uses(TestCase::class);

function s3CopyException(int $status, string $awsCode): RuntimeException
{
    return new class($status, $awsCode) extends RuntimeException
    {
        public function __construct(private readonly int $status, private readonly string $awsCode)
        {
            parent::__construct($awsCode);
        }

        public function getStatusCode(): int
        {
            return $this->status;
        }

        public function getAwsErrorCode(): string
        {
            return $this->awsCode;
        }
    };
}

function s3ConditionalStore(FakeS3Filesystem $disk): S3StoreObject
{
    return new S3StoreObject($disk);
}

function s3ConditionalRefs(): array
{
    $objectId = '00000000-0000-7000-8000-000000000001';

    return [
        new StoredObjectRef('verification', $objectId, 'verification/q/'.str_repeat('ab', 16)),
        new StoredObjectRef('verification', $objectId, 'verification/c/'.str_repeat('cd', 16)),
    ];
}

it('treats an AWS SDK-style magic copyObject client as native capability', function () {
    $disk = new FakeS3Filesystem;
    expect(method_exists($disk->client, 'copyObject'))->toBeFalse()
        ->and(is_callable([$disk->client, 'copyObject']))->toBeTrue();

    $store = s3ConditionalStore($disk);
    [$source, $destination] = s3ConditionalRefs();
    $bytes = verificationMinimalPdf();
    $store->writeAt($source, 'application/pdf', $bytes);

    $store->copyExact($source, $destination);

    expect($disk->client->copyObjectCalls)->toBe(1)
        ->and($disk->copyCalls)->toBe(0)
        ->and($store->observe($destination, 20_971_520)->sha256)->toBe(hash('sha256', $bytes));
});

it('creates the destination with a successful conditional CopyObject through getAdapter', function () {
    $disk = new FakeS3Filesystem;
    $store = s3ConditionalStore($disk);
    [$source, $destination] = s3ConditionalRefs();
    $bytes = verificationMinimalPdf();
    $store->writeAt($source, 'application/pdf', $bytes);

    $store->copyExact($source, $destination);

    expect($disk->client->copyObjectCalls)->toBe(1)
        ->and($disk->copyCalls)->toBe(0)
        ->and($disk->exists($destination->key()))->toBeTrue()
        ->and($store->observe($destination, 20_971_520)->sha256)->toBe(hash('sha256', $bytes));
});

it('reconciles a 412 without overwriting an existing canonical object', function () {
    $disk = new FakeS3Filesystem;
    $store = s3ConditionalStore($disk);
    [$source, $destination] = s3ConditionalRefs();
    $sealed = verificationMinimalPdf();
    $later = verificationAlternatePdf();
    $store->writeAt($source, 'application/pdf', $later);
    $disk->client->bytesOnFailure = $sealed;
    $disk->client->failures = [s3CopyException(412, 'PreconditionFailed')];

    $store->copyExact($source, $destination);
    $store->writeAt($source, 'application/pdf', $later);
    $store->copyExact($source, $destination);

    expect($disk->copyCalls)->toBe(0)
        ->and($disk->client->copyObjectCalls)->toBe(1)
        ->and($store->observe($destination, 20_971_520)->sha256)->toBe(hash('sha256', $sealed));
});

it('retries a 409 on the same conditional CopyObject and never falls back', function () {
    $disk = new FakeS3Filesystem;
    $store = s3ConditionalStore($disk);
    [$source, $destination] = s3ConditionalRefs();
    $bytes = verificationMinimalPdf();
    $store->writeAt($source, 'application/pdf', $bytes);
    $disk->client->failures = [s3CopyException(409, 'ConditionalRequestConflict')];

    $store->copyExact($source, $destination);

    expect($disk->client->copyObjectCalls)->toBe(2)
        ->and($disk->copyCalls)->toBe(0)
        ->and($store->observe($destination, 20_971_520)->sha256)->toBe(hash('sha256', $bytes));
});

it('fails closed after bounded 409 retries without an unconditional copy', function () {
    $disk = new FakeS3Filesystem;
    $store = s3ConditionalStore($disk);
    [$source, $destination] = s3ConditionalRefs();
    $store->writeAt($source, 'application/pdf', verificationMinimalPdf());
    $disk->client->failures = [
        s3CopyException(409, 'ConditionalRequestConflict'),
        s3CopyException(409, 'ConditionalRequestConflict'),
        s3CopyException(409, 'ConditionalRequestConflict'),
    ];

    expect(fn () => $store->copyExact($source, $destination))->toThrow(TransientProviderFailure::class)
        ->and($disk->client->copyObjectCalls)->toBe(3)
        ->and($disk->copyCalls)->toBe(0)
        ->and($disk->exists($destination->key()))->toBeFalse();
});

it('fails closed on a generic provider exception without an unconditional copy', function () {
    $disk = new FakeS3Filesystem;
    $store = s3ConditionalStore($disk);
    [$source, $destination] = s3ConditionalRefs();
    $store->writeAt($source, 'application/pdf', verificationMinimalPdf());
    $disk->client->failures = [new RuntimeException('network')];

    expect(fn () => $store->copyExact($source, $destination))->toThrow(TransientProviderFailure::class)
        ->and($disk->client->copyObjectCalls)->toBe(1)
        ->and($disk->copyCalls)->toBe(0)
        ->and($disk->exists($destination->key()))->toBeFalse();
});

it('fails closed when the adapter has no native conditional CopyObject client', function () {
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('exists')->andReturn(false, true);
    $disk->shouldReceive('copy')->never();
    $store = new S3StoreObject($disk);
    [$source, $destination] = s3ConditionalRefs();

    expect(fn () => $store->copyExact($source, $destination))->toThrow(TransientProviderFailure::class);
});
