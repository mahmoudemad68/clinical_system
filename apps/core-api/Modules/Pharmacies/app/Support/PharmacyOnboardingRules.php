<?php

declare(strict_types=1);

namespace Modules\Pharmacies\Support;

/**
 * Closed pharmacy onboarding validation. Server-owned fields are absent on
 * purpose so clients cannot assign ownership, verification, lifecycle,
 * membership, capabilities, version, or crypto metadata.
 */
final class PharmacyOnboardingRules
{
    /**
     * @return array<string, list<mixed>>
     */
    public static function onboarding(): array
    {
        return [
            'legal_name' => ['required', 'string', 'min:1', 'max:'.self::legalNameMax()],
            'public_name' => ['required', 'string', 'min:1', 'max:'.self::publicNameMax()],
            'legal_registration_identifier' => ['required', 'string', 'min:1', 'max:'.self::registrationMax()],
            'branch_public_name' => ['required', 'string', 'min:1', 'max:'.self::branchNameMax()],
            'address' => ['required', 'string', 'min:1', 'max:'.self::addressMax()],
            'country_code' => ['required', 'string', 'size:2', 'in:EG'],
            'latitude' => ['required', 'numeric', 'gte:-90', 'lte:90'],
            'longitude' => ['required', 'numeric', 'gte:-180', 'lte:180'],
            'phone' => ['required', 'string', 'max:32'],
        ];
    }

    private static function legalNameMax(): int
    {
        return (int) config('pharmacies_module.legal_name_max_length', 200);
    }

    private static function publicNameMax(): int
    {
        return (int) config('pharmacies_module.public_name_max_length', 200);
    }

    private static function registrationMax(): int
    {
        return (int) config('pharmacies_module.legal_registration_max_length', 64);
    }

    private static function branchNameMax(): int
    {
        return (int) config('pharmacies_module.branch_public_name_max_length', 200);
    }

    private static function addressMax(): int
    {
        return (int) config('pharmacies_module.address_max_length', 500);
    }
}
