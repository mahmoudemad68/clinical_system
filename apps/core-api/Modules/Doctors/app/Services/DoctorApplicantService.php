<?php

declare(strict_types=1);

namespace Modules\Doctors\Services;

use DateTimeImmutable;
use Modules\Doctors\Enums\DoctorPublicStatus;
use Modules\Doctors\Enums\DoctorVerificationStatus;
use Modules\Doctors\Services\Persistence\PostgresDoctorProfileStore;
use Modules\Doctors\Support\DoctorApplicantProjection;
use Modules\Doctors\Support\DoctorProfileRecord;
use Modules\Platform\Exceptions\StateConflict;
use Modules\Platform\Exceptions\VersionConflict;
use Modules\Platform\Support\Identifier;

/**
 * Narrow applicant surface for Verification. Does not expose protected
 * identity material and never writes verification tables.
 */
final class DoctorApplicantService
{
    public function __construct(
        private readonly PostgresDoctorProfileStore $store,
    ) {}

    public function findByUserId(Identifier $userId, bool $lock = false): ?DoctorApplicantProjection
    {
        $row = $this->store->findByUserId($userId, $lock);

        return $row instanceof DoctorProfileRecord ? $this->project($row) : null;
    }

    public function findById(Identifier $doctorId, bool $lock = false): ?DoctorApplicantProjection
    {
        $row = $this->store->findById($doctorId, $lock);

        return $row instanceof DoctorProfileRecord ? $this->project($row) : null;
    }

    public function transition(
        Identifier $doctorId,
        DoctorVerificationStatus $target,
        int $expectedVersion,
        DateTimeImmutable $now,
    ): DoctorApplicantProjection {
        $row = $this->store->findById($doctorId, true);
        if (! $row instanceof DoctorProfileRecord) {
            throw new StateConflict;
        }

        if ($row->version !== $expectedVersion) {
            throw new VersionConflict;
        }

        if (! $row->verificationStatus->canTransitionTo($target)) {
            throw new StateConflict;
        }

        $stamp = $now->format('Y-m-d H:i:s.uP');
        $update = [
            'verification_status' => $target->value,
            'version' => $expectedVersion + 1,
            'updated_at' => $stamp,
            'public_status' => DoctorPublicStatus::Hidden->value,
        ];

        if ($target === DoctorVerificationStatus::Approved) {
            $update['approved_at'] = $stamp;
        }

        $affected = $this->store->updateVerificationState($doctorId, $expectedVersion, $update);
        if ($affected !== 1) {
            throw new VersionConflict;
        }

        $fresh = $this->store->findById($doctorId, true);
        if (! $fresh instanceof DoctorProfileRecord) {
            throw new StateConflict;
        }

        return $this->project($fresh);
    }

    private function project(DoctorProfileRecord $row): DoctorApplicantProjection
    {
        return new DoctorApplicantProjection(
            $row->id,
            $row->userId,
            $row->createdByUserId,
            $row->verificationStatus,
            $row->publicStatus,
            $row->version,
            $row->approvedAt,
            $row->suspendedAt,
        );
    }
}
