<?php

declare(strict_types=1);

namespace Modules\Platform\Services\ObjectStorage;

use DateTimeImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Modules\Platform\Contracts\StoreObject;
use Modules\Platform\Exceptions\InvalidValueObject;
use Modules\Platform\Support\BoundedDocumentInspector;
use Modules\Platform\Support\ObjectUploadGrant;
use Modules\Platform\Support\ObservedObject;
use Modules\Platform\Support\StoredObjectRef;
use RuntimeException;
use Throwable;

/**
 * Private S3-compatible object store (MinIO locally).
 *
 * Objects are private. Temporary URLs expire. The adapter never logs the key
 * or the signed URL.
 */
final class S3StoreObject implements StoreObject
{
    public function __construct(
        private readonly Filesystem $disk,
        private readonly int $maxBytes = 20_971_520,
    ) {}

    public function put(string $namespace, string $objectId, string $contentType, string $bytes): StoredObjectRef
    {
        if (strlen($bytes) > $this->maxBytes) {
            throw new RuntimeException('Object exceeds the configured size bound.');
        }

        if (! preg_match('/^[a-z0-9][a-z0-9.+\/-]{0,126}[a-z0-9]$/', $contentType)) {
            throw new RuntimeException('Object content type is not an allowed media type.');
        }

        $ref = new StoredObjectRef($namespace, $objectId);
        $this->writeAt($ref, $contentType, $bytes);

        return $ref;
    }

    public function exists(StoredObjectRef $ref): bool
    {
        return $this->disk->exists($ref->key());
    }

    public function temporaryUrl(StoredObjectRef $ref, DateTimeImmutable $expiresAt): string
    {
        if (! $this->disk->exists($ref->key())) {
            throw new RuntimeException('Object does not exist.');
        }

        return $this->disk->temporaryUrl($ref->key(), $expiresAt);
    }

    public function metadata(StoredObjectRef $ref): array
    {
        if (! $this->disk->exists($ref->key())) {
            throw new RuntimeException('Object does not exist.');
        }

        $mime = $this->disk->mimeType($ref->key());

        return [
            'content_type' => is_string($mime) && $mime !== '' ? $mime : 'application/octet-stream',
            'size_bytes' => $this->disk->size($ref->key()),
            'encrypted' => true,
        ];
    }

    public function anonymousGet(StoredObjectRef $ref): never
    {
        unset($ref);

        throw new RuntimeException('Anonymous access is denied.');
    }

    public function anonymousList(): never
    {
        throw new RuntimeException('Anonymous access is denied.');
    }

    public function createUploadGrant(
        string $namespace,
        string $objectId,
        int $expectedSizeBytes,
        string $declaredMediaType,
        DateTimeImmutable $expiresAt,
    ): ObjectUploadGrant {
        if ($expectedSizeBytes < 1 || $expectedSizeBytes > $this->maxBytes) {
            throw new InvalidValueObject('Object exceeds the configured size bound.');
        }

        if (! preg_match('/^[a-z0-9][a-z0-9.+\/-]{0,126}[a-z0-9]$/', $declaredMediaType)) {
            throw new InvalidValueObject('Object content type is not an allowed media type.');
        }

        $ref = new StoredObjectRef($namespace, $objectId, $namespace.'/q/'.bin2hex(random_bytes(16)));

        return $this->issueUploadGrant($ref, $expectedSizeBytes, $declaredMediaType, $expiresAt);
    }

    public function issueUploadGrant(
        StoredObjectRef $ref,
        int $expectedSizeBytes,
        string $declaredMediaType,
        DateTimeImmutable $expiresAt,
    ): ObjectUploadGrant {
        if ($expectedSizeBytes < 1 || $expectedSizeBytes > $this->maxBytes) {
            throw new InvalidValueObject('Object exceeds the configured size bound.');
        }

        if (! preg_match('/^[a-z0-9][a-z0-9.+\/-]{0,126}[a-z0-9]$/', $declaredMediaType)) {
            throw new InvalidValueObject('Object content type is not an allowed media type.');
        }

        if (! str_contains($ref->key(), '/q/') || str_contains($ref->key(), '/c/')) {
            throw new InvalidValueObject('Upload grants are only issued for ingress locators.');
        }

        if (! method_exists($this->disk, 'temporaryUploadUrl')) {
            throw new RuntimeException('Object store does not support upload grants.');
        }

        /** @var array{url?: string, headers?: array<string, mixed>} $signed */
        $signed = $this->disk->temporaryUploadUrl($ref->key(), $expiresAt, [
            'ContentType' => $declaredMediaType,
            'ContentLength' => $expectedSizeBytes,
        ]);

        $url = (string) ($signed['url'] ?? '');
        if ($url === '') {
            throw new RuntimeException('Object store refused to issue an upload grant.');
        }

        $headers = ['Content-Type' => $declaredMediaType];
        $rawHeaders = $signed['headers'] ?? [];
        if (is_array($rawHeaders)) {
            foreach ($rawHeaders as $name => $value) {
                if (! is_string($name)) {
                    continue;
                }
                if (is_array($value)) {
                    $value = implode(',', array_map(static fn (mixed $item): string => is_scalar($item) ? (string) $item : '', $value));
                }
                if (! is_string($value) && ! is_int($value)) {
                    continue;
                }
                $headers[$name] = (string) $value;
            }
        }

        return new ObjectUploadGrant($ref->objectId, (string) $ref->storageLocator, 'PUT', $url, $headers, $expiresAt);
    }

