<?php

declare(strict_types=1);

namespace Modules\Pharmacies\Support;

use Modules\Access\Contracts\Authorize;
use Modules\Access\Support\Capabilities;
use Modules\Identity\Enums\AccountType;
use Modules\Identity\Support\ActorContext;
use Modules\Pharmacies\Enums\PharmacyBranchStatus;
use Modules\Pharmacies\Enums\PharmacyMembershipRole;
use Modules\Pharmacies\Enums\PharmacyMembershipStatus;
use Modules\Pharmacies\Enums\PharmacyOrganizationStatus;
use Modules\Pharmacies\Enums\PharmacyVerificationStatus;
use Modules\Pharmacies\Services\Persistence\PostgresPharmacyOrganizationStore;
use Modules\Platform\Exceptions\AuthorizationDenied;
use Modules\Platform\Exceptions\FeatureUnavailable;
use Modules\Platform\Support\Identifier;

/**
 * Server-derived actor and ownership checks. A capability name in
 * /me/capabilities is never treated as proof of pharmacy ownership.
 */
final class PharmacyOwnerGuard
{
    public function __construct(
        private readonly PostgresPharmacyOrganizationStore $store,
        private readonly Authorize $authorize,
    ) {}

    /**
     * @return array{organization: PharmacyOrganizationRecord, membership: PharmacyMembershipRecord}
     */
    public function requireApprovedPrivilegedOwner(ActorContext $actor, string $capability, bool $lock = false): array
    {
        if ($actor->accountType !== AccountType::Pharmacy || ! $actor->status->canAccessBusinessEndpoints()) {
            throw new FeatureUnavailable;
        }

        if (! $actor->assuranceLevel->satisfiesPrivilegedSession()) {
            throw new AuthorizationDenied;
        }

        $decision = $this->authorize->decide($actor, $capability);
        if (! $decision->allowed) {
            throw new AuthorizationDenied;
        }

        $membership = $this->store->findOwnerMembershipByUserId($actor->userId, $lock);
        if (! $membership instanceof PharmacyMembershipRecord
            || $membership->status !== PharmacyMembershipStatus::Active
            || $membership->role !== PharmacyMembershipRole::Owner) {
            throw new AuthorizationDenied;
        }

        $organization = $this->store->findOrganizationById($membership->organizationId, $lock);
        if (! $organization instanceof PharmacyOrganizationRecord
            || $organization->verificationStatus !== PharmacyVerificationStatus::Approved
            || $organization->status !== PharmacyOrganizationStatus::Active) {
            throw new AuthorizationDenied;
        }

        return ['organization' => $organization, 'membership' => $membership];
    }

    /**
     * @return array{organization: PharmacyOrganizationRecord, membership: PharmacyMembershipRecord}
     */
    public function requireOwnedOrganization(
        ActorContext $actor,
        Identifier $organizationId,
        string $capability,
        bool $lock = false,
    ): array {
        $resolved = $this->requireApprovedPrivilegedOwner($actor, $capability, $lock);
        if (! $resolved['organization']->id->equals($organizationId)) {
            throw new AuthorizationDenied;
        }

        return $resolved;
    }

    /**
     * @return array{organization: PharmacyOrganizationRecord, membership: PharmacyMembershipRecord, branch: PharmacyBranchRecord}
     */
    public function requireOwnedBranch(
        ActorContext $actor,
        Identifier $organizationId,
        Identifier $branchId,
        string $capability,
        bool $lock = false,
    ): array {
        $resolved = $this->requireOwnedOrganization($actor, $organizationId, $capability, $lock);
        $branch = $this->store->findBranchById($branchId, $lock);
        if (! $branch instanceof PharmacyBranchRecord
            || ! $branch->organizationId->equals($organizationId)
            || $branch->status === PharmacyBranchStatus::Closed
            || $branch->status === PharmacyBranchStatus::Suspended) {
            throw new AuthorizationDenied;
        }

        return [...$resolved, 'branch' => $branch];
    }

    /**
     * @return array{mode: 'owner', organization: PharmacyOrganizationRecord, branch: PharmacyBranchRecord, membership: PharmacyMembershipRecord}|array{mode: 'operator', organization: PharmacyOrganizationRecord, branch: PharmacyBranchRecord, membership: PharmacyMembershipRecord}
     */
    public function requireBranchRead(ActorContext $actor, Identifier $organizationId, Identifier $branchId): array
    {
        $decision = $this->authorize->decide($actor, Capabilities::PHARMACIES_BRANCH_READ_OWN);
        if (! $decision->allowed) {
            throw new AuthorizationDenied;
        }

        if (! $actor->status->canAccessBusinessEndpoints()) {
            throw new FeatureUnavailable;
        }

        if ($actor->accountType !== AccountType::Pharmacy) {
            throw new FeatureUnavailable;
        }

        $branch = $this->store->findBranchById($branchId, false);
        if (! $branch instanceof PharmacyBranchRecord || ! $branch->organizationId->equals($organizationId)) {
            throw new AuthorizationDenied;
        }

        $organization = $this->store->findOrganizationById($organizationId, false);
        if (! $organization instanceof PharmacyOrganizationRecord) {
            throw new AuthorizationDenied;
        }

        if ($actor->assuranceLevel->satisfiesPrivilegedSession()) {
            $owner = $this->store->findOwnerMembershipByUserId($actor->userId, false);
            if ($owner instanceof PharmacyMembershipRecord
                && $owner->status === PharmacyMembershipStatus::Active
                && $owner->organizationId->equals($organizationId)
                && $organization->verificationStatus === PharmacyVerificationStatus::Approved
                && $organization->status === PharmacyOrganizationStatus::Active) {
                return ['mode' => 'owner', 'organization' => $organization, 'branch' => $branch, 'membership' => $owner];
            }
        }

        $operator = $this->store->findActiveBranchOperatorForUserAtBranch($actor->userId, $organizationId, $branchId);
        if ($operator instanceof PharmacyMembershipRecord) {
            return ['mode' => 'operator', 'organization' => $organization, 'branch' => $branch, 'membership' => $operator];
        }

        throw new AuthorizationDenied;
    }

    public function requireActivePharmacyInvitee(ActorContext $actor, string $capability): void
    {
        if ($actor->accountType !== AccountType::Pharmacy || ! $actor->status->canAccessBusinessEndpoints()) {
            throw new FeatureUnavailable;
        }

        $decision = $this->authorize->decide($actor, $capability);
        if (! $decision->allowed) {
            throw new AuthorizationDenied;
        }
    }
}
