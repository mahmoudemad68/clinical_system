<?php

declare(strict_types=1);

namespace Modules\Clinics\Enums;

enum ClinicInvitationStatus: string
{
    case Pending = 'pending';
    case Consumed = 'consumed';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function isAcceptable(): bool
    {
        return $this === self::Pending;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
