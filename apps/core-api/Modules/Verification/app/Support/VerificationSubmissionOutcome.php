<?php

declare(strict_types=1);

namespace Modules\Verification\Support;

/**
 * Compact submit result. GET /doctors/me/verification-status is the canonical
 * projection. This payload must fit the Platform 255-byte idempotency pointer.
 *
 * @phpstan-type OutcomeArray array{
 *     status: string,
 *     doctor_id: string,
 *     case_id: string,
 *     case_status: string,
 *     case_version: int,
 *     profile_version: int,
 *     profile_verification_status: string
 * }
 */
final readonly class VerificationSubmissionOutcome
{
    public const SUBMITTED = 'submitted';

    public function __construct(
        public string $doctorId,
        public string $caseId,
        public string $caseStatus,
        public int $caseVersion,
        public int $profileVersion,
        public string $profileVerificationStatus,
    ) {}

    /**
     * @return OutcomeArray
     */
    public function toArray(): array
    {
        return [
            'status' => self::SUBMITTED,
            'doctor_id' => $this->doctorId,
            'case_id' => $this->caseId,
            'case_status' => $this->caseStatus,
            'case_version' => $this->caseVersion,
            'profile_version' => $this->profileVersion,
            'profile_verification_status' => $this->profileVerificationStatus,
        ];
    }
}
