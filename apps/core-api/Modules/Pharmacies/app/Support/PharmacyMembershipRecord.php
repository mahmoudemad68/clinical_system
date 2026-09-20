<?php

declare(strict_types=1);

namespace Modules\Pharmacies\Support;

use DateTimeImmutable;
use Modules\Pharmacies\Enums\PharmacyMembershipRole;
use Modules\Pharmacies\Enums\PharmacyMembershipStatus;
use Modules\Platform\Support\Identifier;

/**
 * Internal persistence row. Must not leave the Pharmacies module.
 */
final readonly class PharmacyMembershipRecord
{
    public function __construct(
        public Identifier $id,
        public Identifier $organizationId,
        public Identifier $userId,
        public ?Identifier $branchId,
        public PharmacyMembershipRole $role,
        public PharmacyMembershipStatus $status,
        public int $version,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
