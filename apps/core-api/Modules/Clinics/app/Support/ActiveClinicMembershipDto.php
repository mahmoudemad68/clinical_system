<?php

declare(strict_types=1);

namespace Modules\Clinics\Support;

use Modules\Clinics\Enums\ClinicStaffRole;
use Modules\Platform\Support\Identifier;

/**
 * Narrow Phase-03 membership DTO. Active grants only.
 */
final readonly class ActiveClinicMembershipDto
{
    public function __construct(
        public Identifier $membershipId,
        public Identifier $locationId,
        public Identifier $userId,
        public ClinicStaffRole $role,
    ) {}
}
