<?php

declare(strict_types=1);

namespace Modules\Pharmacies\Support;

use DateTimeImmutable;
use Modules\Identity\Services\NationalIdProtector;
use Modules\Identity\Support\PhoneE164;
use Modules\Pharmacies\Enums\PharmacyBranchStatus;
use Modules\Pharmacies\Enums\PharmacyInvitationStatus;
use Modules\Pharmacies\Enums\PharmacyMembershipRole;
use Modules\Pharmacies\Enums\PharmacyMembershipStatus;
use Modules\Pharmacies\Enums\PharmacyOrganizationStatus;
use Modules\Pharmacies\Enums\PharmacyVerificationStatus;
use Modules\Platform\Contracts\HmacHasher;
use Modules\Platform\Services\Persistence\BinaryColumn;
use Modules\Platform\Support\Identifier;

final class PharmacyOrganizationRowFactory
{
    public const ENCRYPT_LEGAL_NAME = 'legal_name';

    public const ENCRYPT_LEGAL_REGISTRATION = 'legal_registration';

    public const ENCRYPT_PHYSICAL_ADDRESS = 'physical_address';

    public const HMAC_LEGAL_REGISTRATION = 'legal_registration_lookup';

    public function __construct(
        private readonly NationalIdProtector $protector,
        private readonly HmacHasher $hmac,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function organizationAttributes(
        Identifier $id,
        string $registrationCanonical,
        array $input,
        DateTimeImmutable $now,
    ): array {
        $stamp = $now->format('Y-m-d H:i:s.uP');
        $keyVersion = $this->protector->encryptionVersion();

        return [
            'id' => $id->value,
            'legal_name_ciphertext' => BinaryColumn::bind(
                $this->protector->encryptSecret(self::ENCRYPT_LEGAL_NAME, (string) $input['legal_name']),
            ),
            'legal_name_key_version' => $keyVersion,
            'public_name' => (string) $input['public_name'],
            'registration_ciphertext' => BinaryColumn::bind(
                $this->protector->encryptSecret(self::ENCRYPT_LEGAL_REGISTRATION, $registrationCanonical),
            ),
            'registration_lookup_hmac' => BinaryColumn::bind(
                $this->hmac->digest(self::HMAC_LEGAL_REGISTRATION, $registrationCanonical),
            ),
            'registration_key_version' => $keyVersion,
            'verification_status' => PharmacyVerificationStatus::Draft->value,
            'status' => PharmacyOrganizationStatus::Draft->value,
            'version' => 1,
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function branchAttributes(
        Identifier $id,
        Identifier $organizationId,
        PhoneE164 $phone,
        array $input,
        DateTimeImmutable $now,
    ): array {
        $stamp = $now->format('Y-m-d H:i:s.uP');
        $keyVersion = $this->protector->encryptionVersion();

        return [
            'id' => $id->value,
            'organization_id' => $organizationId->value,
            'public_name' => (string) $input['branch_public_name'],
            'address_ciphertext' => BinaryColumn::bind(
                $this->protector->encryptSecret(self::ENCRYPT_PHYSICAL_ADDRESS, (string) $input['address']),
            ),
            'address_key_version' => $keyVersion,
            'country_code' => 'EG',
            'phone_ciphertext' => BinaryColumn::bind(
                $this->protector->encryptPhone($phone),
            ),
            'phone_key_version' => $keyVersion,
            'status' => PharmacyBranchStatus::Draft->value,
            'version' => 1,
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function ownerMembershipAttributes(
        Identifier $id,
        Identifier $organizationId,
        Identifier $userId,
        DateTimeImmutable $now,
    ): array {
        $stamp = $now->format('Y-m-d H:i:s.uP');

        return [
            'id' => $id->value,
            'organization_id' => $organizationId->value,
            'user_id' => $userId->value,
            'branch_id' => null,
            'role' => PharmacyMembershipRole::Owner->value,
            'status' => PharmacyMembershipStatus::Pending->value,
            'invited_at' => $stamp,
            'accepted_at' => $stamp,
            'revoked_at' => null,
            'inviter_user_id' => null,
            'revoker_user_id' => null,
            'version' => 1,
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ];
    }

    /**
     * Additional branch after the organization is already approved. Status is
     * server-owned `active` because the organization identity is inherited.
     * No Phase-10 operating mode or inventory flag is written.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function additionalBranchAttributes(
        Identifier $id,
        Identifier $organizationId,
        PhoneE164 $phone,
        array $input,
        DateTimeImmutable $now,
    ): array {
        $stamp = $now->format('Y-m-d H:i:s.uP');
        $keyVersion = $this->protector->encryptionVersion();

        return [
            'id' => $id->value,
            'organization_id' => $organizationId->value,
            'public_name' => (string) $input['public_name'],
            'address_ciphertext' => BinaryColumn::bind(
                $this->protector->encryptSecret(self::ENCRYPT_PHYSICAL_ADDRESS, (string) $input['address']),
            ),
            'address_key_version' => $keyVersion,
            'country_code' => 'EG',
            'phone_ciphertext' => BinaryColumn::bind(
                $this->protector->encryptPhone($phone),
            ),
            'phone_key_version' => $keyVersion,
            'status' => PharmacyBranchStatus::Active->value,
            'version' => 1,
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function encryptAddress(string $address): array
    {
        return [
            'address_ciphertext' => BinaryColumn::bind(
                $this->protector->encryptSecret(self::ENCRYPT_PHYSICAL_ADDRESS, $address),
            ),
            'address_key_version' => $this->protector->encryptionVersion(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function encryptPhone(PhoneE164 $phone): array
    {
        return [
            'phone_ciphertext' => BinaryColumn::bind(
                $this->protector->encryptPhone($phone),
            ),
            'phone_key_version' => $this->protector->encryptionVersion(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function branchOperatorMembershipAttributes(
        Identifier $id,
        Identifier $organizationId,
        Identifier $userId,
        Identifier $branchId,
        Identifier $inviterUserId,
        DateTimeImmutable $now,
        DateTimeImmutable $invitedAt,
    ): array {
        $stamp = $now->format('Y-m-d H:i:s.uP');

        return [
            'id' => $id->value,
            'organization_id' => $organizationId->value,
            'user_id' => $userId->value,
            'branch_id' => $branchId->value,
            'role' => PharmacyMembershipRole::BranchOperator->value,
            'status' => PharmacyMembershipStatus::Active->value,
            'invited_at' => $invitedAt->format('Y-m-d H:i:s.uP'),
            'accepted_at' => $stamp,
            'revoked_at' => null,
            'inviter_user_id' => $inviterUserId->value,
            'revoker_user_id' => null,
            'version' => 1,
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function invitationAttributes(
        Identifier $id,
        Identifier $organizationId,
        Identifier $branchId,
        string $phoneHmac,
        int $hmacVersion,
        Identifier $inviterUserId,
        DateTimeImmutable $now,
        DateTimeImmutable $expiresAt,
    ): array {
        $stamp = $now->format('Y-m-d H:i:s.uP');

        return [
            'id' => $id->value,
            'organization_id' => $organizationId->value,
            'branch_id' => $branchId->value,
            'role' => PharmacyMembershipRole::BranchOperator->value,
            'status' => PharmacyInvitationStatus::Pending->value,
            'target_phone_lookup_hmac' => BinaryColumn::bind($phoneHmac),
            'target_phone_key_version' => $hmacVersion,
            'expires_at' => $expiresAt->format('Y-m-d H:i:s.uP'),
            'invited_at' => $stamp,
            'accepted_at' => null,
            'consumed_at' => null,
            'inviter_user_id' => $inviterUserId->value,
            'version' => 1,
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ];
    }
}
