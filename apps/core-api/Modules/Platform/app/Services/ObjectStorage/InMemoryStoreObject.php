<?php

declare(strict_types=1);

namespace Modules\Platform\Services\ObjectStorage;

use DateTimeImmutable;
use Illuminate\Filesystem\Filesystem;
use Modules\Platform\Contracts\StoreObject;
use Modules\Platform\Exceptions\InvalidValueObject;
use Modules\Platform\Support\BoundedDocumentInspector;
use Modules\Platform\Support\ObjectUploadGrant;
use Modules\Platform\Support\ObservedObject;
use Modules\Platform\Support\StoredObjectRef;
use RuntimeException;

/**
 * In-memory StoreObject for contract tests. Not a production adapter.
 *
 * When a persist directory is set, bytes are also written under a hashed
 * filename so a second PHP process in the same test run can observe them.
 * The filename is never a user-supplied path.
 */
final class InMemoryStoreObject implements StoreObject
{
    /** @var array<string, array{bytes: string, content_type: string}> */
    private array $objects = [];

    public function __construct(
        private readonly int $maxBytes = 20_971_520,
        private readonly ?string $persistDirectory = null,
    ) {
        if ($this->persistDirectory !== null && ! is_dir($this->persistDirectory)) {
            mkdir($this->persistDirectory, 0700, true);
        }
    }

    public function put(string $namespace, string $objectId, string $contentType, string $bytes): StoredObjectRef
    {
        $ref = new StoredObjectRef($namespace, $objectId);
        $this->writeAt($ref, $contentType, $bytes);

        return $ref;
    }

    public function exists(StoredObjectRef $ref): bool
    {
        if (isset($this->objects[$ref->key()])) {
            return true;
        }

        $path = $this->path($ref);

        return $path !== null && is_file($path);
    }

    public function temporaryUrl(StoredObjectRef $ref, DateTimeImmutable $expiresAt): string
    {
        if (! $this->exists($ref)) {
            throw new RuntimeException('Object does not exist.');
        }

        return 'https://objects.invalid/'.$ref->objectId.'?expires='.$expiresAt->getTimestamp();
    }

    public function metadata(StoredObjectRef $ref): array
    {
        $stored = $this->read($ref);

        return [
            'content_type' => $stored['content_type'],
            'size_bytes' => strlen($stored['bytes']),
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

        if (! str_contains($ref->key(), '/q/') || str_contains($ref->key(), '/c/')) {
            throw new InvalidValueObject('Upload grants are only issued for ingress locators.');
        }

        return new ObjectUploadGrant(
            $ref->objectId,
            (string) $ref->storageLocator,
            'PUT',
            'https://objects.invalid/upload/'.$ref->objectId.'?expires='.$expiresAt->getTimestamp(),
            [
                'Content-Type' => $declaredMediaType,
                'Content-Length' => (string) $expectedSizeBytes,
            ],
            $expiresAt,
        );
    }

    public function allocateCanonicalRef(string $namespace, string $objectId): StoredObjectRef
    {
        return new StoredObjectRef($namespace, $objectId, $namespace.'/c/'.bin2hex(random_bytes(16)));
    }

    public function copyExact(StoredObjectRef $source, StoredObjectRef $destination): void
    {
        $stored = $this->read($source);
        if ($this->exists($destination)) {
            throw new InvalidValueObject('Canonical locator is already occupied.');
        }

        $this->writeAt($destination, $stored['content_type'], $stored['bytes']);
    }

    public function providerVersionId(StoredObjectRef $ref): ?string
    {
        unset($ref);

        return null;
    }

    public function writeAt(StoredObjectRef $ref, string $contentType, string $bytes): void
    {
        if (strlen($bytes) > $this->maxBytes) {
            throw new InvalidValueObject('Object exceeds the configured size bound.');
        }

        $this->objects[$ref->key()] = [
            'bytes' => $bytes,
            'content_type' => $contentType,
        ];

        $path = $this->path($ref);
        if ($path !== null) {
            file_put_contents($path, $bytes);
            file_put_contents($path.'.type', $contentType);
        }
    }

    public function observe(StoredObjectRef $ref, int $maxBytes): ObservedObject
    {
        $stream = $this->openStream($ref);

        try {
            $observed = (new BoundedDocumentInspector)->hashAndSize($stream, $maxBytes);
        } finally {
            fclose($stream);
        }

        return new ObservedObject(
            $observed->exists,
            $observed->sizeBytes,
            $observed->sha256,
            $observed->detectedMime,
            '',
        );
    }

    /**
     * @return resource
     */
    public function openStream(StoredObjectRef $ref)
    {
        $stored = $this->read($ref);
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new RuntimeException('Object stream could not be opened.');
        }

        fwrite($stream, $stored['bytes']);
        rewind($stream);

        return $stream;
    }

    public function deleteIfPresent(StoredObjectRef $ref): void
    {
        unset($this->objects[$ref->key()]);
        $this->deletePersisted($ref);
    }

    /**
     * @return array{bytes: string, content_type: string}
     */
    private function read(StoredObjectRef $ref): array
    {
        if (isset($this->objects[$ref->key()])) {
            return $this->objects[$ref->key()];
        }

        $path = $this->path($ref);
        if ($path === null || ! is_file($path)) {
            throw new RuntimeException('Object does not exist.');
        }

        $bytes = file_get_contents($path);
        if (! is_string($bytes)) {
            throw new RuntimeException('Object does not exist.');
        }

        $type = is_file($path.'.type') ? (string) file_get_contents($path.'.type') : 'application/octet-stream';
        $stored = ['bytes' => $bytes, 'content_type' => $type];
        $this->objects[$ref->key()] = $stored;

        return $stored;
    }

    private function path(StoredObjectRef $ref): ?string
    {
        if ($this->persistDirectory === null) {
            return null;
        }

        return $this->persistDirectory.DIRECTORY_SEPARATOR.hash('sha256', $ref->key());
    }

    /**
     * Persist filenames are SHA-256 hex of the internal object key. The
     * basename is allowlisted before deletion so a StoredObjectRef cannot
     * become a filesystem path.
     */
    private function deletePersisted(StoredObjectRef $ref): void
    {
        $directory = $this->persistDirectory;
        if ($directory === null || ! is_dir($directory)) {
            return;
        }

        $name = hash('sha256', $ref->key());
        if (preg_match('/^[a-f0-9]{64}$/', $name) !== 1) {
            return;
        }

        (new Filesystem)->delete([
            $directory.DIRECTORY_SEPARATOR.$name,
            $directory.DIRECTORY_SEPARATOR.$name.'.type',
        ]);
    }
}
