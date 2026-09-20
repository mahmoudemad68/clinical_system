<?php

declare(strict_types=1);

namespace Modules\Verification\Enums;

enum ApplicantType: string
{
    case Doctor = 'doctor';

    case Pharmacy = 'pharmacy';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
