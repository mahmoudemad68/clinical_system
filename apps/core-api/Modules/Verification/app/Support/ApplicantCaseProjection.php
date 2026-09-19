<?php

declare(strict_types=1);

namespace Modules\Verification\Support;

/**
 * Applicant-visible case/status projection. No National ID, HMAC, object
 * keys, reviewer notes, or clinical fields.
 *
 * @phpstan-type DocumentArray array{
 *     document_id: string,
 *     requirement_code: string,
 *     scan_status: string,
 *     status: string,
 *     uploaded_at: string
 * }
 * @phpstan-type ProjectionArray array{
 *     doctor_id: string,
 *     profile_verification_status: string,
 *     profile_public_status: string,
 *     profile_version: int,
 *     case_id: string|null,
 *     case_status: string|null,
 *     case_version: int|null,
 *     case_type: string|null,
 *     submitted_at: string|null,
 *     decided_at: string|null,
 *     decision: string|null,
 *     reason_code: string|null,
 *     documents: list<DocumentArray>
 * }
 */
final readonly class ApplicantCaseProjection
{
    /**
     * @param  list<DocumentArray>  $documents
     */
    public function __construct(
        public string $doctorId,
        public string $profileVerificationStatus,
        public string $profilePublicStatus,
        public int $profileVersion,
        public ?string $caseId,
        public ?string $caseStatus,
        public ?int $caseVersion,
        public ?string $caseType,
        public ?string $submittedAt,
        public ?string $decidedAt,
        public ?string $decision,
        public ?string $reasonCode,
        public array $documents,
    ) {}

    /**
     * @return ProjectionArray
     */
    public function toArray(): array
    {
        return [
            'doctor_id' => $this->doctorId,
            'profile_verification_status' => $this->profileVerificationStatus,
            'profile_public_status' => $this->profilePublicStatus,
            'profile_version' => $this->profileVersion,
            'case_id' => $this->caseId,
            'case_status' => $this->caseStatus,
            'case_version' => $this->caseVersion,
            'case_type' => $this->caseType,
            'submitted_at' => $this->submittedAt,
            'decided_at' => $this->decidedAt,
            'decision' => $this->decision,
            'reason_code' => $this->reasonCode,
            'documents' => $this->documents,
        ];
    }
}
