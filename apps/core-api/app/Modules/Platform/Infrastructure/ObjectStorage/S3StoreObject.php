<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\ObjectStorage;

use App\Modules\Platform\Domain\Contracts\StoreObject;
use App\Modules\Platform\Domain\ValueObjects\StoredObjectRef;
use DateTimeImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use RuntimeException;

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
        private readonly int $maxBytes = 5_242_880,
    ) {}

    public function put(string $namespace, string $objectId, string $contentType, string $bytes): StoredObjectRef
    {
        if (strlen($bytes) > $this->maxBytes) {
            throw new RuntimeException('Object exceeds the Phase 00 size bound.');
        }

        if (! preg_match('/^[a-z0-9][a-z0-9.+\/-]{0,126}[a-z0-9]$/', $contentType)) {
            throw new RuntimeException('Object content type is not an allowed media type.');
        }

        $ref = new StoredObjectRef($namespace, $objectId);

        $this->disk->put($ref->key(), $bytes, [
            'visibility' => 'private',
            'ContentType' => $contentType,
            'Metadata' => [
                'clinic-encrypted' => 'true',
                'clinic-namespace' => $namespace,
            ],
        ]);

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
        throw new RuntimeException('Anonymous access is denied.');
    }
}
