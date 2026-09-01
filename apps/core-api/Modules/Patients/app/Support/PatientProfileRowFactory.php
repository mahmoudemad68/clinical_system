<?php

declare(strict_types=1);

namespace Modules\Patients\Support;

use DateTimeImmutable;
use Modules\Identity\Services\NationalIdProtector;
use Modules\Identity\Support\NationalId;
use Modules\Patients\Enums\PatientStatus;
use Modules\Platform\Services\Persistence\BinaryColumn;
use Modules\Platform\Support\Identifier;

final class PatientProfileRowFactory
{
    public function __construct(
        private readonly NationalIdProtector $protector,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function attributes(
        Identifier $id,
        ?Identifier $userId,
        NationalId $nationalId,
        array $input,
        string $createdByType,
        Identifier $createdById,
        DateTimeImmutable $now,
    ): array {
        $stamp = $now->format('Y-m-d H:i:s.uP');

        return [
            'id' => $id->value,
            'user_id' => $userId?->value,
            'national_id_ciphertext' => BinaryColumn::bind($this->protector->encryptNationalId($nationalId)),
            'national_id_lookup_hmac' => BinaryColumn::bind($this->protector->nationalIdHmac($nationalId)),
            'national_id_key_version' => $this->protector->encryptionVersion(),
            'full_name_ciphertext' => BinaryColumn::bind(
                $this->protector->encryptSecret('patient_full_name', (string) $input['full_name']),
            ),
            'gender' => (string) $input['gender'],
            'date_of_birth' => isset($input['date_of_birth']) && is_string($input['date_of_birth']) && $input['date_of_birth'] !== ''
                ? $input['date_of_birth']
                : null,
            'height_cm' => self::nullableNumber($input['height_cm'] ?? null),
            'weight_kg' => self::nullableNumber($input['weight_kg'] ?? null),
            'marital_status' => self::nullableString($input['marital_status'] ?? null),
            'blood_type' => self::nullableString($input['blood_type'] ?? null),
            'status' => PatientStatus::Active->value,
            'created_by_type' => $createdByType,
            'created_by_id' => $createdById->value,
            'version' => 1,
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ];
    }

    private static function nullableNumber(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (string) $value : null;
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
