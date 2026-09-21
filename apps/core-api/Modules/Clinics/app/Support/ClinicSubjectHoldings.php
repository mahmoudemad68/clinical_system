<?php

declare(strict_types=1);

namespace Modules\Clinics\Support;

use Modules\Clinics\Enums\ClinicMembershipStatus;
use Modules\Identity\Enums\SubjectHoldingAction;
use Modules\Identity\Support\SubjectHoldingPlan;

/**
 * Clinics-owned subject holdings for export/erasure. Identity merges these
 * through ClinicSubjectPrivacy and never names these tables itself.
 */
final class ClinicSubjectHoldings
{
    /**
     * @return list<SubjectHoldingPlan>
     */
    public static function plan(): array
    {
        return [
            new SubjectHoldingPlan(
                'clinic_locations',
                SubjectHoldingAction::IrreversibleTombstone,
                'Owning-doctor erasure closes locations (status=closed, version+=1) and tombstones address ciphertext. Active is not retained as a future authorization or publication source. Coordinates remain a location fact with no contact content.',
            ),
            new SubjectHoldingPlan(
                'clinic_staff_profiles',
                SubjectHoldingAction::IrreversibleTombstone,
                'Keep user_id attached for referential integrity. Staff profiles are not an authorization grant by themselves.',
            ),
            new SubjectHoldingPlan(
                'clinic_staff_memberships',
                SubjectHoldingAction::IrreversibleTombstone,
                'Pending and active memberships for the subject, and memberships attached to the subject doctor\'s locations, become revoked. History is retained. Personal staff location is never collected.',
            ),
            new SubjectHoldingPlan(
                'clinic_staff_invitations',
                SubjectHoldingAction::IrreversibleTombstone,
                'Pending invitations targeting any configured lookup HMAC for the subject, invitations the subject sent as inviter, and pending invitations on the subject doctor\'s locations, are subject holdings. Export counts distinct rows. Erasure cancels pending target/location invitations and tombstones the HMAC. Plaintext phone, HMAC, invitation secret, and other users\' identities are never exported.',
            ),
        ];
    }

    /**
     * @return list<string>
     */
    public static function grantStatuses(): array
    {
        return [
            ClinicMembershipStatus::Pending->value,
            ClinicMembershipStatus::Active->value,
        ];
    }
}
