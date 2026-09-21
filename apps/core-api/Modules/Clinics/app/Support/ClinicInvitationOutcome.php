<?php

declare(strict_types=1);

namespace Modules\Clinics\Support;

use DateTimeImmutable;
use DateTimeZone;
use Modules\Clinics\Enums\ClinicInvitationStatus;

/**
 * Compact invitation write result. Never includes phone, HMAC, or token material.
 *
 * @phpstan-type OutcomeArray array{
 *     invitation_id: string,
 *     location_id: string,
 *     status: string,
 *     expires_at: string
 * }
 */
final readonly class ClinicInvitationOutcome
{
    public function __construct(
        public string $invitationId,
        public string $locationId,
        public ClinicInvitationStatus $status,
        public DateTimeImmutable $expiresAt,
        public bool $created,
    ) {}

    /**
     * @return OutcomeArray
     */
    public function toArray(): array
    {
        return [
            'invitation_id' => $this->invitationId,
            'location_id' => $this->locationId,
            'status' => $this->status->value,
            'expires_at' => $this->expiresAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
        ];
    }
}
