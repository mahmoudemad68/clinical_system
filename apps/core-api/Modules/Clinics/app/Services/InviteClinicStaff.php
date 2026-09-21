<?php

declare(strict_types=1);

namespace Modules\Clinics\Services;

use DateTimeImmutable;
use Modules\Access\Support\Capabilities;
use Modules\Audit\Contracts\AppendAuditEvent;
use Modules\Clinics\Enums\ClinicInvitationStatus;
use Modules\Clinics\Services\Persistence\PostgresClinicStore;
use Modules\Clinics\Support\ClinicInvitationOutcome;
use Modules\Clinics\Support\ClinicLocationRowFactory;
use Modules\Clinics\Support\ClinicOwnerGuard;
use Modules\Clinics\Support\ClinicStaffInvitationRecord;
use Modules\Identity\Services\InvitationRecipientService;
use Modules\Identity\Support\ActorContext;
use Modules\Identity\Support\InvitationPhoneBinding;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\IdentityGenerator;
use Modules\Platform\Contracts\TransactionContext;
use Modules\Platform\Contracts\TransactionRunner;
use Modules\Platform\Exceptions\DuplicateIdentity;
use Modules\Platform\Support\Identifier;

/**
 * Invite a secretary to one clinic location. Phone is bound via Identity HMAC
 * only. Responses never disclose whether the phone belongs to an account.
 *
 * Listed in ApprovedCoordinators.
 */
final class InviteClinicStaff
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

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(ActorContext $actor, Identifier $locationId, array $input): ClinicInvitationOutcome
    {
        $this->guard->requireOwnedLocation($actor, $locationId, Capabilities::CLINICS_STAFF_INVITE);

        $binding = $this->recipients->bindPhone((string) $input['phone']);
        $candidates = $this->lockCandidates($binding);

        return $this->transactions->run(function (TransactionContext $tx) use ($actor, $locationId, $binding, $candidates): ClinicInvitationOutcome {
            $this->guard->requireOwnedLocation(
                $actor,
                $locationId,
                Capabilities::CLINICS_STAFF_INVITE,
                true,
            );
            $this->lockCandidateIndexes($locationId, $candidates);

            $now = $this->clock->now();
            $pending = $this->store->findPendingInvitationsForHmacs($locationId, $candidates, true);
            $live = $this->replayOrExpirePending($pending, $now);
            if ($live instanceof ClinicStaffInvitationRecord) {
                return new ClinicInvitationOutcome(
                    $live->id->value,
                    $live->locationId->value,
                    $live->status,
                    $live->expiresAt,
                    false,
                );
            }

            return $this->createPendingInvitation($tx, $actor, $locationId, $binding, $candidates, $now);
        });
    }

    /**
     * @return list<string>
     */
    private function lockCandidates(InvitationPhoneBinding $binding): array
    {
        $candidates = $binding->orderedLookupHmacs();
        if ($candidates !== []) {
            return $candidates;
        }

        return $binding->phoneLookupHmac !== '' ? [$binding->phoneLookupHmac] : [];
    }

    /**
     * @param  list<string>  $candidates
     */
    private function lockCandidateIndexes(Identifier $locationId, array $candidates): void
    {
        foreach ($candidates as $hmac) {
            $this->store->lockLookupIndex('invite:'.$locationId->value.':'.bin2hex($hmac));
        }
    }

    /**
     * Keep at most one unexpired pending invitation for this canonical phone.
     * Time-expired (and extra rotation-era) pending rows become expired in
     * place so history is preserved and the unique pending index can accept a
     * replacement.
     *
     * @param  list<ClinicStaffInvitationRecord>  $pending
     */
    private function replayOrExpirePending(array $pending, DateTimeImmutable $now): ?ClinicStaffInvitationRecord
    {
        $live = null;
        foreach ($pending as $invitation) {
            if ($invitation->expiresAt > $now && $live === null) {
                $live = $invitation;

                continue;
            }

            $this->store->updateInvitation($invitation->id, [
                'status' => ClinicInvitationStatus::Expired->value,
                'updated_at' => $now->format('Y-m-d H:i:s.uP'),
            ]);
        }

        return $live;
    }

    /**
     * @param  list<string>  $candidates
     */
    private function createPendingInvitation(
        TransactionContext $tx,
        ActorContext $actor,
        Identifier $locationId,
        InvitationPhoneBinding $binding,
        array $candidates,
        DateTimeImmutable $now,
    ): ClinicInvitationOutcome {
        $hours = (int) config('clinics_module.invitation_ttl_hours', 72);
        $expiresAt = $now->modify(sprintf('+%d hours', $hours));
        $invitationId = $this->ids->next();

        try {
            $this->store->insertInvitation($this->rows->invitationAttributes(
                $invitationId,
                $locationId,
                $binding->phoneLookupHmac,
                $binding->hmacVersion,
                $actor->userId,
                $now,
                $expiresAt,
            ));
        } catch (DuplicateIdentity) {
            $retry = $this->store->findPendingInvitationsForHmacs($locationId, $candidates, true);
            $live = $this->firstUnexpired($retry, $now);
            if ($live instanceof ClinicStaffInvitationRecord) {
                return new ClinicInvitationOutcome(
                    $live->id->value,
                    $live->locationId->value,
                    $live->status,
                    $live->expiresAt,
                    false,
                );
            }

            throw new DuplicateIdentity;
        }

        $this->audit->append(
            $tx,
            'clinic.staff_invitation_created',
            'clinic_staff_invitation',
            $invitationId,
            ['reason_code' => 'owner_invite', 'location_id' => $locationId->value],
            $actor->userId,
            'user',
        );

        return new ClinicInvitationOutcome(
            $invitationId->value,
            $locationId->value,
            ClinicInvitationStatus::Pending,
            $expiresAt,
            true,
        );
    }

    /**
     * @param  list<ClinicStaffInvitationRecord>  $pending
     */
    private function firstUnexpired(array $pending, DateTimeImmutable $now): ?ClinicStaffInvitationRecord
    {
        foreach ($pending as $invitation) {
            if ($invitation->expiresAt > $now) {
                return $invitation;
            }
        }

        return null;
    }
}
