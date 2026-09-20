<?php

declare(strict_types=1);

namespace Modules\Pharmacies\Services;

use Modules\Pharmacies\Services\Persistence\PostgresPharmacyOrganizationStore;
use Modules\Pharmacies\Support\PharmacyApplicantProjection;
use Modules\Pharmacies\Support\PharmacyBranchRecord;
use Modules\Pharmacies\Support\PharmacyMembershipRecord;
use Modules\Pharmacies\Support\PharmacyOrganizationRecord;
use Modules\Platform\Support\Identifier;

/**
 * Narrow applicant surface for a later Verification chunk. Does not expose
 * protected identity material, does not write verification tables, and does
 * not transition organization status in this slice.
 */
final class PharmacyApplicantService
{
    public function __construct(
        private readonly PostgresPharmacyOrganizationStore $store,
    ) {}

    public function findByUserId(Identifier $userId, bool $lock = false): ?PharmacyApplicantProjection
    {
        $membership = $this->store->findOwnerMembershipByUserId($userId, $lock);
        if (! $membership instanceof PharmacyMembershipRecord) {
            return null;
        }

        return $this->projectMembership($membership, $lock);
    }

    public function findById(Identifier $organizationId, bool $lock = false): ?PharmacyApplicantProjection
    {
        $organization = $this->store->findOrganizationById($organizationId, $lock);
        if (! $organization instanceof PharmacyOrganizationRecord) {
            return null;
        }

        $membership = $this->store->findOwnerMembershipByOrganizationId($organization->id, $lock);
        if (! $membership instanceof PharmacyMembershipRecord) {
            return null;
        }

        return $this->project($organization, $membership, $lock);
    }

    private function projectMembership(PharmacyMembershipRecord $membership, bool $lock): ?PharmacyApplicantProjection
    {
        $organization = $this->store->findOrganizationById($membership->organizationId, $lock);
        if (! $organization instanceof PharmacyOrganizationRecord) {
            return null;
        }

        return $this->project($organization, $membership, $lock);
    }

    private function project(
        PharmacyOrganizationRecord $organization,
        PharmacyMembershipRecord $membership,
        bool $lock,
    ): ?PharmacyApplicantProjection {
        $branch = $this->store->findInitialBranch($organization->id, $lock);
        if (! $branch instanceof PharmacyBranchRecord) {
            return null;
        }

        return new PharmacyApplicantProjection(
            $organization->id,
            $membership->userId,
            $branch->id,
            $membership->id,
            $organization->verificationStatus,
            $organization->status,
            $membership->status,
            $organization->version,
        );
    }
}
