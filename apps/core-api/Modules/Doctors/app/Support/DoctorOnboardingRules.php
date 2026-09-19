<?php

declare(strict_types=1);

namespace Modules\Doctors\Support;

/**
 * Closed doctor onboarding validation. Server-owned fields are absent on
 * purpose so clients cannot assign identity, verification, listing, or crypto metadata.
 */
final class DoctorOnboardingRules
{
    public const UUID_V7 = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    /**
     * @return array<string, list<mixed>>
     */
    public static function onboarding(): array
    {
        return [
            'national_id' => ['required', 'string', 'max:32'],
            'professional_display_name' => ['required', 'string', 'min:1', 'max:'.self::displayNameMax()],
            'specialty_id' => ['required', 'string', 'regex:'.self::UUID_V7],
            'syndicate_number' => ['sometimes', 'nullable', 'string', 'min:1', 'max:'.self::syndicateMax()],
        ];
    }

    private static function displayNameMax(): int
    {
        return (int) config('doctors_module.professional_display_name_max_length', 200);
    }

    private static function syndicateMax(): int
    {
        return (int) config('doctors_module.syndicate_number_max_length', 64);
    }
}
