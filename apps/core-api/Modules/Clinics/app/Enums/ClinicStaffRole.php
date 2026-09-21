<?php

declare(strict_types=1);

namespace Modules\Clinics\Enums;

enum ClinicStaffRole: string
{
    case Doctor = 'doctor';
    case Secretary = 'secretary';

    /**
     * The location owner remains authoritative through clinic_locations.doctor_id.
     * The doctor membership role is reserved by schema and is not written in
     * this chunk.
     */
    public function isInviteableInChunk10(): bool
    {
        return $this === self::Secretary;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
