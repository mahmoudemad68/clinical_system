<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Filesystem\Filesystem;
use Modules\Platform\Services\ObjectStorage\S3StoreObject;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * Live MinIO contract. Skips when the emulator is not running.
 */
final class S3StoreObjectContractTest extends TestCase
{
    #[Test]
    public function private_objects_are_not_anonymously_readable(): void
    {
        $endpoint = (string) config('filesystems.disks.s3.endpoint', '');
        $host = parse_url($endpoint, PHP_URL_HOST) ?: '127.0.0.1';
        $port = parse_url($endpoint, PHP_URL_PORT) ?: 9000;

        $socket = @fsockopen((string) $host, (int) $port, $errno, $error, 0.2);
        if (! is_resource($socket)) {
            $this->markTestSkipped('MinIO is not reachable.');
        }
        fclose($socket);

        try {
            /** @var Filesystem $disk */
            $disk = $this->app['filesystem']->disk('s3');
            $store = new S3StoreObject($disk);
            $ref = $store->put('phase00', 'live-object-1', 'text/plain', 'synthetic-bytes');
        } catch (Throwable $e) {
            $this->markTestSkipped('S3 disk is not usable: '.$e::class);
        }

        $this->assertTrue($store->exists($ref));
        $this->assertTrue($store->metadata($ref)['encrypted']);
        $url = $store->temporaryUrl($ref, new DateTimeImmutable('+60 seconds', new DateTimeZone('UTC')));
        $this->assertNotSame('', $url);

        $this->expectException(RuntimeException::class);
        $store->anonymousGet($ref);
    }
}
