<?php

declare(strict_types=1);

namespace Modules\Clinics\Support;

use DateTimeImmutable;
use Modules\Clinics\Enums\ClinicInvitationStatus;
use Modules\Clinics\Enums\ClinicLocationStatus;
use Modules\Clinics\Enums\ClinicMembershipStatus;
use Modules\Clinics\Enums\ClinicStaffRole;
use Modules\Identity\Services\NationalIdProtector;
use Modules\Platform\Services\Persistence\BinaryColumn;
use Modules\Platform\Support\Identifier;

final class ClinicLocationRowFactory
{
    public const ENCRYPT_PHYSICAL_ADDRESS = 'physical_address';

    public function __construct(
        private readonly NationalIdProtector $protector,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function locationAttributes(
        Identifier $id,
        Identifier $doctorId,
        array $input,
        DateTimeImmutable $now,
    ): array {
        $stamp = $now->format('Y-m-d H:i:s.uP');

        return [
            'id' => $id->value,
            'doctor_id' => $doctorId->value,
            'public_name' => (string) $input['public_name'],
            'address_ciphertext' => BinaryColumn::bind(
                $this->protector->encryptSecret(self::ENCRYPT_PHYSICAL_ADDRESS, (string) $input['address']),
            ),
            'address_key_version' => $this->protector->encryptionVersion(),
            'country_code' => strtoupper(trim((string) $input['country_code'])),
            'status' => ClinicLocationStatus::Active->value,
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
    public function staffProfileAttributes(Identifier $id, Identifier $userId, DateTimeImmutable $now): array
    {
        $stamp = $now->format('Y-m-d H:i:s.uP');

        return [
            'id' => $id->value,
            'user_id' => $userId->value,
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function membershipAttributes(
        Identifier $id,
        Identifier $staffProfileId,
        Identifier $locationId,
        ClinicStaffRole $role,
        ClinicMembershipStatus $status,
        DateTimeImmutable $now,
        ?Identifier $inviterUserId,
        ?DateTimeImmutable $invitedAt,
        ?DateTimeImmutable $acceptedAt,
    ): array {
        $stamp = $now->format('Y-m-d H:i:s.uP');

        return [
            'id' => $id->value,
            'staff_profile_id' => $staffProfileId->value,
            'location_id' => $locationId->value,
            'role' => $role->value,
            'status' => $status->value,
            'invited_at' => $invitedAt?->format('Y-m-d H:i:s.uP'),
            'accepted_at' => $acceptedAt?->format('Y-m-d H:i:s.uP'),
            'revoked_at' => null,
            'inviter_user_id' => $inviterUserId?->value,
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
        Identifier $locationId,
        string $phoneHmac,
        int $hmacVersion,
        Identifier $inviterUserId,
        DateTimeImmutable $now,
        DateTimeImmutable $expiresAt,
    ): array {
        $stamp = $now->format('Y-m-d H:i:s.uP');

        return [
            'id' => $id->value,
            'location_id' => $locationId->value,
            'role' => ClinicStaffRole::Secretary->value,
            'status' => ClinicInvitationStatus::Pending->value,
            'target_phone_lookup_hmac' => BinaryColumn::bind($phoneHmac),
            'target_phone_key_version' => $hmacVersion,
            'expires_at' => $expiresAt->format('Y-m-d H:i:s.uP'),
            'consumed_at' => null,
            'inviter_user_id' => $inviterUserId->value,
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ];
    }
}
