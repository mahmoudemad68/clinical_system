<?php

declare(strict_types=1);

namespace App\Modules\Auth\Domain\ValueObjects;

enum OtpPurpose: string
{
    case Registration = 'registration';
    case PhoneChange = 'phone_change';
    case Recovery = 'recovery';
    case ProfileClaim = 'profile_claim';
}
