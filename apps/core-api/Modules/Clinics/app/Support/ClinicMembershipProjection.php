<?php

declare(strict_types=1);

namespace Modules\Clinics\Support;

use DateTimeImmutable;
use DateTimeZone;
use Modules\Clinics\Enums\ClinicMembershipStatus;
use Modules\Clinics\Enums\ClinicStaffRole;

/**
 * Safe membership projection for the owning doctor. No phone, HMAC, auth, or
 * clinical fields.
 *
 * @phpstan-type ProjectionArray array{
 *     membership_id: string,
 *     role: string,
 *     status: string,
 *     version: int,
 *     invited_at: string|null,
 *     accepted_at: string|null,
 *     revoked_at: string|null
 * }
 */
final readonly class ClinicMembershipProjection
{
    public function __construct(
        public string $membershipId,
        public ClinicStaffRole $role,
        public ClinicMembershipStatus $status,
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
