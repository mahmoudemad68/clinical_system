<?php

declare(strict_types=1);

namespace Modules\Pharmacies\Support;

use DateTimeImmutable;
use Modules\Pharmacies\Enums\PharmacyInvitationStatus;
use Modules\Pharmacies\Enums\PharmacyMembershipRole;
use Modules\Platform\Support\Identifier;

/**
 * Internal persistence row. Must not leave the Pharmacies module.
 */
final readonly class PharmacyStaffInvitationRecord
{
    public function __construct(
        public Identifier $id,
        public Identifier $organizationId,
        public Identifier $branchId,
        public PharmacyMembershipRole $role,
        public PharmacyInvitationStatus $status,
        public string $targetPhoneLookupHmac,
        public int $targetPhoneKeyVersion,
        public DateTimeImmutable $expiresAt,
        public DateTimeImmutable $invitedAt,
        public ?DateTimeImmutable $acceptedAt,
        public ?DateTimeImmutable $consumedAt,
        public Identifier $inviterUserId,
        public int $version,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
