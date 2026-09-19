<?php

declare(strict_types=1);

namespace Modules\Verification\Support;

/**
 * Review-safe document metadata. object_id is never included.
 *
 * @phpstan-type ProjectionArray array{
 *     document_id: string,
 *     case_id: string,
 *     requirement_code: string,
 *     sha256: string,
 *     detected_mime: string,
 *     size_bytes: int,
 *     scan_status: string,
 *     status: string,
 *     uploaded_at: string
 * }
 */
final readonly class DocumentMetadataProjection
{
    public function __construct(
        public string $documentId,
        public string $caseId,
        public string $requirementCode,
        public string $sha256,
        public string $detectedMime,
        public int $sizeBytes,
        public string $scanStatus,
        public string $status,
        public string $uploadedAt,
    ) {}

    /**
     * @return ProjectionArray
     */
    public function toArray(): array
    {
        return [
            'document_id' => $this->documentId,
            'case_id' => $this->caseId,
            'requirement_code' => $this->requirementCode,
            'sha256' => $this->sha256,
            'detected_mime' => $this->detectedMime,
            'size_bytes' => $this->sizeBytes,
            'scan_status' => $this->scanStatus,
            'status' => $this->status,
            'uploaded_at' => $this->uploadedAt,
        ];
    }
}
