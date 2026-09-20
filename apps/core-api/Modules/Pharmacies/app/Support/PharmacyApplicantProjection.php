<?php

declare(strict_types=1);

namespace Modules\Pharmacies\Support;

use Modules\Pharmacies\Enums\PharmacyMembershipStatus;
use Modules\Pharmacies\Enums\PharmacyOrganizationStatus;
use Modules\Pharmacies\Enums\PharmacyVerificationStatus;
use Modules\Platform\Support\Identifier;

/**
 * Cross-module applicant view. Omits protected identity and location material.
 */
final readonly class PharmacyApplicantProjection
{
    public function __construct(
        public Identifier $organizationId,
        public Identifier $userId,
        public Identifier $branchId,
        public Identifier $membershipId,
        public PharmacyVerificationStatus $verificationStatus,
        public PharmacyOrganizationStatus $status,
        public PharmacyMembershipStatus $membershipStatus,
        public int $version,
    ) {}
}
