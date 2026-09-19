<?php

declare(strict_types=1);

namespace Modules\Verification\Support;

/**
 * Reviewer-safe case projection. No National ID, HMAC, object keys, or
 * reviewer notes plaintext.
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
 *     status: string,
 *     version: int,
 *     doctor_id: string,
 *     submitted_at: string|null,
 *     assigned_reviewer_id: string|null,
 *     decided_at: string|null,
 *     decision: string|null,
 *     reason_code: string|null,
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
    ) {}

    /**
     * @return ProjectionArray
     */
    public function toArray(): array
    {
        return [
            'case_id' => $this->caseId,
            'case_type' => $this->caseType,
            'status' => $this->status,
            'version' => $this->version,
            'doctor_id' => $this->doctorId,
            'submitted_at' => $this->submittedAt,
            'assigned_reviewer_id' => $this->assignedReviewerId,
            'decided_at' => $this->decidedAt,
            'decision' => $this->decision,
            'reason_code' => $this->reasonCode,
            'documents' => $this->documents,
        ];
    }
}
