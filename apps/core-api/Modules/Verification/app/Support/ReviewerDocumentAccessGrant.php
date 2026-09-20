<?php

declare(strict_types=1);

namespace Modules\Verification\Support;

/**
 * Short-lived reviewer read grant. The URL is bearer-style while valid and
 * must never be logged, audited, persisted, or returned from __debugInfo.
 *
 * @phpstan-type GrantArray array{
 *     document_id: string,
 *     url: string,
 *     expires_at: string,
 *     detected_mime: string,
 *     size_bytes: int
 * }
 */
final readonly class ReviewerDocumentAccessGrant
{
    public function __construct(
        public string $documentId,
        public string $url,
        public string $expiresAt,
        public string $detectedMime,
        public int $sizeBytes,
    ) {}

    /**
     * @return GrantArray
     */
    public function toArray(): array
    {
        return [
            'document_id' => $this->documentId,
            'url' => $this->url,
            'expires_at' => $this->expiresAt,
            'detected_mime' => $this->detectedMime,
            'size_bytes' => $this->sizeBytes,
        ];
    }

    /**
     * @return array{document_id: string, expires_at: string, detected_mime: string, size_bytes: int}
     */
    public function __debugInfo(): array
    {
        return [
            'document_id' => $this->documentId,
            'expires_at' => $this->expiresAt,
            'detected_mime' => $this->detectedMime,
            'size_bytes' => $this->sizeBytes,
        ];
    }
}
