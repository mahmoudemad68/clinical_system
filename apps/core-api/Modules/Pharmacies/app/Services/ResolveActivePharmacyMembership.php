<?php

declare(strict_types=1);

namespace Modules\Pharmacies\Services;

use Modules\Pharmacies\Services\Persistence\PostgresPharmacyOrganizationStore;
use Modules\Pharmacies\Support\ActivePharmacyMembershipDto;
use Modules\Pharmacies\Support\PharmacyMembershipRecord;
use Modules\Platform\Support\Identifier;

/**
 * Narrow Phase-02 port. Returns an active branch_operator membership only.
 * This is not a Phase-10 inventory/POS capability grant.
 */
final class ResolveActivePharmacyMembership
{
    public function __construct(
        private readonly PostgresPharmacyOrganizationStore $store,
    ) {}

    public function handle(Identifier $userId, Identifier $organizationId, Identifier $branchId): ?ActivePharmacyMembershipDto
    {
        $row = $this->store->findActiveBranchOperatorForUserAtBranch($userId, $organizationId, $branchId);
        if (! $row instanceof PharmacyMembershipRecord || ! $row->branchId instanceof Identifier) {
            return null;
        }

        return new ActivePharmacyMembershipDto(
            $row->id,
            $row->organizationId,
            $row->branchId,
            $userId,
            $row->role,
        );
    }
}
