<?php

declare(strict_types=1);

namespace Modules\Verification\Events;

use DateTimeImmutable;
use Modules\Platform\Enums\Classification;
use Modules\Platform\Events\DomainEvent;
use Modules\Platform\Support\Identifier;

final readonly class PharmacyVerificationDecided implements DomainEvent
{
    /**
     * @param  list<string>  $branchIds
     */
    public function __construct(
        private Identifier $organizationId,
        private array $branchIds,
        private Identifier $caseId,
        private string $decision,
        private string $reasonCode,
        private DateTimeImmutable $occurredAt,
    ) {}

    public function eventType(): string
    {
        return 'pharmacy.verification_decided';
    }

    public function schemaVersion(): int
    {
        return 1;
    }

    public function aggregateType(): string
    {
        return 'VerificationCase';
    }

    public function aggregateId(): Identifier
    {
        return $this->caseId;
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function classification(): Classification
    {
        return Classification::Internal;
    }

    /**
     * @return array{organization_id: string, branch_ids: list<string>, case_id: string, decision: string, reason_code: string}
     */
    public function payload(): array
    {
        return [
            'organization_id' => $this->organizationId->value,
            'branch_ids' => $this->branchIds,
            'case_id' => $this->caseId->value,
            'decision' => $this->decision,
            'reason_code' => $this->reasonCode,
        ];
    }
}
