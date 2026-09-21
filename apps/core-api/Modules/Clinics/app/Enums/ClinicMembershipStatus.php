<?php

declare(strict_types=1);

namespace Modules\Clinics\Enums;

enum ClinicMembershipStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Suspended = 'suspended';
    case Revoked = 'revoked';

    public function isUsableGrant(): bool
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
