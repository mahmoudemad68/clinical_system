<?php

declare(strict_types=1);

namespace Modules\Platform\Support;

/**
 * Server observation of an immutable stored object. Client Content-Type is
 * not a source for detectedMime.
 */
final readonly class ObservedObject
{
    public function __construct(
        public bool $exists,
        public int $sizeBytes,
        public string $sha256,
        public ?string $detectedMime,
        public string $objectVersion,
    ) {}
}
