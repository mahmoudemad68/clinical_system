<?php

declare(strict_types=1);

namespace Modules\Pharmacies\Support;

use Modules\Pharmacies\Enums\PharmacyMembershipRole;
use Modules\Platform\Support\Identifier;

/**
 * Narrow Phase-02 port. Returns an active branch_operator membership only.
 * This is not a Phase-10 inventory/POS capability grant.
 */
final readonly class ActivePharmacyMembershipDto
{
    public function __construct(
        public Identifier $membershipId,
        public Identifier $organizationId,
        public Identifier $branchId,
        public Identifier $userId,
        public PharmacyMembershipRole $role,
    ) {}
}
