<?php

declare(strict_types=1);

namespace Modules\Doctors\Support;

use DateTimeZone;

final class DoctorProfileProjector
{
    public function project(DoctorProfileRecord $row, SpecialtyRecord $specialty): DoctorProfileProjection
    {
        $utc = new DateTimeZone('UTC');

        return new DoctorProfileProjection(
            $row->id->value,
            $row->professionalDisplayName,
            $row->specialtyId->value,
            $specialty->code,
            $specialty->labelAr,
            $specialty->labelEn,
            $row->verificationStatus->value,
            $row->publicStatus->value,
            $row->version,
            $row->approvedAt?->setTimezone($utc)->format('Y-m-d\TH:i:s.u\Z'),
            $row->suspendedAt?->setTimezone($utc)->format('Y-m-d\TH:i:s.u\Z'),
            $row->createdAt->setTimezone($utc)->format('Y-m-d\TH:i:s.u\Z'),
            $row->updatedAt->setTimezone($utc)->format('Y-m-d\TH:i:s.u\Z'),
        );
    }
}
