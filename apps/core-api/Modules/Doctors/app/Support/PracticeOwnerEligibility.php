<?php

declare(strict_types=1);

namespace Modules\Doctors\Support;

use Modules\Doctors\Enums\DoctorVerificationStatus;
use Modules\Platform\Support\Identifier;

/**
 * Narrow practice-owner eligibility view for Clinics. Exposes only
 * doctor identity, owning user, verification status, and profile version.
 */
final readonly class PracticeOwnerEligibility
{
    public function __construct(
        public Identifier $doctorId,
        public Identifier $userId,
        public DoctorVerificationStatus $verificationStatus,
        public int $version,
    ) {}

    public function isApproved(): bool
    {
        return $this->verificationStatus === DoctorVerificationStatus::Approved;
    }
}
