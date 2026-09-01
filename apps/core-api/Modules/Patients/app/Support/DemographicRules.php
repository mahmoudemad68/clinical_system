<?php

declare(strict_types=1);

namespace Modules\Patients\Support;

use Illuminate\Validation\Rule;

/**
 * Closed demographic validation using ENGINEERING_DEFAULT bounds in
 * config/patients.php. Not a clinical protocol.
 */
final class DemographicRules
{
    /**
     * @return array<string, list<mixed>>
     */
    public static function onboarding(): array
    {
        return array_merge(
            [
                'national_id' => ['required', 'string', 'max:32'],
                'full_name' => ['required', 'string', 'min:1', 'max:'.self::nameMax()],
            ],
            self::shared(requiredGender: true),
        );
    }

    /**
     * @return array<string, list<mixed>>
     */
    public static function patch(): array
    {
        return array_merge(
            [
                'version' => ['required', 'integer', 'min:1'],
                'full_name' => ['sometimes', 'string', 'min:1', 'max:'.self::nameMax()],
            ],
            self::shared(requiredGender: false),
        );
    }

    /**
     * @return array<string, list<mixed>>
     */
    private static function shared(bool $requiredGender): array
    {
        $height = config('patients.height_cm');
        $weight = config('patients.weight_kg');
        $heightMin = is_array($height) ? (float) $height['min'] : 30.0;
        $heightMax = is_array($height) ? (float) $height['max'] : 300.0;
        $weightMin = is_array($weight) ? (float) $weight['min'] : 1.0;
        $weightMax = is_array($weight) ? (float) $weight['max'] : 700.0;
        $dobMin = (string) config('patients.date_of_birth_min', '1850-01-01');

        $gender = $requiredGender
            ? ['required', 'string', Rule::in(self::genders())]
            : ['sometimes', 'string', Rule::in(self::genders())];

        return [
            'gender' => $gender,
            'date_of_birth' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:'.$dobMin, 'before_or_equal:today'],
            'height_cm' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'gte:'.$heightMin, 'lte:'.$heightMax],
            'weight_kg' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'gte:'.$weightMin, 'lte:'.$weightMax],
            'marital_status' => ['sometimes', 'nullable', 'string', Rule::in(self::marital())],
            'blood_type' => ['sometimes', 'nullable', 'string', Rule::in(self::blood())],
        ];
    }

    /**
     * @return list<string>
     */
    public static function genders(): array
    {
        $values = config('patients.gender', ['male', 'female']);

        return is_array($values) ? array_values(array_map('strval', $values)) : ['male', 'female'];
    }

    /**
     * @return list<string>
     */
    public static function blood(): array
    {
        $values = config('patients.blood_types', []);

        return is_array($values) ? array_values(array_map('strval', $values)) : [];
    }

    /**
     * @return list<string>
     */
    public static function marital(): array
    {
        $values = config('patients.marital_statuses', []);

        return is_array($values) ? array_values(array_map('strval', $values)) : [];
    }

    private static function nameMax(): int
    {
        return (int) config('patients.full_name_max_length', 200);
    }
}
