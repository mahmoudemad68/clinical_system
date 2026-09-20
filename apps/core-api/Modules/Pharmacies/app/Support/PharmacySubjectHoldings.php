<?php

declare(strict_types=1);

namespace Modules\Pharmacies\Support;

use Modules\Identity\Enums\SubjectHoldingAction;
use Modules\Identity\Support\SubjectHoldingPlan;

/**
 * Pharmacies-owned subject holdings for export/erasure. Identity merges these
 * through PharmacySubjectPrivacy and never names these tables itself.
 */
final class PharmacySubjectHoldings
{
    /**
     * @return list<SubjectHoldingPlan>
     */
    public static function plan(): array
    {
        return [
            new SubjectHoldingPlan(
                'pharmacy_organizations',
                SubjectHoldingAction::IrreversibleTombstone,
                'Keep the organization row. Tombstone legal-name and legal-registration ciphertext/HMAC. Public name becomes erased. Draft/pending status is retained. Not an operational grant.',
            ),
            new SubjectHoldingPlan(
                'pharmacy_branches',
                SubjectHoldingAction::IrreversibleTombstone,
                'Tombstone address and phone ciphertext for branches of organizations the subject owns. Coordinates remain a location fact with no contact content. Public branch name becomes erased.',
            ),
            new SubjectHoldingPlan(
                'pharmacy_memberships',
                SubjectHoldingAction::IrreversibleTombstone,
                'Keep user_id attached for referential integrity. Founding owner membership stays pending/non-operational. Invitation and revoker identifiers are not rewritten.',
            ),
        ];
    }
}
