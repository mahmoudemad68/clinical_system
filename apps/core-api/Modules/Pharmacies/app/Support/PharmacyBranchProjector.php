<?php

declare(strict_types=1);

namespace Modules\Pharmacies\Support;

use Modules\Platform\Contracts\FieldEncryptor;

final class PharmacyBranchProjector
{
    public function __construct(
        private readonly FieldEncryptor $encryptor,
    ) {}

    public function owner(PharmacyBranchRecord $row): PharmacyBranchPrivateProjection
    {
        return new PharmacyBranchPrivateProjection(
            $row->id->value,
            $row->organizationId->value,
            $row->publicName,
            $row->countryCode,
            $row->status,
            $row->version,
            $row->createdAt,
            $row->updatedAt,
            $this->encryptor->decrypt(PharmacyOrganizationRowFactory::ENCRYPT_PHYSICAL_ADDRESS, $row->addressCiphertext),
            $row->latitude,
            $row->longitude,
        );
    }

    public function operator(PharmacyBranchRecord $row): PharmacyBranchPrivateProjection
    {
        return new PharmacyBranchPrivateProjection(
            $row->id->value,
            $row->organizationId->value,
            $row->publicName,
            $row->countryCode,
            $row->status,
            $row->version,
            $row->createdAt,
            $row->updatedAt,
            null,
            null,
            null,
        );
    }

    public function membership(PharmacyMembershipRecord $row): PharmacyMembershipProjection
    {
        $branchId = $row->branchId;
        assert($branchId !== null);

        return new PharmacyMembershipProjection(
            $row->id->value,
            $branchId->value,
            $row->role,
            $row->status,
            $row->version,
            $row->invitedAt,
            $row->acceptedAt,
            $row->revokedAt,
        );
    }

    /**
     * Compact create body that fits the Platform 255-byte idempotency pointer.
     *
     * @return array{branch_id: string, status: string, version: int}
     */
    public function compactCreate(PharmacyBranchRecord $row): array
    {
        return [
            'branch_id' => $row->id->value,
            'status' => $row->status->value,
            'version' => $row->version,
        ];
    }
}
