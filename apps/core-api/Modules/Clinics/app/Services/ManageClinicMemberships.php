<?php

declare(strict_types=1);

namespace Modules\Clinics\Services;

use Modules\Access\Support\Capabilities;
use Modules\Audit\Contracts\AppendAuditEvent;
use Modules\Clinics\Enums\ClinicMembershipStatus;
use Modules\Clinics\Events\ClinicMembershipChanged;
use Modules\Clinics\Services\Persistence\PostgresClinicStore;
use Modules\Clinics\Support\ClinicLocationProjector;
use Modules\Clinics\Support\ClinicMembershipProjection;
use Modules\Clinics\Support\ClinicOwnerGuard;
use Modules\Clinics\Support\ClinicStaffMembershipRecord;
use Modules\Identity\Support\ActorContext;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\TransactionContext;
use Modules\Platform\Contracts\TransactionRunner;
use Modules\Platform\Exceptions\AuthorizationDenied;
use Modules\Platform\Exceptions\VersionConflict;
use Modules\Platform\Support\Identifier;

/**
 * Owning-doctor membership list and revoke. Revocation is authoritative
 * immediately. Repeated revoke returns the stable revoked state.
 *
 * Listed in ApprovedCoordinators for revoke. There is no Phase 04/09 realtime
 * session invalidation hook yet; MembershipChanged is the authoritative event.
 */
final class ManageClinicMemberships
{
    public function __construct(
        private readonly TransactionRunner $transactions,
        private readonly PostgresClinicStore $store,
        private readonly ClinicLocationProjector $projector,
        private readonly ClinicOwnerGuard $guard,
        private readonly AppendAuditEvent $audit,
        private readonly Clock $clock,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function list(ActorContext $actor, Identifier $locationId): array
    {
        $this->guard->requireOwnedLocation($actor, $locationId, Capabilities::CLINICS_MEMBERSHIP_READ_OWN);

        return array_map(
            fn (ClinicStaffMembershipRecord $row): array => $this->projector->membership($row)->toArray(),
            $this->store->listMembershipsForLocation($locationId),
        );
    }

    public function revoke(ActorContext $actor, Identifier $locationId, Identifier $membershipId): ClinicMembershipProjection
    {
        $this->guard->requireOwnedLocation($actor, $locationId, Capabilities::CLINICS_MEMBERSHIP_REVOKE);

        return $this->transactions->run(function (TransactionContext $tx) use ($actor, $locationId, $membershipId): ClinicMembershipProjection {
            $this->guard->requireOwnedLocation($actor, $locationId, Capabilities::CLINICS_MEMBERSHIP_REVOKE, true);
            $membership = $this->store->findMembershipById($membershipId, true);
            if (! $membership instanceof ClinicStaffMembershipRecord
                || ! $membership->locationId->equals($locationId)) {
                throw new AuthorizationDenied;
            }

            if ($membership->status === ClinicMembershipStatus::Revoked) {
                return $this->projector->membership($membership);
            }

            $now = $this->clock->now();
            $nextVersion = $membership->version + 1;
            $affected = $this->store->updateMembership($membershipId, $membership->version, [
                'status' => ClinicMembershipStatus::Revoked->value,
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
                'clinic.staff_membership_revoked',
                'clinic_staff_membership',
                $membershipId,
                ['reason_code' => 'owner_revoke', 'location_id' => $locationId->value, 'version' => $nextVersion],
                $actor->userId,
                'user',
            );
            $tx->recordEvent(new ClinicMembershipChanged(
                $membershipId,
                $locationId,
                ClinicMembershipStatus::Revoked,
                $now,
            ));

            $fresh = $this->store->findMembershipById($membershipId, true);
            assert($fresh instanceof ClinicStaffMembershipRecord);

            return $this->projector->membership($fresh);
        });
    }
}
