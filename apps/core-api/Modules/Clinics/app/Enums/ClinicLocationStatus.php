<?php

declare(strict_types=1);

namespace Modules\Clinics\Enums;

enum ClinicLocationStatus: string
{
    case Draft = 'draft';
    case Pending = 'pending';
    case Active = 'active';
    case Suspended = 'suspended';
    case Closed = 'closed';

    /**
     * Authoritative location readiness only. Not public listing, schedules,
     * booking, clinical capability, or patient visibility.
     */
    public function isLocationReady(): bool
    {
        return $this === self::Active;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
