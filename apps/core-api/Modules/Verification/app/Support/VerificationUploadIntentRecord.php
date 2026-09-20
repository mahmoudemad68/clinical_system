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
        public ?string $canonicalStorageLocator,
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
        public ?DateTimeImmutable $cleanupCompletedAt,
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

    public function canonicalRef(): ?StoredObjectRef
    {
        if ($this->canonicalStorageLocator === null || $this->canonicalStorageLocator === '') {
            return null;
        }

        return new StoredObjectRef(
            'verification',
            $this->objectId->value,
            $this->canonicalStorageLocator,
        );
    }

    /**
     * Trusted bytes after seal. Scan, inspect, promote, and later reviewer
     * access must use this locator, never the client-writable ingress.
     */
    public function trustedRef(): StoredObjectRef
    {
        $canonical = $this->canonicalRef();
        if (! $canonical instanceof StoredObjectRef) {
            throw new \RuntimeException('Canonical object has not been sealed.');
        }

        return $canonical;
    }

    /**
     * @return list<StoredObjectRef>
     */
    public function storageRefs(): array
    {
        $refs = [$this->storedRef()];
        $canonical = $this->canonicalRef();
        if ($canonical instanceof StoredObjectRef) {
            $refs[] = $canonical;
        }

        return $refs;
    }
}
