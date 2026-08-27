<?php

declare(strict_types=1);

namespace App\Modules\Access\Domain\Events;

use App\Modules\Platform\Domain\Events\DomainEvent;
use App\Modules\Platform\Domain\ValueObjects\Classification;
use App\Modules\Platform\Domain\ValueObjects\Identifier;
use DateTimeImmutable;

final readonly class AccessGrantRevoked implements DomainEvent
{
    public function __construct(
        private Identifier $grantId,
        private Identifier $subjectUserId,
        private string $reasonCode,
        private DateTimeImmutable $revokedAt,
    ) {}

    public function eventType(): string
    {
        return 'access.grant_revoked';
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
        return $this->revokedAt;
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
            'reason_code' => $this->reasonCode,
            'revoked_at' => $this->revokedAt->format(DATE_RFC3339),
        ];
    }
}
