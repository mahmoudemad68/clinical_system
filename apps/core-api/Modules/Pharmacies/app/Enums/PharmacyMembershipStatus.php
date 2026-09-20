<?php

declare(strict_types=1);

namespace Modules\Pharmacies\Enums;

enum PharmacyMembershipStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Suspended = 'suspended';
    case Revoked = 'revoked';

    public function confersBusinessCapability(): bool
    {
        return false;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
