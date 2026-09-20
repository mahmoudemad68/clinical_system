<?php

declare(strict_types=1);

namespace Modules\Verification\Support;

/**
 * Authorized canonical byte stream for a reviewer download. The storage
 * locator never leaves the issuing service. expectedBytes is the persisted
 * trusted size and the authoritative Content-Length.
 */
final readonly class ReviewerDocumentStream
{
    /**
     * @param  resource  $stream
     */
    public function __construct(
        public mixed $stream,
        public string $detectedMime,
        public string $filename,
        public int $expectedBytes,
        public int $chunkBytes,
    ) {}
}
