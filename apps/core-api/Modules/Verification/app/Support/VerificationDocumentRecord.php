<?php

declare(strict_types=1);

namespace Modules\Verification\Support;

use DateTimeImmutable;
use Modules\Platform\Support\Identifier;
use Modules\Verification\Enums\VerificationDocumentScanStatus;
use Modules\Verification\Enums\VerificationDocumentStatus;

final readonly class VerificationDocumentRecord
{
    public function __construct(
        public Identifier $id,
        public Identifier $caseId,
        public string $requirementCode,
        public Identifier $objectId,
        public string $sha256,
        public string $detectedMime,
        public int $sizeBytes,
        public VerificationDocumentScanStatus $scanStatus,
        public VerificationDocumentStatus $status,
        public DateTimeImmutable $uploadedAt,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?Identifier $uploadIntentId = null,
    ) {}

    public function isReviewable(): bool
    {
        return $this->status->isReviewable($this->scanStatus);
    }
}
