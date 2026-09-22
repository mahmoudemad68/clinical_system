<?php

declare(strict_types=1);

namespace Modules\Pharmacies\Services\Adapters;

use DateTimeImmutable;
use Modules\Identity\Contracts\PharmacySubjectPrivacy;
use Modules\Identity\Services\InvitationRecipientService;
use Modules\Identity\Support\SubjectHoldingPlan;
use Modules\Pharmacies\Enums\PharmacyInvitationStatus;
use Modules\Pharmacies\Services\Persistence\PostgresPharmacyOrganizationStore;
use Modules\Pharmacies\Support\PharmacyStaffInvitationRecord;
use Modules\Pharmacies\Support\PharmacySubjectHoldings;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\RandomBytes;
use Modules\Platform\Services\Persistence\BinaryColumn;
use Modules\Platform\Support\Identifier;

final class PostgresPharmacySubjectPrivacy implements PharmacySubjectPrivacy
{
    public function __construct(
        private readonly PostgresPharmacyOrganizationStore $store,
        private readonly InvitationRecipientService $recipients,
        private readonly RandomBytes $random,
        private readonly Clock $clock,
    ) {}

    /**
     * @return list<SubjectHoldingPlan>
     */
    public function holdings(): array
    {
        return PharmacySubjectHoldings::plan();
    }

    public function exportCounts(Identifier $userId): array
    {
        $linked = $this->store->countLinkedToUser($userId);

        return [
            'pharmacy_organizations' => $linked['pharmacy_organizations'],
            'pharmacy_branches' => $linked['pharmacy_branches'],
            'pharmacy_memberships' => $linked['pharmacy_memberships'],
            'pharmacy_staff_invitations' => $this->store->countInvitationsLinkedToSubject(
                $userId,
                $this->recipients->subjectPhoneLookupHmacs($userId),
            ),
        ];
    }

    public function eraseLinked(Identifier $userId): array
    {
        $now = $this->clock->now();
        $cipherTombstone = $this->random->next(32);
        $hmacTombstone = $this->random->next(32);
        $hmacBind = BinaryColumn::bind($hmacTombstone);

        $erased = $this->store->eraseLinked($userId, $cipherTombstone, $hmacTombstone, $now);

        $ownedIds = $this->store->ownedOrganizationIdsForUser($userId, true);
        $cancelled = 0;
        $cancelled += $this->cancelInvitations(
            $this->store->listPendingInvitationsForOrganizations($ownedIds, true),
            $hmacBind,
            $now,
        );
        $cancelled += $this->cancelInvitations(
            $this->store->listPendingInvitationsByHmacs($this->recipients->subjectPhoneLookupHmacs($userId), true),
            $hmacBind,
            $now,
        );

        return [
            ...$erased,
            'pharmacy_staff_invitations' => $cancelled,
        ];
    }

    /**
     * @param  list<PharmacyStaffInvitationRecord>  $invitations
     */
    private function cancelInvitations(array $invitations, mixed $hmacTombstone, DateTimeImmutable $now): int
    {
        $count = 0;
        $stamp = $now->format('Y-m-d H:i:s.uP');
        foreach ($invitations as $invitation) {
            if ($invitation->status !== PharmacyInvitationStatus::Pending) {
                continue;
            }
            $affected = $this->store->updateInvitation($invitation->id, [
                'status' => PharmacyInvitationStatus::Cancelled->value,
                'target_phone_lookup_hmac' => $hmacTombstone,
                'consumed_at' => $stamp,
                'version' => $invitation->version + 1,
                'updated_at' => $stamp,
            ]);
            if ($affected === 1) {
                $count++;
            }
        }

        return $count;
    }
}
