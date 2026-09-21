<?php

declare(strict_types=1);

namespace Modules\Verification\Enums;

enum VerificationCaseType: string
{
    case DoctorVerification = 'doctor_verification';

    case PharmacyVerification = 'pharmacy_verification';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
