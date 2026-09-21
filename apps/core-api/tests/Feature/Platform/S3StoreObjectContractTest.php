<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;
use Modules\Platform\Exceptions\InvalidValueObject;
use Modules\Platform\Services\ObjectStorage\S3StoreObject;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * Live MinIO contract. Skips only when the emulator is absent.
 * A reachable provider with a missing bucket, bad auth, or public policy fails.
 */
final class S3StoreObjectContractTest extends TestCase
{
    #[Test]
    public function private_objects_are_not_anonymously_readable(): void
    {
        $endpoint = (string) config('filesystems.disks.s3.endpoint', '');
        $host = parse_url($endpoint, PHP_URL_HOST) ?: '127.0.0.1';
        $port = (int) (parse_url($endpoint, PHP_URL_PORT) ?: 9000);
        clinicSkipUnlessTcp($this, (string) $host, $port, 'CLINIC_REQUIRE_OBJECT_STORE', 'MinIO');

        try {
            /** @var Filesystem $disk */
            $disk = $this->app['filesystem']->disk('s3');
            $store = new S3StoreObject($disk);
            $ref = $store->put('phase00', 'live-object-1', 'text/plain', 'synthetic-bytes');
        } catch (Throwable $e) {
            $this->fail('MinIO is reachable but the bucket, credentials, or policy is unusable: '.$e::class.' '.$e->getMessage());
        }

        $this->assertTrue($store->exists($ref));
        $this->assertTrue($store->metadata($ref)['encrypted']);
        $url = $store->temporaryUrl($ref, new DateTimeImmutable('+60 seconds', new DateTimeZone('UTC')));
        $this->assertNotSame('', $url);

        $expires = new DateTimeImmutable('+60 seconds', new DateTimeZone('UTC'));
        $grant = $store->createUploadGrant('phase00', 'live-object-grant', 16, 'text/plain', $expires);
        $this->assertSame('PUT', $grant->method);
        $this->assertNotSame('', $grant->url);
        $this->assertStringStartsWith('phase00/q/', $grant->storageLocator);
        $grantHeaderNames = array_map('strtolower', array_keys($grant->headers));
        $this->assertNotContains('host', $grantHeaderNames);
        $this->assertNotContains('connection', $grantHeaderNames);
        $this->assertNotContains('transfer-encoding', $grantHeaderNames);
        $this->assertContains('content-type', $grantHeaderNames);
        $debug = json_encode($grant->__debugInfo(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($grant->storageLocator, $debug);
        $this->assertStringNotContainsString($grant->url, $debug);

        $canonical = $store->allocateCanonicalRef('phase00', 'live-object-1');
        $store->copyExact($ref, $canonical);
        $this->assertTrue($store->exists($canonical));
        $store->writeAt($ref, 'text/plain', 'synthetic-bytes-overwritten');
        $this->assertSame(hash('sha256', 'synthetic-bytes'), $store->observe($canonical, 20_971_520)->sha256);
        $store->copyExact($ref, $canonical);
        $this->assertSame(hash('sha256', 'synthetic-bytes'), $store->observe($canonical, 20_971_520)->sha256);
        $this->assertNotSame(hash('sha256', 'synthetic-bytes'), $store->providerVersionId($canonical) ?? '');

        $this->expectException(InvalidValueObject::class);
        $store->issueUploadGrant($canonical, 16, 'text/plain', $expires);
    }

    #[Test]
    public function anonymous_http_access_to_the_private_bucket_is_denied(): void
    {
        $endpoint = rtrim((string) config('filesystems.disks.s3.endpoint', ''), '/');
        $host = parse_url($endpoint, PHP_URL_HOST) ?: '127.0.0.1';
        $port = (int) (parse_url($endpoint, PHP_URL_PORT) ?: 9000);
        clinicSkipUnlessTcp($this, (string) $host, $port, 'CLINIC_REQUIRE_OBJECT_STORE', 'MinIO');

        try {
            /** @var Filesystem $disk */
            $disk = $this->app['filesystem']->disk('s3');
            $store = new S3StoreObject($disk);
            $ref = $store->put('phase00', 'live-anon-1', 'text/plain', 'synthetic-private-bytes');
        } catch (Throwable $e) {
            $this->fail('MinIO is reachable but the bucket, credentials, or policy is unusable: '.$e::class.' '.$e->getMessage());
        }

        $bucket = (string) config('filesystems.disks.s3.bucket');
        $anonymousObject = Http::withOptions(['http_errors' => false])->get($endpoint.'/'.$bucket.'/'.$ref->key());
        $anonymousList = Http::withOptions(['http_errors' => false])->get($endpoint.'/'.$bucket);

        $this->assertFalse($anonymousObject->successful(), 'anonymous object GET must not succeed');
        $this->assertNotSame(200, $anonymousObject->status());
        $this->assertFalse($anonymousList->successful(), 'anonymous bucket list must not succeed');
        $this->assertNotSame(200, $anonymousList->status());

        try {
            $store->anonymousList();
            $this->fail('anonymous list must not succeed');
        } catch (RuntimeException) {
        }

        $this->expectException(RuntimeException::class);
        $store->anonymousGet($ref);
    }
}
