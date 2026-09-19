<?php

declare(strict_types=1);

namespace Modules\Platform\Contracts;

use DateTimeImmutable;
use Modules\Platform\Support\ObjectUploadGrant;
use Modules\Platform\Support\ObservedObject;
use Modules\Platform\Support\StoredObjectRef;

/**
 * Store a private object. Isolated from scanning, retrieval, and generation.
 *
 * S3 is the original-file source of truth. Objects are private; access is by
 * a bounded signed URL. Anonymous access is denied. Clients never receive
 * permanent credentials or a raw object key as a public identifier.
 */
interface StoreObject
{
    public function put(string $namespace, string $objectId, string $contentType, string $bytes): StoredObjectRef;

    public function exists(StoredObjectRef $ref): bool;

    public function temporaryUrl(StoredObjectRef $ref, DateTimeImmutable $expiresAt): string;

    /**
     * Encryption / storage metadata that is safe to persist. Never the bytes.
     *
     * @return array{content_type: string, size_bytes: int, encrypted: bool}
     */
    public function metadata(StoredObjectRef $ref): array;

    /**
     * Must never succeed. Anonymous access is denied for every implementation.
     */
    public function anonymousGet(StoredObjectRef $ref): never;

    /**
     * Must never succeed. Anonymous listing is denied for every implementation.
     */
    public function anonymousList(): never;

    public function createUploadGrant(
        string $namespace,
        string $objectId,
        int $expectedSizeBytes,
        string $declaredMediaType,
        DateTimeImmutable $expiresAt,
    ): ObjectUploadGrant;

    /**
     * Server-side write to a previously issued locator. Used by tests and
     * internal copies. Not a client authorization path.
     */
    public function writeAt(StoredObjectRef $ref, string $contentType, string $bytes): void;

    public function observe(StoredObjectRef $ref, int $maxBytes): ObservedObject;

    /**
     * @return resource
     */
    public function openStream(StoredObjectRef $ref);

    public function deleteIfPresent(StoredObjectRef $ref): void;
}
