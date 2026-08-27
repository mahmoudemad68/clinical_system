<?php

declare(strict_types=1);

namespace Modules\Access\Events;

use DateTimeImmutable;
use Modules\Platform\Enums\Classification;
use Modules\Platform\Events\DomainEvent;
use Modules\Platform\Support\Identifier;

final readonly class AccessGrantIssued implements DomainEvent
{
    public function __construct(
        private Identifier $grantId,
        private Identifier $subjectUserId,
        private string $capability,
        private string $resourceType,
        private Identifier $resourceId,
        private string $contextType,
        private Identifier $contextId,
        private string $reasonCode,
        private DateTimeImmutable $issuedAt,
    ) {}

    public function eventType(): string
    {
        return 'access.grant_issued';
    }

    public function schemaVersion(): int
    {
        return 1;
    }

    public function aggregateType(): string
    {
        return 'ContextualAccessGrant';
    }

    public function aggregateId(): Identifier
    {
        return $this->grantId;
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->issuedAt;
    }

    public function classification(): Classification
    {
        return Classification::Internal;
    }

    public function payload(): array
    {
        return [
            'grant_id' => $this->grantId->value,
            'subject_user_id' => $this->subjectUserId->value,
            'capability' => $this->capability,
            'resource_type' => $this->resourceType,
            'resource_id' => $this->resourceId->value,
            'context_type' => $this->contextType,
            'context_id' => $this->contextId->value,
            'reason_code' => $this->reasonCode,
            'issued_at' => $this->issuedAt->format(DATE_RFC3339),
        ];
    }
}
