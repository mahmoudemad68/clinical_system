<?php

declare(strict_types=1);

namespace Modules\Verification\Support;

/**
 * Applicant-safe upload status. No locator, signed URL, hash, National ID,
 * or scanner payload.
 *
 * @phpstan-type ProjectionArray array{
 *     upload_id: string,
 *     requirement_code: string,
 *     state: string,
 *     rejection_reason: string|null,
 *     expires_at: string,
 *     completed_at: string|null
 * }
 */
final readonly class VerificationUploadProjection
{
    public function __construct(
        public string $uploadId,
        public string $requirementCode,
        public string $state,
        public ?string $rejectionReason,
        public string $expiresAt,
        public ?string $completedAt,
    ) {}

    /**
     * @return ProjectionArray
     */
    public function toArray(): array
    {
        return [
            'upload_id' => $this->uploadId,
            'requirement_code' => $this->requirementCode,
            'state' => $this->state,
            'rejection_reason' => $this->rejectionReason,
            'expires_at' => $this->expiresAt,
            'completed_at' => $this->completedAt,
        ];
    }
}
