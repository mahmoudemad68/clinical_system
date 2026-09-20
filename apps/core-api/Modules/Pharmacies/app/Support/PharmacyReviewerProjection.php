<?php

declare(strict_types=1);

namespace Modules\Pharmacies\Support;

/**
 * Reviewer-safe organization/branch projection. No legal registration, legal
 * name, address, phone, coordinates, ciphertext, HMAC, or key versions.
 *
 * @phpstan-type BranchArray array{
 *     branch_id: string,
 *     public_name: string,
 *     country_code: string,
 *     status: string
 * }
 * @phpstan-type ProjectionArray array{
 *     organization_id: string,
 *     public_name: string,
 *     verification_status: string,
 *     status: string,
 *     version: int,
 *     initial_branch: BranchArray
 * }
 */
final readonly class PharmacyReviewerProjection
{
    public function __construct(
        public string $organizationId,
        public string $publicName,
        public string $verificationStatus,
        public string $status,
        public int $version,
        public string $branchId,
        public string $branchPublicName,
        public string $countryCode,
        public string $branchStatus,
    ) {}

    /**
     * @return ProjectionArray
     */
    public function toArray(): array
    {
        return [
            'organization_id' => $this->organizationId,
            'public_name' => $this->publicName,
            'verification_status' => $this->verificationStatus,
            'status' => $this->status,
            'version' => $this->version,
            'initial_branch' => [
                'branch_id' => $this->branchId,
                'public_name' => $this->branchPublicName,
                'country_code' => $this->countryCode,
                'status' => $this->branchStatus,
            ],
        ];
    }
}
