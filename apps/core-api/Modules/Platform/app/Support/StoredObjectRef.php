<?php

declare(strict_types=1);

namespace Modules\Platform\Support;

use Modules\Platform\Exceptions\InvalidValueObject;

/**
 * A reference to a private stored object. Not a URL, not a filesystem path.
 *
 * `objectId` is the opaque application identifier. `storageLocator` is the
 * classified internal key used only by StoreObject adapters.
 */
final readonly class StoredObjectRef
{
    public function __construct(
        public string $namespace,
        public string $objectId,
        public ?string $storageLocator = null,
    ) {
        if (! preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $namespace)) {
            throw new InvalidValueObject('Object namespace is not a valid slug.');
        }

        if (! preg_match('/^[A-Za-z0-9._-]{1,128}$/', $objectId)) {
            throw new InvalidValueObject('Object identifier is malformed.');
        }

        if ($storageLocator !== null) {
            if (str_contains($storageLocator, '..') || str_contains($storageLocator, '//') || str_starts_with($storageLocator, '/')) {
                throw new InvalidValueObject('Object locator is malformed.');
            }
            if (preg_match('/^[a-z][a-z0-9_\/.-]{1,200}$/', $storageLocator) !== 1) {
                throw new InvalidValueObject('Object locator is malformed.');
            }
        }
    }

    public function key(): string
    {
        return $this->storageLocator ?? ($this->namespace.'/'.$this->objectId);
    }

    /**
     * @return array{namespace: string, objectId: string}
     */
    public function __debugInfo(): array
    {
        return [
            'namespace' => $this->namespace,
            'objectId' => $this->objectId,
        ];
    }
}
