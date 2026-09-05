<?php

declare(strict_types=1);

namespace Modules\Patients\Support;

use Modules\Identity\Enums\SubjectHoldingAction;
use Modules\Identity\Support\SubjectHoldingPlan;

/**
 * Patients-owned subject holdings for export/erasure. Identity merges these
 * through PatientSubjectPrivacy and never names these tables itself.
 */
final class PatientSubjectHoldings
{
    /**
     * @return list<SubjectHoldingPlan>
     */
    public static function plan(): array
    {
        return [
            new SubjectHoldingPlan(
                'patient_profiles',
                SubjectHoldingAction::IrreversibleTombstone,
                'Unlink user_id, tombstone National ID ciphertext/HMAC and name ciphertext, set status archived. Unlinked walk-in profiles are not selected by user_id. Not a clinical record.',
            ),
            new SubjectHoldingPlan(
                'patient_demographic_revisions',
                SubjectHoldingAction::PreserveSecurityAudit,
                'Append-only demographic field history. Name ciphertext in historical revision rows is a documented residual and is not rewritten.',
            ),
        ];
    }
}
