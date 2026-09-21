<?php

declare(strict_types=1);

namespace Modules\Verification\Support;

/**
 * Applicant-visible pharmacy case/status projection. No legal name, legal
 * registration, address, phone, coordinates, ciphertext, HMAC, key versions,
 * reviewer notes, scanner internals, storage locators, or document hashes.
 *
 * @phpstan-type DocumentArray array{
 *     document_id: string,
 *     requirement_code: string,
 *     scan_status: string,
 *     status: string,
 *     uploaded_at: string
 * }
 * @phpstan-type ProjectionArray array{
 *     applicant_type: string,
 *     organization_id: string,
 *     organization_verification_status: string,
 *     organization_status: string,
 *     organization_version: int,
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
final readonly class PharmacyApplicantCaseProjection
{
    /**
     * @param  list<DocumentArray>  $documents
     */
    public function __construct(
        public string $organizationId,
        public string $organizationVerificationStatus,
        public string $organizationStatus,
        public int $organizationVersion,
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
            'applicant_type' => 'pharmacy',
            'organization_id' => $this->organizationId,
            'organization_verification_status' => $this->organizationVerificationStatus,
            'organization_status' => $this->organizationStatus,
            'organization_version' => $this->organizationVersion,
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
