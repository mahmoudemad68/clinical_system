<?php

declare(strict_types=1);

namespace Modules\Platform\Support;

/**
 * Server observation of a stored object. Client Content-Type is not a source
 * for detectedMime.
 *
 * `objectVersion` is a provider version-id when the store exposes one.
 * It is never a SHA-256 content hash. Empty string means "no provider
 * version-id"; immutability is the server-only canonical locator plus
 * the cryptographic hash.
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
