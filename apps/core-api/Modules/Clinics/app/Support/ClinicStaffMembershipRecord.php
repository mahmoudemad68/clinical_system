<?php

declare(strict_types=1);

namespace Modules\Clinics\Support;

use DateTimeImmutable;
use Modules\Clinics\Enums\ClinicMembershipStatus;
use Modules\Clinics\Enums\ClinicStaffRole;
use Modules\Platform\Support\Identifier;

/**
 * Internal persistence row. Must not leave the Clinics module.
 */
final readonly class ClinicStaffMembershipRecord
{
    public function __construct(
        public Identifier $id,
        public Identifier $staffProfileId,
        public Identifier $locationId,
        public ClinicStaffRole $role,
        public ClinicMembershipStatus $status,
        public int $version,
        public ?DateTimeImmutable $invitedAt,
        public ?DateTimeImmutable $acceptedAt,
        public ?DateTimeImmutable $revokedAt,
        public ?Identifier $inviterUserId,
        public ?Identifier $revokerUserId,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
