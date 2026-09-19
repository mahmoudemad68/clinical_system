<?php

declare(strict_types=1);

namespace Modules\Doctors\Support;

/**
 * Own-profile projection. Never includes ciphertext, HMAC, key versions,
 * National ID, syndicate number, or another doctor's data.
 *
 * @phpstan-type ProjectionArray array{
 *     doctor_id: string,
 *     professional_display_name: string,
 *     specialty_id: string,
 *     specialty_code: string,
 *     specialty_label_ar: string,
 *     specialty_label_en: string,
 *     verification_status: string,
 *     public_status: string,
 *     version: int,
 *     approved_at: string|null,
 *     suspended_at: string|null,
 *     created_at: string,
 *     updated_at: string
 * }
 */
final readonly class DoctorProfileProjection
{
    public function __construct(
        public string $doctorId,
        public string $professionalDisplayName,
        public string $specialtyId,
        public string $specialtyCode,
        public string $specialtyLabelAr,
        public string $specialtyLabelEn,
        public string $verificationStatus,
        public string $publicStatus,
        public int $version,
        public ?string $approvedAt,
        public ?string $suspendedAt,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    /**
     * @return ProjectionArray
     */
    public function toArray(): array
    {
        return [
            'doctor_id' => $this->doctorId,
            'professional_display_name' => $this->professionalDisplayName,
            'specialty_id' => $this->specialtyId,
            'specialty_code' => $this->specialtyCode,
            'specialty_label_ar' => $this->specialtyLabelAr,
            'specialty_label_en' => $this->specialtyLabelEn,
            'verification_status' => $this->verificationStatus,
            'public_status' => $this->publicStatus,
            'version' => $this->version,
            'approved_at' => $this->approvedAt,
            'suspended_at' => $this->suspendedAt,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