    public function allocateCanonicalRef(string $namespace, string $objectId): StoredObjectRef
    {
        return new StoredObjectRef($namespace, $objectId, $namespace.'/c/'.bin2hex(random_bytes(16)));
    }

    /**
     * Server-only seal copy. S3 CopyObject is treated as atomic at the
     * destination key: the provider either materializes the complete object
     * or leaves the key absent. An existing destination is never overwritten,
     * so a crash/retry cannot replace sealed canonical bytes with later
     * ingress contents. CopyObject is issued with If-None-Match: * when the
     * client supports it; 412 is reconciled as "already sealed".
     */
    public function copyExact(StoredObjectRef $source, StoredObjectRef $destination): void
    {
        if ($this->exists($destination)) {
            $this->assertOccupiedCanonical($destination);

            return;
        }

        if (! $this->exists($source)) {
            throw new RuntimeException('Object does not exist.');
        }

        if (! $this->copyOnceUnlessExists($source, $destination)) {
            try {
                $this->assertOccupiedCanonical($destination);
            } catch (Throwable) {
                throw new RuntimeException('Object seal copy failed.');
            }

            return;
        }

        try {
            $this->disk->setVisibility($destination->key(), 'private');
        } catch (Throwable) {
        }
    }

    public function providerVersionId(StoredObjectRef $ref): ?string
    {
        if (! method_exists($this->disk, 'getClient')) {
            return null;
        }

        try {
            $client = $this->disk->getClient();
            $config = method_exists($this->disk, 'getConfig') ? $this->disk->getConfig() : [];
            $bucket = is_array($config) ? (string) ($config['bucket'] ?? '') : '';
            if ($bucket === '' || ! is_object($client) || ! method_exists($client, 'headObject')) {
                return null;
            }

            /** @var array<string, mixed> $result */
            $result = $client->headObject([
                'Bucket' => $bucket,
                'Key' => $ref->key(),
            ]);
            $version = $result['VersionId'] ?? null;
            if (! is_string($version) || $version === '' || strtolower($version) === 'null') {
                return null;
            }

            return $version;
        } catch (Throwable) {
            return null;
        }
    }

    public function writeAt(StoredObjectRef $ref, string $contentType, string $bytes): void
    {
        if (strlen($bytes) > $this->maxBytes) {
            throw new InvalidValueObject('Object exceeds the configured size bound.');
        }

        $this->disk->put($ref->key(), $bytes, [
            'visibility' => 'private',
            'ContentType' => $contentType,
            'Metadata' => [
                'clinic-encrypted' => 'true',
                'clinic-namespace' => $ref->namespace,
            ],
        ]);
    }

    public function observe(StoredObjectRef $ref, int $maxBytes): ObservedObject
    {
        $stream = $this->openStream($ref);

        try {
            $observed = (new BoundedDocumentInspector)->hashAndSize($stream, $maxBytes);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return new ObservedObject(
            $observed->exists,
            $observed->sizeBytes,
            $observed->sha256,
            $observed->detectedMime,
            $this->providerVersionId($ref) ?? '',
        );
    }

    /**
     * @return resource
     */
    public function openStream(StoredObjectRef $ref)
    {
        if (! $this->disk->exists($ref->key())) {
            throw new RuntimeException('Object does not exist.');
        }

        $stream = $this->disk->readStream($ref->key());
        if (! is_resource($stream)) {
            throw new RuntimeException('Object stream could not be opened.');
        }

        return $stream;
    }

    public function deleteIfPresent(StoredObjectRef $ref): void
    {
        if ($this->disk->exists($ref->key())) {
            $this->disk->delete($ref->key());
        }
    }

    /**
     * Copy source onto destination only when the destination key is absent.
     * Returns true when this call created the destination.
     */
    private function copyOnceUnlessExists(StoredObjectRef $source, StoredObjectRef $destination): bool
    {
        if (method_exists($this->disk, 'getClient') && method_exists($this->disk, 'getConfig')) {
            $client = $this->disk->getClient();
            $config = $this->disk->getConfig();
            $bucket = is_array($config) ? (string) ($config['bucket'] ?? '') : '';
            if ($bucket !== '' && is_object($client) && method_exists($client, 'copyObject')) {
                try {
                    $client->copyObject([
                        'Bucket' => $bucket,
                        'Key' => $destination->key(),
                        'CopySource' => $bucket.'/'.$source->key(),
                        'IfNoneMatch' => '*',
                        'ACL' => 'private',
                    ]);

                    return true;
                } catch (Throwable $e) {
                    if ($this->isPreconditionFailed($e)) {
                        return false;
                    }
                }
            }
        }

        if ($this->exists($destination)) {
            return false;
        }

        return $this->disk->copy($source->key(), $destination->key()) === true;
    }

    private function assertOccupiedCanonical(StoredObjectRef $destination): void
    {
        $meta = $this->metadata($destination);
        if ($meta['size_bytes'] < 1) {
            throw new InvalidValueObject('Canonical locator is occupied by an empty object.');
        }
    }

    private function isPreconditionFailed(Throwable $e): bool
    {
        $status = method_exists($e, 'getStatusCode') ? (int) $e->getStatusCode() : 0;
        $awsCode = method_exists($e, 'getAwsErrorCode') ? (string) $e->getAwsErrorCode() : '';

        return $status === 412
            || $awsCode === 'PreconditionFailed'
            || str_contains($e->getMessage(), 'PreconditionFailed');
    }
}
