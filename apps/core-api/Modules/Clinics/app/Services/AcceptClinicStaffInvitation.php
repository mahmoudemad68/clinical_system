<?php

declare(strict_types=1);

namespace Modules\Clinics\Services;

use DateTimeImmutable;
use Modules\Access\Support\Capabilities;
use Modules\Audit\Contracts\AppendAuditEvent;
use Modules\Clinics\Enums\ClinicInvitationStatus;
use Modules\Clinics\Enums\ClinicMembershipStatus;
use Modules\Clinics\Enums\ClinicStaffRole;
use Modules\Clinics\Events\ClinicMembershipChanged;
use Modules\Clinics\Services\Persistence\PostgresClinicStore;
use Modules\Clinics\Support\ClinicLocationRowFactory;
use Modules\Clinics\Support\ClinicMembershipProjection;
use Modules\Clinics\Support\ClinicOwnerGuard;
use Modules\Clinics\Support\ClinicStaffInvitationRecord;
use Modules\Clinics\Support\ClinicStaffMembershipRecord;
use Modules\Clinics\Support\ClinicStaffProfileRecord;
use Modules\Identity\Services\InvitationRecipientService;
use Modules\Identity\Support\ActorContext;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\IdentityGenerator;
use Modules\Platform\Contracts\TransactionContext;
use Modules\Platform\Contracts\TransactionRunner;
use Modules\Platform\Exceptions\AuthorizationDenied;
use Modules\Platform\Exceptions\DuplicateIdentity;
use Modules\Platform\Support\Identifier;

/**
 * Accept a secretary invitation. Invitation ID is not authorization; the
 * authenticated actor must match the HMAC identity binding.
 *
 * Listed in ApprovedCoordinators.
 */
final class AcceptClinicStaffInvitation
{
    public function __construct(
        private readonly TransactionRunner $transactions,
        private readonly PostgresClinicStore $store,
        private readonly ClinicLocationRowFactory $rows,
        private readonly ClinicOwnerGuard $guard,
        private readonly InvitationRecipientService $recipients,
        private readonly AppendAuditEvent $audit,
        private readonly IdentityGenerator $ids,
        private readonly Clock $clock,
    ) {}

    public function handle(ActorContext $actor, Identifier $invitationId): ClinicMembershipProjection
    {
        $this->guard->requireActiveSecretary($actor, Capabilities::CLINICS_STAFF_ACCEPT);

        return $this->transactions->run(function (TransactionContext $tx) use ($actor, $invitationId): ClinicMembershipProjection {
            $this->guard->requireActiveSecretary($actor, Capabilities::CLINICS_STAFF_ACCEPT);
            $this->store->lockLookupIndex('accept:'.$invitationId->value);
            $invitation = $this->store->findInvitationById($invitationId, true);
            if (! $invitation instanceof ClinicStaffInvitationRecord) {
                throw new AuthorizationDenied;
            }

            if (! $this->recipients->actorMatchesInvitationHmac($actor->userId, $invitation->targetPhoneLookupHmac)) {
                throw new AuthorizationDenied;
            }

            $now = $this->clock->now();
            if ($invitation->status !== ClinicInvitationStatus::Pending || $invitation->expiresAt <= $now) {
                throw new AuthorizationDenied;
            }

            $profile = $this->findOrCreateStaffProfile($actor->userId, $now);

            $existingGrant = $this->store->findGrantMembershipForProfileAtLocation($profile->id, $invitation->locationId, true);
            if ($existingGrant instanceof ClinicStaffMembershipRecord) {
                $this->consumeInvitation($invitation, $now);
                $this->auditAccept($tx, $actor, $existingGrant, $invitation, false);

                return $this->projectMembership($existingGrant);
            }

            $membershipId = $this->ids->next();
            try {
                $this->store->insertMembership($this->rows->membershipAttributes(
                    $membershipId,
                    $profile->id,
                    $invitation->locationId,
                    ClinicStaffRole::Secretary,
                    ClinicMembershipStatus::Active,
                    $now,
                    $invitation->inviterUserId,
                    $invitation->createdAt,
                    $now,
                ));
            } catch (DuplicateIdentity) {
                $retry = $this->store->findGrantMembershipForProfileAtLocation($profile->id, $invitation->locationId, true);
                if (! $retry instanceof ClinicStaffMembershipRecord) {
                    throw new DuplicateIdentity;
                }
                $this->consumeInvitation($invitation, $now);

                return $this->projectMembership($retry);
            }

            $this->consumeInvitation($invitation, $now);
            $membership = $this->store->findMembershipById($membershipId, true);
            assert($membership instanceof ClinicStaffMembershipRecord);

            $this->auditAccept($tx, $actor, $membership, $invitation, true);
            $tx->recordEvent(new ClinicMembershipChanged(
                $membership->id,
                $invitation->locationId,
                ClinicMembershipStatus::Active,
                $now,
            ));

            return $this->projectMembership($membership);
        });
    }

    private function findOrCreateStaffProfile(Identifier $userId, DateTimeImmutable $now): ClinicStaffProfileRecord
    {
        $profile = $this->store->findStaffProfileByUserId($userId, true);
        if ($profile instanceof ClinicStaffProfileRecord) {
            return $profile;
        }

        $profileId = $this->ids->next();
        try {
            $this->store->insertStaffProfile($this->rows->staffProfileAttributes($profileId, $userId, $now));
        } catch (DuplicateIdentity) {
            $retry = $this->store->findStaffProfileByUserId($userId, true);
            if (! $retry instanceof ClinicStaffProfileRecord) {
                throw new DuplicateIdentity;
            }

            return $retry;
        }

        $created = $this->store->findStaffProfileByUserId($userId, true);
        if (! $created instanceof ClinicStaffProfileRecord) {
            throw new DuplicateIdentity;
        }

        return $created;
    }

    private function consumeInvitation(ClinicStaffInvitationRecord $invitation, DateTimeImmutable $now): void
    {
        $this->store->updateInvitation($invitation->id, [
            'status' => ClinicInvitationStatus::Consumed->value,
            'consumed_at' => $now->format('Y-m-d H:i:s.uP'),
            'updated_at' => $now->format('Y-m-d H:i:s.uP'),
        ]);
    }

    private function auditAccept(
        TransactionContext $tx,
        ActorContext $actor,
        ClinicStaffMembershipRecord $membership,
        ClinicStaffInvitationRecord $invitation,
        bool $created,
    ): void {
        $this->audit->append(
            $tx,
            'clinic.staff_invitation_accepted',
            'clinic_staff_membership',
            $membership->id,
            [
                'reason_code' => $created ? 'invitation_accepted' : 'invitation_replay',
                'location_id' => $invitation->locationId->value,
            ],
            $actor->userId,
            'user',
        );
    }

    private function projectMembership(ClinicStaffMembershipRecord $membership): ClinicMembershipProjection
    {
        return new ClinicMembershipProjection(
            $membership->id->value,
            $membership->role,
            $membership->status,
            $membership->version,
            $membership->invitedAt,
            $membership->acceptedAt,
            $membership->revokedAt,
        );
    }
}
