<?php

declare(strict_types=1);

namespace Modules\Verification\Support;

/**
 * Compact pharmacy case-open result. GET verification-status is the canonical
 * projection. This payload must fit the Platform 255-byte idempotency pointer.
 *
 * @phpstan-type OutcomeArray array{
 *     status: string,
 *     organization_id: string,
 *     case_id: string,
 *     case_status: string,
 *     case_version: int,
 *     organization_version: int
 * }
 */
final readonly class PharmacyVerificationCaseOutcome
{
    public const READY = 'ready';

    public function __construct(
        public string $organizationId,
        public string $caseId,
        public string $caseStatus,
        public int $caseVersion,
        public int $organizationVersion,
    ) {}

    /**
     * @return OutcomeArray
     */
    public function toArray(): array
    {
        return [
            'status' => self::READY,
            'organization_id' => $this->organizationId,
            'case_id' => $this->caseId,
            'case_status' => $this->caseStatus,
            'case_version' => $this->caseVersion,
            'organization_version' => $this->organizationVersion,
        ];
    }
}
