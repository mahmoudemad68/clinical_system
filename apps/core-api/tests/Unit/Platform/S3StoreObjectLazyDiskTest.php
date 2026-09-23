<?php

declare(strict_types=1);

use Modules\Platform\Services\ObjectStorage\S3StoreObject;
use Modules\Platform\Support\StoredObjectRef;
use Tests\Support\FakeS3Filesystem;
use Tests\TestCase;

uses(TestCase::class);

it('does not resolve the filesystem disk until a store method runs', function () {
    $resolved = 0;
    $disk = new FakeS3Filesystem;
    $store = new S3StoreObject(function () use (&$resolved, $disk): FakeS3Filesystem {
        $resolved++;

        return $disk;
    });

    expect($resolved)->toBe(0);

    expect($store->exists(new StoredObjectRef('phase00', '00000000-0000-7000-8000-000000000001')))->toBeFalse();

    expect($resolved)->toBe(1);

    expect($store->exists(new StoredObjectRef('phase00', '00000000-0000-7000-8000-000000000002')))->toBeFalse();

    expect($resolved)->toBe(1);
});

it('still denies anonymous object access without constructing a disk', function () {
    $resolved = 0;
    $store = new S3StoreObject(function () use (&$resolved): FakeS3Filesystem {
        $resolved++;

        return new FakeS3Filesystem;
    });

    expect(fn () => $store->anonymousList())->toThrow(RuntimeException::class, 'Anonymous access is denied.')
        ->and($resolved)->toBe(0);

    expect(fn () => $store->anonymousGet(new StoredObjectRef('phase00', '00000000-0000-7000-8000-000000000003')))
        ->toThrow(RuntimeException::class, 'Anonymous access is denied.')
        ->and($resolved)->toBe(0);
});
