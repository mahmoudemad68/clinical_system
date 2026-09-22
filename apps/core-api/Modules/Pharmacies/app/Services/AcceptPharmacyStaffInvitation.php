<?php

declare(strict_types=1);

namespace Modules\Pharmacies\Services;

use DateTimeImmutable;
use Modules\Access\Support\Capabilities;
use Modules\Audit\Contracts\AppendAuditEvent;
use Modules\Identity\Services\InvitationRecipientService;
use Modules\Identity\Support\ActorContext;
use Modules\Pharmacies\Enums\PharmacyBranchStatus;
use Modules\Pharmacies\Enums\PharmacyInvitationStatus;
use Modules\Pharmacies\Enums\PharmacyMembershipRole;
use Modules\Pharmacies\Enums\PharmacyMembershipStatus;
use Modules\Pharmacies\Enums\PharmacyOrganizationStatus;
use Modules\Pharmacies\Enums\PharmacyVerificationStatus;
use Modules\Pharmacies\Events\PharmacyMembershipChanged;
use Modules\Pharmacies\Services\Persistence\PostgresPharmacyOrganizationStore;
use Modules\Pharmacies\Support\PharmacyBranchProjector;
use Modules\Pharmacies\Support\PharmacyBranchRecord;
use Modules\Pharmacies\Support\PharmacyMembershipProjection;
use Modules\Pharmacies\Support\PharmacyMembershipRecord;
use Modules\Pharmacies\Support\PharmacyOrganizationRecord;
use Modules\Pharmacies\Support\PharmacyOrganizationRowFactory;
use Modules\Pharmacies\Support\PharmacyOwnerGuard;
use Modules\Pharmacies\Support\PharmacyStaffInvitationRecord;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\IdentityGenerator;
use Modules\Platform\Contracts\TransactionContext;
use Modules\Platform\Contracts\TransactionRunner;
use Modules\Platform\Exceptions\AuthorizationDenied;
use Modules\Platform\Exceptions\DuplicateIdentity;
use Modules\Platform\Exceptions\StateConflict;
use Modules\Platform\Support\Identifier;

/**
 * Accept a branch_operator invitation. Invitation ID is not authorization; the
 * authenticated actor must match the HMAC identity binding. UNIQUE
 * (organization_id, user_id) is preserved: the same user cannot hold
 * branch_operator memberships for multiple branches in one organization, and
 * a founding owner cannot become a second owner through this path.
 *
 * Listed in ApprovedCoordinators.
 */
final class AcceptPharmacyStaffInvitation
{
    public function __construct(
        private readonly TransactionRunner $transactions,
        private readonly PostgresPharmacyOrganizationStore $store,
        private readonly PharmacyOrganizationRowFactory $rows,
        private readonly PharmacyBranchProjector $projector,
        private readonly PharmacyOwnerGuard $guard,
        private readonly InvitationRecipientService $recipients,
        private readonly AppendAuditEvent $audit,
        private readonly IdentityGenerator $ids,
        private readonly Clock $clock,
    ) {}

