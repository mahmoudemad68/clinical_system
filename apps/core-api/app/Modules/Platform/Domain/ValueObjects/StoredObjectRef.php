<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\ValueObjects;

use App\Modules\Platform\Domain\Exceptions\InvalidValueObject;

/**
 * A reference to a private stored object. Not a URL, not a filesystem path.
 */
final readonly class StoredObjectRef
{
    public function __construct(
        public string $namespace,
        public string $objectId,
    ) {
        if (! preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $namespace)) {
            throw new InvalidValueObject('Object namespace is not a valid slug.');
        }

        if (! preg_match('/^[A-Za-z0-9._-]{1,128}$/', $objectId)) {
            throw new InvalidValueObject('Object identifier is malformed.');
        }
    }

    public function key(): string
    {
        return $this->namespace.'/'.$this->objectId;
    }
}
