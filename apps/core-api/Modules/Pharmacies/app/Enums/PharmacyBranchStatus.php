<?php

declare(strict_types=1);

namespace Modules\Pharmacies\Enums;

enum PharmacyBranchStatus: string
{
    case Draft = 'draft';
    case Pending = 'pending';
    case Active = 'active';
    case Suspended = 'suspended';
    case Closed = 'closed';

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
