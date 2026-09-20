<?php

declare(strict_types=1);

namespace Modules\Pharmacies\Support;

/**
 * Compact onboarding result. GET /pharmacy-organizations/me is the canonical
 * projection. This payload must fit the Platform 255-byte idempotency pointer.
 */
final readonly class PharmacyOnboardingOutcome
{
    public const ORGANIZATION_READY = 'organization_ready';

    public const MANUAL_REVIEW_REQUIRED = 'manual_review_required';

    public function __construct(
        public string $status,
        public ?string $organizationId,
        public ?string $branchId,
        public ?string $membershipId,
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
            'status' => self::ORGANIZATION_READY,
            'organization_id' => $this->organizationId,
            'branch_id' => $this->branchId,
            'membership_id' => $this->membershipId,
            'version' => $this->version,
        ];
    }
}
