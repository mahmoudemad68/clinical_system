<?php

declare(strict_types=1);

namespace Modules\Verification\Support;

/**
 * Authorized canonical byte stream for a reviewer download. The storage
 * locator never leaves the issuing service.
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
        public int $maxBytes,
        public int $chunkBytes,
    ) {}
}
