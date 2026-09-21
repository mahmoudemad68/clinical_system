<?php

declare(strict_types=1);

namespace Modules\Pharmacies\Services;

use DateTimeImmutable;
use Modules\Pharmacies\Enums\PharmacyBranchStatus;
use Modules\Pharmacies\Enums\PharmacyMembershipStatus;
use Modules\Pharmacies\Enums\PharmacyOrganizationStatus;
use Modules\Pharmacies\Enums\PharmacyVerificationStatus;
use Modules\Pharmacies\Services\Persistence\PostgresPharmacyOrganizationStore;
use Modules\Pharmacies\Support\PharmacyApplicantProjection;
use Modules\Pharmacies\Support\PharmacyBranchRecord;
use Modules\Pharmacies\Support\PharmacyMembershipRecord;
use Modules\Pharmacies\Support\PharmacyOrganizationRecord;
use Modules\Platform\Exceptions\StateConflict;
use Modules\Platform\Exceptions\VersionConflict;
use Modules\Platform\Support\Identifier;

/**
 * Narrow applicant surface for Verification. Does not expose protected
 * identity material and never writes verification tables.
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
        $membership = $this->store->findOwnerMembershipByOrganizationId($organizationId, $lock);
        if (! $membership instanceof PharmacyMembershipRecord) {
            return null;
        }

        return $this->projectMembership($membership, $lock);
    }

    public function transition(
        Identifier $organizationId,
        PharmacyVerificationStatus $target,
        int $expectedOrganizationVersion,
        DateTimeImmutable $now,
    ): PharmacyApplicantProjection {
        $membership = $this->store->findOwnerMembershipByOrganizationId($organizationId, true);
        if (! $membership instanceof PharmacyMembershipRecord) {
            throw new StateConflict;
        }

        $organization = $this->store->findOrganizationById($organizationId, true);
        if (! $organization instanceof PharmacyOrganizationRecord) {
            throw new StateConflict;
        }

        $branch = $this->store->findInitialBranch($organization->id, true);
        if (! $branch instanceof PharmacyBranchRecord) {
            throw new StateConflict;
        }

        if ($organization->version !== $expectedOrganizationVersion) {
            throw new VersionConflict;
        }

        if (! $organization->verificationStatus->canTransitionTo($target)) {
            throw new StateConflict;
        }

        $organizationStatus = $this->organizationStatusFor($target);
        $branchStatus = $this->branchStatusFor($target);
        $membershipStatus = $this->membershipStatusFor($target);
        $this->assertNonOperationalUnlessApproved($target, $organizationStatus, $branchStatus, $membershipStatus);

        $stamp = $now->format('Y-m-d H:i:s.uP');

        $affected = $this->store->updateOrganization($organization->id, $expectedOrganizationVersion, [
            'verification_status' => $target->value,
            'status' => $organizationStatus->value,
            'version' => $expectedOrganizationVersion + 1,
            'updated_at' => $stamp,
        ]);
        if ($affected !== 1) {
            throw new VersionConflict;
        }

        $affected = $this->store->updateBranch($branch->id, $branch->version, [
            'status' => $branchStatus->value,
            'version' => $branch->version + 1,
            'updated_at' => $stamp,
        ]);
        if ($affected !== 1) {
            throw new VersionConflict;
        }

        $affected = $this->store->updateMembership($membership->id, $membership->version, [
            'status' => $membershipStatus->value,
            'version' => $membership->version + 1,
            'updated_at' => $stamp,
        ]);
        if ($affected !== 1) {
            throw new VersionConflict;
        }

        $fresh = $this->project($organization->id, true);
        if (! $fresh instanceof PharmacyApplicantProjection) {
            throw new StateConflict;
        }

        return $fresh;
    }

    private function organizationStatusFor(PharmacyVerificationStatus $target): PharmacyOrganizationStatus
    {
        return $target === PharmacyVerificationStatus::Approved
            ? PharmacyOrganizationStatus::Active
            : PharmacyOrganizationStatus::Pending;
    }

    private function branchStatusFor(PharmacyVerificationStatus $target): PharmacyBranchStatus
    {
        return $target === PharmacyVerificationStatus::Approved
            ? PharmacyBranchStatus::Active
            : PharmacyBranchStatus::Pending;
    }

    private function membershipStatusFor(PharmacyVerificationStatus $target): PharmacyMembershipStatus
    {
        return $target === PharmacyVerificationStatus::Approved
            ? PharmacyMembershipStatus::Active
            : PharmacyMembershipStatus::Pending;
    }

    private function assertNonOperationalUnlessApproved(
        PharmacyVerificationStatus $target,
        PharmacyOrganizationStatus $organizationStatus,
        PharmacyBranchStatus $branchStatus,
        PharmacyMembershipStatus $membershipStatus,
    ): void {
        if ($target === PharmacyVerificationStatus::Approved) {
            if (
                $organizationStatus !== PharmacyOrganizationStatus::Active
                || $branchStatus !== PharmacyBranchStatus::Active
                || $membershipStatus !== PharmacyMembershipStatus::Active
            ) {
                throw new StateConflict;
            }

            return;
        }

        if (
            $organizationStatus === PharmacyOrganizationStatus::Active
            || $branchStatus === PharmacyBranchStatus::Active
            || $membershipStatus === PharmacyMembershipStatus::Active
        ) {
            throw new StateConflict;
        }
    }

    private function projectMembership(PharmacyMembershipRecord $membership, bool $lock): ?PharmacyApplicantProjection
    {
        $organization = $this->store->findOrganizationById($membership->organizationId, $lock);
        if (! $organization instanceof PharmacyOrganizationRecord) {
            return null;
        }

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

    private function project(Identifier $organizationId, bool $lock): ?PharmacyApplicantProjection
    {
        $membership = $this->store->findOwnerMembershipByOrganizationId($organizationId, $lock);
        if (! $membership instanceof PharmacyMembershipRecord) {
            return null;
        }

        return $this->projectMembership($membership, $lock);
    }
}
