<?php

declare(strict_types=1);

namespace Modules\Platform\Services\ObjectStorage;

use DateTimeImmutable;
use Modules\Platform\Contracts\StoreObject;
use Modules\Platform\Support\StoredObjectRef;
use RuntimeException;

/** In-memory StoreObject for contract tests. Not a production adapter. */
final class InMemoryStoreObject implements StoreObject
{
    /** @var array<string, array{bytes: string, content_type: string}> */
    private array $objects = [];

    public function put(string $namespace, string $objectId, string $contentType, string $bytes): StoredObjectRef
    {
        $ref = new StoredObjectRef($namespace, $objectId);
        $this->objects[$ref->key()] = [
            'bytes' => $bytes,
            'content_type' => $contentType,
        ];

        return $ref;
    }

    public function exists(StoredObjectRef $ref): bool
    {
        return isset($this->objects[$ref->key()]);
    }

    public function temporaryUrl(StoredObjectRef $ref, DateTimeImmutable $expiresAt): string
    {
        if (! $this->exists($ref)) {
            throw new RuntimeException('Object does not exist.');
        }

        return 'https://objects.invalid/'.$ref->key().'?expires='.$expiresAt->getTimestamp();
    }

    public function metadata(StoredObjectRef $ref): array
    {
        if (! $this->exists($ref)) {
            throw new RuntimeException('Object does not exist.');
        }

        $stored = $this->objects[$ref->key()];

        return [
            'content_type' => $stored['content_type'],
            'size_bytes' => strlen($stored['bytes']),
            'encrypted' => true,
        ];
    }

    public function anonymousGet(StoredObjectRef $ref): never
    {
        throw new RuntimeException('Anonymous access is denied.');
    }
}
