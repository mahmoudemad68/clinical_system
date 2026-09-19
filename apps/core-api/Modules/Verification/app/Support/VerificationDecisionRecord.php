<?php

declare(strict_types=1);

namespace Modules\Verification\Support;

use DateTimeImmutable;
use Modules\Platform\Support\Identifier;
use Modules\Verification\Enums\VerificationDecision;

final readonly class VerificationDecisionRecord
{
    public function __construct(
        public Identifier $id,
        public Identifier $caseId,
        public VerificationDecision $decision,
        public string $reasonCode,
        public Identifier $reviewerId,
        public string $reviewerAssuranceLevel,
        public ?string $notesCiphertext,
        public DateTimeImmutable $createdAt,
    ) {}

    public function matches(VerificationDecision $decision, string $reasonCode, Identifier $reviewerId): bool
    {
        return $this->decision === $decision
            && $this->reasonCode === $reasonCode
            && $this->reviewerId->equals($reviewerId);
    }
}
