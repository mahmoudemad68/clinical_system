<?php

declare(strict_types=1);

namespace Modules\Auth\Enums;

enum ClientClass: string
{
    case PatientMobile = 'patient_mobile';
    case DoctorDesktop = 'doctor_desktop';
    case PharmacyDesktop = 'pharmacy_desktop';
    case AdminWeb = 'admin_web';

    public function usesCookieSession(): bool
    {
        return $this === self::AdminWeb;
    }

    public function usesDeviceToken(): bool
    {
        return ! $this->usesCookieSession();
    }

    public function compatibleWith(string $accountType): bool
    {
        return match ($this) {
            self::PatientMobile => $accountType === 'patient',
            self::DoctorDesktop => $accountType === 'doctor',
            self::PharmacyDesktop => $accountType === 'pharmacy',
            self::AdminWeb => $accountType === 'admin' || $accountType === 'secretary',
        };
    }

    public function defaultPlatform(): DevicePlatform
    {
        return match ($this) {
            self::PatientMobile => DevicePlatform::Android,
            self::DoctorDesktop, self::PharmacyDesktop => DevicePlatform::Linux,
            self::AdminWeb => DevicePlatform::Web,
        };
    }
}
