<?php

declare(strict_types=1);

namespace Modules\Patients\Support;

/**
 * Compact onboarding result. GET /patients/me/profile is the canonical
 * projection. This payload must fit the Platform 255-byte idempotency pointer.
 */
final readonly class OnboardingOutcome
{
    public const PROFILE_READY = 'profile_ready';

    public const MANUAL_REVIEW_REQUIRED = 'manual_review_required';

    public function __construct(
        public string $status,
        public ?string $patientId,
        public ?int $version,
        public bool $created,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        if ($this->status === self::MANUAL_REVIEW_REQUIRED) {
            return ['status' => self::MANUAL_REVIEW_REQUIRED];
        }

        return [
            'status' => self::PROFILE_READY,
            'patient_id' => $this->patientId,
            'version' => $this->version,
        ];
    }
}
