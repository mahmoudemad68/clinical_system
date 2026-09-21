<?php

declare(strict_types=1);

namespace Modules\Clinics\Support;

use DateTimeImmutable;
use Modules\Clinics\Enums\ClinicInvitationStatus;
use Modules\Clinics\Enums\ClinicStaffRole;
use Modules\Platform\Support\Identifier;

/**
 * Internal persistence row. Must not leave the Clinics module.
 */
final readonly class ClinicStaffInvitationRecord
{
    public function __construct(
        public Identifier $id,
        public Identifier $locationId,
        public ClinicStaffRole $role,
        public ClinicInvitationStatus $status,
        public string $targetPhoneLookupHmac,
        public int $targetPhoneKeyVersion,
        public DateTimeImmutable $expiresAt,
        public ?DateTimeImmutable $consumedAt,
        public Identifier $inviterUserId,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
