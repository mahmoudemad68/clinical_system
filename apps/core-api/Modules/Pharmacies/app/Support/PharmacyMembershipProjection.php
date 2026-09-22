<?php

declare(strict_types=1);

namespace Modules\Pharmacies\Support;

use DateTimeImmutable;
use DateTimeZone;
use Modules\Pharmacies\Enums\PharmacyMembershipRole;
use Modules\Pharmacies\Enums\PharmacyMembershipStatus;

/**
 * Safe membership projection for the owning pharmacy. No phone, HMAC, auth,
 * National ID, email, user_id, inventory, or clinical fields.
 *
 * @phpstan-type ProjectionArray array{
 *     membership_id: string,
 *     branch_id: string,
 *     role: string,
 *     status: string,
 *     version: int,
 *     invited_at: string|null,
 *     accepted_at: string|null,
 *     revoked_at: string|null
 * }
 */
final readonly class PharmacyMembershipProjection
{
    public function __construct(
        public string $membershipId,
        public string $branchId,
        public PharmacyMembershipRole $role,
        public PharmacyMembershipStatus $status,
        public int $version,
        public ?DateTimeImmutable $invitedAt,
        public ?DateTimeImmutable $acceptedAt,
        public ?DateTimeImmutable $revokedAt,
    ) {}

    /**
     * @return ProjectionArray
     */
    public function toArray(): array
    {
        return [
            'membership_id' => $this->membershipId,
            'branch_id' => $this->branchId,
            'role' => $this->role->value,
            'status' => $this->status->value,
            'version' => $this->version,
            'invited_at' => self::instant($this->invitedAt),
            'accepted_at' => self::instant($this->acceptedAt),
            'revoked_at' => self::instant($this->revokedAt),
        ];
    }

    private static function instant(?DateTimeImmutable $value): ?string
    {
        if (! $value instanceof DateTimeImmutable) {
            return null;
        }

        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }
}
