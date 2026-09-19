<?php

declare(strict_types=1);

namespace Modules\Doctors\Support;

use Modules\Identity\Enums\SubjectHoldingAction;
use Modules\Identity\Support\SubjectHoldingPlan;

/**
 * Doctors-owned subject holdings for export/erasure. Identity merges these
 * through DoctorSubjectPrivacy and never names these tables itself.
 */
final class DoctorSubjectHoldings
{
    /**
     * @return list<SubjectHoldingPlan>
     */
    public static function plan(): array
    {
        return [
            new SubjectHoldingPlan(
                'doctor_profiles',
                SubjectHoldingAction::IrreversibleTombstone,
                'Keep user_id attached (unique, NOT NULL), tombstone National ID and syndicate ciphertext/HMAC, keep draft/hidden status. Specialties are reference data and are not subject-linked.',
            ),
            new SubjectHoldingPlan(
                'specialties',
                SubjectHoldingAction::NotSubjectLinked,
                'Reference catalog owned by Doctors. Not erased with a subject.',
            ),
        ];
    }
}
