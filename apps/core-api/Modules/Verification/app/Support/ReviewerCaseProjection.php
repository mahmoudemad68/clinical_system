<?php

declare(strict_types=1);

namespace Modules\Verification\Support;

use Modules\Doctors\Support\DoctorReviewerProjection;

/**
 * Reviewer-safe case projection. No National ID, HMAC, object keys, or
 * reviewer notes plaintext.
 *
 * @phpstan-import-type OutcomeArray from VerificationDecisionOutcome
 *
 * @phpstan-type DocumentArray array{
 *     document_id: string,
 *     requirement_code: string,
 *     sha256: string,
 *     detected_mime: string,
 *     size_bytes: int,
 *     scan_status: string,
 *     status: string,
 *     uploaded_at: string
 * }
 * @phpstan-type ProjectionArray array{
 *     case_id: string,
 *     case_type: string,
 *     case_status: string,
 *     case_version: int,
 *     submitted_at: string|null,
 *     assignment: string,
 *     assigned_to_me: bool,
 *     decided_at: string|null,
 *     decision: string|null,
 *     reason_code: string|null,
 *     doctor_id: string,
 *     professional_display_name: string,
 *     specialty: array{
 *         specialty_id: string,
 *         code: string,
 *         label_ar: string,
 *         label_en: string
 *     },
 *     doctor_verification_status: string,
 *     doctor_public_status: string,
 *     profile_version: int,
 *     documents: list<DocumentArray>
 * }
 */
final readonly class ReviewerCaseProjection
{
    /**
     * @param  list<DocumentArray>  $documents
     */
    public function __construct(
        public string $caseId,
        public string $caseType,
        public string $status,
        public int $version,
        public string $doctorId,
        public ?string $submittedAt,
        public ?string $assignedReviewerId,
        public ?string $decidedAt,
        public ?string $decision,
        public ?string $reasonCode,
        public array $documents,
        public bool $assignedToMe,
        public string $assignment,
        public DoctorReviewerProjection $doctor,
    ) {}

    /**
     * HTTP-safe reviewer detail. Omits assigned_reviewer_id.
     *
     * @return ProjectionArray
     */
    public function toArray(): array
    {
        return [
            'case_id' => $this->caseId,
            'case_type' => $this->caseType,
            'case_status' => $this->status,
            'case_version' => $this->version,
            'submitted_at' => $this->submittedAt,
            'assignment' => $this->assignment,
            'assigned_to_me' => $this->assignedToMe,
            'decided_at' => $this->decidedAt,
            'decision' => $this->decision,
            'reason_code' => $this->reasonCode,
            ...$this->doctor->toArray(),
            'documents' => $this->documents,
        ];
    }

    /**
     * @return OutcomeArray
     */
    public function toDecisionOutcome(): array
    {
        return (new VerificationDecisionOutcome(
            $this->caseId,
            $this->status,
            $this->version,
            (string) $this->decision,
            (string) $this->reasonCode,
        ))->toArray();
    }
}
