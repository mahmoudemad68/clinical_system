<?php

declare(strict_types=1);

namespace Modules\Pharmacies\Events;

use DateTimeImmutable;
use Modules\Pharmacies\Enums\PharmacyBranchChangeType;
use Modules\Platform\Enums\Classification;
use Modules\Platform\Events\DomainEvent;
use Modules\Platform\Support\Identifier;

final readonly class PharmacyBranchChanged implements DomainEvent
{
    public function __construct(
        private Identifier $branchId,
        private Identifier $organizationId,
        private int $version,
        private PharmacyBranchChangeType $changeType,
        private DateTimeImmutable $occurredAt,
    ) {}

    public function eventType(): string
    {
        return 'pharmacy.branch_changed';
    }

    public function schemaVersion(): int
    {
        return 1;
    }

    public function aggregateType(): string
    {
        return 'PharmacyBranch';
    }

    public function aggregateId(): Identifier
    {
        return $this->branchId;
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
     * @return array{branch_id: string, organization_id: string, version: int, change_type: string}
     */
    public function payload(): array
    {
        return [
            'branch_id' => $this->branchId->value,
            'organization_id' => $this->organizationId->value,
            'version' => $this->version,
            'change_type' => $this->changeType->value,
        ];
    }
}
