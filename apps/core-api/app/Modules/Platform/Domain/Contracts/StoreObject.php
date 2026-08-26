<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Contracts;

use App\Modules\Platform\Domain\ValueObjects\StoredObjectRef;
use DateTimeImmutable;

/**
 * Store a private object. Isolated from scanning, retrieval, and generation.
 *
 * S3 is the original-file source of truth. Objects are private; access is by
 * a bounded signed URL. Anonymous access is denied.
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
}
