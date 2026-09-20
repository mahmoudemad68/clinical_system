<?php

declare(strict_types=1);

namespace Modules\Verification\Support;

/**
 * Compact decision result sized for the Platform 255-byte idempotency pointer.
 * GET case detail is the canonical reviewer projection.
 *
 * @phpstan-type OutcomeArray array{
 *     case_id: string,
 *     case_status: string,
 *     case_version: int,
 *     decision: string,
 *     reason_code: string
 * }
 */
final readonly class VerificationDecisionOutcome
{
    public function __construct(
        public string $caseId,
        public string $caseStatus,
        public int $caseVersion,
        public string $decision,
        public string $reasonCode,
    ) {}

    /**
     * @return OutcomeArray
     */
    public function toArray(): array
    {
        return [
            'case_id' => $this->caseId,
            'case_status' => $this->caseStatus,
            'case_version' => $this->caseVersion,
            'decision' => $this->decision,
            'reason_code' => $this->reasonCode,
        ];
    }
}
