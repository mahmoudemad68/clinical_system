<?php

declare(strict_types=1);

namespace Modules\Pharmacies\Support;

/**
 * Own-organization projection. Never includes ciphertext, HMAC, key versions,
 * legal registration, legal name, address, phone, or coordinates.
 *
 * @phpstan-type BranchArray array{
 *     branch_id: string,
 *     public_name: string,
 *     country_code: string,
 *     status: string,
 *     version: int
 * }
 * @phpstan-type MembershipArray array{
 *     membership_id: string,
 *     role: string,
 *     status: string
 * }
 * @phpstan-type ProjectionArray array{
 *     organization_id: string,
 *     public_name: string,
 *     verification_status: string,
 *     status: string,
 *     version: int,
 *     created_at: string,
 *     updated_at: string,
 *     initial_branch: BranchArray,
 *     membership: MembershipArray
 * }
 */
final readonly class PharmacyOrganizationProjection
{
    public function __construct(
        public string $organizationId,
        public string $publicName,
        public string $verificationStatus,
        public string $status,
        public int $version,
        public string $createdAt,
        public string $updatedAt,
        public string $branchId,
        public string $branchPublicName,
        public string $countryCode,
        public string $branchStatus,
        public int $branchVersion,
        public string $membershipId,
        public string $membershipRole,
        public string $membershipStatus,
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
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'initial_branch' => [
                'branch_id' => $this->branchId,
                'public_name' => $this->branchPublicName,
                'country_code' => $this->countryCode,
                'status' => $this->branchStatus,
                'version' => $this->branchVersion,
            ],
            'membership' => [
                'membership_id' => $this->membershipId,
                'role' => $this->membershipRole,
                'status' => $this->membershipStatus,
            ],
        ];
    }
}