    public function handle(ActorContext $actor, Identifier $invitationId): PharmacyMembershipProjection
    {
        $this->guard->requireActivePharmacyInvitee($actor, Capabilities::PHARMACIES_STAFF_ACCEPT);

        return $this->transactions->run(function (TransactionContext $tx) use ($actor, $invitationId): PharmacyMembershipProjection {
            $this->guard->requireActivePharmacyInvitee($actor, Capabilities::PHARMACIES_STAFF_ACCEPT);
            $this->store->lockLookupIndex('accept:'.$invitationId->value);
            $invitation = $this->store->findInvitationById($invitationId, true);
            if (! $invitation instanceof PharmacyStaffInvitationRecord) {
                throw new AuthorizationDenied;
            }

            if (! $this->recipients->actorMatchesInvitationHmac($actor->userId, $invitation->targetPhoneLookupHmac)) {
                throw new AuthorizationDenied;
            }

            $now = $this->clock->now();
            if ($invitation->status !== PharmacyInvitationStatus::Pending || $invitation->expiresAt <= $now) {
                throw new AuthorizationDenied;
            }

            $organization = $this->store->findOrganizationById($invitation->organizationId, true);
            $branch = $this->store->findBranchById($invitation->branchId, true);
            if (! $organization instanceof PharmacyOrganizationRecord
                || ! $branch instanceof PharmacyBranchRecord
                || ! $branch->organizationId->equals($invitation->organizationId)
                || $organization->verificationStatus !== PharmacyVerificationStatus::Approved
                || $organization->status !== PharmacyOrganizationStatus::Active
                || $branch->status === PharmacyBranchStatus::Closed
                || $branch->status === PharmacyBranchStatus::Suspended) {
                throw new AuthorizationDenied;
            }

            $this->store->lockLookupIndex('membership:'.$invitation->organizationId->value.':'.$actor->userId->value);
            $existing = $this->store->findMembershipByUserAndOrganization($actor->userId, $invitation->organizationId, true);
            if ($existing instanceof PharmacyMembershipRecord) {
                return $this->convergeExisting($tx, $actor, $existing, $invitation, $now);
            }

            $membershipId = $this->ids->next();
            try {
                $this->store->insertMembership($this->rows->branchOperatorMembershipAttributes(
                    $membershipId,
                    $invitation->organizationId,
                    $actor->userId,
                    $invitation->branchId,
                    $invitation->inviterUserId,
                    $now,
                    $invitation->invitedAt,
                ));
            } catch (DuplicateIdentity) {
                $retry = $this->store->findMembershipByUserAndOrganization($actor->userId, $invitation->organizationId, true);
                if (! $retry instanceof PharmacyMembershipRecord) {
                    throw new DuplicateIdentity;
                }

                return $this->convergeExisting($tx, $actor, $retry, $invitation, $now);
            }

            $this->consumeInvitation($invitation, $now);
            $membership = $this->store->findMembershipById($membershipId, true);
            assert($membership instanceof PharmacyMembershipRecord);

            $this->auditAccept($tx, $actor, $membership, $invitation, true);
            $tx->recordEvent(new PharmacyMembershipChanged(
                $membership->id,
                $invitation->branchId,
                PharmacyMembershipStatus::Active,
                $now,
            ));

            return $this->projector->membership($membership);
        });
    }

    private function convergeExisting(
        TransactionContext $tx,
        ActorContext $actor,
        PharmacyMembershipRecord $existing,
        PharmacyStaffInvitationRecord $invitation,
        DateTimeImmutable $now,
    ): PharmacyMembershipProjection {
        $sameBranch = $existing->branchId instanceof Identifier
            && $existing->branchId->equals($invitation->branchId);
        $liveOperator = $existing->role === PharmacyMembershipRole::BranchOperator
            && in_array($existing->status, [PharmacyMembershipStatus::Pending, PharmacyMembershipStatus::Active], true);

        if ($sameBranch && $liveOperator) {
            $this->consumeInvitation($invitation, $now);
            $this->auditAccept($tx, $actor, $existing, $invitation, false);

            return $this->projector->membership($existing);
        }

        throw new StateConflict;
    }

    private function consumeInvitation(PharmacyStaffInvitationRecord $invitation, DateTimeImmutable $now): void
    {
        $stamp = $now->format('Y-m-d H:i:s.uP');
        $this->store->updateInvitation($invitation->id, [
            'status' => PharmacyInvitationStatus::Consumed->value,
            'accepted_at' => $stamp,
            'consumed_at' => $stamp,
            'version' => $invitation->version + 1,
            'updated_at' => $stamp,
        ]);
    }

    private function auditAccept(
        TransactionContext $tx,
        ActorContext $actor,
        PharmacyMembershipRecord $membership,
        PharmacyStaffInvitationRecord $invitation,
        bool $created,
    ): void {
        $this->audit->append(
            $tx,
            'pharmacy.staff_invitation_accepted',
            'pharmacy_membership',
            $membership->id,
            [
                'reason_code' => $created ? 'invitation_accepted' : 'invitation_replay',
                'organization_id' => $invitation->organizationId->value,
                'branch_id' => $invitation->branchId->value,
            ],
            $actor->userId,
            'user',
        );
    }
}
