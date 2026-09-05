<?php

declare(strict_types=1);

namespace Modules\Patients\Support;

use DateTimeZone;
use Modules\Platform\Contracts\FieldEncryptor;

final class PatientProfileProjector
{
    public function __construct(
        private readonly FieldEncryptor $encryptor,
    ) {}

    public function project(PatientProfileRecord $row): PatientProfileProjection
    {
        $utc = new DateTimeZone('UTC');

        return new PatientProfileProjection(
            $row->id->value,
            $this->encryptor->decrypt('patient_full_name', $row->fullNameCiphertext),
            $row->gender,
            $row->dateOfBirth,
            $row->heightCm,
            $row->weightKg,
            $row->maritalStatus,
            $row->bloodType,
            $row->status->value,
            $row->version,
            $row->createdAt->setTimezone($utc)->format('Y-m-d\TH:i:s.u\Z'),
            $row->updatedAt->setTimezone($utc)->format('Y-m-d\TH:i:s.u\Z'),
        );
    }
}
