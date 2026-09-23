<?php

declare(strict_types=1);

namespace Modules\Verification\Support;

use Modules\Doctors\Support\AdminCreatedDoctorResult;

/**
 * Compact Admin-created doctor + case result. Must fit the 255-byte
 * idempotency pointer.
 *
 * @phpstan-type OutcomeArray array{
 *     status: string,
 *     doctor_id?: string,
 *     profile_version?: int,
 *     case_id?: string,
 *     case_version?: int,
 *     case_status?: string
 * }
 */
final readonly class AdminDoctorApplicantOutcome
{
    public function __construct(
        public string $status,
        public ?string $doctorId = null,
        public ?int $profileVersion = null,
        public ?string $caseId = null,
        public ?int $caseVersion = null,
        public ?string $caseStatus = null,
    ) {}

    public static function fromCreated(AdminCreatedDoctorResult $created, ApplicantCaseProjection $opened): self
    {
        if ($created->status === AdminCreatedDoctorResult::MANUAL_REVIEW_REQUIRED || $created->doctorId === null) {
            return new self(AdminCreatedDoctorResult::MANUAL_REVIEW_REQUIRED);
        }

        if ($opened->caseId === null || $opened->caseVersion === null || $opened->caseStatus === null) {
            return new self(AdminCreatedDoctorResult::MANUAL_REVIEW_REQUIRED);
        }

        return new self(
            $created->status,
            $created->doctorId,
            $created->version ?? $opened->profileVersion,
            $opened->caseId,
            $opened->caseVersion,
            $opened->caseStatus,
        );
    }

    /**
     * @return OutcomeArray
     */
    public function toArray(): array
    {
        if ($this->status === AdminCreatedDoctorResult::MANUAL_REVIEW_REQUIRED) {
            return ['status' => $this->status];
        }

        return [
            'status' => $this->status,
            'doctor_id' => $this->doctorId,
            'profile_version' => $this->profileVersion,
            'case_id' => $this->caseId,
            'case_version' => $this->caseVersion,
            'case_status' => $this->caseStatus,
        ];
    }
}
