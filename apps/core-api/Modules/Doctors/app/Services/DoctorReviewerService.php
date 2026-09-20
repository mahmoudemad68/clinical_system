<?php

declare(strict_types=1);

namespace Modules\Doctors\Services;

use Modules\Doctors\Services\Persistence\PostgresDoctorProfileStore;
use Modules\Doctors\Services\Persistence\PostgresSpecialtyStore;
use Modules\Doctors\Support\DoctorProfileRecord;
use Modules\Doctors\Support\DoctorReviewerProjection;
use Modules\Doctors\Support\SpecialtyRecord;
use Modules\Platform\Support\Identifier;

/**
 * Narrow reviewer-facing professional projection. Verification and Admin
 * consume this instead of querying doctor_profiles or specialties.
 */
final class DoctorReviewerService
{
    public function __construct(
        private readonly PostgresDoctorProfileStore $profiles,
        private readonly PostgresSpecialtyStore $specialties,
    ) {}

    public function findById(Identifier $doctorId): ?DoctorReviewerProjection
    {
        $mapped = $this->findByIds([$doctorId]);

        return $mapped[$doctorId->value] ?? null;
    }

    /**
     * @param  list<Identifier>  $doctorIds
     * @return array<string, DoctorReviewerProjection>
     */
    public function findByIds(array $doctorIds): array
    {
        $ids = [];
        foreach ($doctorIds as $id) {
            $ids[$id->value] = $id->value;
        }
        if ($ids === []) {
            return [];
        }

        $rows = $this->profiles->findByIds(array_values($ids));
        $specialtyIds = [];
        foreach ($rows as $row) {
            $specialtyIds[$row->specialtyId->value] = $row->specialtyId->value;
        }
        $specialties = $this->specialties->findByIds(array_values($specialtyIds));

        $out = [];
        foreach ($rows as $row) {
            $specialty = $specialties[$row->specialtyId->value] ?? null;
            $out[$row->id->value] = $this->project($row, $specialty);
        }

        return $out;
    }

    private function project(DoctorProfileRecord $row, ?SpecialtyRecord $specialty): DoctorReviewerProjection
    {
        return new DoctorReviewerProjection(
            $row->id->value,
            $row->professionalDisplayName,
            $row->specialtyId->value,
            $specialty instanceof SpecialtyRecord ? $specialty->code : '',
            $specialty instanceof SpecialtyRecord ? $specialty->labelAr : '',
            $specialty instanceof SpecialtyRecord ? $specialty->labelEn : '',
            $row->verificationStatus->value,
            $row->publicStatus->value,
            $row->version,
        );
    }
}
