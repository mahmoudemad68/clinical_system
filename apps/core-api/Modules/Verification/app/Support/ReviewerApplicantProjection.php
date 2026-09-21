<?php

declare(strict_types=1);

namespace Modules\Verification\Support;

use Modules\Doctors\Support\DoctorReviewerProjection;
use Modules\Pharmacies\Support\PharmacyReviewerProjection;
use Modules\Platform\Exceptions\InvalidValueObject;
use Modules\Verification\Enums\ApplicantType;

/**
 * Discriminated reviewer-safe applicant summary. Doctor JSON remains the
 * existing professional fields; pharmacy adds the public organization/branch
 * projection. Unknown applicant types fail closed.
 */
final readonly class ReviewerApplicantProjection
{
    private function __construct(
        public ApplicantType $applicantType,
        public ?DoctorReviewerProjection $doctor,
        public ?PharmacyReviewerProjection $pharmacy,
    ) {}

    public static function doctor(DoctorReviewerProjection $doctor): self
    {
        return new self(ApplicantType::Doctor, $doctor, null);
    }

    public static function pharmacy(PharmacyReviewerProjection $pharmacy): self
    {
        return new self(ApplicantType::Pharmacy, null, $pharmacy);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return match ($this->applicantType) {
            ApplicantType::Doctor => [
                'applicant_type' => ApplicantType::Doctor->value,
                ...$this->requireDoctor()->toArray(),
            ],
            ApplicantType::Pharmacy => [
                'applicant_type' => ApplicantType::Pharmacy->value,
                ...$this->requirePharmacy()->toArray(),
            ],
        };
    }

    public function requireDoctor(): DoctorReviewerProjection
    {
        if (! $this->doctor instanceof DoctorReviewerProjection) {
            throw new InvalidValueObject('Doctor reviewer projection is required.');
        }

        return $this->doctor;
    }

    public function requirePharmacy(): PharmacyReviewerProjection
    {
        if (! $this->pharmacy instanceof PharmacyReviewerProjection) {
            throw new InvalidValueObject('Pharmacy reviewer projection is required.');
        }

        return $this->pharmacy;
    }
}
