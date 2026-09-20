<?php

declare(strict_types=1);

namespace Tests\Support;

use DateTimeImmutable;
use Modules\Platform\Contracts\StoreObject;
use Modules\Platform\Support\ObjectUploadGrant;
use Modules\Platform\Support\ObservedObject;
use Modules\Platform\Support\StoredObjectRef;

/**
 * Test adapter: observation reports the real canonical object, then openStream
 * truncates or fails so a GET cannot complete a valid-looking document.
 */
final class ObserveThenFailStoreObject implements StoreObject
{
    public function __construct(
        private readonly StoreObject $inner,
        private readonly int $truncateAfterBytes,
        private readonly bool $freadReturnsFalse = true,
    ) {}

    public function put(string $namespace, string $objectId, string $contentType, string $bytes): StoredObjectRef
    {
        return $this->inner->put($namespace, $objectId, $contentType, $bytes);
    }

    public function exists(StoredObjectRef $ref): bool
    {
        return $this->inner->exists($ref);
    }

    public function temporaryUrl(StoredObjectRef $ref, DateTimeImmutable $expiresAt): string
    {
        return $this->inner->temporaryUrl($ref, $expiresAt);
    }

    public function metadata(StoredObjectRef $ref): array
    {
        return $this->inner->metadata($ref);
    }

    public function anonymousGet(StoredObjectRef $ref): never
    {
        $this->inner->anonymousGet($ref);
    }

    public function anonymousList(): never
    {
        $this->inner->anonymousList();
    }

    public function createUploadGrant(
        string $namespace,
        string $objectId,
        int $expectedSizeBytes,
        string $declaredMediaType,
        DateTimeImmutable $expiresAt,
    ): ObjectUploadGrant {
        return $this->inner->createUploadGrant($namespace, $objectId, $expectedSizeBytes, $declaredMediaType, $expiresAt);
    }

    public function issueUploadGrant(
        StoredObjectRef $ref,
        int $expectedSizeBytes,
        string $declaredMediaType,
        DateTimeImmutable $expiresAt,
    ): ObjectUploadGrant {
        return $this->inner->issueUploadGrant($ref, $expectedSizeBytes, $declaredMediaType, $expiresAt);
    }

    public function allocateCanonicalRef(string $namespace, string $objectId): StoredObjectRef
    {
        return $this->inner->allocateCanonicalRef($namespace, $objectId);
    }

    public function copyExact(StoredObjectRef $source, StoredObjectRef $destination): void
    {
        $this->inner->copyExact($source, $destination);
    }

    public function providerVersionId(StoredObjectRef $ref): ?string
    {
        return $this->inner->providerVersionId($ref);
    }

    public function writeAt(StoredObjectRef $ref, string $contentType, string $bytes): void
    {
        $this->inner->writeAt($ref, $contentType, $bytes);
    }

    public function observe(StoredObjectRef $ref, int $maxBytes): ObservedObject
    {
        return $this->inner->observe($ref, $maxBytes);
    }

    /**
     * @return resource
     */
    public function openStream(StoredObjectRef $ref)
    {
        return ClinicTruncatingReadStreamWrapper::wrap(
            $this->inner->openStream($ref),
            $this->truncateAfterBytes,
            $this->freadReturnsFalse,
        );
    }

    public function deleteIfPresent(StoredObjectRef $ref): void
    {
        $this->inner->deleteIfPresent($ref);
    }
}
