<?php

declare(strict_types=1);

namespace Modules\Verification\Support;

use Modules\Doctors\Support\DoctorReviewerProjection;

/**
 * Queue row for privileged reviewers. No protected identifiers, documents,
 * hashes, notes, or storage locators.
 *
 * @phpstan-type QueueItemArray array{
 *     case_id: string,
 *     case_type: string,
 *     case_status: string,
 *     case_version: int,
 *     submitted_at: string|null,
 *     assignment: string,
 *     assigned_to_me: bool,
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
 *     profile_version: int
 * }
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
        public DoctorReviewerProjection $doctor,
    ) {}

    /**
     * @return QueueItemArray
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
            ...$this->doctor->toArray(),
        ];
    }
}
