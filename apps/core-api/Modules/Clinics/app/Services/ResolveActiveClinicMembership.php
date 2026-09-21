<?php

declare(strict_types=1);

namespace Modules\Clinics\Services;

use Modules\Clinics\Services\Persistence\PostgresClinicStore;
use Modules\Clinics\Support\ActiveClinicMembershipDto;
use Modules\Clinics\Support\ClinicStaffMembershipRecord;
use Modules\Platform\Support\Identifier;

/**
 * Narrow Phase-03 port. Returns an active location-scoped membership only.
 */
final class ResolveActiveClinicMembership
{
    public function __construct(
        private readonly PostgresClinicStore $store,
    ) {}

    public function handle(Identifier $userId, Identifier $locationId): ?ActiveClinicMembershipDto
    {
        $row = $this->store->findActiveMembershipForUserAtLocation($userId, $locationId);
        if (! $row instanceof ClinicStaffMembershipRecord) {
            return null;
        }

        return new ActiveClinicMembershipDto(
            $row->id,
            $row->locationId,
            $userId,
            $row->role,
        );
    }
}
