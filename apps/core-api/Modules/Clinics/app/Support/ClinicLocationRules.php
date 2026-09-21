<?php

declare(strict_types=1);

namespace Modules\Clinics\Support;

/**
 * Closed clinic location and staff HTTP validation. Server-owned fields are
 * absent so clients cannot assign doctor_id, status, version, actor IDs,
 * membership status, or arbitrary roles.
 */
final class ClinicLocationRules
{
    /**
     * @return array<string, list<mixed>>
     */
    public static function create(): array
    {
        return [
            'public_name' => ['required', 'string', 'min:1', 'max:'.self::publicNameMax()],
            'address' => ['required', 'string', 'min:1', 'max:'.self::addressMax()],
            'country_code' => ['required', 'string', 'size:2', 'in:EG'],
            'latitude' => ['required', 'numeric', 'gte:-90', 'lte:90'],
            'longitude' => ['required', 'numeric', 'gte:-180', 'lte:180'],
        ];
    }

    /**
     * @return array<string, list<mixed>>
     */
    public static function update(): array
    {
        return [
            'expected_version' => ['required', 'integer', 'min:1'],
            'public_name' => ['sometimes', 'string', 'min:1', 'max:'.self::publicNameMax()],
            'address' => ['sometimes', 'string', 'min:1', 'max:'.self::addressMax()],
            'country_code' => ['sometimes', 'string', 'size:2', 'in:EG'],
            'latitude' => ['sometimes', 'numeric', 'gte:-90', 'lte:90'],
            'longitude' => ['sometimes', 'numeric', 'gte:-180', 'lte:180'],
        ];
    }

    /**
     * @return array<string, list<mixed>>
     */
    public static function invite(): array
    {
        return [
            'phone' => ['required', 'string', 'max:32'],
        ];
    }

    /**
     * Empty closed body. Invitation identity is server-derived.
     *
     * @return array<string, list<mixed>>
     */
    public static function accept(): array
    {
        return [];
    }

    private static function publicNameMax(): int
    {
        return (int) config('clinics_module.public_name_max_length', 200);
    }

    private static function addressMax(): int
    {
        return (int) config('clinics_module.address_max_length', 500);
    }
}
