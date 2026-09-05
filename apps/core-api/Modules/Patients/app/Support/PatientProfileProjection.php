<?php

declare(strict_types=1);

namespace Modules\Patients\Support;

/**
 * Own-profile demographic projection. Never includes ciphertext, HMAC,
 * key versions, or other patients' data.
 *
 * @phpstan-type ProjectionArray array{
 *     patient_id: string,
 *     full_name: string,
 *     gender: string,
 *     date_of_birth: string|null,
 *     height_cm: string|null,
 *     weight_kg: string|null,
 *     marital_status: string|null,
 *     blood_type: string|null,
 *     status: string,
 *     version: int,
 *     created_at: string,
 *     updated_at: string
 * }
 */
final readonly class PatientProfileProjection
{
    public function __construct(
        public string $patientId,
        public string $fullName,
        public string $gender,
        public ?string $dateOfBirth,
        public ?string $heightCm,
        public ?string $weightKg,
        public ?string $maritalStatus,
        public ?string $bloodType,
        public string $status,
        public int $version,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    /**
     * @return ProjectionArray
     */
    public function toArray(): array
    {
        return [
            'patient_id' => $this->patientId,
            'full_name' => $this->fullName,
            'gender' => $this->gender,
            'date_of_birth' => $this->dateOfBirth,
            'height_cm' => $this->heightCm,
            'weight_kg' => $this->weightKg,
            'marital_status' => $this->maritalStatus,
            'blood_type' => $this->bloodType,
            'status' => $this->status,
            'version' => $this->version,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
