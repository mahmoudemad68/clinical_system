<?php

declare(strict_types=1);

namespace Modules\Doctors\Enums;

enum DoctorPublicStatus: string
{
    case Hidden = 'hidden';
    case Listed = 'listed';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
