<?php

declare(strict_types=1);

namespace Modules\Platform\Services\ObjectStorage;

use DateTimeImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Modules\Platform\Contracts\StoreObject;
use Modules\Platform\Exceptions\InvalidValueObject;
use Modules\Platform\Exceptions\TransientProviderFailure;
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
    private const CONDITIONAL_COPY_ATTEMPTS = 3;

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

        $rawHeaders = $signed['headers'] ?? [];
        $headers = is_array($rawHeaders)
            ? $this->clientSafeUploadHeaders($rawHeaders, $declaredMediaType)
            : ['Content-Type' => $declaredMediaType];

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
     * client supports it; 412 is reconciled as "already sealed". A 409
     * ConditionalRequestConflict retries the same conditional CopyObject a
     * bounded number of times, then fails transiently. Native-client errors
     * never fall through to an unconditional copy.
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
            if ($bucket === '' || ! is_object($client) || ! is_callable([$client, 'headObject'])) {
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
     * Native S3 CopyObject uses If-None-Match: *. 412 means occupied.
     * 409 retries the same conditional operation a bounded number of times.
     * Any other native-client failure fails closed and never falls through
     * to an unconditional copy.
     * Returns true when this call created the destination.
     */
    private function copyOnceUnlessExists(StoredObjectRef $source, StoredObjectRef $destination): bool
    {
        $native = $this->nativeCopyClient();
        if ($native === null) {
            throw new TransientProviderFailure('Object store cannot perform a conditional canonical copy.');
        }

        [$client, $bucket] = $native;
        $attempts = 0;
        while ($attempts < self::CONDITIONAL_COPY_ATTEMPTS) {
            $attempts++;
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
                if ($this->isConditionalConflict($e) && $attempts < self::CONDITIONAL_COPY_ATTEMPTS) {
                    continue;
                }
                if ($this->isConditionalConflict($e)) {
                    throw new TransientProviderFailure('Object seal copy conflicted.');
                }

                throw new TransientProviderFailure('Object seal copy failed.');
            }
        }

        throw new TransientProviderFailure('Object seal copy conflicted.');
    }

    /**
     * @return array{0: object, 1: string}|null
     */
    private function nativeCopyClient(): ?array
    {
        $client = null;
        if (method_exists($this->disk, 'getClient')) {
            $resolved = $this->disk->getClient();
            $client = is_object($resolved) ? $resolved : null;
        }

        if ($client === null && method_exists($this->disk, 'getAdapter')) {
            $adapter = $this->disk->getAdapter();
            if (is_object($adapter) && method_exists($adapter, 'getClient')) {
                $resolved = $adapter->getClient();
                $client = is_object($resolved) ? $resolved : null;
            }
        }

        $bucket = '';
        if (method_exists($this->disk, 'getConfig')) {
            $config = $this->disk->getConfig();
            $bucket = is_array($config) ? (string) ($config['bucket'] ?? '') : '';
        }

        // Aws\S3\S3Client implements CopyObject via __call, so method_exists()
        // is false on a real client. is_callable() is the native-capability check.
        if ($bucket === '' || $client === null || ! is_callable([$client, 'copyObject'])) {
            return null;
        }

        return [$client, $bucket];
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

    private function isConditionalConflict(Throwable $e): bool
    {
        $status = method_exists($e, 'getStatusCode') ? (int) $e->getStatusCode() : 0;
        $awsCode = method_exists($e, 'getAwsErrorCode') ? (string) $e->getAwsErrorCode() : '';

        return $status === 409
            || $awsCode === 'ConditionalRequestConflict'
            || str_contains($e->getMessage(), 'ConditionalRequestConflict');
    }

    /**
     * Project provider temporary-upload headers into a client-settable grant.
     * Host is request authority and must be generated by the HTTP stack.
     * Connection and Transfer-Encoding are hop-by-hop and must not be copied.
     * Unfamiliar provider headers are preserved (for example signed x-amz-*).
     *
     * @param  array<array-key, mixed>  $rawHeaders
     * @return array<string, string>
     */
    private function clientSafeUploadHeaders(array $rawHeaders, string $declaredMediaType): array
    {
        $headers = ['Content-Type' => $declaredMediaType];
        foreach ($rawHeaders as $name => $value) {
            if (! is_string($name) || $name === '') {
                continue;
            }
            if (is_array($value)) {
                $value = implode(',', array_map(static fn (mixed $item): string => is_scalar($item) ? (string) $item : '', $value));
            }
            if (! is_string($value) && ! is_int($value)) {
                continue;
            }
            if ($this->isForbiddenUploadGrantHeader($name)) {
                continue;
            }
            $headers[$name] = (string) $value;
        }

        return $headers;
    }

    private function isForbiddenUploadGrantHeader(string $name): bool
    {
        return match (strtolower($name)) {
            'host', 'connection', 'transfer-encoding' => true,
            default => false,
        };
    }
}
