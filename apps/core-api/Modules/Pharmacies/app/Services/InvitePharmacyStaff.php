<?php

declare(strict_types=1);

namespace Modules\Pharmacies\Services;

use DateTimeImmutable;
use Modules\Access\Support\Capabilities;
use Modules\Audit\Contracts\AppendAuditEvent;
use Modules\Identity\Services\InvitationRecipientService;
use Modules\Identity\Support\ActorContext;
use Modules\Identity\Support\InvitationPhoneBinding;
use Modules\Pharmacies\Enums\PharmacyInvitationStatus;
use Modules\Pharmacies\Services\Persistence\PostgresPharmacyOrganizationStore;
use Modules\Pharmacies\Support\PharmacyInvitationOutcome;
use Modules\Pharmacies\Support\PharmacyOrganizationRowFactory;
use Modules\Pharmacies\Support\PharmacyOwnerGuard;
use Modules\Pharmacies\Support\PharmacyStaffInvitationRecord;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\IdentityGenerator;
use Modules\Platform\Contracts\TransactionContext;
use Modules\Platform\Contracts\TransactionRunner;
use Modules\Platform\Exceptions\DuplicateIdentity;
use Modules\Platform\Support\Identifier;

/**
 * Invite a branch_operator to one pharmacy branch. Phone is bound via Identity
 * HMAC only. Responses never disclose whether the phone belongs to an account.
 * The inviteable role is server-owned branch_operator. Owner cannot be invited.
 *
 * Listed in ApprovedCoordinators.
 */
final class InvitePharmacyStaff
{
    public function __construct(
        private readonly TransactionRunner $transactions,
        private readonly PostgresPharmacyOrganizationStore $store,
        private readonly PharmacyOrganizationRowFactory $rows,
        private readonly PharmacyOwnerGuard $guard,
        private readonly InvitationRecipientService $recipients,
        private readonly AppendAuditEvent $audit,
        private readonly IdentityGenerator $ids,
        private readonly Clock $clock,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(
        ActorContext $actor,
        Identifier $organizationId,
        Identifier $branchId,
        array $input,
    ): PharmacyInvitationOutcome {
        $this->guard->requireOwnedBranch($actor, $organizationId, $branchId, Capabilities::PHARMACIES_STAFF_INVITE);

        $binding = $this->recipients->bindPhone((string) $input['phone']);
        $candidates = $this->lockCandidates($binding);

        return $this->transactions->run(function (TransactionContext $tx) use (
            $actor,
            $organizationId,
            $branchId,
            $binding,
            $candidates,
        ): PharmacyInvitationOutcome {
            $this->guard->requireOwnedBranch(
                $actor,
                $organizationId,
                $branchId,
                Capabilities::PHARMACIES_STAFF_INVITE,
                true,
            );
            $this->lockCandidateIndexes($organizationId, $branchId, $candidates);

            $now = $this->clock->now();
            $pending = $this->store->findPendingInvitationsForHmacs($organizationId, $branchId, $candidates, true);
            $live = $this->replayOrExpirePending($pending, $now);
            if ($live instanceof PharmacyStaffInvitationRecord) {
                return new PharmacyInvitationOutcome(
                    $live->id->value,
                    $live->status,
                    $live->expiresAt,
                    false,
                );
            }

            return $this->createPendingInvitation($tx, $actor, $organizationId, $branchId, $binding, $candidates, $now);
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
    private function lockCandidateIndexes(Identifier $organizationId, Identifier $branchId, array $candidates): void
    {
        foreach ($candidates as $hmac) {
            $this->store->lockLookupIndex('invite:'.$organizationId->value.':'.$branchId->value.':'.bin2hex($hmac));
        }
    }

    /**
     * @param  list<PharmacyStaffInvitationRecord>  $pending
     */
    private function replayOrExpirePending(array $pending, DateTimeImmutable $now): ?PharmacyStaffInvitationRecord
    {
        $live = null;
        foreach ($pending as $invitation) {
            if ($invitation->expiresAt > $now && $live === null) {
                $live = $invitation;

                continue;
            }

            $this->store->updateInvitation($invitation->id, [
                'status' => PharmacyInvitationStatus::Expired->value,
                'version' => $invitation->version + 1,
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
        Identifier $organizationId,
        Identifier $branchId,
        InvitationPhoneBinding $binding,
        array $candidates,
        DateTimeImmutable $now,
    ): PharmacyInvitationOutcome {
        $hours = (int) config('pharmacies_module.invitation_ttl_hours', 72);
        $expiresAt = $now->modify(sprintf('+%d hours', $hours));
        $invitationId = $this->ids->next();

        try {
            $this->store->insertInvitation($this->rows->invitationAttributes(
                $invitationId,
                $organizationId,
                $branchId,
                $binding->phoneLookupHmac,
                $binding->hmacVersion,
                $actor->userId,
                $now,
                $expiresAt,
            ));
        } catch (DuplicateIdentity) {
            $retry = $this->store->findPendingInvitationsForHmacs($organizationId, $branchId, $candidates, true);
            $live = $this->firstUnexpired($retry, $now);
            if ($live instanceof PharmacyStaffInvitationRecord) {
                return new PharmacyInvitationOutcome(
                    $live->id->value,
                    $live->status,
                    $live->expiresAt,
                    false,
                );
            }

            throw new DuplicateIdentity;
        }

        $this->audit->append(
            $tx,
            'pharmacy.staff_invitation_created',
            'pharmacy_staff_invitation',
            $invitationId,
            ['reason_code' => 'owner_invite', 'organization_id' => $organizationId->value, 'branch_id' => $branchId->value],
            $actor->userId,
            'user',
        );

        return new PharmacyInvitationOutcome(
            $invitationId->value,
            PharmacyInvitationStatus::Pending,
            $expiresAt,
            true,
        );
    }

    /**
     * @param  list<PharmacyStaffInvitationRecord>  $pending
     */
    private function firstUnexpired(array $pending, DateTimeImmutable $now): ?PharmacyStaffInvitationRecord
    {
        foreach ($pending as $invitation) {
            if ($invitation->expiresAt > $now) {
                return $invitation;
            }
        }

        return null;
    }
}
