<?php

declare(strict_types=1);

namespace Modules\Doctors\Services;

use Modules\Doctors\Services\Persistence\PostgresDoctorProfileStore;
use Modules\Doctors\Support\DoctorProfileRecord;
use Modules\Doctors\Support\PracticeOwnerEligibility;
use Modules\Platform\Support\Identifier;

/**
 * Purpose-specific Doctors public contract for Clinics. Clinics must not
 * query doctor_profiles. Public listing is not part of this projection.
 */
final class PracticeOwnerEligibilityService
{
    public function __construct(
        private readonly PostgresDoctorProfileStore $store,
    ) {}

    public function findByUserId(Identifier $userId, bool $lock = false): ?PracticeOwnerEligibility
    {
        $row = $this->store->findByUserId($userId, $lock);

        return $row instanceof DoctorProfileRecord ? $this->project($row) : null;
    }

    public function findByDoctorId(Identifier $doctorId, bool $lock = false): ?PracticeOwnerEligibility
    {
        $row = $this->store->findById($doctorId, $lock);

        return $row instanceof DoctorProfileRecord ? $this->project($row) : null;
    }

    private function project(DoctorProfileRecord $row): PracticeOwnerEligibility
    {
        return new PracticeOwnerEligibility(
            $row->id,
            $row->userId,
            $row->verificationStatus,
            $row->version,
        );
    }
}
