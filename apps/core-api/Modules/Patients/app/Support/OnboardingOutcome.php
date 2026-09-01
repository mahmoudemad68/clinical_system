<?php

declare(strict_types=1);

namespace Modules\Patients\Support;

final readonly class OnboardingOutcome
{
    public const PROFILE_READY = 'profile_ready';

    public const MANUAL_REVIEW_REQUIRED = 'manual_review_required';

    public function __construct(
        public string $status,
        public ?PatientProfileProjection $profile,
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

        $profile = $this->profile;
        assert($profile instanceof PatientProfileProjection);

        return [
            'status' => self::PROFILE_READY,
            'profile' => $profile->toArray(),
        ];
    }
}
