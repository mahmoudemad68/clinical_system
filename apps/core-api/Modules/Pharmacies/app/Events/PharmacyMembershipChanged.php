<?php

declare(strict_types=1);

namespace Modules\Pharmacies\Events;

use DateTimeImmutable;
use Modules\Pharmacies\Enums\PharmacyMembershipScopeType;
use Modules\Pharmacies\Enums\PharmacyMembershipStatus;
use Modules\Platform\Enums\Classification;
use Modules\Platform\Events\DomainEvent;
use Modules\Platform\Support\Identifier;

final readonly class PharmacyMembershipChanged implements DomainEvent
{
    public function __construct(
        private Identifier $membershipId,
        private Identifier $scopeId,
        private PharmacyMembershipStatus $status,
        private DateTimeImmutable $occurredAt,
        private PharmacyMembershipScopeType $scopeType = PharmacyMembershipScopeType::PharmacyBranch,
    ) {}

    public function eventType(): string
    {
        return 'pharmacy.membership_changed';
    }

    public function schemaVersion(): int
    {
        return 1;
    }

    public function aggregateType(): string
    {
        return 'PharmacyMembership';
    }

    public function aggregateId(): Identifier
    {
        return $this->membershipId;
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function classification(): Classification
    {
        return Classification::Personal;
    }

    /**
     * @return array{membership_id: string, scope_type: string, scope_id: string, status: string}
     */
    public function payload(): array
    {
        return [
            'membership_id' => $this->membershipId->value,
            'scope_type' => $this->scopeType->value,
            'scope_id' => $this->scopeId->value,
            'status' => $this->status->value,
        ];
    }
}
