<?php

declare(strict_types=1);

namespace Modules\Verification\Support;

/**
 * Queue row for privileged reviewers. No protected identifiers, documents,
 * hashes, notes, or storage locators. Applicant fields are discriminated by
 * applicant_type.
 */
final readonly class ReviewerQueueItemProjection
{
    public function __construct(
        public string $caseId,
        public string $caseType,
        public string $caseStatus,
        public int $caseVersion,
        public ?string $submittedAt,
        public string $assignment,
        public bool $assignedToMe,
        public ReviewerApplicantProjection $applicant,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'case_id' => $this->caseId,
            'case_type' => $this->caseType,
            'case_status' => $this->caseStatus,
            'case_version' => $this->caseVersion,
            'submitted_at' => $this->submittedAt,
            'assignment' => $this->assignment,
            'assigned_to_me' => $this->assignedToMe,
            ...$this->applicant->toArray(),
        ];
    }
}
