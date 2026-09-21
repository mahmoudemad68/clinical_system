<?php

declare(strict_types=1);

namespace Modules\Clinics\Services\Adapters;

use Modules\Clinics\Enums\ClinicInvitationStatus;
use Modules\Clinics\Enums\ClinicLocationStatus;
use Modules\Clinics\Enums\ClinicMembershipStatus;
use Modules\Clinics\Services\Persistence\PostgresClinicStore;
use Modules\Clinics\Support\ClinicStaffInvitationRecord;
use Modules\Clinics\Support\ClinicStaffMembershipRecord;
use Modules\Clinics\Support\ClinicSubjectHoldings;
use Modules\Doctors\Services\PracticeOwnerEligibilityService;
use Modules\Doctors\Support\PracticeOwnerEligibility;
use Modules\Identity\Contracts\ClinicSubjectPrivacy;
use Modules\Identity\Services\InvitationRecipientService;
use Modules\Identity\Support\SubjectHoldingPlan;
use Modules\Platform\Contracts\Clock;
use Modules\Platform\Contracts\RandomBytes;
use Modules\Platform\Services\Persistence\BinaryColumn;
use Modules\Platform\Support\Identifier;

final class PostgresClinicSubjectPrivacy implements ClinicSubjectPrivacy
{
    public function __construct(
        private readonly PostgresClinicStore $store,
        private readonly PracticeOwnerEligibilityService $owners,
        private readonly InvitationRecipientService $recipients,
        private readonly RandomBytes $random,
        private readonly Clock $clock,
    ) {}

    /**
     * @return list<SubjectHoldingPlan>
     */
    public function holdings(): array
    {
        return ClinicSubjectHoldings::plan();
    }

    public function exportCounts(Identifier $userId): array
    {
        $staff = $this->store->countStaffHoldingsForUser($userId);
        $owner = $this->owners->findByUserId($userId, false);
        $owned = $owner instanceof PracticeOwnerEligibility
            ? $this->store->countLocationsByDoctorIds([$owner->doctorId->value])
            : 0;

        return [
            'clinic_locations' => $owned + $staff['clinic_locations_via_membership'],
            'clinic_staff_profiles' => $staff['clinic_staff_profiles'],
            'clinic_staff_memberships' => $staff['clinic_staff_memberships'],
            'clinic_staff_invitations' => $this->store->countInvitationsLinkedToSubject(
                $userId,
                $this->recipients->subjectPhoneLookupHmacs($userId),
            ),
        ];
    }

    public function eraseLinked(Identifier $userId): array
    {
        $now = $this->clock->now();
        $stamp = $now->format('Y-m-d H:i:s.uP');
        $cipherTombstone = BinaryColumn::bind($this->random->next(32));
        $hmacTombstone = BinaryColumn::bind($this->random->next(32));

        $closedLocations = 0;
        $revokedMemberships = 0;
        $cancelledInvitations = 0;

        $owner = $this->owners->findByUserId($userId, true);
        $owned = $owner instanceof PracticeOwnerEligibility
            ? $this->store->listLocationsByDoctorIds([$owner->doctorId->value], true)
            : [];

        foreach ($owned as $location) {
            if ($location->status !== ClinicLocationStatus::Closed) {
                $affected = $this->store->updateLocation($location->id, $location->version, [
                    'status' => ClinicLocationStatus::Closed->value,
                    'address_ciphertext' => $cipherTombstone,
                    'public_name' => 'erased',
                    'version' => $location->version + 1,
                    'updated_at' => $stamp,
                ]);
                if ($affected === 1) {
                    $closedLocations++;
                }
            } else {
                $this->store->updateLocation($location->id, $location->version, [
                    'address_ciphertext' => $cipherTombstone,
                    'public_name' => 'erased',
                    'updated_at' => $stamp,
                ]);
            }

            $revokedMemberships += $this->revokeGrants(
                $this->store->listGrantMembershipsForLocations([$location->id->value], true),
                $userId,
                $now,
            );
            $cancelledInvitations += $this->cancelInvitations(
                $this->store->listPendingInvitationsForLocations([$location->id->value], true),
                $hmacTombstone,
                $now,
            );
        }

        $revokedMemberships += $this->revokeGrants(
            $this->store->listGrantMembershipsForUser($userId, true),
            $userId,
            $now,
        );
        $cancelledInvitations += $this->cancelInvitations(
            $this->store->listPendingInvitationsByHmacs($this->recipients->subjectPhoneLookupHmacs($userId), true),
            $hmacTombstone,
            $now,
        );

        return [
            'clinic_locations' => $closedLocations,
            'clinic_staff_profiles' => $this->store->countStaffHoldingsForUser($userId)['clinic_staff_profiles'],
            'clinic_staff_memberships' => $revokedMemberships,
            'clinic_staff_invitations' => $cancelledInvitations,
        ];
    }

    /**
     * @param  list<ClinicStaffMembershipRecord>  $memberships
     */
    private function revokeGrants(array $memberships, Identifier $revoker, \DateTimeImmutable $now): int
    {
        $count = 0;
        $stamp = $now->format('Y-m-d H:i:s.uP');
        foreach ($memberships as $membership) {
            if ($membership->status === ClinicMembershipStatus::Revoked) {
                continue;
            }
            $affected = $this->store->updateMembership($membership->id, $membership->version, [
                'status' => ClinicMembershipStatus::Revoked->value,
                'revoked_at' => $stamp,
                'revoker_user_id' => $revoker->value,
                'version' => $membership->version + 1,
                'updated_at' => $stamp,
            ]);
            if ($affected === 1) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  list<ClinicStaffInvitationRecord>  $invitations
     */
    private function cancelInvitations(array $invitations, mixed $hmacTombstone, \DateTimeImmutable $now): int
    {
        $count = 0;
        $stamp = $now->format('Y-m-d H:i:s.uP');
        foreach ($invitations as $invitation) {
            if ($invitation->status !== ClinicInvitationStatus::Pending) {
                continue;
            }
            $affected = $this->store->updateInvitation($invitation->id, [
                'status' => ClinicInvitationStatus::Cancelled->value,
                'target_phone_lookup_hmac' => $hmacTombstone,
                'consumed_at' => $stamp,
                'updated_at' => $stamp,
            ]);
            if ($affected === 1) {
                $count++;
            }
        }

        return $count;
    }
}
