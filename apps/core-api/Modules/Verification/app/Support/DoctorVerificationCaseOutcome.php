<?php

declare(strict_types=1);

namespace Modules\Verification\Support;

use Modules\Platform\Exceptions\StateConflict;

/**
 * Compact doctor case-open result. GET verification-status is the canonical
 * projection. This payload must fit the Platform 255-byte idempotency pointer.
 *
 * @phpstan-type OutcomeArray array{
 *     status: string,
 *     doctor_id: string,
 *     case_id: string,
 *     case_status: string,
 *     case_version: int,
 *     profile_version: int
 * }
 */
final readonly class DoctorVerificationCaseOutcome
{
    public const READY = 'ready';

    public function __construct(
        public string $doctorId,
        public string $caseId,
        public string $caseStatus,
        public int $caseVersion,
        public int $profileVersion,
    ) {}

    public static function fromApplicantProjection(ApplicantCaseProjection $projection): self
    {
        if ($projection->caseId === null || $projection->caseStatus === null || $projection->caseVersion === null) {
            throw new StateConflict;
        }

        if (! in_array($projection->caseStatus, ['draft', 'pending_review'], true)) {
            throw new StateConflict;
        }

        return new self(
            $projection->doctorId,
            $projection->caseId,
            $projection->caseStatus,
            $projection->caseVersion,
            $projection->profileVersion,
        );
    }

    /**
     * @return OutcomeArray
     */
    public function toArray(): array
    {
        return [
            'status' => self::READY,
            'doctor_id' => $this->doctorId,
            'case_id' => $this->caseId,
            'case_status' => $this->caseStatus,
            'case_version' => $this->caseVersion,
            'profile_version' => $this->profileVersion,
        ];
    }
}
