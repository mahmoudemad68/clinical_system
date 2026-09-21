<?php

declare(strict_types=1);

namespace Modules\Clinics\Support;

use DateTimeZone;
use Modules\Platform\Contracts\FieldEncryptor;

final class ClinicLocationProjector
{
    public function __construct(
        private readonly FieldEncryptor $encryptor,
    ) {}

    public function owner(ClinicLocationRecord $row): ClinicLocationPrivateProjection
    {
        return new ClinicLocationPrivateProjection(
            $row->id->value,
            $row->publicName,
            $row->countryCode,
            $row->status,
            $row->version,
            $row->createdAt,
            $row->updatedAt,
            $this->encryptor->decrypt(ClinicLocationRowFactory::ENCRYPT_PHYSICAL_ADDRESS, $row->addressCiphertext),
            $row->latitude,
            $row->longitude,
        );
    }

    public function staff(ClinicLocationRecord $row): ClinicLocationPrivateProjection
    {
        return new ClinicLocationPrivateProjection(
            $row->id->value,
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

    public function membership(ClinicStaffMembershipRecord $row): ClinicMembershipProjection
    {
        return new ClinicMembershipProjection(
            $row->id->value,
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
     * @return array{location_id: string, status: string, version: int}
     */
    public function compactCreate(ClinicLocationRecord $row): array
    {
        return [
            'location_id' => $row->id->value,
            'status' => $row->status->value,
            'version' => $row->version,
        ];
    }

    public static function utc(string $stamp): string
    {
        return (new \DateTimeImmutable($stamp))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }
}
