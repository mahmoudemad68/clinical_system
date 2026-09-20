<?php

declare(strict_types=1);

namespace Modules\Doctors\Support;

/**
 * Reviewer-safe professional projection. No National ID, syndicate number,
 * ciphertext, HMAC, key versions, phone, or auth-session data.
 *
 * @phpstan-type SpecialtyArray array{
 *     specialty_id: string,
 *     code: string,
 *     label_ar: string,
 *     label_en: string
 * }
 * @phpstan-type ProjectionArray array{
 *     doctor_id: string,
 *     professional_display_name: string,
 *     specialty: SpecialtyArray,
 *     doctor_verification_status: string,
 *     doctor_public_status: string,
 *     profile_version: int
 * }
 */
final readonly class DoctorReviewerProjection
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
        public int $profileVersion,
    ) {}

    /**
     * @return ProjectionArray
     */
    public function toArray(): array
    {
        return [
            'doctor_id' => $this->doctorId,
            'professional_display_name' => $this->professionalDisplayName,
            'specialty' => [
                'specialty_id' => $this->specialtyId,
                'code' => $this->specialtyCode,
                'label_ar' => $this->specialtyLabelAr,
                'label_en' => $this->specialtyLabelEn,
            ],
            'doctor_verification_status' => $this->verificationStatus,
            'doctor_public_status' => $this->publicStatus,
            'profile_version' => $this->profileVersion,
        ];
    }
}
