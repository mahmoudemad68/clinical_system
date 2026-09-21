<?php

declare(strict_types=1);

namespace Modules\Verification\Support;

/**
 * Reviewer-safe case projection. No National ID, HMAC, object keys, or
 * reviewer notes plaintext. Applicant fields are discriminated by
 * applicant_type.
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
        public string $applicantId,
        public ?string $submittedAt,
        public ?string $assignedReviewerId,
        public ?string $decidedAt,
        public ?string $decision,
        public ?string $reasonCode,
        public array $documents,
        public bool $assignedToMe,
        public string $assignment,
        public ReviewerApplicantProjection $applicant,
    ) {}

    /**
     * HTTP-safe reviewer detail. Omits assigned_reviewer_id.
     *
     * @return array<string, mixed>
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
            ...$this->applicant->toArray(),
            'documents' => $this->documents,
        ];
    }

    /**
     * Compact idempotency pointer. Notes and documents are omitted.
     *
     * @return array{
     *     case_id: string,
     *     case_status: string,
     *     case_version: int,
     *     decision: string,
     *     reason_code: string
     * }
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
