<?php

declare(strict_types=1);

namespace Modules\Identity\Support;

use Modules\Identity\Enums\AssuranceLevel;
use Modules\Platform\Support\Identifier;

/**
 * Internal claim-ceremony result. HTTP still sees only the generic onboarding
 * contract: profile_ready or manual_review_required.
 */
final readonly class ProfileClaimOutcome
{
    public const LINKED = 'linked';

    public const MANUAL_REVIEW = 'manual_review_required';

    public function __construct(
        public string $status,
        public ?Identifier $patientId = null,
        public string $assuranceLevel = AssuranceLevel::Ial2ProofPending->value,
        public bool $lockoutIssued = false,
    ) {}

    public static function linked(Identifier $patientId): self
    {
        return new self(self::LINKED, $patientId, AssuranceLevel::Ial2VerifiedLink->value);
    }

    public static function manualReview(string $assuranceLevel = AssuranceLevel::Ial2ProofPending->value, bool $lockoutIssued = false): self
    {
        return new self(self::MANUAL_REVIEW, null, $assuranceLevel, $lockoutIssued);
    }

    public function isLinked(): bool
    {
        return $this->status === self::LINKED;
    }
}
