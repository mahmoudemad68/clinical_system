<?php

declare(strict_types=1);

namespace Modules\Pharmacies\Events;

use DateTimeImmutable;
use Modules\Platform\Enums\Classification;
use Modules\Platform\Events\DomainEvent;
use Modules\Platform\Support\Identifier;

final readonly class PharmacyOrganizationCreated implements DomainEvent
{
    public function __construct(
        private Identifier $organizationId,
        private Identifier $branchId,
        private Identifier $membershipId,
        private Identifier $linkedUserId,
        private string $sourceType,
        private DateTimeImmutable $occurredAt,
    ) {}

    public function eventType(): string
    {
        return 'pharmacy.organization_created';
    }

    public function schemaVersion(): int
    {
        return 1;
    }

    public function aggregateType(): string
    {
        return 'PharmacyOrganization';
    }

    public function aggregateId(): Identifier
    {
        return $this->organizationId;
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
     * @return array{organization_id: string, branch_id: string, membership_id: string, linked_user_id: string, source_type: string}
     */
    public function payload(): array
    {
        return [
            'organization_id' => $this->organizationId->value,
            'branch_id' => $this->branchId->value,
            'membership_id' => $this->membershipId->value,
            'linked_user_id' => $this->linkedUserId->value,
            'source_type' => $this->sourceType,
        ];
    }
}
