<?php

declare(strict_types=1);

namespace Tests\Support;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Platform\Contracts\StoreObject;
use Modules\Platform\Contracts\TransactionRunner;
use Modules\Platform\Exceptions\TransientProviderFailure;
use Modules\Platform\Support\ObjectUploadGrant;
use Modules\Platform\Support\ObservedObject;
use Modules\Platform\Support\StoredObjectRef;
use RuntimeException;

/**
 * Test decorator that records canonical allocation/copy and can inject
 * crash-like failures around the seal copy. Not a production adapter.
 */
final class RecordingStoreObject implements StoreObject
{
    public int $allocateCount = 0;

    public int $copyCount = 0;

    public bool $failBeforeCopy = false;

    public bool $failAfterCopy = false;

    public bool $locatorWasDurableBeforeCopy = false;

    /** @var list<string> */
    public array $allocatedLocators = [];

    /** @var list<string> */
    public array $copiedLocators = [];

    /** @var list<StoredObjectRef> */
    public array $temporaryUrlRefs = [];

    /** @var list<StoredObjectRef> */
    public array $openStreamRefs = [];

    /** @var list<StoredObjectRef> */
    public array $observeRefs = [];

    public int $observeWhileTransactionOpen = 0;

    public ?int $truncateOpenStreamAfterBytes = null;

    public bool $openStreamReadReturnsFalse = false;

    public int $deleteAttempts = 0;

    public int $failNextDeletes = 0;

    public function __construct(private readonly StoreObject $inner) {}

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
        $this->temporaryUrlRefs[] = $ref;

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
        $ref = $this->inner->allocateCanonicalRef($namespace, $objectId);
        $this->allocateCount++;
        $this->allocatedLocators[] = (string) $ref->storageLocator;

        return $ref;
    }

    public function copyExact(StoredObjectRef $source, StoredObjectRef $destination): void
    {
        $locator = (string) $destination->storageLocator;
        $this->locatorWasDurableBeforeCopy = $locator !== ''
            && DB::table('verification_upload_intents')->where('canonical_storage_locator', $locator)->exists();
        if (! $this->locatorWasDurableBeforeCopy) {
            throw new RuntimeException('canonical locator was not durable before copy');
        }
        if ($this->failBeforeCopy) {
            throw new TransientProviderFailure('simulated seal copy failure');
        }

        $this->inner->copyExact($source, $destination);
        $this->copyCount++;
        $this->copiedLocators[] = $locator;

        if ($this->failAfterCopy) {
            throw new TransientProviderFailure('simulated post-copy failure');
        }
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
        $this->observeRefs[] = $ref;
        if (app(TransactionRunner::class)->inTransaction()) {
            $this->observeWhileTransactionOpen++;
        }

        return $this->inner->observe($ref, $maxBytes);
    }

    /**
     * @return resource
     */
    public function openStream(StoredObjectRef $ref)
    {
        $this->openStreamRefs[] = $ref;
        $stream = $this->inner->openStream($ref);
        if ($this->truncateOpenStreamAfterBytes === null) {
            return $stream;
        }

        return ClinicTruncatingReadStreamWrapper::wrap(
            $stream,
            $this->truncateOpenStreamAfterBytes,
            $this->openStreamReadReturnsFalse,
        );
    }

    public function deleteIfPresent(StoredObjectRef $ref): void
    {
        $this->deleteAttempts++;
        if ($this->failNextDeletes > 0) {
            $this->failNextDeletes--;

            throw new TransientProviderFailure('simulated object delete failure');
        }

        $this->inner->deleteIfPresent($ref);
    }
}
