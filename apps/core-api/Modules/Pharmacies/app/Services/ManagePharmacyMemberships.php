<?php

declare(strict_types=1);

namespace Modules\Pharmacies\Services;

use Modules\Access\Support\Capabilities;
use Modules\Audit\Contracts\AppendAuditEvent;
use Modules\Identity\Support\ActorContext;
use Modules\Pharmacies\Enums\PharmacyMembershipRole;
use Modules\Pharmacies\Enums\PharmacyMembershipStatus;
use Modules\Pharmacies\Events\PharmacyMembershipChanged;
use Modules\Pharmacies\Services\Persistence\PostgresPharmacyOrganizationStore;
use Modules\Pharmacies\Support\PharmacyBranchProjector;
use Modules\Pharmacies\Support\PharmacyMembershipProjection;
use Modules\Pharmacies\Support\PharmacyMembershipRecord;
use Modules\Pharmacies\Support\PharmacyOwnerGuard;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\TransactionContext;
use Modules\Platform\Contracts\TransactionRunner;
use Modules\Platform\Exceptions\AuthorizationDenied;
use Modules\Platform\Exceptions\VersionConflict;
use Modules\Platform\Support\Identifier;

/**
 * Owning-pharmacy membership list and revoke. Only branch_operator membership
 * may be revoked. Founding owner membership is not revocable through this
 * route. Revocation is authoritative immediately. Repeated revoke returns the
 * stable revoked state without version churn.
 *
 * Listed in ApprovedCoordinators for revoke.
 */
final class ManagePharmacyMemberships
{
    public function __construct(
        private readonly TransactionRunner $transactions,
        private readonly PostgresPharmacyOrganizationStore $store,
        private readonly PharmacyBranchProjector $projector,
        private readonly PharmacyOwnerGuard $guard,
        private readonly AppendAuditEvent $audit,
        private readonly Clock $clock,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function list(ActorContext $actor, Identifier $organizationId, Identifier $branchId): array
    {
        $this->guard->requireOwnedBranch($actor, $organizationId, $branchId, Capabilities::PHARMACIES_MEMBERSHIP_READ_OWN);

        return array_map(
            fn (PharmacyMembershipRecord $row): array => $this->projector->membership($row)->toArray(),
            $this->store->listBranchOperatorMembershipsForBranch($organizationId, $branchId),
        );
    }

    public function revoke(
        ActorContext $actor,
        Identifier $organizationId,
        Identifier $branchId,
        Identifier $membershipId,
    ): PharmacyMembershipProjection {
        $this->guard->requireOwnedBranch($actor, $organizationId, $branchId, Capabilities::PHARMACIES_MEMBERSHIP_REVOKE);

        return $this->transactions->run(function (TransactionContext $tx) use (
            $actor,
            $organizationId,
            $branchId,
            $membershipId,
        ): PharmacyMembershipProjection {
            $this->guard->requireOwnedBranch($actor, $organizationId, $branchId, Capabilities::PHARMACIES_MEMBERSHIP_REVOKE, true);
            $membership = $this->store->findMembershipById($membershipId, true);
            if (! $membership instanceof PharmacyMembershipRecord
                || ! $membership->organizationId->equals($organizationId)
                || ! ($membership->branchId instanceof Identifier)
                || ! $membership->branchId->equals($branchId)
                || $membership->role !== PharmacyMembershipRole::BranchOperator) {
                throw new AuthorizationDenied;
            }

            if ($membership->status === PharmacyMembershipStatus::Revoked) {
                return $this->projector->membership($membership);
            }

            $now = $this->clock->now();
            $nextVersion = $membership->version + 1;
            $affected = $this->store->updateMembership($membershipId, $membership->version, [
                'status' => PharmacyMembershipStatus::Revoked->value,
                'revoked_at' => $now->format('Y-m-d H:i:s.uP'),
                'revoker_user_id' => $actor->userId->value,
                'version' => $nextVersion,
                'updated_at' => $now->format('Y-m-d H:i:s.uP'),
            ]);
            if ($affected !== 1) {
                throw new VersionConflict;
            }

            $this->audit->append(
                $tx,
                'pharmacy.staff_membership_revoked',
                'pharmacy_membership',
                $membershipId,
                [
                    'reason_code' => 'owner_revoke',
                    'organization_id' => $organizationId->value,
                    'branch_id' => $branchId->value,
                    'version' => $nextVersion,
                ],
                $actor->userId,
                'user',
            );
            $tx->recordEvent(new PharmacyMembershipChanged(
                $membershipId,
                $branchId,
                PharmacyMembershipStatus::Revoked,
                $now,
            ));

            $fresh = $this->store->findMembershipById($membershipId, true);
            assert($fresh instanceof PharmacyMembershipRecord);

            return $this->projector->membership($fresh);
        });
    }
}
