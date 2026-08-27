<?php

declare(strict_types=1);

namespace Modules\Identity\Enums;

enum AccountType: string
{
    case Patient = 'patient';
    case Doctor = 'doctor';
    case Pharmacy = 'pharmacy';
    case Secretary = 'secretary';
    case Admin = 'admin';

    public function requiresTotpForPrivilegedSession(): bool
    {
        return match ($this) {
            self::Admin, self::Doctor, self::Pharmacy => true,
            self::Patient, self::Secretary => false,
        };
    }

    /**
     * Coarse actor class for bounded metric labels. Not an authorization input.
     */
    public function actorClass(): string
    {
        return $this->value;
    }
}
