<?php

declare(strict_types=1);

namespace Modules\Verification\Support;

use DateTimeImmutable;
use Modules\Platform\Support\Identifier;
use Modules\Platform\Support\StoredObjectRef;
use Modules\Verification\Enums\VerificationUploadState;

final readonly class VerificationUploadIntentRecord
{
    public function __construct(
        public Identifier $id,
        public Identifier $caseId,
        public Identifier $createdByUserId,
        public string $requirementCode,
        public Identifier $objectId,
        public string $storageLocator,
        public VerificationUploadState $state,
        public int $expectedSizeBytes,
        public string $declaredMediaType,
        public ?string $expectedSha256,
        public ?string $objectVersion,
        public ?int $observedSizeBytes,
        public ?string $observedSha256,
        public ?string $detectedMime,
        public ?string $scannerIdentity,
        public ?string $scannerVersion,
        public ?string $rejectionReason,
        public DateTimeImmutable $expiresAt,
        public ?DateTimeImmutable $completedAt,
        public ?DateTimeImmutable $availableAt,
        public ?DateTimeImmutable $cleanupEligibleAt,
        public int $processingAttempts,
        public int $version,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}

    public function storedRef(): StoredObjectRef
    {
        return new StoredObjectRef(
            'verification',
            $this->objectId->value,
            $this->storageLocator,
        );
    }
}
